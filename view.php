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
 * Learner view for Drive Resource.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Access is enforced immediately by activity_context::require_from_cmid().
// phpcs:ignore moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_videoplayer\local\access\activity_context;
use mod_videoplayer\local\resource\resource_descriptor;
use mod_videoplayer\output\resource_view;

$cmid = required_param('id', PARAM_INT);
$activity = activity_context::require_from_cmid($cmid);
$cm = $activity->cm();
$course = $activity->course();
$instance = $activity->instance();
$context = $activity->context();
$resource = resource_descriptor::from_instance($instance, $context);

$PAGE->set_url('/mod/videoplayer/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name, true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$event = \mod_videoplayer\event\course_module_viewed::create([
    'objectid' => $instance->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('videoplayer', $instance);
$event->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$progressrecord = null;
if (!isguestuser()) {
    $progressrecord = $DB->get_record('videoplayer_views', [
        'videoplayerid' => $instance->id,
        'userid' => $USER->id,
    ]) ?: null;
}

if ($resource->is_available()) {
    if ($resource->is_pdf_like()) {
        $PAGE->requires->js_call_amd('mod_videoplayer/pdfviewer', 'init');
    } else if ($resource->is_video()) {
        $PAGE->requires->js_call_amd('mod_videoplayer/nativevideo', 'init');
    } else if ($resource->is_audio()) {
        $PAGE->requires->js_call_amd('mod_videoplayer/nativeaudio', 'init');
    } else {
        $PAGE->requires->js_call_amd('mod_videoplayer/protectedui', 'init');
        if (!isguestuser() && (bool)get_config('mod_videoplayer', 'enabletracking')) {
            $requiredseconds = max(60, (int)get_config('mod_videoplayer', 'defaultrequiredseconds'));
            $PAGE->requires->js_call_amd('mod_videoplayer/progress', 'init', [[
                'cmid' => $cm->id,
                'requiredSeconds' => $requiredseconds,
                'interval' => 30000,
                'initialProgress' => (float)($progressrecord->progress ?? 0),
                'initialTimeSpent' => (int)($progressrecord->timespent ?? 0),
                'completed' => !empty($progressrecord->completed),
            ]]);
        }
    }
}

$output = $PAGE->get_renderer('mod_videoplayer');

echo $OUTPUT->header();

if (!empty($instance->intro)) {
    echo $OUTPUT->box(
        format_module_intro('videoplayer', $instance, $cm->id),
        'generalbox mod_introbox',
        'videoplayerintro'
    );
}

if (!$resource->is_available()) {
    echo $output->render_invalid_resource();
} else {
    echo $output->render_resource_view(new resource_view($activity, $resource, $progressrecord));
}

echo $OUTPUT->footer();
