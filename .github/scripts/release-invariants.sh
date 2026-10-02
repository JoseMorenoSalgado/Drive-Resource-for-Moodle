#!/usr/bin/env bash
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

fail() {
    echo "::error::$1"
    exit 1
}

require_file() {
    local path="$1"
    [[ -s "$path" ]] || fail "Required production file is missing or empty: $path"
}

echo "Checking required production assets..."
require_file "thirdpartylibs/pdfjs/pdf.min.mjs"
require_file "thirdpartylibs/pdfjs/pdf.worker.min.mjs"
require_file "amd/build/nativevideo.min.js"
require_file "amd/build/pdfviewer.min.js"
require_file "amd/build/pdfviewer.min.js.map"
require_file "protected.php"

echo "Checking retired Google runtime isolation..."
if grep -RniE 'drive\.google\.com|docs\.google\.com|googleusercontent\.com|googlevideo\.com|content-workspacevideo-pa\.googleapis\.com' \
    classes amd/src templates mod_form.php lib.php protected.php view.php; then
    fail "Production Moodle runtime contains a retired Google upstream host."
fi
if grep -RniE 'drive::(extract_file_id|is_supported_url|protected_content_url|resolve_download_warning_url)|drive_stream_resolver' \
    classes amd/src templates mod_form.php lib.php protected.php view.php; then
    fail "Production Moodle runtime contains retired Google resolver logic."
fi

if grep -RniE 'b-cdn\.net|mediadelivery\.net' templates amd/src/nativevideo.js; then
    fail "Learner-facing video code contains an Elearning Stream upstream host."
fi

echo "Checking that retired remote/iframe viewers cannot re-enter presentation code..."
if grep -RniE "<iframe|/preview([?\"'[:space:]]|$)" templates amd/src; then
    fail "Browser-facing presentation code contains an iframe/preview viewer path."
fi

echo "Checking removed player/CDN dependencies..."
if grep -RniE     'cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com|unpkg\.com|plyr\.js|video\.js'     templates amd/src; then
    fail "A removed player or CDN dependency reappeared in browser-facing code."
fi

echo "Checking protected endpoint security boundary..."
grep -q 'activity_context::require_from_cmid' protected.php     || fail "protected.php no longer enforces the central activity access boundary."
grep -q 'session\\manager::write_close' protected.php     || fail "protected.php no longer releases the Moodle session lock before streaming."

if grep -nE 'required_param\([^,]+,[[:space:]]*PARAM_URL|optional_param\([^,]+,[^,]+,[[:space:]]*PARAM_URL' protected.php; then
    fail "protected.php accepts a browser-supplied arbitrary URL."
fi

echo "Checking upstream redirect confinement..."
if grep -n "CURLOPT_FOLLOWLOCATION => true" \
    classes/local/http_range_proxy.php \
    classes/local/protected_stream.php; then
    fail "Automatic cURL redirect following bypasses per-hop upstream allow-list validation."
fi

echo "Checking byte-range and stall-resilience invariants..."
grep -q 'CURLOPT_RANGE' classes/local/http_range_proxy.php     || fail "HTTP proxy no longer forwards byte ranges with CURLOPT_RANGE."
grep -q "Accept-Ranges: bytes" classes/local/http_range_proxy.php     || fail "HTTP proxy no longer exposes byte-range support."
grep -q 'CURLOPT_LOW_SPEED_LIMIT' classes/local/http_range_proxy.php     || fail "HTTP proxy no longer bounds effectively stalled upstream transfers."
grep -q 'connection_aborted()' classes/local/http_range_proxy.php     || fail "HTTP proxy no longer stops abandoned browser range requests."
grep -q 'MAX_RECOVERY_ATTEMPTS' amd/src/nativevideo.js     || fail "Video recovery is no longer bounded."
grep -q 'pendingSeekTarget' amd/src/nativevideo.js     || fail "Protected mobile seek no longer batches slider input before Range navigation."
grep -q 'commitSeek' amd/src/nativevideo.js     || fail "Protected mobile seek commit logic is missing."
grep -q 'desiredSeekPosition' amd/src/nativevideo.js     || fail "Protected seek recovery no longer preserves the requested second."
grep -q "addEventListener('pointerup'" amd/src/nativevideo.js     || fail "Touch seek no longer commits on pointer release."
if grep -q 'mod-videoplayer-meta' templates/video.mustache; then
    fail "Learner video view must not render distracting metadata chips."
fi
grep -q "searchParams.set('refresh', '1')" amd/src/nativevideo.js     || fail "Video recovery no longer refreshes the protected signed stream."
grep -q 'watchedranges' amd/src/nativevideo.js     || fail "Video completion no longer submits watched ranges."
grep -q 'playback_url' classes/local/stream/protected_resource_service.php     || fail "Elearning Stream playback no longer resolves through WHMCS."
grep -q 'http_range_proxy::proxy' classes/local/stream/protected_resource_service.php     || fail "Managed stream playback bypasses the protected byte proxy."
grep -q 'streamplayback' db/caches.php     || fail "Managed stream playback cache is missing."
grep -q 'watched_range_set' classes/local/progress/progress_service.php     || fail "Server completion no longer validates watched ranges."
grep -q 'watchedranges' db/install.xml     || fail "Watched-range persistence is missing from XMLDB."
grep -q '2026092204' db/upgrade.php     || fail "Critical beta schema-repair savepoint is missing from upgrade.php."
grep -q 'completionprogressenabled' db/upgrade.php     || fail "Completion schema recovery is missing from upgrade.php."
grep -q "get_columns('videoplayer')" lib.php     || fail "Course cache is not resilient to partial beta schemas."
if grep -A12 "new xmldb_field('watchedranges'" db/upgrade.php | grep -q "'duration'"; then
    fail "watchedranges DDL must not depend on AFTER duration column ordering."
fi

echo "Checking resource compatibility boundary..."
grep -q "no longer parses, resolves or generates Google" classes/local/drive.php \
    || fail "Historical resource helper no longer documents the retired provider boundary."
grep -q "fail closed" classes/local/resource/resource_descriptor.php \
    || fail "Legacy remote resources must fail closed."

echo "Checking Moodle 4.5 completion form API compatibility..."
if grep -n 'get_suffixed_name' mod_form.php; then
    fail "mod_form.php uses get_suffixed_name(), which does not exist in Moodle 4.5 moodleform_mod."
fi
grep -q 'get_suffix()' mod_form.php     || fail "Custom completion controls no longer use Moodle 4.5 get_suffix()."

echo "Checking release metadata..."
grep -q "\$plugin->supported = \[405, 405\]" version.php     || fail "Moodle 4.5 support declaration changed unexpectedly."
grep -q 'MATURITY_RC' version.php     || fail "The Moodle release candidate must declare RC maturity."

echo "Elearning Stream release invariants: PASS"
