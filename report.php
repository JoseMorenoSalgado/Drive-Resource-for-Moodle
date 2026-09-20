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
 * Progress report for Drive Resource.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

use mod_videoplayer\local\access\activity_context;
use mod_videoplayer\output\progress_report_table;

$cmid = required_param('id', PARAM_INT);
$activity = activity_context::require_from_cmid($cmid, 'mod/videoplayer:viewreport');
$cm = $activity->cm();
$course = $activity->course();
$instance = $activity->instance();
$context = $activity->context();

$PAGE->set_url('/mod/videoplayer/report.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('progressreport', 'mod_videoplayer'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->navbar->add(format_string($instance->name), new moodle_url('/mod/videoplayer/view.php', ['id' => $cm->id]));
$PAGE->navbar->add(get_string('progressreport', 'mod_videoplayer'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('progressreport', 'mod_videoplayer') . ': ' . format_string($instance->name));

$table = new progress_report_table('mod-videoplayer-report-' . $cm->id, (int)$course->id);
$table->define_columns([
    'fullname',
    'email',
    'timespent',
    'lastposition',
    'completionpercentage',
    'completed',
    'timemodified',
]);
$table->define_headers([
    get_string('fullnameuser'),
    get_string('email'),
    get_string('timespent', 'mod_videoplayer'),
    get_string('lastposition', 'mod_videoplayer'),
    get_string('completionpercentage', 'mod_videoplayer'),
    get_string('completed', 'completion'),
    get_string('lastmodified'),
]);
$table->define_baseurl($PAGE->url);
$table->sortable(true, 'timemodified', SORT_DESC);
$table->no_sorting('fullname');
$table->collapsible(false);
$table->set_attribute('class', 'generaltable generalbox mod-videoplayer-report');

$userfields = user_picture::fields('u', ['email']);
$fields = "{$userfields}, vv.timespent, vv.lastposition, vv.duration, vv.completionpercentage, vv.completed, vv.timemodified";
$from = '{videoplayer_views} vv JOIN {user} u ON u.id = vv.userid';
$where = 'vv.videoplayerid = :videoplayerid';
$table->set_sql($fields, $from, $where, ['videoplayerid' => $instance->id]);
$table->out(50, false);

echo html_writer::div(
    html_writer::link(
        new moodle_url('/mod/videoplayer/view.php', ['id' => $cm->id]),
        get_string('backtoresource', 'mod_videoplayer'),
        ['class' => 'btn btn-secondary mt-3']
    ),
    'mod-videoplayer-report-actions'
);

echo $OUTPUT->footer();
