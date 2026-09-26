#!/usr/bin/env bash
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

fail() {
    echo "::error::$1"
    exit 1
}

require_file() {
    [[ -s "$1" ]] || fail "Required Elearning Stream/WHMCS file is missing or empty: $1"
}

echo "Checking Elearning Stream/WHMCS required files..."
require_file "classes/local/provider/bunny_stream.php"
require_file "classes/local/whmcs_gateway_client.php"
require_file "classes/external/create_bunny_upload.php"
require_file "classes/external/refresh_bunny_upload.php"
require_file "classes/external/complete_bunny_upload.php"
require_file "amd/src/bunnyupload.js"
require_file "amd/build/bunnyupload.min.js"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/index.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/lib/RequestAuthenticator.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/lib/BackendRegistry.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/lib/AdminDashboard.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/lib/AuditLogger.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/api/asset-import.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/api/playback-authorize.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/api/usage-report.php"
require_file "integrations/whmcs/modules/servers/driveresource/driveresource.php"
require_file "integrations/whmcs/modules/servers/driveresource/lib/MetricsProvider.php"
require_file "integrations/whmcs/modules/servers/driveresource/lib/ClientPortal.php"
require_file "integrations/whmcs/modules/servers/driveresource/lib/MoodleConnectionProbe.php"
require_file "integrations/whmcs/modules/servers/driveresource/lib/Translator.php"
require_file "integrations/whmcs/modules/servers/driveresource/lang/english.php"
require_file "integrations/whmcs/modules/servers/driveresource/lang/spanish.php"
require_file "gateway-status.php"
require_file "classes/local/transfer_meter.php"
require_file "classes/task/sync_transfer_usage.php"

echo "Checking fresh-install and upgrade schema parity..."
grep -q 'NAME="providerassetid"' db/install.xml || fail "providerassetid is missing from install.xml."
grep -q 'NAME="provideruploadid"' db/install.xml || fail "provideruploadid is missing from install.xml."
grep -q 'NAME="providerfilesize"' db/install.xml || fail "providerfilesize is missing from install.xml."
grep -q 'NAME="providerstatus"' db/install.xml || fail "providerstatus is missing from install.xml."
grep -q 'NAME="providerasset_idx"' db/install.xml || fail "providerasset_idx is missing from install.xml."
grep -q '2026092103' db/upgrade.php || fail "Bunny provider upgrade savepoint is missing."
grep -q '2026092501' db/upgrade.php || fail "Indexed source-default repair savepoint is missing."
grep -q "drop_index(\$table, \$sourceindex)" db/upgrade.php || fail "source_idx must be dropped before changing the source default."
grep -q "change_field_default(\$table, \$sourcefield)" db/upgrade.php || fail "source default migration is missing."
grep -q "add_index(\$table, \$sourceindex)" db/upgrade.php || fail "source_idx must be recreated after changing the source default."
grep -q '2026092202' db/upgrade.php || fail "Progress schema repair savepoint is missing."
grep -q '2026092401' db/upgrade.php || fail "Elearning Stream RC1 source-default upgrade savepoint is missing."
grep -q 'DEFAULT="bunnystream"' db/install.xml || fail "Fresh installs must default to Elearning Stream video."

echo "Checking Moodle/Elearning Stream secret boundary..."
if grep -RniE 'AccessKey:|bunny_api_key|bunny_token_key' classes amd db lib.php mod_form.php settings.php view.php templates; then
    fail "A Bunny management credential identifier leaked into Moodle runtime code."
fi
grep -q "Provider credentials remain protected in the gateway" lang/en/videoplayer.php     || fail "Moodle secret-boundary language invariant is missing."
if grep -nE "= '[^']*WHMCS" lang/en/videoplayer.php lang/es/videoplayer.php; then
    fail "Customer-facing Moodle language still exposes WHMCS implementation wording."
fi
if grep -RniE 'object_storage_access_key|object_storage_secret_key|bunny_api_key|bunny_token_key' classes amd db lib.php mod_form.php settings.php view.php templates; then
    fail "Provider management credential identifiers leaked into Moodle runtime code."
fi
if grep -q "addElement('select', 'source'" mod_form.php; then
    fail "New Moodle activities must not expose the legacy resource-source selector."
fi
grep -q "addElement('hidden', 'source', \$currentsource)" mod_form.php     || fail "Moodle form no longer pins the production source internally."
grep -Fq "js_call_amd('mod_videoplayer/nativevideo', 'init')" view.php     || fail "Moodle native video player initialization is missing."
grep -Fq "uploadMetadata(file, title)" amd/src/bunnyupload.js     || fail "TUS upload metadata must receive the Moodle activity title."
grep -Fq "title: nameField && nameField.value ? nameField.value : selectedFile.name" amd/src/bunnyupload.js     || fail "Direct upload must prefer the Moodle activity name over the local filename."
if grep -Fq "is_available() && !\$resource->is_bunny_stream()" view.php; then
    fail "Elearning Stream videos must not be excluded from native player initialization."
fi
grep -Fq "\$courseid = (int)\$this->get_course();" mod_form.php     || fail "Direct upload must use moodleform_mod::get_course() for the real course id."
if grep -Fq "\$this->course->id" mod_form.php; then
    fail "Direct upload still references the invalid moodleform_mod course object property."
fi
grep -q "video\.bunnycdn\.com" classes/local/whmcs_gateway_client.php     || fail "Moodle no longer pins the TUS upload host."
if grep -Rni '/library/' classes amd/src amd/build; then
    fail "Moodle runtime contains a Bunny management API path."
fi

echo "Checking WHMCS-only provider management credentials..."
grep -q "'bunny_api_key'" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "WHMCS addon no longer owns the Bunny API key setting."
grep -q "'AccessKey: '" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "WHMCS Bunny API client authentication is missing."
grep -q 'hash_hmac' integrations/whmcs/modules/addons/driveresource_gateway/lib/RequestAuthenticator.php     || fail "Moodle-to-WHMCS HMAC verification is missing."
grep -Fq "X-Drive-Resource-Token: " classes/local/whmcs_gateway_client.php     || fail "Moodle must send the dedicated service-token header."
grep -Fq "header('X-Drive-Resource-Token')" integrations/whmcs/modules/addons/driveresource_gateway/lib/RequestAuthenticator.php     || fail "Gateway must accept the dedicated service-token header."
grep -Fq "preg_match('/^[a-f0-9]{64}$/', \$token)" integrations/whmcs/modules/addons/driveresource_gateway/lib/RequestAuthenticator.php     || fail "Gateway service token must remain exact 64-hex."
grep -q 'mod_driveresource_nonces' integrations/whmcs/modules/addons/driveresource_gateway/lib/RequestAuthenticator.php     || fail "Replay nonce protection is missing."

echo "Checking multi-tenant provider architecture..."
grep -q "'version' => '0.5.1'" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "WHMCS addon version 0.5.1 is missing."
grep -q "function driveresource_gateway_upgrade" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "WHMCS addon upgrade function is missing."
grep -q "video_backend_key" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "Independent video-provider schema is missing."
grep -q "object_backend_key" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "Independent object-storage schema is missing."
grep -q "driveresource_gateway_ensure_provider_schema" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "0.5.0 provider schema migration is missing."
grep -q "S3_COMPATIBLE" integrations/whmcs/modules/addons/driveresource_gateway/lib/BackendRegistry.php     || fail "S3-compatible provider registry entry is missing."
grep -A8 "self::S3_COMPATIBLE" integrations/whmcs/modules/addons/driveresource_gateway/lib/BackendRegistry.php | grep -q "'operational' => false"     || fail "S3 data plane must remain gated until its adapter is implemented."
grep -q "Video Provider" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "WHMCS video-provider selector is missing."
grep -q "Protected PDF Storage" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "WHMCS protected-PDF storage selector is missing."
grep -q "Object Storage Profile" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "WHMCS object-storage profile selector is missing."
grep -q "'object_storage_provider'" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "S3 provider configuration is missing."
grep -q "'object_storage_endpoint'" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "S3 endpoint configuration is missing."
grep -q "'object_storage_secret_key'" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "S3 secret configuration is missing."
grep -q "'public_gateway_url'" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "Branded public gateway URL setting is missing."
grep -q "Elearning Stream Gateway" integrations/whmcs/modules/addons/driveresource_gateway/index.php     || fail "Branded gateway landing page is missing."
grep -q "controlled backend migration is required" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Unsafe backend switching protection is missing."
grep -q "requireServiceCapability" integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php     || fail "Gateway backend capability enforcement is missing."
grep -q "AdminDashboard" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "Multi-client admin dashboard is not wired."
grep -q "backend_key" integrations/whmcs/modules/addons/driveresource_gateway/lib/AdminDashboard.php     || fail "Multi-client dashboard does not expose/filter backend identity."
grep -q "renderInstallationHealth" integrations/whmcs/modules/addons/driveresource_gateway/lib/AdminDashboard.php     || fail "WHMCS installation health diagnostics are missing."
grep -q "Generic usernames" integrations/whmcs/modules/addons/driveresource_gateway/lib/AdminDashboard.php     || fail "WHMCS provisioning diagnostics do not detect generic usernames."
grep -q "'RequiresServer' => false" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Elearning Stream must not require a fake WHMCS server assignment."
grep -q "function driveresource_AdminServicesTabFields" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Admin Moodle connection details are missing."
grep -q "service_token" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Translated admin service token handoff is missing."
grep -q "function driveresource_AdminCustomButtonArray" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Admin provisioning repair action is missing."
grep -q "function driveresource_provision_moodle_connection" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Shared idempotent Moodle provisioning helper is missing."

echo "Checking WHMCS client self-service portal..."
grep -q "function driveresource_ClientAreaAllowedFunctions" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Client-area self-service allow-list is missing."
grep -q "publicGatewayUrl" integrations/whmcs/modules/servers/driveresource/lib/ClientPortal.php     || fail "Client portal does not expose the branded gateway URL."
grep -q "public_gateway_url" integrations/whmcs/modules/servers/driveresource/lib/ClientPortal.php     || fail "Client portal is not wired to the branded gateway setting."
if grep -q 'name="moodleurl"' integrations/whmcs/modules/servers/driveresource/lib/ClientPortal.php; then
    fail "Customer portal must not expose the internal Moodle site binding as an editable field."
fi
if grep -A12 "function driveresource_ClientAreaAllowedFunctions" integrations/whmcs/modules/servers/driveresource/driveresource.php | grep -q "UpdateMoodleUrl"; then
    fail "Customer self-service must not be allowed to change the internal Moodle site binding."
fi
grep -q "ValidateMoodleConnection" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Client Moodle connection validation is missing."
grep -q "DeleteVideo" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Client video deletion action is missing."
grep -q "activeRefs" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Internal Moodle URL reassignment protection is missing."
grep -q "where('active', true)" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Client video deletion must block active Moodle references."
grep -q "generate_new_key" integrations/whmcs/modules/servers/driveresource/lang/english.php     || fail "English client token rotation string is missing."
grep -q "generate_new_key" integrations/whmcs/modules/servers/driveresource/lang/spanish.php     || fail "Spanish client token rotation string is missing."
grep -q "validate_connection" integrations/whmcs/modules/servers/driveresource/lang/english.php     || fail "English connection validation string is missing."
grep -q "videos_title" integrations/whmcs/modules/servers/driveresource/lang/spanish.php     || fail "Spanish video library string is missing."
grep -q "Translator::fromParams" integrations/whmcs/modules/servers/driveresource/lib/ClientPortal.php     || fail "Client portal is not using module localisation."
grep -q "generate_token('plain')" integrations/whmcs/modules/servers/driveresource/lib/ClientPortal.php     || fail "Client self-service forms are missing the WHMCS CSRF token."
grep -Fq "preg_match('/^[a-f0-9]{64}$/'" integrations/whmcs/modules/servers/driveresource/lib/ClientPortal.php     || fail "Client portal no longer validates Drive Resource token format."
grep -Fq "preg_match('/^[a-f0-9]{64}$/'" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Admin service token handoff no longer validates Drive Resource token format."
grep -Fq "\$tokenisusable = (bool) preg_match('/^[a-f0-9]{64}$/'" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Provisioning must reject generic WHMCS passwords and generate the module token."
grep -Fq "!preg_match('/^[a-f0-9]{64}$/'" integrations/whmcs/modules/servers/driveresource/lib/MoodleConnectionProbe.php     || fail "Connection probe must require the exact Drive Resource token format."
grep -q "standard HTTPS port 443" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Stored Moodle URL validation must match the signed probe port policy."
grep -q "MoodleConnectionProbe" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Signed Moodle probe is not wired."

echo "Checking custom-theme WHMCS client-area fallback..."
grep -q "ClientAreaProductDetailsOutput" integrations/whmcs/modules/addons/driveresource_gateway/hooks.php     || fail "Custom-theme client-area fallback hook is missing."
grep -q "data-dr-service-id" integrations/whmcs/modules/servers/driveresource/lib/ClientPortal.php     || fail "Client portal service identity marker is missing."
grep -q "servertype.*driveresource" integrations/whmcs/modules/addons/driveresource_gateway/hooks.php     || fail "Client-area fallback does not verify the product provisioning module."
grep -q "h.userid.*clientId" integrations/whmcs/modules/addons/driveresource_gateway/hooks.php     || fail "Client-area fallback does not verify service ownership."

echo "Checking per-service transfer metering..."
grep -q "NAME=\"videoplayer_transfer_events\"" db/install.xml     || fail "Moodle transfer queue table is missing."
grep -q "2026092208" db/upgrade.php     || fail "Moodle transfer metering savepoint is missing."
grep -q "sync_transfer_usage" db/tasks.php     || fail "Transfer synchronization task is missing."
grep -q "transfer_meter::record" classes/local/stream/protected_resource_service.php     || fail "Protected playback does not queue transfer bytes."
grep -q "transferbytes" classes/local/http_range_proxy.php     || fail "Range proxy does not meter emitted bytes."
grep -q "usage-report.php" classes/local/whmcs_gateway_client.php     || fail "Moodle transfer batches are not sent to WHMCS."
grep -q "function recordTransfer" integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php     || fail "WHMCS transfer ingestion is missing."
grep -q "mod_driveresource_usage_reports" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "WHMCS idempotent usage report table is missing."
grep -q "video_transfer_gb" integrations/whmcs/modules/servers/driveresource/lib/MetricsProvider.php     || fail "WHMCS transfer Usage Billing metric is missing."
grep -q "TYPE_PERIOD_MONTH" integrations/whmcs/modules/servers/driveresource/lib/MetricsProvider.php     || fail "Transfer usage must be a monthly-period metric."
grep -q "serviceid <> :serviceid" classes/task/sync_transfer_usage.php     || fail "Stale service transfer events are not isolated."
grep -q "gatewaynonces" db/caches.php     || fail "Moodle connection probe replay cache is missing."
grep -q "hash_hmac" gateway-status.php     || fail "Moodle connection endpoint HMAC verification is missing."
grep -Fq "!preg_match('/^[a-f0-9]{64}$/'" gateway-status.php     || fail "Moodle connection endpoint must require the exact service token format."
grep -Fq "!preg_match('/^[a-f0-9]{64}$/'" classes/local/whmcs_gateway_client.php     || fail "Moodle gateway preflight must reject generic WHMCS passwords."
grep -q "NO_MOODLE_COOKIES" gateway-status.php     || fail "Moodle connection probe endpoint must not create browser sessions."

echo "Checking quota and overage controls..."
grep -q 'reserved_bytes' integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php     || fail "Concurrent upload reservation accounting is missing."
grep -q 'overage_allowed' integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php     || fail "WHMCS plan overage policy is missing."
grep -q "'video_storage_gb'" integrations/whmcs/modules/servers/driveresource/lib/MetricsProvider.php     || fail "WHMCS storage usage metric is missing."
grep -q 'TYPE_SNAPSHOT' integrations/whmcs/modules/servers/driveresource/lib/MetricsProvider.php     || fail "Storage usage must remain a snapshot metric."

echo "Checking backup/restore reservation policy..."
if grep -q "'provideruploadid'" backup/moodle2/backup_videoplayer_stepslib.php; then
    fail "Transient WHMCS upload reservations must not be exported in Moodle backups."
fi
grep -q 'reconcile_bunny_asset' backup/moodle2/restore_videoplayer_stepslib.php     || fail "Restored Bunny assets are not reconciled through WHMCS."

if grep -Rni "Bunny Stream" templates lang/en/videoplayer.php lang/es/videoplayer.php; then
    fail "Customer-facing Moodle UI must use Elearning Stream branding."
fi

echo "Checking existing Elearning Stream URL import..."
grep -q 'extract_asset_id_from_url' classes/local/provider/bunny_stream.php     || fail "Existing stream URL parser is missing."
grep -q "streaminputmode" mod_form.php     || fail "Elearning Stream input mode selector is missing."
grep -q "streamurl" mod_form.php     || fail "Elearning Stream URL field is missing."
grep -q "asset-import.php" classes/local/whmcs_gateway_client.php     || fail "Moodle existing-asset import call is missing."
grep -q "function importAsset" integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php     || fail "WHMCS existing-asset import orchestration is missing."
grep -q "already assigned to another service" integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php     || fail "Cross-service ownership protection is missing."
grep -q "sourcebunnystream.*Elearning Stream" lang/en/videoplayer.php     || fail "Elearning Stream branding is missing from Moodle."
grep -q "sourcebunnystream.*Elearning Stream" lang/es/videoplayer.php     || fail "Elearning Stream Spanish branding is missing from Moodle."

echo "Checking custom Elearning Stream public URL aliases..."
grep -q "'bunny_public_aliases'" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "WHMCS public video alias setting is missing."
grep -q "publicVideoHosts" integrations/whmcs/modules/addons/driveresource_gateway/lib/Config.php     || fail "WHMCS public video alias validation is missing."
grep -q "isAllowedPublicVideoHost" integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php     || fail "WHMCS is not authoritative for pasted video hostnames."
grep -q "extract_candidate_asset_id_from_url" classes/local/provider/bunny_stream.php     || fail "Moodle custom-host candidate URL parser is missing."
grep -q "import_asset_url" classes/local/whmcs_gateway_client.php     || fail "Moodle does not delegate pasted URL validation to WHMCS."
grep -q "'url' => \$url" classes/local/whmcs_gateway_client.php     || fail "Pasted URL is not sent transiently to WHMCS for validation."

echo "Checking redacted WHMCS control-plane auditing..."
grep -q "mod_driveresource_audit" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "WHMCS audit table is missing."
grep -q "class AuditLogger" integrations/whmcs/modules/addons/driveresource_gateway/lib/AuditLogger.php     || fail "WHMCS audit logger is missing."
grep -q "token|password|secret|signature" integrations/whmcs/modules/addons/driveresource_gateway/lib/AuditLogger.php     || fail "Audit secret-key redaction guard is missing."
grep -q "moodle_url_changed" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Moodle URL changes are not audited."
grep -q "moodle_token_rotated" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Moodle token rotation is not audited."
grep -q "moodle_connection_validated" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Connection validation is not audited."
grep -q "video_deleted" integrations/whmcs/modules/servers/driveresource/driveresource.php     || fail "Client video deletion is not audited."
grep -q "renderAudit" integrations/whmcs/modules/addons/driveresource_gateway/lib/AdminDashboard.php     || fail "WHMCS admin audit panel is missing."
grep -q "purgeAuditEvents" integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayMaintenance.php     || fail "WHMCS audit retention cleanup is missing."

echo "Checking protected Elearning Stream playback..."
grep -q "function authorizePlayback" integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php     || fail "WHMCS playback authorization is missing."
grep -q "function playbackUrl" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "Provider playback signer is missing."
grep -q "hasMP4Fallback" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "MP4 fallback verification is missing."
grep -q "HS256-" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "Playback token signing is missing."
grep -q "function playback_url" classes/local/whmcs_gateway_client.php     || fail "Moodle playback authorization client is missing."
grep -q "streamplayback" db/caches.php     || fail "Playback authorization cache definition is missing."
grep -q "ELEARNING_STREAM" classes/local/stream/protected_resource_service.php     || fail "Elearning Stream is not proxied through protected.php."
grep -q "b-cdn.net" classes/local/stream/upstream_url_policy.php     || fail "Provider CDN SSRF allow-list entry is missing."
if grep -q "bunny_pending" classes/output/resource_view.php; then
    fail "Elearning Stream must render with the own HTML5 video player, not the pending placeholder."
fi

echo "Checking direct-upload implementation..."
grep -q "AuthorizationSignature" amd/src/bunnyupload.js     || fail "Bunny presigned TUS signature header is missing."
grep -q "mod_videoplayer_refresh_bunny_upload" amd/src/bunnyupload.js     || fail "Long-running TUS authorization refresh is missing."
grep -q "credentials: 'omit'" amd/src/bunnyupload.js     || fail "Direct Bunny upload must not send Moodle cookies cross-origin."

echo "Elearning Stream Gateway integration invariants: PASS"
