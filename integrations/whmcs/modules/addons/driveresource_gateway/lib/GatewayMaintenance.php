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
        $this->expireAbandonedUploads();
        $this->syncProviderStorage();
        $this->deleteExpiredOrphans();
        $this->purgeNonces();
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

            Capsule::table('mod_driveresource_uploads')
                ->where('upload_id', (string) $row->upload_id)
                ->update([
                    'accounted_bytes' => $storageBytes,
                    'status' => $nextStatus,
                    'updated_at' => time(),
                ]);
            $affected[(int) $row->service_id] = true;
        }

        foreach (array_keys($affected) as $serviceId) {
            $this->recalculateServiceUsage((int) $serviceId);
        }
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
            ->whereIn('u.status', ['processing', 'ready', 'bound'])
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
