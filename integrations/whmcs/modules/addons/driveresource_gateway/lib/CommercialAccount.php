<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use WHMCS\Database\Capsule;

/**
 * Resolve commercial-account policy without exposing billing internals to Moodle.
 *
 * The WHMCS service id remains the stable external tenant identifier. One
 * commercial account may own multiple Moodle installations, each with a
 * distinct site binding and token.
 */
final class CommercialAccount
{
    public const MODE_FREE = 'free';
    public const MODE_PAYG = 'payg';
    public const MODE_LEGACY = 'legacy';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_LOW_BALANCE = 'low_balance';
    public const STATUS_UPLOAD_RESTRICTED = 'upload_restricted';
    public const STATUS_GRACE_PERIOD = 'grace_period';
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Load the commercial account attached to a WHMCS service.
     *
     * @param int $serviceId WHMCS service id.
     * @return object|null
     */
    public static function find(int $serviceId, bool $forUpdate = false): ?object
    {
        if ($serviceId <= 0 || !Capsule::schema()->hasTable('mod_driveresource_accounts')) {
            return null;
        }

        $query = Capsule::table('mod_driveresource_accounts')
            ->where('service_id', $serviceId);
        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Calculate whether a new upload may be reserved.
     *
     * Existing pre-0.6.0 tenants retain the historical quota/overage policy
     * through MODE_LEGACY. New FREE accounts cannot exceed the free storage
     * allowance. PAYG accounts retain the free allowance and may exceed it only
     * while prepaid credit is available and the account is operational.
     *
     * @param object $service Locked legacy service/account aggregate row.
     * @param int $incomingBytes Source bytes being reserved.
     * @return array<string,int|bool|string>
     */
    public static function uploadPolicy(object $service, int $incomingBytes): array
    {
        $serviceId = (int) ($service->service_id ?? 0);
        $used = max(0, (int) ($service->used_bytes ?? 0));
        $reserved = max(0, (int) ($service->reserved_bytes ?? 0));
        $incomingBytes = max(0, $incomingBytes);
        $projected = $used + $reserved + $incomingBytes;

        $account = self::find($serviceId, true);
        if (!$account) {
            $included = max(0, (int) ($service->quota_bytes ?? 0));
            $overage = max(0, $projected - $included);

            return [
                'mode' => self::MODE_LEGACY,
                'includedbytes' => $included,
                'usedbytes' => $used,
                'reservedbytes' => $reserved + $incomingBytes,
                'projectedbytes' => $projected,
                'overagebytes' => $overage,
                'overageallowed' => (bool) ($service->overage_allowed ?? false),
                'balance_microusd' => 0,
            ];
        }

        $mode = strtolower((string) ($account->billing_mode ?? self::MODE_FREE));
        $status = strtolower((string) ($account->status ?? self::STATUS_ACTIVE));
        $included = max(0, (int) ($account->free_storage_bytes ?? 0));
        $balance = (int) ($account->balance_microusd ?? 0);
        $overage = max(0, $projected - $included);

        $operational = in_array($status, [
            self::STATUS_ACTIVE,
            self::STATUS_LOW_BALANCE,
            self::STATUS_GRACE_PERIOD,
        ], true);

        if ($mode === self::MODE_LEGACY) {
            $overageAllowed = (bool) ($service->overage_allowed ?? false);
        } else if ($mode === self::MODE_PAYG) {
            $overageAllowed = $operational && $balance > 0;
        } else {
            $overageAllowed = false;
        }

        return [
            'mode' => $mode,
            'activationverified' => (bool) ($account->activation_verified ?? false),
            'includedbytes' => $included,
            'usedbytes' => $used,
            'reservedbytes' => $reserved + $incomingBytes,
            'projectedbytes' => $projected,
            'overagebytes' => $overage,
            'overageallowed' => $overageAllowed,
            'balance_microusd' => $balance,
        ];
    }

    /**
     * Maximum number of Moodle installations for the account.
     *
     * A return value of 0 means unlimited.
     *
     * @param object $account Commercial account row.
     * @return int
     */
    public static function installationLimit(object $account): int
    {
        $mode = strtolower((string) ($account->billing_mode ?? self::MODE_FREE));
        if ($mode === self::MODE_PAYG || $mode === self::MODE_LEGACY) {
            return max(0, (int) ($account->paid_installation_limit ?? 0));
        }

        return max(1, (int) ($account->free_installation_limit ?? 1));
    }

    /**
     * Determine whether another Moodle installation may be created.
     *
     * @param int $serviceId WHMCS service id.
     * @return bool
     */
    public static function canAddInstallation(int $serviceId): bool
    {
        $account = self::find($serviceId);
        if (!$account) {
            return false;
        }

        $limit = self::installationLimit($account);
        if ($limit === 0) {
            return true;
        }

        $active = (int) Capsule::table('mod_driveresource_installations')
            ->where('service_id', $serviceId)
            ->where('status', 'active')
            ->count();

        return $active < $limit;
    }
}
