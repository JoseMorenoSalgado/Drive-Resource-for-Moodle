<?php
/**
 * Elearning Stream media gateway addon.
 *
 * @copyright  2026 Elearning Cloud
 * @license    Proprietary companion module distributed with Elearning Stream
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

/**
 * Addon metadata and provider-secret configuration.
 *
 * Bunny Stream secrets remain in this WHMCS addon. They are never returned to
 * Moodle. The gateway returns only short-lived, video-scoped TUS signatures.
 *
 * @return array
 */
function driveresource_gateway_config(): array
{
    return [
        'name' => 'Elearning Stream Gateway',
        'description' => 'Multi-tenant media gateway, quota control and provider credential boundary for Elearning Stream.',
        'version' => '0.6.0',
        'author' => 'Elearning Cloud',
        'fields' => [
            'public_gateway_url' => [
                'FriendlyName' => 'Public Gateway URL',
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'https://stream.elearningcloud.io',
                'Description' => 'Customer-facing HTTPS URL copied into Moodle. Configure this hostname as a reverse proxy to the addon.',
            ],
            'video_provider' => [
                'FriendlyName' => 'Video Provider',
                'Type' => 'dropdown',
                'Options' => [
                    'elearningstream' => 'Elearning Stream',
                ],
                'Default' => 'elearningstream',
                'Description' => 'Default managed-video provider. More providers can be added without changing Moodle credentials.',
            ],
            'bunny_library_id' => [
                'FriendlyName' => 'Elearning Stream Library ID',
                'Type' => 'text',
                'Size' => '30',
                'Description' => 'Video Library used by Elearning Stream.',
            ],
            'bunny_api_key' => [
                'FriendlyName' => 'Elearning Stream API Key',
                'Type' => 'password',
                'Size' => '45',
                'Description' => 'Server-side only. Never copied to Moodle.',
            ],
            'bunny_cdn_hostname' => [
                'FriendlyName' => 'Elearning Stream CDN Hostname',
                'Type' => 'text',
                'Size' => '45',
                'Description' => 'Provider CDN hostname used by Moodle protected playback. The Pull Zone must support byte ranges; enable Cache Slicing for uncached MP4 seeking. Example: vz-xxxxxxxx-xxx.b-cdn.net.',
            ],
            'bunny_public_aliases' => [
                'FriendlyName' => 'Elearning Stream Public Aliases',
                'Type' => 'text',
                'Size' => '70',
                'Default' => '',
                'Description' => 'Optional customer-facing hostnames accepted when pasting existing video URLs. Separate multiple hostnames with commas. Example: video.elearningcloud.io.',
            ],
            'bunny_token_key' => [
                'FriendlyName' => 'Elearning Stream Token Key',
                'Type' => 'password',
                'Size' => '45',
                'Description' => 'CDN and embed-view Token Authentication Key for the Bunny Video Library/Pull Zone. Do not use the Stream API key. Server-side only; never copied to Moodle.',
            ],
            'playback_ttl' => [
                'FriendlyName' => 'Elearning Stream Playback TTL',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '300',
                'Description' => 'Lifetime in seconds for short-lived server-side playback URLs (60–1800).',
            ],
            'tus_ttl' => [
                'FriendlyName' => 'Direct Upload TTL',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '21600',
                'Description' => 'Lifetime in seconds for a direct-upload signature (300–86400).',
            ],
            'clock_skew' => [
                'FriendlyName' => 'Gateway Clock Skew',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '300',
                'Description' => 'Maximum accepted Moodle request timestamp drift in seconds (60–900).',
            ],
            'unbound_grace_hours' => [
                'FriendlyName' => 'Unbound Upload Grace',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '24',
                'Description' => 'Hours to retain a completed upload that was never saved into a Moodle activity.',
            ],
            'free_storage_gb' => [
                'FriendlyName' => 'Free Storage GB',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '7',
                'Description' => 'Video storage included with every activated account before PAYG storage applies.',
            ],
            'free_transfer_gb' => [
                'FriendlyName' => 'Free Monthly Transfer GB',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '20',
                'Description' => 'Monthly video transfer included with every activated account before PAYG transfer applies.',
            ],
            'activation_credit_usd' => [
                'FriendlyName' => 'Activation Credit USD',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '1',
                'Description' => 'Credit granted once after WHMCS provisions the paid account activation.',
            ],
            'minimum_recharge_usd' => [
                'FriendlyName' => 'Minimum PAYG Recharge USD',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '10',
                'Description' => 'Minimum wallet recharge that upgrades a FREE account to PAYG.',
            ],
            'storage_rate_usd_per_gb' => [
                'FriendlyName' => 'PAYG Storage USD / GB-month',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '0.03',
                'Description' => 'Customer rate for storage above the free allowance.',
            ],
            'transfer_rate_usd_per_gb' => [
                'FriendlyName' => 'PAYG Transfer USD / GB',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '0.12',
                'Description' => 'Customer rate for transfer above the monthly free allowance.',
            ],
            'free_installation_limit' => [
                'FriendlyName' => 'FREE Moodle Installations',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '1',
                'Description' => 'Maximum active Moodle installations on FREE.',
            ],
            'paid_installation_limit' => [
                'FriendlyName' => 'PAYG Moodle Installations',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '0',
                'Description' => 'Maximum active Moodle installations on PAYG. Use 0 for unlimited.',
            ],
            'object_storage_provider' => [
                'FriendlyName' => 'Protected PDF / Object Storage Provider',
                'Type' => 'dropdown',
                'Options' => [
                    'disabled' => 'Disabled',
                    'aws_s3' => 'Amazon S3',
                    'cloudflare_r2' => 'Cloudflare R2',
                    'wasabi' => 'Wasabi',
                    'backblaze_b2' => 'Backblaze B2 (S3)',
                    'hetzner' => 'Hetzner Object Storage',
                    'custom_s3' => 'Custom S3-compatible',
                ],
                'Default' => 'disabled',
                'Description' => 'Provider profile reserved for protected PDF/object storage. Credentials stay only in this gateway.',
            ],
            'object_storage_endpoint' => [
                'FriendlyName' => 'S3 Endpoint',
                'Type' => 'text',
                'Size' => '60',
                'Default' => '',
                'Description' => 'HTTPS S3-compatible endpoint, for example https://s3.eu-central-1.amazonaws.com or your R2/Wasabi endpoint.',
            ],
            'object_storage_region' => [
                'FriendlyName' => 'S3 Region',
                'Type' => 'text',
                'Size' => '25',
                'Default' => 'auto',
                'Description' => 'Provider region. Cloudflare R2 commonly uses auto.',
            ],
            'object_storage_bucket' => [
                'FriendlyName' => 'S3 Bucket',
                'Type' => 'text',
                'Size' => '40',
                'Default' => '',
                'Description' => 'Bucket dedicated to protected documents.',
            ],
            'object_storage_access_key' => [
                'FriendlyName' => 'S3 Access Key',
                'Type' => 'password',
                'Size' => '45',
                'Description' => 'Server-side only. Never copied to Moodle.',
            ],
            'object_storage_secret_key' => [
                'FriendlyName' => 'S3 Secret Key',
                'Type' => 'password',
                'Size' => '45',
                'Description' => 'Server-side only. Never copied to Moodle.',
            ],
            'object_storage_path_style' => [
                'FriendlyName' => 'S3 Path Style',
                'Type' => 'yesno',
                'Description' => 'Enable for S3-compatible providers that require bucket names in the URL path.',
                'Default' => '',
            ],
        ],
    ];
}

/**
 * Create gateway persistence tables.
 *
 * @return array
 */
function driveresource_gateway_activate(): array
{
    try {
        $schema = Capsule::schema();

        if (!$schema->hasTable('mod_driveresource_services')) {
            $schema->create('mod_driveresource_services', static function (Blueprint $table): void {
                $table->unsignedInteger('service_id')->primary();
                $table->string('site_url', 512);
                $table->char('site_hash', 64)->index();
                $table->char('token_hash', 64);
                $table->string('status', 16)->default('active')->index();
                // Legacy backend fields remain as aliases for the video provider.
                $table->string('backend_key', 32)->default('elearningstream')->index();
                $table->string('backend_profile', 64)->default('default');
                $table->string('video_backend_key', 32)->default('elearningstream')->index();
                $table->string('video_backend_profile', 64)->default('default');
                $table->string('video_collection_id', 64)->nullable()->index();
                $table->string('video_collection_name', 191)->nullable();
                $table->string('object_backend_key', 32)->default('none')->index();
                $table->string('object_backend_profile', 64)->default('default');
                $table->string('connection_status', 16)->default('pending')->index();
                $table->unsignedInteger('connection_checked_at')->nullable();
                $table->string('connection_message', 255)->nullable();
                $table->char('transfer_period', 7)->nullable()->index();
                $table->unsignedBigInteger('transfer_bytes')->default(0);
                $table->unsignedInteger('transfer_updated_at')->nullable();
                $table->unsignedBigInteger('quota_bytes')->default(7000000000);
                $table->unsignedBigInteger('used_bytes')->default(0);
                $table->unsignedBigInteger('reserved_bytes')->default(0);
                $table->boolean('overage_allowed')->default(true);
                $table->unsignedInteger('retention_days')->default(0);
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->unsignedInteger('suspended_at')->nullable();
                $table->unsignedInteger('terminated_at')->nullable();
            });
        }

        if (!$schema->hasTable('mod_driveresource_uploads')) {
            $schema->create('mod_driveresource_uploads', static function (Blueprint $table): void {
                $table->char('upload_id', 32)->primary();
                $table->unsignedInteger('service_id')->index();
                $table->string('video_id', 64)->nullable();
                $table->unique('video_id', 'dr_upload_video_unique');
                $table->string('filename', 255);
                $table->string('display_name', 255)->nullable();
                $table->unsignedBigInteger('source_size')->default(0);
                $table->unsignedBigInteger('accounted_bytes')->default(0);
                $table->string('status', 32)->default('reserved')->index();
                $table->unsignedInteger('course_id')->default(0);
                $table->unsignedInteger('bound_instance_id')->default(0);
                $table->unsignedInteger('expires_at');
                $table->unsignedInteger('delete_after')->nullable()->index();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->unsignedInteger('completed_at')->nullable();
            });
        }

        if (!$schema->hasTable('mod_driveresource_asset_refs')) {
            $schema->create('mod_driveresource_asset_refs', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('service_id')->index();
                $table->string('video_id', 64)->index();
                $table->char('site_hash', 64);
                $table->string('site_url', 512);
                $table->unsignedInteger('instance_id');
                $table->unsignedInteger('course_id')->default(0);
                $table->boolean('active')->default(true)->index();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->unique(
                    ['service_id', 'site_hash', 'instance_id', 'video_id'],
                    'dr_asset_ref_unique'
                );
            });
        }

        if (!$schema->hasTable('mod_driveresource_nonces')) {
            $schema->create('mod_driveresource_nonces', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('service_id')->index();
                $table->char('nonce', 32);
                $table->unsignedInteger('expires_at')->index();
                $table->unique(['service_id', 'nonce'], 'dr_nonce_unique');
            });
        }

        if (!$schema->hasTable('mod_driveresource_usage_reports')) {
            $schema->create('mod_driveresource_usage_reports', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('service_id')->index();
                $table->char('report_id', 64);
                $table->char('period_key', 7)->index();
                $table->unsignedBigInteger('bytes')->default(0);
                $table->unsignedInteger('created_at');
                $table->unique(['service_id', 'report_id'], 'dr_usage_report_unique');
            });
        }

        if (!$schema->hasTable('mod_driveresource_audit')) {
            $schema->create('mod_driveresource_audit', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('service_id')->index();
                $table->string('actor_type', 16)->index();
                $table->unsignedInteger('actor_id')->nullable()->index();
                $table->string('action', 64)->index();
                $table->text('metadata_json')->nullable();
                $table->unsignedInteger('created_at')->index();
            });
        }

        driveresource_gateway_ensure_multitenant_schema();
        driveresource_gateway_ensure_provider_schema();
        driveresource_gateway_ensure_collection_schema();
        driveresource_gateway_ensure_asset_reference_schema();
        driveresource_gateway_ensure_video_ownership_schema();
        driveresource_gateway_ensure_upload_display_name_schema();
        driveresource_gateway_ensure_portal_schema();
        driveresource_gateway_ensure_audit_schema();
        driveresource_gateway_ensure_commercial_account_schema();

        return ['status' => 'success', 'description' => 'Elearning Stream Gateway activated.'];
    } catch (Throwable $exception) {
        return ['status' => 'error', 'description' => $exception->getMessage()];
    }
}

/**
 * Upgrade addon persistence for multi-tenant backend selection.
 *
 * WHMCS invokes this function after detecting a module version change.
 *
 * @param array $vars WHMCS addon variables, including previously installed version.
 * @return void
 */
function driveresource_gateway_upgrade(array $vars): void
{
    $installed = (string) ($vars['version'] ?? '0.0.0');
    if (version_compare($installed, '0.3.0', '<')) {
        driveresource_gateway_ensure_multitenant_schema();
    }
    if (version_compare($installed, '0.4.0', '<')) {
        driveresource_gateway_ensure_portal_schema();
    }
    if (version_compare($installed, '0.4.1', '<')) {
        driveresource_gateway_ensure_audit_schema();
    }
    if (version_compare($installed, '0.5.0', '<')) {
        driveresource_gateway_ensure_provider_schema();
    }
    if (version_compare($installed, '0.5.3', '<')) {
        driveresource_gateway_ensure_collection_schema();
    }
    if (version_compare($installed, '0.5.5', '<')) {
        driveresource_gateway_ensure_asset_reference_schema();
    }
    if (version_compare($installed, '0.5.8', '<')) {
        driveresource_gateway_ensure_upload_display_name_schema();
    }
    if (version_compare($installed, '0.5.9', '<')) {
        driveresource_gateway_ensure_video_ownership_schema();
    }
    if (version_compare($installed, '0.6.0', '<')) {
        driveresource_gateway_ensure_commercial_account_schema();
    }
}

/**
 * Ensure one provider video can belong to only one WHMCS service.
 *
 * The provider GUID identifies one physical asset. Multiple Moodle activities
 * may reference it through mod_driveresource_asset_refs, but ownership and
 * accounting must remain singular at the upload/asset layer.
 *
 * @return void
 */
function driveresource_gateway_ensure_video_ownership_schema(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_driveresource_uploads')) {
        return;
    }

    $indexes = Capsule::select(
        "SHOW INDEX FROM mod_driveresource_uploads WHERE Key_name = ?",
        ['dr_upload_video_unique']
    );
    if ($indexes !== []) {
        return;
    }

    $duplicate = Capsule::table('mod_driveresource_uploads')
        ->select('video_id')
        ->whereNotNull('video_id')
        ->groupBy('video_id')
        ->havingRaw('COUNT(*) > 1')
        ->first();
    if ($duplicate) {
        throw new RuntimeException(
            'Elearning Stream Gateway detected duplicate provider video ownership. '
                . 'Resolve duplicate mod_driveresource_uploads.video_id rows before upgrading to 0.5.9.'
        );
    }

    $schema->table('mod_driveresource_uploads', static function (Blueprint $table): void {
        $table->unique('video_id', 'dr_upload_video_unique');
    });
}

/**
 * Preserve the provider/source filename while exposing the Moodle activity
 * title separately in the WHMCS client portal.
 *
 * @return void
 */
function driveresource_gateway_ensure_upload_display_name_schema(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_driveresource_uploads')) {
        return;
    }

    if (!$schema->hasColumn('mod_driveresource_uploads', 'display_name')) {
        $schema->table('mod_driveresource_uploads', static function (Blueprint $table): void {
            $table->string('display_name', 255)->nullable()->after('filename');
        });
    }
}

/**
 * Ensure backend identity exists on every provisioned service.
 *
 * Existing services remain on Elearning Stream/default. The fields are generic
 * so future S3-compatible backends can be added without changing tenant,
 * billing, quota or Moodle authentication identifiers.
 *
 * @return void
 */
function driveresource_gateway_ensure_multitenant_schema(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_driveresource_services')) {
        return;
    }

    if (!$schema->hasColumn('mod_driveresource_services', 'backend_key')) {
        $schema->table('mod_driveresource_services', static function (Blueprint $table): void {
            $table->string('backend_key', 32)->default('elearningstream')->index();
        });
    }

    if (!$schema->hasColumn('mod_driveresource_services', 'backend_profile')) {
        $schema->table('mod_driveresource_services', static function (Blueprint $table): void {
            $table->string('backend_profile', 64)->default('default');
        });
    }

    Capsule::table('mod_driveresource_services')
        ->whereNull('backend_key')
        ->orWhere('backend_key', '')
        ->update(['backend_key' => 'elearningstream']);

    Capsule::table('mod_driveresource_services')
        ->whereNull('backend_profile')
        ->orWhere('backend_profile', '')
        ->update(['backend_profile' => 'default']);
}

/**
 * Split media-provider identity into independent video and object-storage lanes.
 *
 * Legacy backend_key/backend_profile continue to mirror the video provider so
 * existing API code and installed services remain backward compatible.
 *
 * @return void
 */
function driveresource_gateway_ensure_provider_schema(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_driveresource_services')) {
        return;
    }

    $addedVideoKey = false;
    $addedVideoProfile = false;

    if (!$schema->hasColumn('mod_driveresource_services', 'video_backend_key')) {
        $schema->table('mod_driveresource_services', static function (Blueprint $table): void {
            $table->string('video_backend_key', 32)->default('elearningstream')->index();
        });
        $addedVideoKey = true;
    }

    if (!$schema->hasColumn('mod_driveresource_services', 'video_backend_profile')) {
        $schema->table('mod_driveresource_services', static function (Blueprint $table): void {
            $table->string('video_backend_profile', 64)->default('default');
        });
        $addedVideoProfile = true;
    }

    if (!$schema->hasColumn('mod_driveresource_services', 'object_backend_key')) {
        $schema->table('mod_driveresource_services', static function (Blueprint $table): void {
            $table->string('object_backend_key', 32)->default('none')->index();
        });
    }

    if (!$schema->hasColumn('mod_driveresource_services', 'object_backend_profile')) {
        $schema->table('mod_driveresource_services', static function (Blueprint $table): void {
            $table->string('object_backend_profile', 64)->default('default');
        });
    }

    // Preserve any legacy video backend assignment when introducing the split.
    if ($addedVideoKey || $addedVideoProfile) {
        $services = Capsule::table('mod_driveresource_services')
            ->select(['service_id', 'backend_key', 'backend_profile'])
            ->get();

        foreach ($services as $service) {
            Capsule::table('mod_driveresource_services')
                ->where('service_id', (int) $service->service_id)
                ->update([
                    'video_backend_key' => trim((string) ($service->backend_key ?? '')) !== ''
                        ? (string) $service->backend_key
                        : 'elearningstream',
                    'video_backend_profile' => trim((string) ($service->backend_profile ?? '')) !== ''
                        ? (string) $service->backend_profile
                        : 'default',
                ]);
        }
    }
}

/**
 * Ensure each service can persist its provider-side virtual classroom.
 *
 * One Bunny collection is allocated lazily per WHMCS service. Existing
 * services are left unassigned until the next upload/import or an explicit
 * organisation action creates the provider collection.
 *
 * @return void
 */
function driveresource_gateway_ensure_collection_schema(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_driveresource_services')) {
        return;
    }

    if (!$schema->hasColumn('mod_driveresource_services', 'video_collection_id')) {
        $schema->table('mod_driveresource_services', static function (Blueprint $table): void {
            $table->string('video_collection_id', 64)->nullable()->index();
        });
    }

    if (!$schema->hasColumn('mod_driveresource_services', 'video_collection_name')) {
        $schema->table('mod_driveresource_services', static function (Blueprint $table): void {
            $table->string('video_collection_name', 191)->nullable();
        });
    }
}

/**
 * Preserve one reference row per Moodle activity and provider video.
 *
 * Earlier gateway releases used one row per Moodle activity, which allowed a
 * bind of a replacement video to overwrite the historical reference needed by
 * a queued release task. Rebuilding the unique index with video_id keeps both
 * transitions independently addressable and makes concurrent cron workers safe.
 *
 * @return void
 */
function driveresource_gateway_ensure_asset_reference_schema(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_driveresource_asset_refs')) {
        return;
    }

    // WHMCS officially runs on MySQL/MariaDB. Inspect the existing named index
    // before changing it so addon activation/upgrade remains idempotent even
    // after a partial deployment or a repeated activation.
    $indexes = Capsule::select(
        "SHOW INDEX FROM mod_driveresource_asset_refs WHERE Key_name = ?",
        ['dr_asset_ref_unique']
    );
    $columns = [];
    foreach ($indexes as $index) {
        $columns[(int)($index->Seq_in_index ?? 0)] = (string)($index->Column_name ?? '');
    }
    ksort($columns);
    $columns = array_values(array_filter($columns));

    $expected = ['service_id', 'site_hash', 'instance_id', 'video_id'];
    if ($columns === $expected) {
        return;
    }

    if ($columns !== []) {
        $schema->table('mod_driveresource_asset_refs', static function (Blueprint $table): void {
            $table->dropUnique('dr_asset_ref_unique');
        });
    }

    $schema->table('mod_driveresource_asset_refs', static function (Blueprint $table): void {
        $table->unique(
            ['service_id', 'site_hash', 'instance_id', 'video_id'],
            'dr_asset_ref_unique'
        );
    });
}

/**
 * Ensure client-portal connection and transfer metering schema.
 *
 * @return void
 */
function driveresource_gateway_ensure_portal_schema(): void
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_driveresource_services')) {
        return;
    }

    $columns = [
        'connection_status' => static function (Blueprint $table): void {
            $table->string('connection_status', 16)->default('pending')->index();
        },
        'connection_checked_at' => static function (Blueprint $table): void {
            $table->unsignedInteger('connection_checked_at')->nullable();
        },
        'connection_message' => static function (Blueprint $table): void {
            $table->string('connection_message', 255)->nullable();
        },
        'transfer_period' => static function (Blueprint $table): void {
            $table->char('transfer_period', 7)->nullable()->index();
        },
        'transfer_bytes' => static function (Blueprint $table): void {
            $table->unsignedBigInteger('transfer_bytes')->default(0);
        },
        'transfer_updated_at' => static function (Blueprint $table): void {
            $table->unsignedInteger('transfer_updated_at')->nullable();
        },
    ];

    foreach ($columns as $column => $callback) {
        if (!$schema->hasColumn('mod_driveresource_services', $column)) {
            $schema->table('mod_driveresource_services', $callback);
        }
    }

    if (!$schema->hasTable('mod_driveresource_usage_reports')) {
        $schema->create('mod_driveresource_usage_reports', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('service_id')->index();
            $table->char('report_id', 64);
            $table->char('period_key', 7)->index();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedInteger('created_at');
            $table->unique(['service_id', 'report_id'], 'dr_usage_report_unique');
        });
    }

    Capsule::table('mod_driveresource_services')
        ->whereNull('connection_status')
        ->orWhere('connection_status', '')
        ->update(['connection_status' => 'pending']);
}

/**
 * Ensure redacted control-plane audit persistence exists.
 *
 * @return void
 */
function driveresource_gateway_ensure_audit_schema(): void
{
    $schema = Capsule::schema();
    if ($schema->hasTable('mod_driveresource_audit')) {
        return;
    }

    $schema->create('mod_driveresource_audit', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->unsignedInteger('service_id')->index();
        $table->string('actor_type', 16)->index();
        $table->unsignedInteger('actor_id')->nullable()->index();
        $table->string('action', 64)->index();
        $table->text('metadata_json')->nullable();
        $table->unsignedInteger('created_at')->index();
    });
}

/**
 * Ensure account, Moodle-installation, wallet and daily-usage persistence.
 *
 * Version 0.6.0 changes the commercial ownership boundary from one WHMCS
 * service per Moodle site to one WHMCS service per Elearning Stream account.
 * Existing service rows remain as aggregate compatibility records while site
 * credentials move into mod_driveresource_installations.
 *
 * Existing tenants are backfilled as legacy accounts so an upgrade cannot
 * unexpectedly block active customers. Newly provisioned services are created
 * as FREE accounts by the provisioning module.
 *
 * @return void
 */
function driveresource_gateway_ensure_commercial_account_schema(): void
{
    $schema = Capsule::schema();

    if (!$schema->hasTable('mod_driveresource_accounts')) {
        $schema->create('mod_driveresource_accounts', static function (Blueprint $table): void {
            $table->unsignedInteger('service_id')->primary();
            $table->unsignedInteger('client_id')->nullable()->index();
            $table->string('billing_mode', 16)->default('free')->index();
            $table->boolean('activation_verified')->default(false)->index();
            $table->bigInteger('activation_amount_microusd')->default(1000000);
            $table->bigInteger('balance_microusd')->default(0);
            $table->unsignedBigInteger('free_storage_bytes')->default(7000000000);
            $table->unsignedBigInteger('free_transfer_bytes')->default(20000000000);
            $table->unsignedBigInteger('storage_rate_microusd_per_gb')->default(30000);
            $table->unsignedBigInteger('transfer_rate_microusd_per_gb')->default(120000);
            $table->unsignedBigInteger('minimum_recharge_microusd')->default(10000000);
            $table->unsignedInteger('free_installation_limit')->default(1);
            $table->unsignedInteger('paid_installation_limit')->default(0);
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('grace_until')->nullable()->index();
            $table->unsignedInteger('paid_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
    }

    if (!$schema->hasTable('mod_driveresource_installations')) {
        $schema->create('mod_driveresource_installations', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('service_id')->index();
            $table->string('label', 191)->nullable();
            $table->string('site_url', 512);
            $table->char('site_hash', 64);
            $table->char('token_hash', 64);
            $table->string('status', 16)->default('active')->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->string('connection_status', 16)->default('pending')->index();
            $table->unsignedInteger('connection_checked_at')->nullable();
            $table->string('connection_message', 255)->nullable();
            $table->unsignedInteger('last_seen_at')->nullable()->index();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->unique(['service_id', 'site_hash'], 'dr_installation_site_unique');
            $table->unique(['service_id', 'token_hash'], 'dr_installation_token_unique');
        });
    }

    if (!$schema->hasTable('mod_driveresource_wallet_ledger')) {
        $schema->create('mod_driveresource_wallet_ledger', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('service_id')->index();
            $table->string('entry_type', 32)->index();
            $table->bigInteger('amount_microusd');
            $table->bigInteger('balance_after_microusd');
            $table->char('currency', 3)->default('USD');
            $table->char('idempotency_key', 64)->unique();
            $table->string('external_ref', 191)->nullable()->index();
            $table->text('metadata_json')->nullable();
            $table->unsignedInteger('created_at')->index();
        });
    }

    if (!$schema->hasTable('mod_driveresource_usage_daily')) {
        $schema->create('mod_driveresource_usage_daily', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('service_id')->index();
            $table->char('usage_date', 10);
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->unsignedBigInteger('transfer_bytes')->default(0);
            $table->unsignedBigInteger('billable_storage_bytes')->default(0);
            $table->unsignedBigInteger('billable_transfer_bytes')->default(0);
            $table->unsignedBigInteger('charge_microusd')->default(0);
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->unique(['service_id', 'usage_date'], 'dr_usage_daily_unique');
        });
    }

    if (!$schema->hasTable('mod_driveresource_wallet_orders')) {
        $schema->create('mod_driveresource_wallet_orders', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('service_id')->index();
            $table->unsignedInteger('client_id')->index();
            $table->unsignedInteger('invoice_id')->nullable()->unique();
            $table->unsignedBigInteger('amount_microusd');
            $table->char('currency', 3)->default('USD');
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('paid_at')->nullable();
            $table->unsignedInteger('refunded_at')->nullable();
            $table->unsignedInteger('updated_at');
        });
    }

    if (
        $schema->hasTable('mod_driveresource_uploads')
        && !$schema->hasColumn('mod_driveresource_uploads', 'installation_id')
    ) {
        $schema->table('mod_driveresource_uploads', static function (Blueprint $table): void {
            $table->unsignedBigInteger('installation_id')->nullable()->index();
        });
    }

    if (
        $schema->hasTable('mod_driveresource_usage_reports')
        && !$schema->hasColumn('mod_driveresource_usage_reports', 'installation_id')
    ) {
        $schema->table('mod_driveresource_usage_reports', static function (Blueprint $table): void {
            $table->unsignedBigInteger('installation_id')->nullable()->index();
        });
    }

    if (!$schema->hasTable('mod_driveresource_services')) {
        return;
    }

    $now = time();
    $services = Capsule::table('mod_driveresource_services')->get();
    foreach ($services as $service) {
        $serviceId = (int) $service->service_id;
        $clientId = Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->value('userid');

        $account = Capsule::table('mod_driveresource_accounts')
            ->where('service_id', $serviceId)
            ->first();

        if (!$account) {
            Capsule::table('mod_driveresource_accounts')->insert([
                'service_id' => $serviceId,
                'client_id' => $clientId !== null ? (int) $clientId : null,
                // Preserve existing commercial behavior until explicitly
                // migrated to the new prepaid PAYG contract.
                'billing_mode' => 'legacy',
                'activation_verified' => true,
                'activation_amount_microusd' => 0,
                'balance_microusd' => 0,
                'free_storage_bytes' => max(0, (int) ($service->quota_bytes ?? 7000000000)),
                'free_transfer_bytes' => 20000000000,
                'storage_rate_microusd_per_gb' => 30000,
                'transfer_rate_microusd_per_gb' => 120000,
                'minimum_recharge_microusd' => 10000000,
                'free_installation_limit' => 1,
                'paid_installation_limit' => 0,
                'status' => (string) ($service->status ?? 'active') === 'active'
                    ? 'active'
                    : 'suspended',
                'grace_until' => null,
                'paid_at' => null,
                'updated_at' => $now,
                'created_at' => (int) ($service->created_at ?? $now),
            ]);
        }

        $siteUrl = trim((string) ($service->site_url ?? ''));
        $siteHash = trim((string) ($service->site_hash ?? ''));
        $tokenHash = trim((string) ($service->token_hash ?? ''));
        if ($siteUrl === '' || $siteHash === '' || $tokenHash === '') {
            continue;
        }

        $existing = Capsule::table('mod_driveresource_installations')
            ->where('service_id', $serviceId)
            ->where('site_hash', $siteHash)
            ->first();

        if (!$existing) {
            Capsule::table('mod_driveresource_installations')->insert([
                'service_id' => $serviceId,
                'label' => 'Primary Moodle',
                'site_url' => $siteUrl,
                'site_hash' => $siteHash,
                'token_hash' => $tokenHash,
                'status' => (string) ($service->status ?? 'active') === 'active'
                    ? 'active'
                    : 'suspended',
                'is_primary' => true,
                'connection_status' => (string) ($service->connection_status ?? 'pending'),
                'connection_checked_at' => $service->connection_checked_at ?? null,
                'connection_message' => $service->connection_message ?? null,
                'last_seen_at' => null,
                'created_at' => (int) ($service->created_at ?? $now),
                'updated_at' => $now,
            ]);
        }
    }
}

/**
 * Keep billing/media state on deactivation to prevent accidental asset loss.
 *
 * @return array
 */
function driveresource_gateway_deactivate(): array
{
    return [
        'status' => 'success',
        'description' => 'Gateway disabled. Media/accounting tables were retained intentionally.',
    ];
}

/**
 * Lightweight admin dashboard.
 *
 * @param array $vars WHMCS addon variables.
 * @return void
 */
function driveresource_gateway_output(array $vars): void
{
    (new \WHMCS\Module\Addon\DriveresourceGateway\AdminDashboard())->render();
}
