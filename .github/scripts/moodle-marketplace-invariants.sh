#!/usr/bin/env bash
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

fail() {
    echo "::error::$1"
    exit 1
}

require_file() {
    [[ -s "$1" ]] || fail "Required Moodle Marketplace file is missing or empty: $1"
}

reject_path() {
    [[ ! -e "$1" ]] || fail "Retired runtime path must not ship: $1"
}

echo "Checking Moodle Marketplace package contract..."
require_file "version.php"
require_file "classes/privacy/provider.php"
require_file "backup/moodle2/backup_videoplayer_stepslib.php"
require_file "backup/moodle2/restore_videoplayer_stepslib.php"
require_file "thirdpartylibs.xml"
require_file "thirdpartylibs/pdfjs/readme_moodle.txt"
require_file "docs/moodle-marketplace.md"
require_file "docs/course-format-integration.md"

grep -q "\$plugin->component = 'mod_videoplayer'" version.php     || fail "Stable Moodle component identifier changed."
grep -q "\$plugin->version = 2026100203" version.php     || fail "Marketplace candidate build is not 2026100203."
grep -q "\$plugin->release = '1.3.0-rc4-m45'" version.php     || fail "Marketplace candidate release is not 1.3.0-rc4-m45."
grep -q "\$plugin->supported = \[405, 405\]" version.php     || fail "Current release must remain scoped to tested Moodle 4.5 support."

echo "Checking retired presentation features are physically absent..."
for path in     amd/src/nativeaudio.js     amd/src/progress.js     amd/src/protectedui.js     templates/audio.mustache     templates/image.mustache     templates/resource.mustache     templates/bunny_pending.mustache     classes/local/gamification/reward_service.php     classes/event/reward_awarded.php
do
    reject_path "$path"
done

if grep -RniE 'enablegamification|pointsperpage|videoplayer_rewards|eventrewardawarded'     classes lib.php mod_form.php view.php protected.php report.php backup/moodle2 db/install.xml; then
    fail "Retired gamification/runtime schema reappeared."
fi

if grep -qE 'NAME="(videourl|type|displaymode|disabledownload|enablegamification|pointsperpage|points)"' db/install.xml; then
    fail "Fresh-install XMLDB contains retired activity/progress fields."
fi

grep -q '2026100203' db/upgrade.php     || fail "rc4 cleanup upgrade savepoint is missing."
grep -q "drop_table(\$rewardstable)" db/upgrade.php     || fail "Upgrade no longer removes the retired reward table."

if grep -q "cleanup_pdf_cache'" db/tasks.php; then
    fail "Obsolete PDF cache cleanup is still registered as scheduled work."
fi
if grep -RniE 'pdf_cache_(ttl|dir)|warm_drive_pdf_cache|download_to_file' classes/local/protected_stream.php; then
    fail "Local protected delivery contains retired remote/cache responsibilities."
fi

echo "Checking runtime source boundary..."
grep -q "SOURCE_RETIRED_REMOTE = 'googledrive'" classes/local/resource_compatibility.php     || fail "Historical source migration key changed."
if grep -qE 'RESOURCE_TYPES|default_mimetype|resolve_record_type|is_supported_configured_type'     classes/local/resource_compatibility.php; then
    fail "Persisted compatibility helper regained runtime presentation behavior."
fi
grep -q 'is_managed_video' classes/local/resource/resource_descriptor.php     || fail "Managed-video descriptor boundary is missing."
grep -q 'is_pdf' classes/local/resource/resource_descriptor.php     || fail "Protected PDF descriptor boundary is missing."

echo "Checking Moodle packaging boundary..."
grep -q 'rm -rf package/videoplayer/integrations' .github/workflows/package.yml     || fail "Moodle package no longer excludes WHMCS integration code."
grep -q 'rm -rf package/videoplayer/tests' .github/workflows/package.yml     || fail "Moodle package no longer excludes test sources."
grep -q 'readme_moodle.txt' thirdpartylibs/pdfjs/readme_moodle.txt     && fail "PDF.js readme unexpectedly references itself as a requirement." || true
grep -q '<location>thirdpartylibs/pdfjs</location>' thirdpartylibs.xml     || fail "PDF.js third-party declaration location is missing."

echo "Checking future course-format isolation contract..."
grep -q 'format_elearningstream' docs/course-format-integration.md     || fail "Future course format component contract is missing."
grep -q 'get_fast_modinfo' docs/course-format-integration.md     || fail "Future course format does not declare Moodle modinfo integration."
grep -q 'must never:' docs/course-format-integration.md     || fail "Future course format forbidden-coupling contract is missing."

echo "Elearning Stream Moodle Marketplace invariants: PASS"
