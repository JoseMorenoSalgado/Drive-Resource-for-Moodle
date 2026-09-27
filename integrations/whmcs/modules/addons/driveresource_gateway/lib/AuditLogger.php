<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use Throwable;
use WHMCS\Database\Capsule;

/**
 * Redacted audit trail for customer/admin control-plane actions.
 */
final class AuditLogger
{
    /**
     * Write one audit record without ever persisting secret values.
     *
     * @param int $serviceId WHMCS service id.
     * @param string $action Stable action key.
     * @param array $metadata Non-secret action metadata.
     * @return void
     */
    public static function log(int $serviceId, string $action, array $metadata = []): void
    {
        if (
            $serviceId <= 0
            || !preg_match('/^[a-z0-9_.-]{3,64}$/', $action)
            || !Capsule::schema()->hasTable('mod_driveresource_audit')
        ) {
            return;
        }

        [$actorType, $actorId] = self::actor();
        $safe = self::sanitize($metadata);

        try {
            Capsule::table('mod_driveresource_audit')->insert([
                'service_id' => $serviceId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => $action,
                'metadata_json' => $safe !== []
                    ? json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'created_at' => time(),
            ]);
        } catch (Throwable $exception) {
            if (function_exists('logModuleCall')) {
                logModuleCall(
                    'driveresource',
                    'AuditLogger',
                    ['serviceid' => $serviceId, 'action' => $action],
                    ['error' => $exception->getMessage()],
                    null,
                    ['password', 'token', 'key', 'secret', 'signature']
                );
            }
        }
    }

    /**
     * Resolve the current WHMCS actor from the active session.
     *
     * @return array{0:string,1:int|null}
     */
    private static function actor(): array
    {
        $adminId = (int) ($_SESSION['adminid'] ?? 0);
        if ($adminId > 0) {
            return ['admin', $adminId];
        }

        $clientId = (int) ($_SESSION['uid'] ?? 0);
        if ($clientId > 0) {
            return ['client', $clientId];
        }

        return ['system', null];
    }

    /**
     * Remove secret-looking keys and bound metadata size.
     *
     * @param array $metadata Input metadata.
     * @return array
     */
    private static function sanitize(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            $key = strtolower(trim((string) $key));
            if (
                $key === ''
                || preg_match('/token|password|secret|signature|api.?key|credential/i', $key)
            ) {
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$key] = $value;
                continue;
            }

            if (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 512);
            }
        }

        return $safe;
    }
}
