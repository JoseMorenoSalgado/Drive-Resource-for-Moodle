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
use WHMCS\Module\Server\Driveresource\ClientPortal;
use WHMCS\Module\Server\Driveresource\MetricsProvider;
use WHMCS\Module\Server\Driveresource\MoodleConnectionProbe;

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
        'RequiresServer' => false,
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
    return driveresource_provision_moodle_connection($params, false);
}

/**
 * Admin-only recovery action for an already-created WHMCS service.
 *
 * WHMCS can have a service marked Active before a provisioning command has
 * ever run (for example after manual product assignment). This action makes
 * the Moodle credential lifecycle explicit and recoverable.
 *
 * @return array<string,string>
 */
function driveresource_AdminCustomButtonArray(): array
{
    return [
        'Generar/Reparar conexión Moodle' => 'ProvisionMoodleConnection',
        'Rotar token Moodle' => 'RotateMoodleToken',
        'Validar conexión Moodle' => 'ValidateMoodleConnection',
    ];
}

/**
 * Permit self-service functions invoked by the custom client dashboard.
 *
 * @return string[]
 */
function driveresource_ClientAreaAllowedFunctions(): array
{
    return [
        'ProvisionMoodleConnection',
        'RotateMoodleToken',
        'UpdateMoodleUrl',
        'ValidateMoodleConnection',
        'DeleteVideo',
    ];
}

/**
 * Change the Moodle site bound to this customer's service.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_UpdateMoodleUrl(array $params): string
{
    try {
        driveresource_require_post();
        driveresource_require_gateway();

        $serviceId = (int) ($params['serviceid'] ?? 0);
        $url = driveresource_normalize_site_url((string) ($_POST['moodleurl'] ?? ''));
        $now = time();

        Capsule::connection()->transaction(function () use ($serviceId, $url, $now): void {
            $service = Capsule::table('mod_driveresource_services')
                ->where('service_id', $serviceId)
                ->lockForUpdate()
                ->first();
            if (!$service) {
                throw new RuntimeException('Drive Resource service is not provisioned.');
            }

            Capsule::table('mod_driveresource_services')
                ->where('service_id', $serviceId)
                ->update([
                    'site_url' => $url,
                    'site_hash' => hash('sha256', $url),
                    'connection_status' => 'pending',
                    'connection_checked_at' => null,
                    'connection_message' => 'URL actualizada. Valida la conexión después de configurar Moodle.',
                    'updated_at' => $now,
                ]);

            Capsule::table('mod_driveresource_asset_refs')
                ->where('service_id', $serviceId)
                ->update([
                    'site_url' => $url,
                    'site_hash' => hash('sha256', $url),
                    'updated_at' => $now,
                ]);
        });

        if (isset($params['model'])) {
            $params['model']->serviceProperties->save([
                'Moodle Site URL' => $url,
            ]);
        }

        return 'success';
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

/**
 * Test that Moodle has the same site URL, Service ID and token.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_ValidateMoodleConnection(array $params): string
{
    try {
        driveresource_require_post();
        driveresource_require_gateway();

        $serviceId = (int) ($params['serviceid'] ?? 0);
        $service = Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->first();
        if (!$service) {
            throw new RuntimeException('Drive Resource service is not provisioned.');
        }

        $token = driveresource_service_token($params);
        $result = (new MoodleConnectionProbe())->probe(
            $serviceId,
            (string) $service->site_url,
            $token
        );

        Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->update([
                'connection_status' => $result['connected'] ? 'connected' : 'failed',
                'connection_checked_at' => time(),
                'connection_message' => mb_substr((string) $result['message'], 0, 255),
                'updated_at' => time(),
            ]);

        return $result['connected'] ? 'success' : (string) $result['message'];
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

/**
 * Permanently delete one unreferenced video owned by this service.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_DeleteVideo(array $params): string
{
    try {
        driveresource_require_post();
        driveresource_require_gateway();

        $serviceId = (int) ($params['serviceid'] ?? 0);
        $uploadId = strtolower(trim((string) ($_POST['uploadid'] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw new RuntimeException('Invalid video identifier.');
        }

        $service = Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->first();
        if (!$service || (string) ($service->backend_key ?? '') !== 'elearningstream') {
            throw new RuntimeException('This service does not use Elearning Stream.');
        }

        $upload = Capsule::table('mod_driveresource_uploads')
            ->where('service_id', $serviceId)
            ->where('upload_id', $uploadId)
            ->where('status', '<>', 'deleted')
            ->first();
        if (!$upload || empty($upload->video_id)) {
            throw new RuntimeException('Video not found.');
        }

        $references = (int) Capsule::table('mod_driveresource_asset_refs')
            ->where('service_id', $serviceId)
            ->where('video_id', (string) $upload->video_id)
            ->where('active', true)
            ->count();
        if ($references > 0) {
            throw new RuntimeException(
                'Este video todavía está vinculado a una actividad Moodle y no puede eliminarse.'
            );
        }

        driveresource_stream_client()->deleteVideo((string) $upload->video_id);

        Capsule::connection()->transaction(function () use ($serviceId, $uploadId): void {
            Capsule::table('mod_driveresource_uploads')
                ->where('service_id', $serviceId)
                ->where('upload_id', $uploadId)
                ->update([
                    'status' => 'deleted',
                    'accounted_bytes' => 0,
                    'delete_after' => null,
                    'updated_at' => time(),
                ]);

            $used = (int) Capsule::table('mod_driveresource_uploads')
                ->where('service_id', $serviceId)
                ->whereIn('status', ['processing', 'ready', 'bound'])
                ->sum('accounted_bytes');

            Capsule::table('mod_driveresource_services')
                ->where('service_id', $serviceId)
                ->update([
                    'used_bytes' => max(0, $used),
                    'updated_at' => time(),
                ]);
        });

        return 'success';
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

/**
 * Provision or repair the Moodle gateway identity for this WHMCS service.
 *
 * Existing tokens are preserved when WHMCS still has the plaintext service
 * password. If the password is missing, a new token is generated and its hash
 * is atomically replaced in the gateway tenant row.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_ProvisionMoodleConnection(array $params): string
{
    return driveresource_provision_moodle_connection($params, false);
}

/**
 * Explicitly rotate the Moodle service token.
 *
 * Use only when the old token is believed compromised or the administrator
 * intentionally wants to reconnect Moodle.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_RotateMoodleToken(array $params): string
{
    return driveresource_provision_moodle_connection($params, true);
}

/**
 * Shared provisioning implementation.
 *
 * @param array $params WHMCS module parameters.
 * @param bool $forcerotation Whether to replace an existing valid token.
 * @return string
 */
function driveresource_provision_moodle_connection(array $params, bool $forcerotation): string
{
    try {
        driveresource_require_gateway();

        $serviceId = (int) ($params['serviceid'] ?? 0);
        if ($serviceId <= 0) {
            throw new RuntimeException('WHMCS service ID is missing.');
        }

        $siteUrl = driveresource_site_url($params);
        $now = time();
        $existing = Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->first();

        $token = trim((string) ($params['password'] ?? ''));
        if ($token === '' && isset($params['model'])) {
            try {
                $token = trim((string) $params['model']->serviceProperties->get('Password'));
            } catch (Throwable $exception) {
                $token = '';
            }
        }

        $tokenisusable = strlen($token) >= 32;
        if ($forcerotation || !$tokenisusable) {
            $token = bin2hex(random_bytes(32));
        }

        $quotaBytes = driveresource_quota_bytes($params);
        $overageAllowed = driveresource_overage_allowed($params);
        $retentionDays = driveresource_retention_days($params);
        $backendKey = driveresource_backend_key($params);
        $backendProfile = driveresource_backend_profile($params);

        $values = [
            'site_url' => $siteUrl,
            'site_hash' => hash('sha256', $siteUrl),
            'token_hash' => hash('sha256', $token),
            'status' => 'active',
            'backend_key' => $backendKey,
            'backend_profile' => $backendProfile,
            'connection_status' => 'pending',
            'connection_checked_at' => null,
            'connection_message' => 'Pendiente de validación desde WHMCS.',
            'quota_bytes' => $quotaBytes,
            'overage_allowed' => $overageAllowed,
            'retention_days' => $retentionDays,
            'updated_at' => $now,
            'suspended_at' => null,
            'terminated_at' => null,
        ];

        if (!$existing) {
            $values['created_at'] = $now;
            $values['used_bytes'] = 0;
            $values['reserved_bytes'] = 0;
        }

        Capsule::table('mod_driveresource_services')->updateOrInsert(
            ['service_id' => $serviceId],
            $values
        );

        if (!isset($params['model'])) {
            throw new RuntimeException('WHMCS service model is unavailable.');
        }

        // WHMCS Service Properties maps these names to protected core service
        // fields for directly-created products.
        $params['model']->serviceProperties->save([
            'Username' => 'dr-' . $serviceId,
            'Password' => $token,
        ]);

        logModuleCall(
            'driveresource',
            $forcerotation ? 'RotateMoodleToken' : 'ProvisionMoodleConnection',
            [
                'serviceid' => $serviceId,
                'siteurl' => $siteUrl,
                'backend' => $backendKey,
            ],
            [
                'status' => 'success',
                'username' => 'dr-' . $serviceId,
                'tokenrotated' => $forcerotation || !$tokenisusable,
            ],
            null,
            ['Password', 'password', 'token']
        );

        return 'success';
    } catch (Throwable $exception) {
        logModuleCall(
            'driveresource',
            $forcerotation ? 'RotateMoodleToken' : 'ProvisionMoodleConnection',
            ['serviceid' => $params['serviceid'] ?? 0],
            ['error' => $exception->getMessage()],
            null,
            ['Password', 'password', 'token']
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
 * Show Moodle connection details on the WHMCS administrator service page.
 *
 * The service password is the service-scoped Moodle gateway token. WHMCS
 * stores it in its protected service property store; this callback surfaces it
 * only to authorised WHMCS administrators working on the service.
 *
 * @param array $params WHMCS module parameters.
 * @return array<string,string>
 */
function driveresource_AdminServicesTabFields(array $params): array
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $gatewayUrl = driveresource_gateway_url_hint($params);
    $token = trim((string) ($params['password'] ?? ''));

    if ($token === '' && isset($params['model'])) {
        try {
            $token = trim((string) $params['model']->serviceProperties->get('Password'));
        } catch (Throwable $exception) {
            $token = '';
        }
    }

    $provisioned = Capsule::table('mod_driveresource_services')
        ->where('service_id', $serviceId)
        ->exists();

    return [
        'Estado conexión Moodle' => $provisioned && strlen($token) >= 32
            ? '<span class="label label-success">Provisionada</span>'
            : '<span class="label label-warning">Pendiente</span>',
        'Moodle Gateway URL' => htmlspecialchars($gatewayUrl, ENT_QUOTES, 'UTF-8'),
        'Moodle Service ID' => (string) $serviceId,
        'Moodle Service Token' => $token !== ''
            ? htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
            : 'Pendiente — use Generar/Reparar conexión Moodle.',
    ];
}

/**
 * Build the addon URL hint from the current WHMCS installation.
 *
 * This is display-only. Moodle administrators should still verify the public
 * WHMCS base URL if WHMCS runs behind a proxy or custom admin topology.
 *
 * @param array $params WHMCS module parameters.
 * @return string
 */
function driveresource_gateway_url_hint(array $params): string
{
    $systemUrl = rtrim((string) ($params['systemurl'] ?? ''), '/');
    if ($systemUrl === '') {
        return '/modules/addons/driveresource_gateway';
    }

    return $systemUrl . '/modules/addons/driveresource_gateway';
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
        return (new ClientPortal($params))->render();
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

    $required = [
        'backend_key',
        'backend_profile',
        'connection_status',
        'connection_checked_at',
        'transfer_period',
        'transfer_bytes',
    ];
    foreach ($required as $column) {
        if (!$schema->hasColumn('mod_driveresource_services', $column)) {
            throw new RuntimeException(
                'Drive Resource Media Gateway 0.4.0 schema upgrade is required before using this service.'
            );
        }
    }
    if (!$schema->hasTable('mod_driveresource_usage_reports')) {
        throw new RuntimeException(
            'Drive Resource Media Gateway 0.4.0 usage schema is missing.'
        );
    }
}

/**
 * Require client self-service actions to use POST.
 *
 * @return void
 */
function driveresource_require_post(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        throw new RuntimeException('This action requires POST.');
    }
}

/**
 * Retrieve the current plaintext service token from WHMCS protected fields.
 *
 * @param array $params Module parameters.
 * @return string
 */
function driveresource_service_token(array $params): string
{
    $token = trim((string) ($params['password'] ?? ''));
    if ($token !== '' || !isset($params['model'])) {
        return $token;
    }

    try {
        return trim((string) $params['model']->serviceProperties->get('Password'));
    } catch (Throwable $exception) {
        return '';
    }
}

/**
 * Resolve the Elearning Stream management client from the companion addon.
 *
 * @return \WHMCS\Module\Addon\DriveresourceGateway\BunnyClient
 */
function driveresource_stream_client()
{
    $lib = dirname(__DIR__, 2) . '/addons/driveresource_gateway/lib';
    require_once $lib . '/Config.php';
    require_once $lib . '/BunnyClient.php';

    return new \WHMCS\Module\Addon\DriveresourceGateway\BunnyClient();
}

/**
 * Normalize one Moodle wwwroot URL.
 *
 * @param string $raw Candidate URL.
 * @return string
 */
function driveresource_normalize_site_url(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        throw new RuntimeException('Moodle Site URL is required.');
    }
    if (!preg_match('#^https?://#i', $raw)) {
        $raw = 'https://' . $raw;
    }

    $parts = parse_url($raw);
    if (
        !$parts
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
    ) {
        throw new RuntimeException('Moodle Site URL must be a valid HTTPS URL.');
    }
    if (
        !empty($parts['user'])
        || !empty($parts['pass'])
        || !empty($parts['query'])
        || !empty($parts['fragment'])
    ) {
        throw new RuntimeException(
            'Moodle Site URL must not contain credentials, query parameters or fragments.'
        );
    }

    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = rtrim((string) ($parts['path'] ?? ''), '/');

    return 'https://' . strtolower((string) $parts['host']) . $port . $path;
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
    $serviceId = (int) ($params['serviceid'] ?? 0);
    if ($serviceId > 0 && Capsule::schema()->hasTable('mod_driveresource_services')) {
        $existing = Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->value('site_url');
        if (is_string($existing) && trim($existing) !== '') {
            return driveresource_normalize_site_url($existing);
        }
    }

    $raw = (string) (($params['customfields']['Moodle Site URL'] ?? '') ?: ($params['domain'] ?? ''));
    return driveresource_normalize_site_url($raw);
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
