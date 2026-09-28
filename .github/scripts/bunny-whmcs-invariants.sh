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
require_file "classes/local/gateway/upload_authorisation.php"
require_file "classes/external/create_bunny_upload.php"
require_file "classes/external/refresh_bunny_upload.php"
require_file "classes/external/complete_bunny_upload.php"
require_file "amd/src/bunnyupload.js"
require_file "amd/build/bunnyupload.min.js"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/lib/RequestAuthenticator.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/api/asset-import.php"
require_file "integrations/whmcs/modules/addons/driveresource_gateway/api/playback-authorize.php"
require_file "integrations/whmcs/modules/servers/driveresource/driveresource.php"
require_file "integrations/whmcs/modules/servers/driveresource/lib/MetricsProvider.php"

echo "Checking fresh-install and upgrade schema parity..."
grep -q 'NAME="providerassetid"' db/install.xml || fail "providerassetid is missing from install.xml."
grep -q 'NAME="provideruploadid"' db/install.xml || fail "provideruploadid is missing from install.xml."
grep -q 'NAME="providerfilesize"' db/install.xml || fail "providerfilesize is missing from install.xml."
grep -q 'NAME="providerstatus"' db/install.xml || fail "providerstatus is missing from install.xml."
grep -q 'NAME="providerasset_idx"' db/install.xml || fail "providerasset_idx is missing from install.xml."
grep -q '2026092103' db/upgrade.php || fail "Bunny provider upgrade savepoint is missing."
grep -q '2026092202' db/upgrade.php || fail "Progress schema repair savepoint is missing."

echo "Checking Moodle/Elearning Stream secret boundary..."
if grep -RniE 'AccessKey:|bunny_api_key|bunny_token_key' classes amd db lib.php mod_form.php settings.php view.php templates; then
    fail "A Bunny management credential identifier leaked into Moodle runtime code."
fi
grep -q "Provider credentials remain protected in the gateway" lang/en/videoplayer.php     || fail "Moodle secret-boundary language invariant is missing."
grep -q "video\.bunnycdn\.com" classes/local/gateway/upload_authorisation.php     || fail "Moodle no longer pins the TUS upload host."
if grep -Rni '/library/' classes amd/src amd/build; then
    fail "Moodle runtime contains a Bunny management API path."
fi

echo "Checking WHMCS-only provider management credentials..."
grep -q "'bunny_api_key'" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "WHMCS addon no longer owns the Bunny API key setting."
grep -q "'AccessKey: '" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "WHMCS Bunny API client authentication is missing."
grep -q 'hash_hmac' integrations/whmcs/modules/addons/driveresource_gateway/lib/RequestAuthenticator.php     || fail "Moodle-to-WHMCS HMAC verification is missing."
grep -q 'mod_driveresource_nonces' integrations/whmcs/modules/addons/driveresource_gateway/lib/RequestAuthenticator.php     || fail "Replay nonce protection is missing."

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

echo "Checking protected Elearning Stream playback..."
grep -q "function authorizePlayback" integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php     || fail "WHMCS playback authorization is missing."
grep -q "function playbackUrl" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "Provider playback signer is missing."
grep -q "hasMP4Fallback" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "MP4 fallback verification is missing."
grep -q "HS256-" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "Playback token signing is missing."
grep -q "assertPlaybackUrlAccessible" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "Signed playback preflight is missing."
grep -q "display_name" integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php     || fail "WHMCS video display-name schema is missing."
grep -q "'display_name' => \$title" integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php     || fail "WHMCS title synchronization on rename is missing."
grep -q "\$upload->display_name" integrations/whmcs/modules/servers/driveresource/lib/ClientPortal.php     || fail "WHMCS client portal does not use the synchronized display name."
grep -q "CURLOPT_RANGE => '0-0'" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "Playback preflight must remain a bounded one-byte request."
grep -q "\$status === 206" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "Playback preflight must require HTTP 206."
grep -q "Content-Range" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "Playback preflight must validate Content-Range."
grep -q "Cache Slicing" integrations/whmcs/modules/addons/driveresource_gateway/lib/BunnyClient.php     || fail "Range failure must include an actionable CDN diagnostic."
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
grep -q "mod-videoplayer-bunny-dropzone" mod_form.php     || fail "Teacher upload dropzone is missing."
grep -q "addEventListener('drop'" amd/src/bunnyupload.js     || fail "Drag-and-drop upload handling is missing."
grep -q "mod-videoplayer-bunny-progress-bytes" mod_form.php     || fail "Byte-level upload progress UI is missing."
grep -q "selectedFile" amd/src/bunnyupload.js     || fail "Selected-file upload state is missing."

# Guard video title updates behind the authenticated WHMCS reference boundary.
require_file "integrations/whmcs/modules/addons/driveresource_gateway/api/asset-rename.php"
grep -q 'renameAsset' integrations/whmcs/modules/addons/driveresource_gateway/api/asset-rename.php || fail "Rename gateway endpoint is missing."
grep -q "where('active', true)" integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php || fail "Rename reference authorization is missing."

echo "Checking managed-video lifecycle serialization..."
grep -q "'service_id', 'site_hash', 'instance_id', 'video_id'" \
    integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php \
    || fail "Asset references must remain unique per Moodle activity and provider video."

python3 - <<'PY'
from pathlib import Path

path = Path("integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php")
source = path.read_text()

def method(name, next_name):
    start = source.index(f"public function {name}")
    end = source.index(f"public function {next_name}", start)
    return source[start:end]

bind = method("bindAsset", "reconcileAsset")
reconcile = method("reconcileAsset", "renameAsset")
release = method("releaseAsset", "recordTransfer")

if "lockForUpdate()" not in bind:
    raise SystemExit("bindAsset must lock the upload row before creating a reference")
if "lockForUpdate()" not in reconcile:
    raise SystemExit("reconcileAsset must lock the upload row before restoring a reference")
if "lockForUpdate()" not in release:
    raise SystemExit("releaseAsset must lock upload/reference rows before destructive transition")
if "This activity does not own the video." not in release:
    raise SystemExit("releaseAsset must reject unknown activity/video references")
if "where('video_id', $videoId)" not in release:
    raise SystemExit("releaseAsset must bind authorization to the exact video")
if "requestedUploadId" not in release or "bound_instance_id" not in release:
    raise SystemExit("releaseAsset must support reservation proof for a never-bound Moodle activity")

moodle_release = Path("classes/task/release_bunny_asset.php").read_text()
if "uploadid" not in moodle_release:
    raise SystemExit("Moodle release task must carry the upload reservation when available")

print("Managed-video lifecycle serialization: PASS")
PY

echo "Elearning Stream/WHMCS integration invariants: PASS"
