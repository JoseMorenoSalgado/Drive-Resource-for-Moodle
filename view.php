<?php
// This file is part of Moodle - https://moodle.org/
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
 * Learner-facing Drive Resource activity page.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');

use mod_videoplayer\local\drive;

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('videoplayer', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$videoplayer = $DB->get_record('videoplayer', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/videoplayer:view', $context);

$PAGE->set_url('/mod/videoplayer/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($videoplayer->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->requires->css('/mod/videoplayer/styles_activity.css');
$PAGE->requires->css('/mod/videoplayer/styles_visual_refinements.css');

$event = \mod_videoplayer\event\course_module_viewed::create([
    'objectid' => $videoplayer->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('videoplayer', $videoplayer);
$event->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$source = $videoplayer->source ?? 'googledrive';
$type = 'pdf';
$protectedurl = new moodle_url('/mod/videoplayer/protected.php', [
    'id' => $cm->id,
    'v' => $videoplayer->timemodified ?? time(),
]);

if ($source === 'localpdf') {
    $localpdffile = videoplayer_get_localpdf_file($context);
    if (!$localpdffile) {
        echo $OUTPUT->header();
        echo html_writer::div(get_string('protectedresourceunavailable', 'mod_videoplayer'), 'alert alert-danger');
        echo $OUTPUT->footer();
        exit;
    }
    $protectedurl->param('fh', substr($localpdffile->get_contenthash(), 0, 12));
} else {
    $fileid = drive::extract_file_id($videoplayer->videourl ?? '');
    if (!$fileid) {
        echo $OUTPUT->header();
        if (!empty($videoplayer->intro)) {
            echo $OUTPUT->box(
                format_module_intro('videoplayer', $videoplayer, $cm->id),
                'generalbox mod_introbox',
                'videoplayerintro'
            );
        }
        echo html_writer::div(get_string('invaliddriveurl', 'mod_videoplayer'), 'alert alert-danger');
        echo $OUTPUT->footer();
        exit;
    }

    $type = drive::resolve_record_type($videoplayer);
}

$ispdfcompatible = drive::is_pdf_type($type);
$isvideo = $type === 'video';
$isimage = $type === 'image';
$trackingenabled = (string) get_config('mod_videoplayer', 'enabletracking') !== '0';

$typestringkey = 'type' . $type;
$typestring = get_string_manager()->string_exists($typestringkey, 'mod_videoplayer')
    ? get_string($typestringkey, 'mod_videoplayer')
    : get_string('typefile', 'mod_videoplayer');

$progressrecord = null;
if (!isguestuser() && $trackingenabled) {
    $progressrecord = $DB->get_record('videoplayer_views', [
        'videoplayerid' => $videoplayer->id,
        'userid' => $USER->id,
    ]);
}

$initialprogress = $progressrecord ? (float) $progressrecord->progress : 0;
$initialtimespent = $progressrecord ? (int) ($progressrecord->timespent ?? 0) : 0;
$completed = $progressrecord ? (bool) $progressrecord->completed : false;
$configuredrequiredseconds = get_config('mod_videoplayer', 'defaultrequiredseconds');
$requiredseconds = $configuredrequiredseconds === false
    ? 300
    : max(60, (int) $configuredrequiredseconds);
$displaymode = videoplayer_get_safe_pdf_displaymode($videoplayer->displaymode ?? null);
$showresourcetype = (string) get_config('mod_videoplayer', 'showresourcetype') !== '0';

if (!isguestuser() && $trackingenabled && !$ispdfcompatible && !$isvideo) {
    $PAGE->requires->js_call_amd('mod_videoplayer/progress', 'init', [[
        'cmid' => $cm->id,
        'requiredSeconds' => $requiredseconds,
        'interval' => 30000,
        'initialProgress' => $initialprogress,
        'completed' => $completed,
    ]]);
}

if ($ispdfcompatible) {
    $PAGE->requires->css('/mod/videoplayer/styles_pdf_overlay.css');
    $PAGE->requires->css('/mod/videoplayer/styles_pdf_mobile.css');
    $PAGE->requires->js_call_amd('mod_videoplayer/pdfviewer', 'init');
} else if ($isvideo) {
    $PAGE->requires->css('/mod/videoplayer/thirdpartylibs/plyr/plyr.css');
    $PAGE->requires->js_call_amd('mod_videoplayer/plyr', 'init');
}

$playerstyle = '';
if (get_config('mod_videoplayer', 'playercolormode') === 'custom') {
    $playercolor = trim((string) get_config('mod_videoplayer', 'playercolor'));
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $playercolor)) {
        $playerstyle = '--mod-videoplayer-player-color: ' . $playercolor . ';';
    }
}

$initialpage = $progressrecord && !empty($progressrecord->lastpage)
    ? max(1, (int) $progressrecord->lastpage)
    : 1;
$totalpages = $progressrecord && !empty($progressrecord->totalpages)
    ? max(0, (int) $progressrecord->totalpages)
    : 0;
$visitedpages = $progressrecord && !empty($progressrecord->visitedpages)
    ? (string) $progressrecord->visitedpages
    : '[]';
$lastsecond = $progressrecord ? max(0, (float) ($progressrecord->lastsecond ?? 0)) : 0;
$totalseconds = $progressrecord ? max(0, (float) ($progressrecord->totalseconds ?? 0)) : 0;
$watchedranges = $progressrecord && !empty($progressrecord->watchedranges)
    ? (string) $progressrecord->watchedranges
    : '[]';
$points = $progressrecord && !empty($progressrecord->points) ? (int) $progressrecord->points : 0;
$completionpercent = $progressrecord ? (float) $progressrecord->completionpercentage : 0;
$watermark = fullname($USER) . ' · ' . userdate(time(), get_string('strftimedatetimeshort', 'langconfig'));

$templatecontext = [
    'type' => $type,
    'source' => $source,
    'cmid' => $cm->id,
    'trackingcmid' => $trackingenabled && !isguestuser() ? $cm->id : 0,
    'trackingenabled' => $trackingenabled,
    'resourcetype' => get_string('resourcetype', 'mod_videoplayer') . ': ' . $typestring,
    'showresourcetype' => $showresourcetype,
    'pdfurl' => $protectedurl->out(false),
    'videourl' => $protectedurl->out(false),
    'imageurl' => $protectedurl->out(false),
    // Do not declare a guessed MIME type on the HTML source. Google Drive can
    // store MP4, WebM, MOV and M4V resources under the same activity type.
    // protected.php validates and relays the actual upstream Content-Type, so
    // the browser must negotiate against that response instead of trusting an
    // incorrect hard-coded video/mp4 hint.
    'videomimetype' => '',
    'videoerrorcodec' => get_string('videoerrorcodec', 'mod_videoplayer'),
    'videoerrornetwork' => get_string('videoerrornetwork', 'mod_videoplayer'),
    'videoerrorgeneric' => get_string('videoerrorgeneric', 'mod_videoplayer'),
    'title' => format_string($videoplayer->name),
    'playerstyle' => $playerstyle,
    'displaymode' => $displaymode,
    'ebookmode' => false,
    'bookmode' => false,
    'disabledownload' => !empty($videoplayer->disabledownload),
    'disablecontextmenu' => !empty($videoplayer->disablecontextmenu),
    'enablewatermark' => !empty($videoplayer->enablewatermark),
    'enablegamification' => !empty($videoplayer->enablegamification),
    'pointsperpage' => (int) ($videoplayer->pointsperpage ?? 1),
    'initialpage' => $initialpage,
    'initialprogress' => $initialprogress,
    'initialtimespent' => $initialtimespent,
    'totalpages' => $totalpages,
    'visitedpages' => $visitedpages,
    'lastsecond' => $lastsecond,
    'totalseconds' => $totalseconds,
    'watchedranges' => $watchedranges,
    'points' => $points,
    'completionpercent' => round($completionpercent, 2),
    'watermark' => $watermark,
];

echo $OUTPUT->header();

if (!empty($videoplayer->intro)) {
    echo $OUTPUT->box(
        format_module_intro('videoplayer', $videoplayer, $cm->id),
        'generalbox mod_introbox',
        'videoplayerintro'
    );
}

if ($ispdfcompatible) {
    echo $OUTPUT->render_from_template('mod_videoplayer/pdfjs', $templatecontext);
} else if ($isvideo) {
    echo $OUTPUT->render_from_template('mod_videoplayer/video', $templatecontext);
} else if ($isimage) {
    echo $OUTPUT->render_from_template('mod_videoplayer/image', $templatecontext);
} else {
    echo html_writer::div(get_string('unsupportedprotectedresource', 'mod_videoplayer'), 'alert alert-warning');
}

echo $OUTPUT->footer();
