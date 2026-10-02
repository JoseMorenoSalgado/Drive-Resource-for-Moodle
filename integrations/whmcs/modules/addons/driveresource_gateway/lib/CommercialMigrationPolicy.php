<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

/**
 * Pure legacy-to-0.6 commercial migration mapping.
 *
 * Database orchestration remains in driveresource_gateway.php. This class
 * contains only deterministic row construction so migration behavior can be
 * executed in CI with representative pre-0.6 service records.
 */
final class CommercialMigrationPolicy
{
    /**
     * Build the legacy commercial-account row for one existing service.
     *
     * @param object $service Existing mod_driveresource_services row.
     * @param int|null $clientId WHMCS client id.
     * @param int $now Migration timestamp.
     * @return array<string,int|string|bool|null>
     */
    public static function legacyAccountValues(object $service, ?int $clientId, int $now): array
    {
        $serviceStatus = strtolower(trim((string) ($service->status ?? 'active')));
        $createdAt = max(0, (int) ($service->created_at ?? 0));

        return [
            'service_id' => (int) ($service->service_id ?? 0),
            'client_id' => $clientId !== null && $clientId > 0 ? $clientId : null,
            'billing_mode' => CommercialAccount::MODE_LEGACY,
            'activation_verified' => true,
            'activation_amount_microusd' => 0,
            'activation_invoice_id' => null,
            'activation_settlement_version' => 0,
            'activation_refunded_at' => null,
            'balance_microusd' => 0,
            'free_storage_bytes' => max(0, (int) ($service->quota_bytes ?? 7000000000)),
            'free_transfer_bytes' => 20000000000,
            'storage_rate_microusd_per_gb' => 30000,
            'transfer_rate_microusd_per_gb' => 120000,
            'minimum_recharge_microusd' => 10000000,
            'free_installation_limit' => 1,
            'paid_installation_limit' => 0,
            'status' => $serviceStatus === 'active'
                ? CommercialAccount::STATUS_ACTIVE
                : CommercialAccount::STATUS_SUSPENDED,
            'grace_until' => null,
            'paid_at' => null,
            'updated_at' => $now,
            'created_at' => $createdAt > 0 ? $createdAt : $now,
        ];
    }

    /**
     * Build the primary Moodle installation row when the legacy service has
     * a complete site/token binding.
     *
     * @param object $service Existing mod_driveresource_services row.
     * @param int $now Migration timestamp.
     * @return array<string,int|string|bool|null>|null
     */
    public static function primaryInstallationValues(object $service, int $now): ?array
    {
        $serviceId = (int) ($service->service_id ?? 0);
        $siteUrl = trim((string) ($service->site_url ?? ''));
        $siteHash = trim((string) ($service->site_hash ?? ''));
        $tokenHash = trim((string) ($service->token_hash ?? ''));

        if ($serviceId <= 0 || $siteUrl === '' || $siteHash === '' || $tokenHash === '') {
            return null;
        }

        $serviceStatus = strtolower(trim((string) ($service->status ?? 'active')));
        $createdAt = max(0, (int) ($service->created_at ?? 0));

        return [
            'service_id' => $serviceId,
            'label' => 'Primary Moodle',
            'site_url' => $siteUrl,
            'site_hash' => $siteHash,
            'token_hash' => $tokenHash,
            'status' => $serviceStatus === 'active' ? 'active' : 'suspended',
            'is_primary' => true,
            'connection_status' => (string) ($service->connection_status ?? 'pending'),
            'connection_checked_at' => $service->connection_checked_at ?? null,
            'connection_message' => $service->connection_message ?? null,
            'last_seen_at' => null,
            'created_at' => $createdAt > 0 ? $createdAt : $now,
            'updated_at' => $now,
        ];
    }
}
