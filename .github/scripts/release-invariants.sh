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
require_file "amd/build/nativevideo.min.js.map"
require_file "amd/build/pdfviewer.min.js"
require_file "amd/build/pdfviewer.min.js.map"
require_file "protected.php"

echo "Checking browser-facing URL confidentiality..."
if grep -RniE     'drive\.google\.com|docs\.google\.com|googleusercontent\.com|googlevideo\.com|content-workspacevideo-pa\.googleapis\.com'     templates amd/src; then
    fail "Browser-facing templates/AMD source contain a Google upstream host."
fi

echo "Checking that Google/iframe viewers cannot re-enter presentation code..."
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

echo "Checking byte-range and stall-resilience invariants..."
grep -q 'CURLOPT_RANGE' classes/local/http_range_proxy.php     || fail "HTTP proxy no longer forwards byte ranges with CURLOPT_RANGE."
grep -q "Accept-Ranges: bytes" classes/local/http_range_proxy.php     || fail "HTTP proxy no longer exposes byte-range support."
grep -q 'CURLOPT_LOW_SPEED_LIMIT' classes/local/http_range_proxy.php     || fail "HTTP proxy no longer bounds effectively stalled upstream transfers."
grep -q 'connection_aborted()' classes/local/http_range_proxy.php     || fail "HTTP proxy no longer stops abandoned browser range requests."
grep -q 'MAX_RECOVERY_ATTEMPTS' amd/src/nativevideo.js     || fail "Video recovery is no longer bounded."
grep -q "searchParams.set('refresh', '1')" amd/src/nativevideo.js     || fail "Video recovery no longer refreshes the protected signed stream."

echo "Checking release metadata..."
grep -q "\$plugin->supported = \[405, 405\]" version.php     || fail "Moodle 4.5 support declaration changed unexpectedly."
grep -q 'MATURITY_RC' version.php     || fail "Hardening branch must remain release-candidate maturity until all exit gates pass."

echo "Drive Resource release invariants: PASS"
