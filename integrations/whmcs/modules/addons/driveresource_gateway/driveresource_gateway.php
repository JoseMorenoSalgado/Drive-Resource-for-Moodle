<?php
/**
 * Drive Resource WHMCS media gateway addon.
 *
 * @copyright  2026 Elearning Cloud
 * @license    Proprietary companion module distributed with Drive Resource
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
        'name' => 'Drive Resource Media Gateway',
        'description' => 'WHMCS authorization, quota and Elearning Stream credential boundary for Drive Resource.',
        'version' => '0.4.0',
        'author' => 'Elearning Cloud',
        'fields' => [
            'bunny_library_id' => [
                'FriendlyName' => 'Elearning Stream Library ID',
                'Type' => 'text',
                'Size' => '30',
                'Description' => 'Video Library used by Drive Resource.',
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
                'Description' => 'Provider CDN hostname used by Moodle protected playback. Example: vz-xxxxxxxx-xxx.b-cdn.net.',
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
                'Description' => 'Server-side playback signing key. Never copied to Moodle.',
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
                $table->string('backend_key', 32)->default('elearningstream')->index();
                $table->string('backend_profile', 64)->default('default');
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
                $table->unsignedInteger('retention_days')->default(30);
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
                $table->string('video_id', 64)->nullable()->index();
                $table->string('filename', 255);
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
                $table->unique(['service_id', 'site_hash', 'instance_id'], 'dr_asset_ref_unique');
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

        driveresource_gateway_ensure_multitenant_schema();
        driveresource_gateway_ensure_portal_schema();

        return ['status' => 'success', 'description' => 'Drive Resource Media Gateway activated.'];
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
