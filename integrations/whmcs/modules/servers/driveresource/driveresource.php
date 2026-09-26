<?php
/**
 * Elearning Stream provisioning module.
 *
 * @copyright  2026 Elearning Cloud
 * @license    Proprietary companion module distributed with Elearning Stream
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Translator.php';
require_once __DIR__ . '/lib/ClientPortal.php';
require_once __DIR__ . '/lib/MetricsProvider.php';
require_once __DIR__ . '/lib/MoodleConnectionProbe.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Setting;
use WHMCS\Module\Server\Driveresource\ClientPortal;
use WHMCS\Module\Server\Driveresource\MetricsProvider;
use WHMCS\Module\Server\Driveresource\MoodleConnectionProbe;
use WHMCS\Module\Server\Driveresource\Translator;

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
            'Default' => '0',
            'Description' => '0 deletes the provider video after its final Moodle reference is removed. Use 1-365 only when a recovery grace period is required.',
        ],
        'Video Provider' => [
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'elearningstream',
            'Loader' => 'driveresource_VideoProviderLoader',
            'SimpleMode' => true,
            'Description' => 'Managed-video provider assigned to services created from this product.',
        ],
        'Video Provider Profile' => [
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'default',
            'Description' => 'Credential/profile selector for the video provider.',
        ],
        'Protected PDF Storage' => [
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'none',
            'Loader' => 'driveresource_DocumentProviderLoader',
            'SimpleMode' => true,
            'Description' => 'Independent object-storage provider for protected PDFs. Video-only plans can keep this disabled.',
        ],
        'Object Storage Profile' => [
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'default',
            'Description' => 'Credential/profile selector for S3-compatible protected document storage.',
        ],
    ];
}

/**
 * Populate the managed-video provider selector.
 *
 * @param array $params WHMCS module parameters.
 * @return array<string,string>
 */
function driveresource_VideoProviderLoader(array $params): array
{
    return [
        'elearningstream' => 'Elearning Stream',
    ];
}

/**
 * Populate the protected-document provider selector.
 *
 * The S3 assignment is stored independently from video so the data-plane
 * adapter can be enabled without migrating the Moodle service identity.
 *
 * @param array $params WHMCS module parameters.
 * @return array<string,string>
 */
function driveresource_DocumentProviderLoader(array $params): array
{
    return [
        'none' => 'Disabled (video only)',
        's3compatible' => 'S3-compatible Object Storage',
    ];
}

/**
 * Backward-compatible alias retained for existing WHMCS product metadata.
 *
 * @param array $params WHMCS module parameters.
 * @return array<string,string>
 */
function driveresource_BackendLoader(array $params): array
{
    return driveresource_VideoProviderLoader($params);
}

/**
 * Provision an Elearning Stream tenant.
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
    $translator = Translator::fromParams([]);

    return [
        $translator->t('generate_token') => 'ProvisionMoodleConnection',
        $translator->t('generate_new_key') => 'RotateMoodleToken',
        $translator->t('validate_connection') => 'ValidateMoodleConnection',
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
        $previousUrl = '';

        Capsule::connection()->transaction(function () use (
            $serviceId,
            $url,
            $now,
            $params,
            &$previousUrl
        ): void {
            $service = Capsule::table('mod_driveresource_services')
                ->where('service_id', $serviceId)
                ->lockForUpdate()
                ->first();
            if (!$service) {
                throw new RuntimeException('Elearning Stream service is not provisioned.');
            }

            $currentUrl = rtrim((string) $service->site_url, '/');
            $previousUrl = $currentUrl;
            if ($currentUrl !== rtrim($url, '/')) {
                $activeRefs = (int) Capsule::table('mod_driveresource_asset_refs')
                    ->where('service_id', $serviceId)
                    ->where('active', true)
                    ->count();
                if ($activeRefs > 0) {
                    throw new RuntimeException(
                        Translator::fromParams($params)->t('url_locked_by_refs')
                    );
                }
            }

            Capsule::table('mod_driveresource_services')
                ->where('service_id', $serviceId)
                ->update([
                    'site_url' => $url,
                    'site_hash' => hash('sha256', $url),
                    'connection_status' => 'pending',
                    'connection_checked_at' => null,
                    'connection_message' => 'connection_pending',
                    'updated_at' => $now,
                ]);
        });

        if (isset($params['model'])) {
            $params['model']->serviceProperties->save([
                'Moodle Site URL' => $url,
            ]);
        }

        if ($previousUrl !== rtrim($url, '/')) {
            driveresource_audit($serviceId, 'moodle_url_changed', [
                'old_url' => $previousUrl,
                'new_url' => rtrim($url, '/'),
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
            throw new RuntimeException('Elearning Stream service is not provisioned.');
        }

        $token = driveresource_service_token($params);
        $translator = Translator::fromParams($params);
        $result = (new MoodleConnectionProbe($translator))->probe(
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

        driveresource_audit($serviceId, 'moodle_connection_validated', [
            'connected' => (bool) $result['connected'],
            'site_url' => (string) $service->site_url,
            'plugin_version' => (int) ($result['pluginversion'] ?? 0),
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
            throw new RuntimeException(Translator::fromParams($params)->t('video_invalid'));
        }

        $service = Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->first();
        if (!$service) {
            throw new RuntimeException(Translator::fromParams($params)->t('video_not_found'));
        }

        $videoBackend = (string) ($service->video_backend_key ?? $service->backend_key ?? '');
        if ($videoBackend !== 'elearningstream') {
            throw new RuntimeException(Translator::fromParams($params)->t('backend_mismatch'));
        }

        $upload = Capsule::table('mod_driveresource_uploads')
            ->where('service_id', $serviceId)
            ->where('upload_id', $uploadId)
            ->where('status', '<>', 'deleted')
            ->first();
        if (!$upload || empty($upload->video_id)) {
            throw new RuntimeException(Translator::fromParams($params)->t('video_not_found'));
        }

        $references = (int) Capsule::table('mod_driveresource_asset_refs')
            ->where('service_id', $serviceId)
            ->where('video_id', (string) $upload->video_id)
            ->where('active', true)
            ->count();
        if ($references > 0) {
            throw new RuntimeException(
                Translator::fromParams($params)->t('video_still_referenced')
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

        driveresource_audit($serviceId, 'video_deleted', [
            'video_id' => (string) $upload->video_id,
            'upload_id' => $uploadId,
            'filename' => (string) $upload->filename,
            'bytes' => (int) $upload->accounted_bytes,
        ]);

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

        $tokenisusable = (bool) preg_match('/^[a-f0-9]{64}$/', $token);
        if ($forcerotation || !$tokenisusable) {
            $token = bin2hex(random_bytes(32));
        }

        $quotaBytes = driveresource_quota_bytes($params);
        $overageAllowed = driveresource_overage_allowed($params);
        $retentionDays = driveresource_retention_days($params);
        $videoBackendKey = driveresource_video_backend_key($params);
        $videoBackendProfile = driveresource_video_backend_profile($params);
        $objectBackendKey = driveresource_object_backend_key($params);
        $objectBackendProfile = driveresource_object_backend_profile($params);

        $values = [
            'site_url' => $siteUrl,
            'site_hash' => hash('sha256', $siteUrl),
            'token_hash' => hash('sha256', $token),
            'status' => 'active',
            // Legacy backend fields mirror the video provider for API compatibility.
            'backend_key' => $videoBackendKey,
            'backend_profile' => $videoBackendProfile,
            'video_backend_key' => $videoBackendKey,
            'video_backend_profile' => $videoBackendProfile,
            'object_backend_key' => $objectBackendKey,
            'object_backend_profile' => $objectBackendProfile,
            'connection_status' => 'pending',
            'connection_checked_at' => null,
            'connection_message' => 'connection_pending',
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
                'video_provider' => $videoBackendKey,
                'object_provider' => $objectBackendKey,
            ],
            [
                'status' => 'success',
                'username' => 'dr-' . $serviceId,
                'tokenrotated' => $forcerotation || !$tokenisusable,
            ],
            null,
            ['Password', 'password', 'token']
        );

        driveresource_audit(
            $serviceId,
            $forcerotation ? 'moodle_token_rotated' : 'moodle_connection_provisioned',
            [
                'site_url' => $siteUrl,
                'video_provider' => $videoBackendKey,
                'object_provider' => $objectBackendKey,
                'token_rotated' => (bool) ($forcerotation || !$tokenisusable),
            ]
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
            throw new RuntimeException('Elearning Stream service is not provisioned.');
        }

        $videoBackendKey = driveresource_video_backend_key($params);
        $videoBackendProfile = driveresource_video_backend_profile($params);
        $objectBackendKey = driveresource_object_backend_key($params);
        $objectBackendProfile = driveresource_object_backend_profile($params);
        $currentBackend = strtolower(trim((string) (
            $service->video_backend_key
            ?? $service->backend_key
            ?? 'elearningstream'
        )));

        if ($currentBackend !== $videoBackendKey) {
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
                'backend_key' => $videoBackendKey,
                'backend_profile' => $videoBackendProfile,
                'video_backend_key' => $videoBackendKey,
                'video_backend_profile' => $videoBackendProfile,
                'object_backend_key' => $objectBackendKey,
                'object_backend_profile' => $objectBackendProfile,
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
        $token = strtolower(trim((string) ($params['password'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new RuntimeException(
                'Gateway token must be a 64-character hexadecimal Drive Resource token.'
            );
        }

        $serviceId = (int) $params['serviceid'];
        Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->update([
                'token_hash' => hash('sha256', $token),
                'connection_status' => 'pending',
                'connection_checked_at' => null,
                'connection_message' => 'connection_pending',
                'updated_at' => time(),
            ]);

        driveresource_audit($serviceId, 'moodle_token_rotated', [
            'source' => 'change_password',
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
    $translator = Translator::fromParams($params);
    $gatewayUrl = driveresource_gateway_url_hint($params);
    $token = driveresource_service_token($params);
    $service = Capsule::table('mod_driveresource_services')
        ->where('service_id', $serviceId)
        ->first();

    $connection = $service ? strtolower((string) ($service->connection_status ?? 'pending')) : 'pending';
    $connectionLabel = $connection === 'connected'
        ? '<span class="label label-success">'
            . htmlspecialchars($translator->t('connection_connected'), ENT_QUOTES, 'UTF-8') . '</span>'
        : ($connection === 'failed'
            ? '<span class="label label-danger">'
                . htmlspecialchars($translator->t('connection_failed'), ENT_QUOTES, 'UTF-8') . '</span>'
            : '<span class="label label-warning">'
                . htmlspecialchars($translator->t('connection_pending'), ENT_QUOTES, 'UTF-8') . '</span>');

    $storage = '—';
    $transfer = '—';
    if ($service) {
        $storage = driveresource_format_bytes((int) $service->used_bytes)
            . ' / ' . driveresource_format_bytes((int) $service->quota_bytes);
        $transferBytes = (string) ($service->transfer_period ?? '') === gmdate('Y-m')
            ? (int) ($service->transfer_bytes ?? 0)
            : 0;
        $transfer = driveresource_format_bytes($transferBytes) . ' (' . gmdate('Y-m') . ')';
    }

    return [
        $translator->t('card_connection') => $connectionLabel,
        $translator->t('gateway_url') => htmlspecialchars($gatewayUrl, ENT_QUOTES, 'UTF-8'),
        $translator->t('service_id') => (string) $serviceId,
        $translator->t('service_token') => $token !== ''
            ? htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
            : htmlspecialchars($translator->t('token_missing'), ENT_QUOTES, 'UTF-8'),
        $translator->t('card_storage') => htmlspecialchars($storage, ENT_QUOTES, 'UTF-8'),
        $translator->t('monthly_transfer') => htmlspecialchars($transfer, ENT_QUOTES, 'UTF-8'),
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
    $configured = trim((string) Setting::getSettingValueForModule(
        'driveresource_gateway',
        'public_gateway_url'
    ));

    if ($configured !== '') {
        $parts = parse_url($configured);
        if (
            is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && empty($parts['query'])
            && empty($parts['fragment'])
        ) {
            return rtrim($configured, '/');
        }
    }

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
        return '<div class="alert alert-danger">Elearning Stream Gateway is unavailable.</div>';
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
        throw new RuntimeException('Activate the Elearning Stream Gateway addon before provisioning services.');
    }

    $required = [
        'backend_key',
        'backend_profile',
        'video_backend_key',
        'video_backend_profile',
        'object_backend_key',
        'object_backend_profile',
        'connection_status',
        'connection_checked_at',
        'transfer_period',
        'transfer_bytes',
    ];
    foreach ($required as $column) {
        if (!$schema->hasColumn('mod_driveresource_services', $column)) {
            throw new RuntimeException(
                'Elearning Stream Gateway 0.5.0 schema upgrade is required before using this service.'
            );
        }
    }
    if (!$schema->hasTable('mod_driveresource_usage_reports')) {
        throw new RuntimeException(
            'Elearning Stream Gateway 0.5.0 usage schema is missing.'
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
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        return $token;
    }

    if (!isset($params['model'])) {
        return '';
    }

    try {
        $token = trim((string) $params['model']->serviceProperties->get('Password'));
        return preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
    } catch (Throwable $exception) {
        return '';
    }
}

/**
 * Write one redacted Drive Resource audit event.
 *
 * @param int $serviceId WHMCS service id.
 * @param string $action Stable action key.
 * @param array $metadata Non-secret metadata.
 * @return void
 */
function driveresource_audit(int $serviceId, string $action, array $metadata = []): void
{
    $lib = dirname(__DIR__, 2) . '/addons/driveresource_gateway/lib';
    require_once $lib . '/AuditLogger.php';

    \WHMCS\Module\Addon\DriveresourceGateway\AuditLogger::log(
        $serviceId,
        $action,
        $metadata
    );
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

    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        throw new RuntimeException('Moodle Site URL must use the standard HTTPS port 443.');
    }

    $port = isset($parts['port']) ? ':443' : '';
    $path = rtrim((string) ($parts['path'] ?? ''), '/');

    return 'https://' . strtolower((string) $parts['host']) . $port . $path;
}

/**
 * Resolve the product video provider while preserving legacy products.
 *
 * @param array $params Module parameters.
 * @return string
 */
function driveresource_video_backend_key(array $params): string
{
    $key = strtolower(trim((string) ($params['configoption4'] ?? 'elearningstream')));
    if ($key === '') {
        $key = 'elearningstream';
    }

    $supported = driveresource_VideoProviderLoader($params);
    if (!array_key_exists($key, $supported)) {
        throw new RuntimeException(
            'Video provider "' . $key . '" is not provisionable by this module version.'
        );
    }

    return $key;
}

/**
 * Resolve the video provider profile.
 *
 * @param array $params Module parameters.
 * @return string
 */
function driveresource_video_backend_profile(array $params): string
{
    return driveresource_normalize_profile((string) ($params['configoption5'] ?? 'default'));
}

/**
 * Resolve the protected-document object-storage provider.
 *
 * @param array $params Module parameters.
 * @return string
 */
function driveresource_object_backend_key(array $params): string
{
    $key = strtolower(trim((string) ($params['configoption6'] ?? 'none')));
    if ($key === '') {
        $key = 'none';
    }

    $supported = driveresource_DocumentProviderLoader($params);
    if (!array_key_exists($key, $supported)) {
        throw new RuntimeException(
            'Object-storage provider "' . $key . '" is not assignable by this module version.'
        );
    }

    return $key;
}

/**
 * Resolve the object-storage profile.
 *
 * @param array $params Module parameters.
 * @return string
 */
function driveresource_object_backend_profile(array $params): string
{
    return driveresource_normalize_profile((string) ($params['configoption7'] ?? 'default'));
}

/**
 * Normalize one provider profile identifier.
 *
 * @param string $profile Profile.
 * @return string
 */
function driveresource_normalize_profile(string $profile): string
{
    $profile = strtolower(trim($profile));
    if ($profile === '') {
        $profile = 'default';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $profile)) {
        throw new RuntimeException('Provider profile contains unsupported characters.');
    }

    return $profile;
}

/**
 * Backward-compatible video-backend aliases.
 *
 * @param array $params Module parameters.
 * @return string
 */
function driveresource_backend_key(array $params): string
{
    return driveresource_video_backend_key($params);
}

/**
 * @param array $params Module parameters.
 * @return string
 */
function driveresource_backend_profile(array $params): string
{
    return driveresource_video_backend_profile($params);
}

/**
 * Format decimal storage/transfer bytes.
 *
 * @param int $bytes Bytes.
 * @return string
 */
function driveresource_format_bytes(int $bytes): string
{
    $bytes = max(0, $bytes);
    if ($bytes >= 1000000000000) {
        return number_format($bytes / 1000000000000, 2) . ' TB';
    }
    if ($bytes >= 1000000000) {
        return number_format($bytes / 1000000000, 2) . ' GB';
    }
    if ($bytes >= 1000000) {
        return number_format($bytes / 1000000, 2) . ' MB';
    }

    return number_format($bytes / 1000, 2) . ' KB';
}

/**
 * Human-readable backend name.
 *
 * @param string $key Backend key.
 * @return string
 */
function driveresource_backend_label(string $key): string
{
    return match ($key) {
        'elearningstream' => 'Elearning Stream',
        's3compatible' => 'S3-compatible Object Storage',
        'none' => 'Disabled',
        default => $key,
    };
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
    return max(0, min(365, (int) ($params['configoption3'] ?? 0)));
}
