<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Authorised protected resource delivery endpoint.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');

use mod_videoplayer\local\drive;
use mod_videoplayer\local\http_range_proxy;
use mod_videoplayer\local\protected_stream;
use mod_videoplayer\task\precache_pdf;

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('videoplayer', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$videoplayer = $DB->get_record('videoplayer', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/videoplayer:view', $context);

$filename = clean_filename(format_string($videoplayer->name, true, ['context' => $context]));
if ($filename === '') {
    $filename = 'drive-resource';
}

\core\session\manager::write_close();
@set_time_limit(0);
while (ob_get_level()) {
    ob_end_clean();
}

if (($videoplayer->source ?? 'googledrive') === 'localpdf') {
    $file = videoplayer_get_localpdf_file($context);
    if (!$file) {
        throw new moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
    }
    if (!preg_match('/\.pdf$/i', $filename)) {
        $filename .= '.pdf';
    }

    protected_stream::send_stored_pdf($file, $filename);
}

$fileid = drive::extract_file_id($videoplayer->videourl ?? '');
if (!$fileid) {
    throw new moodle_exception('invaliddriveurl', 'mod_videoplayer');
}

$type = drive::resolve_record_type($videoplayer);

if (!drive::is_pdf_type($type) && !get_config('mod_videoplayer', 'protectedmode')) {
    throw new moodle_exception('protectedmodedisabled', 'mod_videoplayer');
}

$url = drive::protected_content_url($videoplayer->videourl, $fileid, $type);
if (!$url) {
    throw new moodle_exception('unsupportedprotectedresource', 'mod_videoplayer');
}

$contenttype = drive::default_mimetype($type);
if (drive::is_pdf_type($type) && !preg_match('/\.pdf$/i', $filename)) {
    $filename .= '.pdf';
} else if ($type === 'video' && !preg_match('/\.(mp4|webm|m4v|mov)$/i', $filename)) {
    $filename .= '.mp4';
}

$cachestatus = 'BYPASS';
$pdfcache = drive::is_pdf_type($type) && (string)get_config('mod_videoplayer', 'pdfcacheenabled') !== '0';
if ($pdfcache) {
    $cachekey = protected_stream::cache_key($fileid, $type);
    $cachefile = protected_stream::cache_file_for($fileid, $type);

    if (protected_stream::is_fresh_pdf_cache($cachefile)) {
        protected_stream::send_file(
            $cachefile,
            $filename,
            $contenttype,
            $cachekey,
            filemtime($cachefile) ?: time(),
            'HIT'
        );
    }

    // First-byte latency is more important than synchronously filling the
    // complete PDF cache. Stream the requested range immediately and warm the
    // full cache in Moodle cron. Duplicate task suppression avoids queue churn
    // when PDF.js opens several ranges in parallel.
    try {
        $task = new precache_pdf();
        $task->set_component('mod_videoplayer');
        $task->set_custom_data(['instanceid' => (int)$videoplayer->id]);
        \core\task\manager::queue_adhoc_task($task, true);
        $cachestatus = 'MISS_QUEUED';
    } catch (Throwable $exception) {
        debugging('Drive Resource PDF cache task queue failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
        $cachestatus = 'MISS';
    }
}

http_range_proxy::proxy($url, $filename, $contenttype, $cachestatus);
