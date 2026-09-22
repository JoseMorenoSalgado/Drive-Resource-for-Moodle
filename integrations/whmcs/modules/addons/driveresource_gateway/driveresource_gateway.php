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
        'description' => 'WHMCS authorization, quota and Bunny Stream credential boundary for Drive Resource.',
        'version' => '0.1.0',
        'author' => 'Elearning Cloud',
        'fields' => [
            'bunny_library_id' => [
                'FriendlyName' => 'Bunny Stream Library ID',
                'Type' => 'text',
                'Size' => '30',
                'Description' => 'Video Library used by Drive Resource.',
            ],
            'bunny_api_key' => [
                'FriendlyName' => 'Bunny Stream API Key',
                'Type' => 'password',
                'Size' => '45',
                'Description' => 'Server-side only. Never copied to Moodle.',
            ],
            'bunny_cdn_hostname' => [
                'FriendlyName' => 'Bunny Stream CDN Hostname',
                'Type' => 'text',
                'Size' => '45',
                'Description' => 'Example: vz-xxxxxxxx-xxx.b-cdn.net. Reserved for secure playback phase.',
            ],
            'bunny_token_key' => [
                'FriendlyName' => 'Bunny CDN Token Key',
                'Type' => 'password',
                'Size' => '45',
                'Description' => 'Server-side playback signing key. Never copied to Moodle.',
            ],
            'tus_ttl' => [
                'FriendlyName' => 'Direct Upload TTL',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '3600',
                'Description' => 'Lifetime in seconds for a direct-upload signature (300–86400).',
            ],
            'clock_skew' => [
                'FriendlyName' => 'Gateway Clock Skew',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '300',
                'Description' => 'Maximum accepted Moodle request timestamp drift in seconds (60–900).',
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
                $table->unsignedBigInteger('quota_bytes')->default(7516192768);
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

        return ['status' => 'success', 'description' => 'Drive Resource Media Gateway activated.'];
    } catch (Throwable $exception) {
        return ['status' => 'error', 'description' => $exception->getMessage()];
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
    $services = Capsule::table('mod_driveresource_services')->count();
    $active = Capsule::table('mod_driveresource_services')->where('status', 'active')->count();
    $uploads = Capsule::table('mod_driveresource_uploads')->count();
    $bytes = (int) Capsule::table('mod_driveresource_services')->sum('used_bytes');

    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Drive Resource Media Gateway</strong></div>';
    echo '<div class="panel-body">';
    echo '<p>Provisioned services: ' . (int) $services . ' &middot; Active: ' . (int) $active . '</p>';
    echo '<p>Tracked videos: ' . (int) $uploads . ' &middot; Accounted storage: '
        . htmlspecialchars(number_format($bytes / 1073741824, 2), ENT_QUOTES, 'UTF-8') . ' GB</p>';
    echo '<p>Bunny credentials are retained inside WHMCS and are not exposed to Moodle.</p>';
    echo '</div></div>';
}
