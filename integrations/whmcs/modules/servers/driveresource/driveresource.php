<?php
/**
 * Drive Resource WHMCS provisioning module.
 *
 * @copyright  2026 Elearning Cloud
 * @license    Proprietary companion module distributed with Drive Resource
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Driveresource\MetricsProvider;

/**
 * Module metadata.
 *
 * @return array
 */
function driveresource_MetaData(): array
{
    return [
        'DisplayName' => 'Elearning Stream',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
    ];
}

/**
 * Product-level commercial controls.
 *
 * @return array
 */
function driveresource_ConfigOptions(): array
{
    return [
        'Included Storage GB' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '7',
            'Description' => 'Storage included with the service before usage overage applies.',
        ],
        'Allow Storage Overage' => [
            'Type' => 'yesno',
            'Description' => 'Permit uploads above the included storage quota and bill the excess.',
            'Default' => 'on',
        ],
        'Retention Days' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '30',
            'Description' => 'Days to retain an unreferenced video before physical deletion.',
        ],
        'Storage Backend' => [
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'elearningstream',
            'Loader' => 'driveresource_BackendLoader',
            'SimpleMode' => true,
            'Description' => 'Backend assigned to every service created from this product.',
        ],
        'Backend Profile' => [
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'default',
            'Description' => 'Credential/profile selector reserved for multiple provider accounts or future S3 buckets.',
        ],
    ];
}

/**
 * Populate the backend selector with backends safe to provision.
 *
 * S3-compatible storage is intentionally omitted until its adapter is
 * production-ready. Adding it later will not change existing service schema.
 *
 * @param array $params WHMCS module parameters.
 * @return array<string,string>
 */
function driveresource_BackendLoader(array $params): array
{
    return [
        'elearningstream' => 'Elearning Stream',
    ];
}

/**
 * Provision a Drive Resource tenant.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_CreateAccount(array $params): string
{
    try {
        driveresource_require_gateway();
        $serviceId = (int) $params['serviceid'];
        $siteUrl = driveresource_site_url($params);
        $token = bin2hex(random_bytes(32));
        $now = time();

        $quotaBytes = driveresource_quota_bytes($params);
        $overageAllowed = driveresource_overage_allowed($params);
        $retentionDays = driveresource_retention_days($params);
        $backendKey = driveresource_backend_key($params);
        $backendProfile = driveresource_backend_profile($params);

        Capsule::table('mod_driveresource_services')->updateOrInsert(
            ['service_id' => $serviceId],
            [
                'site_url' => $siteUrl,
                'site_hash' => hash('sha256', $siteUrl),
                'token_hash' => hash('sha256', $token),
                'status' => 'active',
                'backend_key' => $backendKey,
                'backend_profile' => $backendProfile,
                'quota_bytes' => $quotaBytes,
                'overage_allowed' => $overageAllowed,
                'retention_days' => $retentionDays,
                'updated_at' => $now,
                'created_at' => $now,
                'suspended_at' => null,
                'terminated_at' => null,
            ]
        );

        // Core service properties use WHMCS-supported protected fields. The
        // service password is the Moodle-to-WHMCS token, not a Bunny secret.
        $params['model']->serviceProperties->save([
            'Username' => 'dr-' . $serviceId,
            'Password' => $token,
        ]);

        return 'success';
    } catch (Throwable $exception) {
        logModuleCall(
            'driveresource',
            'CreateAccount',
            ['serviceid' => $params['serviceid'] ?? 0],
            ['error' => $exception->getMessage()],
            null,
            []
        );
        return $exception->getMessage();
    }
}

/**
 * Suspend uploads/playback authorization for an overdue service.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_SuspendAccount(array $params): string
{
    return driveresource_set_status((int) $params['serviceid'], 'suspended');
}

/**
 * Restore an active service.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_UnsuspendAccount(array $params): string
{
    return driveresource_set_status((int) $params['serviceid'], 'active');
}

/**
 * Mark service terminated without immediately deleting media.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_TerminateAccount(array $params): string
{
    try {
        driveresource_require_gateway();
        $now = time();
        Capsule::table('mod_driveresource_services')
            ->where('service_id', (int) $params['serviceid'])
            ->update([
                'status' => 'terminated',
                'terminated_at' => $now,
                'updated_at' => $now,
            ]);

        return 'success';
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

/**
 * Apply upgraded/downgraded quota settings.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_ChangePackage(array $params): string
{
    try {
        driveresource_require_gateway();

        $serviceId = (int) $params['serviceid'];
        $service = Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->first();
        if (!$service) {
            throw new RuntimeException('Drive Resource service is not provisioned.');
        }

        $backendKey = driveresource_backend_key($params);
        $backendProfile = driveresource_backend_profile($params);
        $currentBackend = strtolower(trim((string) ($service->backend_key ?? 'elearningstream')));

        if ($currentBackend !== $backendKey) {
            $assets = (int) Capsule::table('mod_driveresource_uploads')
                ->where('service_id', $serviceId)
                ->where('status', '<>', 'deleted')
                ->count();
            if ($assets > 0 || (int) $service->used_bytes > 0 || (int) $service->reserved_bytes > 0) {
                throw new RuntimeException(
                    'Storage backend cannot be changed while the service owns media. '
                    . 'A controlled backend migration is required.'
                );
            }
        }

        Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->update([
                'backend_key' => $backendKey,
                'backend_profile' => $backendProfile,
                'quota_bytes' => driveresource_quota_bytes($params),
                'overage_allowed' => driveresource_overage_allowed($params),
                'retention_days' => driveresource_retention_days($params),
                'updated_at' => time(),
            ]);

        return 'success';
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

/**
 * Rotate the Moodle gateway token through WHMCS's service password workflow.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_ChangePassword(array $params): string
{
    try {
        driveresource_require_gateway();
        $token = trim((string) ($params['password'] ?? ''));
        if (strlen($token) < 32) {
            throw new RuntimeException('Gateway token must contain at least 32 characters.');
        }

        Capsule::table('mod_driveresource_services')
            ->where('service_id', (int) $params['serviceid'])
            ->update([
                'token_hash' => hash('sha256', $token),
                'updated_at' => time(),
            ]);

        return 'success';
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

/**
 * Validate the logical WHMCS server assignment.
 *
 * @param array $params WHMCS module parameters.
 * @return array
 */
function driveresource_TestConnection(array $params): array
{
    try {
        driveresource_require_gateway();
        return ['success' => true, 'error' => ''];
    } catch (Throwable $exception) {
        return ['success' => false, 'error' => $exception->getMessage()];
    }
}

/**
 * Usage Billing metric provider.
 *
 * @param array $params WHMCS module parameters.
 * @return MetricsProvider
 */
function driveresource_MetricProvider(array $params): MetricsProvider
{
    return new MetricsProvider($params);
}

/**
 * Client-area summary without exposing the service token.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_ClientArea(array $params): string
{
    try {
        driveresource_require_gateway();
        $service = Capsule::table('mod_driveresource_services')
            ->where('service_id', (int) $params['serviceid'])
            ->first();

        if (!$service) {
            return '<p>Drive Resource service is not provisioned.</p>';
        }

        $used = number_format(((int) $service->used_bytes) / 1000000000, 2);
        $quota = number_format(((int) $service->quota_bytes) / 1000000000, 2);

        return '<div class="alert alert-info">'
            . '<strong>' . htmlspecialchars(
                driveresource_backend_label((string) ($service->backend_key ?? 'elearningstream')),
                ENT_QUOTES,
                'UTF-8'
            ) . '</strong><br>'
            . 'Storage: ' . htmlspecialchars($used, ENT_QUOTES, 'UTF-8')
            . ' GB / ' . htmlspecialchars($quota, ENT_QUOTES, 'UTF-8') . ' GB included.<br>'
            . 'Status: ' . htmlspecialchars((string) $service->status, ENT_QUOTES, 'UTF-8') . '<br>'
            . 'Backend: ' . htmlspecialchars(
                driveresource_backend_label((string) ($service->backend_key ?? 'elearningstream')),
                ENT_QUOTES,
                'UTF-8'
            )
            . '</div>';
    } catch (Throwable $exception) {
        return '<div class="alert alert-danger">Drive Resource gateway is unavailable.</div>';
    }
}

/**
 * Set service status.
 *
 * @param int $serviceId WHMCS service id.
 * @param string $status New status.
 * @return string
 */
function driveresource_set_status(int $serviceId, string $status): string
{
    try {
        driveresource_require_gateway();
        $now = time();
        $values = [
            'status' => $status,
            'updated_at' => $now,
        ];
        if ($status === 'suspended') {
            $values['suspended_at'] = $now;
        } else if ($status === 'active') {
            $values['suspended_at'] = null;
            $values['terminated_at'] = null;
        }

        Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->update($values);

        return 'success';
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

/**
 * Ensure the addon schema is available.
 *
 * @return void
 */
function driveresource_require_gateway(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_driveresource_services')) {
        throw new RuntimeException('Activate the Drive Resource Media Gateway addon before provisioning services.');
    }

    if (
        !$schema->hasColumn('mod_driveresource_services', 'backend_key')
        || !$schema->hasColumn('mod_driveresource_services', 'backend_profile')
    ) {
        throw new RuntimeException(
            'Drive Resource Media Gateway 0.3.0 schema upgrade is required before provisioning services.'
        );
    }
}

/**
 * Resolve the product backend while preserving legacy products.
 *
 * @param array $params Module parameters.
 * @return string
 */
function driveresource_backend_key(array $params): string
{
    $key = strtolower(trim((string) ($params['configoption4'] ?? '')));
    if ($key === '') {
        $key = 'elearningstream';
    }

    $supported = driveresource_BackendLoader($params);
    if (!array_key_exists($key, $supported)) {
        throw new RuntimeException(
            'Storage backend "' . $key . '" is not provisionable by this module version.'
        );
    }

    return $key;
}

/**
 * Resolve a backend profile identifier.
 *
 * The profile is generic by design. Today "default" maps to the global
 * Elearning Stream addon credentials. Future S3 releases can map profiles to
 * independently managed endpoint/bucket credentials without changing service
 * identity.
 *
 * @param array $params Module parameters.
 * @return string
 */
function driveresource_backend_profile(array $params): string
{
    $profile = strtolower(trim((string) ($params['configoption5'] ?? 'default')));
    if ($profile === '') {
        $profile = 'default';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $profile)) {
        throw new RuntimeException('Backend profile contains unsupported characters.');
    }

    return $profile;
}

/**
 * Human-readable backend name.
 *
 * @param string $key Backend key.
 * @return string
 */
function driveresource_backend_label(string $key): string
{
    return $key === 'elearningstream' ? 'Elearning Stream' : $key;
}

/**
 * Resolve the exact Moodle site URL bound to the service.
 *
 * Create a WHMCS product custom field named "Moodle Site URL" for installations
 * that use a subdirectory. Otherwise the standard service domain is used.
 *
 * @param array $params Module parameters.
 * @return string
 */
function driveresource_site_url(array $params): string
{
    $raw = trim((string) (($params['customfields']['Moodle Site URL'] ?? '') ?: ($params['domain'] ?? '')));
    if ($raw === '') {
        throw new RuntimeException('Moodle Site URL is required.');
    }
    if (!preg_match('#^https?://#i', $raw)) {
        $raw = 'https://' . $raw;
    }

    $parts = parse_url($raw);
    if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        throw new RuntimeException('Moodle Site URL must be a valid HTTPS URL.');
    }
    if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
        throw new RuntimeException('Moodle Site URL must not contain credentials, query parameters or fragments.');
    }

    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = rtrim((string) ($parts['path'] ?? ''), '/');

    return 'https://' . strtolower((string) $parts['host']) . $port . $path;
}

/**
 * Included quota in bytes.
 *
 * @param array $params Module parameters.
 * @return int
 */
function driveresource_quota_bytes(array $params): int
{
    $gb = (float) ($params['configoption1'] ?? 7);
    $gb = max(0.1, min(100000, $gb));
    return (int) round($gb * 1000000000);
}

/**
 * Whether the service may exceed included storage.
 *
 * @param array $params Module parameters.
 * @return bool
 */
function driveresource_overage_allowed(array $params): bool
{
    return strtolower((string) ($params['configoption2'] ?? 'on')) === 'on';
}

/**
 * Retention period for orphaned assets.
 *
 * @param array $params Module parameters.
 * @return int
 */
function driveresource_retention_days(array $params): int
{
    return max(0, min(365, (int) ($params['configoption3'] ?? 30)));
}
