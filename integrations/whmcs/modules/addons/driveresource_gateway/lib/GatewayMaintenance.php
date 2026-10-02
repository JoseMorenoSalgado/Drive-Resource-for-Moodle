<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use Throwable;
use WHMCS\Database\Capsule;

/**
 * Daily provider/accounting maintenance.
 */
final class GatewayMaintenance
{
    private ?BunnyClient $bunny = null;

    /**
     * Lazily resolve the Elearning Stream client.
     *
     * Maintenance queries are backend-scoped first, so S3-only services will
     * never require Elearning Stream credentials merely because cron runs.
     *
     * @return BunnyClient
     */
    private function streamClient(): BunnyClient
    {
        if ($this->bunny === null) {
            $this->bunny = new BunnyClient();
        }

        return $this->bunny;
    }

    /**
     * Run bounded maintenance work.
     *
     * @return void
     */
    public function run(): void
    {
        $this->syncServicePoliciesFromProducts();
        $this->expireAbandonedUploads();
        $this->syncProviderStorage();
        $this->settleDailyStorageUsage();
        $this->deleteExpiredOrphans();
        $this->purgeNonces();
        $this->purgeUsageReports();
        $this->purgeAuditEvents();
    }

    /**
     * Synchronise mutable policy from WHMCS products into gateway services.
     *
     * This repairs legacy tenants that retained historical module defaults
     * after a code upgrade. The WHMCS product remains authoritative for quota,
     * overage and retention. If retention is reduced to zero, already-orphaned
     * assets are made eligible for deletion in this same maintenance run.
     *
     * @return void
     */
    private function syncServicePoliciesFromProducts(): void
    {
        if (
            !Capsule::schema()->hasTable('tblhosting')
            || !Capsule::schema()->hasTable('tblproducts')
            || !Capsule::schema()->hasTable('mod_driveresource_services')
        ) {
            return;
        }

        $rows = Capsule::table('mod_driveresource_services as s')
            ->join('tblhosting as h', 'h.id', '=', 's.service_id')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('p.servertype', 'driveresource')
            ->select([
                's.service_id',
                's.retention_days',
                'p.configoption1',
                'p.configoption2',
                'p.configoption3',
            ])
            ->limit(1000)
            ->get();

        $now = time();
        foreach ($rows as $row) {
            $quotaGb = max(0.1, min(100000.0, (float) ($row->configoption1 ?: 7)));
            $retention = max(0, min(365, (int) ($row->configoption3 ?? 0)));
            $overageAllowed = strtolower((string) ($row->configoption2 ?? 'on')) === 'on';
            $previousRetention = max(0, (int) ($row->retention_days ?? 0));

            Capsule::table('mod_driveresource_services')
                ->where('service_id', (int) $row->service_id)
                ->update([
                    'quota_bytes' => (int) round($quotaGb * 1000000000),
                    'overage_allowed' => $overageAllowed,
                    'retention_days' => $retention,
                    'updated_at' => $now,
                ]);

            if ($previousRetention <= 0 || $retention !== 0) {
                continue;
            }

            $uploads = Capsule::table('mod_driveresource_uploads')
                ->where('service_id', (int) $row->service_id)
                ->whereNotNull('delete_after')
                ->where('delete_after', '>', $now)
                ->whereIn('status', ['processing', 'ready', 'bound', 'deleting'])
                ->get();

            foreach ($uploads as $upload) {
                $references = (int) Capsule::table('mod_driveresource_asset_refs')
                    ->where('service_id', (int) $row->service_id)
                    ->where('video_id', (string) $upload->video_id)
                    ->where('active', true)
                    ->count();
                if ($references === 0) {
                    Capsule::table('mod_driveresource_uploads')
                        ->where('upload_id', (string) $upload->upload_id)
                        ->update([
                            'delete_after' => $now,
                            'updated_at' => $now,
                        ]);
                }
            }
        }
    }

    /**
     * Release quota reservations for uploads that never completed.
     *
     * @return void
     */
    private function expireAbandonedUploads(): void
    {
        $now = time();
        $query = Capsule::table('mod_driveresource_uploads as u')
            ->join('mod_driveresource_services as s', 's.service_id', '=', 'u.service_id');
        if (Capsule::schema()->hasColumn('mod_driveresource_services', 'backend_key')) {
            $query->where('s.backend_key', BackendRegistry::ELEARNING_STREAM);
        }
        $rows = $query
            ->whereIn('u.status', ['reserved', 'authorized'])
            ->where('u.expires_at', '<', $now - 3600)
            ->orderBy('u.expires_at', 'asc')
            ->limit(100)
            ->select('u.*')
            ->get();

        foreach ($rows as $row) {
            Capsule::connection()->transaction(function () use ($row, $now): void {
                $upload = Capsule::table('mod_driveresource_uploads')
                    ->where('upload_id', (string) $row->upload_id)
                    ->lockForUpdate()
                    ->first();
                if (!$upload || !in_array((string) $upload->status, ['reserved', 'authorized'], true)) {
                    return;
                }

                $service = Capsule::table('mod_driveresource_services')
                    ->where('service_id', (int) $upload->service_id)
                    ->lockForUpdate()
                    ->first();
                if ($service) {
                    Capsule::table('mod_driveresource_services')
                        ->where('service_id', (int) $upload->service_id)
                        ->update([
                            'reserved_bytes' => max(
                                0,
                                (int) $service->reserved_bytes - (int) $upload->source_size
                            ),
                            'updated_at' => $now,
                        ]);
                }

                Capsule::table('mod_driveresource_uploads')
                    ->where('upload_id', (string) $upload->upload_id)
                    ->update([
                        'status' => 'expired',
                        'accounted_bytes' => 0,
                        'updated_at' => $now,
                    ]);
            });

            if (!empty($row->video_id)) {
                try {
                    $this->streamClient()->deleteVideo((string) $row->video_id);
                } catch (Throwable $exception) {
                    // The database reservation is already released. A later
                    // provider reconciliation can remove any remote orphan.
                }
            }
        }
    }

    /**
     * Replace provisional source-size accounting with Bunny storageSize.
     *
     * Bunny's storage can be larger than the source because adaptive renditions
     * and optional MP4/original files consume additional bytes.
     *
     * @return void
     */
    private function syncProviderStorage(): void
    {
        $cutoff = time() - 1800;
        $query = Capsule::table('mod_driveresource_uploads as u')
            ->join('mod_driveresource_services as s', 's.service_id', '=', 'u.service_id');
        if (Capsule::schema()->hasColumn('mod_driveresource_services', 'backend_key')) {
            $query->where('s.backend_key', BackendRegistry::ELEARNING_STREAM);
        }
        $rows = $query
            ->whereIn('u.status', ['processing', 'ready', 'bound'])
            ->whereNotNull('u.video_id')
            ->where('u.updated_at', '<', $cutoff)
            ->orderBy('u.updated_at', 'asc')
            ->limit(200)
            ->select('u.*')
            ->get();

        $affected = [];
        foreach ($rows as $row) {
            try {
                $video = $this->streamClient()->getVideo((string) $row->video_id);
            } catch (Throwable $exception) {
                continue;
            }

            $storageBytes = max(0, (int) ($video['storageSize'] ?? 0));
            if ($storageBytes <= 0) {
                $storageBytes = max(0, (int) $row->accounted_bytes);
            }
            $providerStatus = (int) ($video['status'] ?? 0);
            $nextStatus = $providerStatus === 4
                ? ((string) $row->status === 'bound' ? 'bound' : 'ready')
                : (string) $row->status;
            $providerTitle = mb_substr(trim((string) ($video['title'] ?? '')), 0, 255);

            $update = [
                'accounted_bytes' => $storageBytes,
                'status' => $nextStatus,
                'updated_at' => time(),
            ];
            if ($providerTitle !== '') {
                $update['display_name'] = $providerTitle;
            }

            Capsule::table('mod_driveresource_uploads')
                ->where('upload_id', (string) $row->upload_id)
                ->update($update);
            $affected[(int) $row->service_id] = true;
        }

        foreach (array_keys($affected) as $serviceId) {
            $this->recalculateServiceUsage((int) $serviceId);
        }
    }

    /**
     * Snapshot and charge PAYG storage once per UTC day.
     *
     * Storage pricing is configured as USD/GB-month. The current provider
     * storage snapshot is prorated by the number of days in the current UTC
     * month. A deterministic ledger key makes the charge retry-safe.
     *
     * FREE accounts are never charged for storage, but provider growth beyond
     * the free allowance (for example after transcoding) restricts new uploads.
     *
     * @return void
     */
    private function settleDailyStorageUsage(): void
    {
        $schema = Capsule::schema();
        foreach ([
            'mod_driveresource_accounts',
            'mod_driveresource_services',
            'mod_driveresource_usage_daily',
            'mod_driveresource_wallet_ledger',
        ] as $table) {
            if (!$schema->hasTable($table)) {
                return;
            }
        }

        $usageDate = gmdate('Y-m-d');
        $daysInMonth = max(28, (int) gmdate('t'));
        $rows = Capsule::table('mod_driveresource_accounts as a')
            ->join('mod_driveresource_services as s', 's.service_id', '=', 'a.service_id')
            ->where('a.activation_verified', true)
            ->whereIn('a.billing_mode', [
                CommercialAccount::MODE_FREE,
                CommercialAccount::MODE_PAYG,
            ])
            ->where('s.status', 'active')
            ->select([
                'a.service_id',
                'a.billing_mode',
                'a.free_storage_bytes',
                'a.storage_rate_microusd_per_gb',
                's.used_bytes',
            ])
            ->limit(1000)
            ->get();

        foreach ($rows as $row) {
            $serviceId = (int) $row->service_id;
            $storageBytes = max(0, (int) $row->used_bytes);
            $freeBytes = max(0, (int) $row->free_storage_bytes);
            $billableBytes = max(0, $storageBytes - $freeBytes);
            $mode = (string) $row->billing_mode;
            $rate = max(0, (int) $row->storage_rate_microusd_per_gb);
            $charge = $mode === CommercialAccount::MODE_PAYG
                ? $this->dailyStorageChargeMicrousd($billableBytes, $rate, $daysInMonth)
                : 0;
            $ledgerKey = hash('sha256', 'storage|' . $serviceId . '|' . $usageDate);
            $now = time();

            Capsule::connection()->transaction(function () use (
                $serviceId,
                $storageBytes,
                $billableBytes,
                $mode,
                $charge,
                $ledgerKey,
                $usageDate,
                $now
            ): void {
                $daily = Capsule::table('mod_driveresource_usage_daily')
                    ->where('service_id', $serviceId)
                    ->where('usage_date', $usageDate)
                    ->lockForUpdate()
                    ->first();

                $storageChargeAlreadyRecorded = Capsule::table('mod_driveresource_wallet_ledger')
                    ->where('idempotency_key', $ledgerKey)
                    ->lockForUpdate()
                    ->first();

                if (
                    $mode === CommercialAccount::MODE_PAYG
                    && $charge > 0
                    && !$storageChargeAlreadyRecorded
                ) {
                    $account = Capsule::table('mod_driveresource_accounts')
                        ->where('service_id', $serviceId)
                        ->lockForUpdate()
                        ->first();
                    if (!$account) {
                        return;
                    }

                    $balance = (int) $account->balance_microusd;
                    $debited = $charge;
                    $nextBalance = $balance - $charge;
                    $nextStatus = $nextBalance > 0
                        ? (string) $account->status
                        : CommercialAccount::STATUS_UPLOAD_RESTRICTED;

                    Capsule::table('mod_driveresource_accounts')
                        ->where('service_id', $serviceId)
                        ->update([
                            'balance_microusd' => $nextBalance,
                            'status' => $nextStatus,
                            'updated_at' => $now,
                        ]);

                    Capsule::table('mod_driveresource_wallet_ledger')->insert([
                        'service_id' => $serviceId,
                        'entry_type' => 'storage_debit',
                        'amount_microusd' => -$debited,
                        'balance_after_microusd' => $nextBalance,
                        'currency' => 'USD',
                        'idempotency_key' => $ledgerKey,
                        'external_ref' => null,
                        'metadata_json' => json_encode([
                            'usage_date' => $usageDate,
                            'billable_bytes' => $billableBytes,
                            'calculated_charge_microusd' => $charge,
                            'debt_after_microusd' => max(0, -$nextBalance),
                        ], JSON_UNESCAPED_SLASHES),
                        'created_at' => $now,
                    ]);
                } else if (
                    $mode === CommercialAccount::MODE_FREE
                    && $billableBytes > 0
                ) {
                    Capsule::table('mod_driveresource_accounts')
                        ->where('service_id', $serviceId)
                        ->update([
                            'status' => CommercialAccount::STATUS_UPLOAD_RESTRICTED,
                            'updated_at' => $now,
                        ]);
                }

                if ($daily) {
                    $nextCharge = (int) $daily->charge_microusd;
                    if (!$storageChargeAlreadyRecorded) {
                        $nextCharge += $charge;
                    }

                    Capsule::table('mod_driveresource_usage_daily')
                        ->where('id', (int) $daily->id)
                        ->update([
                            'storage_bytes' => $storageBytes,
                            'billable_storage_bytes' => $billableBytes,
                            'charge_microusd' => $nextCharge,
                            'updated_at' => $now,
                        ]);
                } else {
                    Capsule::table('mod_driveresource_usage_daily')->insert([
                        'service_id' => $serviceId,
                        'usage_date' => $usageDate,
                        'storage_bytes' => $storageBytes,
                        'transfer_bytes' => 0,
                        'billable_storage_bytes' => $billableBytes,
                        'billable_transfer_bytes' => 0,
                        'charge_microusd' => $charge,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
        }
    }

    /**
     * Calculate one day's prorated storage charge without floating-point money.
     *
     * @param int $bytes Billable storage bytes.
     * @param int $rateMicrousdPerGb Monthly price per decimal GB in micro-USD.
     * @param int $daysInMonth UTC month length.
     * @return int
     */
    private function dailyStorageChargeMicrousd(
        int $bytes,
        int $rateMicrousdPerGb,
        int $daysInMonth
    ): int {
        if ($bytes <= 0 || $rateMicrousdPerGb <= 0) {
            return 0;
        }

        $wholeGb = intdiv($bytes, 1000000000);
        $remainder = $bytes % 1000000000;
        $monthly = ($wholeGb * $rateMicrousdPerGb)
            + intdiv(($remainder * $rateMicrousdPerGb) + 999999999, 1000000000);

        return intdiv($monthly + $daysInMonth - 1, $daysInMonth);
    }

    /**
     * Delete assets whose WHMCS retention period expired and have no references.
     *
     * @return void
     */
    private function deleteExpiredOrphans(): void
    {
        $now = time();
        $query = Capsule::table('mod_driveresource_uploads as u')
            ->join('mod_driveresource_services as s', 's.service_id', '=', 'u.service_id');
        if (Capsule::schema()->hasColumn('mod_driveresource_services', 'backend_key')) {
            $query->where('s.backend_key', BackendRegistry::ELEARNING_STREAM);
        }
        $rows = $query
            ->whereNotNull('u.delete_after')
            ->where('u.delete_after', '<=', $now)
            ->whereIn('u.status', ['processing', 'ready', 'bound', 'deleting'])
            ->orderBy('u.delete_after', 'asc')
            ->limit(100)
            ->select('u.*')
            ->get();

        foreach ($rows as $row) {
            $references = Capsule::table('mod_driveresource_asset_refs')
                ->where('service_id', (int) $row->service_id)
                ->where('video_id', (string) $row->video_id)
                ->where('active', true)
                ->count();
            if ($references > 0) {
                Capsule::table('mod_driveresource_uploads')
                    ->where('upload_id', (string) $row->upload_id)
                    ->update(['delete_after' => null, 'updated_at' => $now]);
                continue;
            }

            try {
                $this->streamClient()->deleteVideo((string) $row->video_id);
            } catch (Throwable $exception) {
                continue;
            }

            Capsule::table('mod_driveresource_uploads')
                ->where('upload_id', (string) $row->upload_id)
                ->update([
                    'status' => 'deleted',
                    'accounted_bytes' => 0,
                    'delete_after' => null,
                    'updated_at' => $now,
                ]);
            $this->recalculateServiceUsage((int) $row->service_id);
        }
    }

    /**
     * Recompute storage from provider-accounted assets.
     *
     * @param int $serviceId WHMCS service id.
     * @return void
     */
    private function recalculateServiceUsage(int $serviceId): void
    {
        $bytes = (int) Capsule::table('mod_driveresource_uploads')
            ->where('service_id', $serviceId)
            ->whereIn('status', ['processing', 'ready', 'bound'])
            ->sum('accounted_bytes');

        Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->update([
                'used_bytes' => max(0, $bytes),
                'updated_at' => time(),
            ]);
    }

    /**
     * Keep idempotency report history bounded.
     *
     * Reports older than 18 months are no longer needed to protect retries of
     * current billing periods and can be removed safely.
     *
     * @return void
     */
    private function purgeUsageReports(): void
    {
        if (!Capsule::schema()->hasTable('mod_driveresource_usage_reports')) {
            return;
        }

        $cutoff = gmdate('Y-m', strtotime('-18 months'));
        Capsule::table('mod_driveresource_usage_reports')
            ->where('period_key', '<', $cutoff)
            ->delete();
    }

    /**
     * Bound the redacted control-plane audit trail to 24 months.
     *
     * @return void
     */
    private function purgeAuditEvents(): void
    {
        if (!Capsule::schema()->hasTable('mod_driveresource_audit')) {
            return;
        }

        Capsule::table('mod_driveresource_audit')
            ->where('created_at', '<', time() - (730 * 86400))
            ->delete();
    }

    /**
     * Remove expired replay-protection nonces.
     *
     * @return void
     */
    private function purgeNonces(): void
    {
        Capsule::table('mod_driveresource_nonces')
            ->where('expires_at', '<', time())
            ->delete();
    }
}
