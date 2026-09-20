<?php
// This file is part of Moodle - http://moodle.org/.
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Progress report for mod_videoplayer.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoplayer\local\drive;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('videoplayer', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$videoplayer = $DB->get_record('videoplayer', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/videoplayer:viewreport', $context);

$PAGE->set_url('/mod/videoplayer/report.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('progressreport', 'mod_videoplayer'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->navbar->add(format_string($videoplayer->name), new moodle_url('/mod/videoplayer/view.php', ['id' => $cm->id]));
$PAGE->navbar->add(get_string('progressreport', 'mod_videoplayer'));

$type = drive::resolve_record_type($videoplayer);

$ispdf = drive::is_pdf_type($type);
$isvideo = $type === 'video';

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('progressreport', 'mod_videoplayer') . ': ' . format_string($videoplayer->name));

$table = new flexible_table('mod-videoplayer-report-' . $cm->id);
$table->define_columns([
    'fullname',
    'email',
    'progress',
    'completionpercentage',
    'completed',
    'lastposition',
    'timespent',
    'timemodified',
]);
$table->define_headers([
    get_string('fullnameuser'),
    get_string('email'),
    get_string('progress', 'mod_videoplayer'),
    get_string('completionpercentage', 'mod_videoplayer'),
    get_string('completed', 'completion'),
    get_string('lastposition', 'mod_videoplayer'),
    get_string('timespent', 'mod_videoplayer'),
    get_string('lastmodified'),
]);
$table->define_baseurl($PAGE->url);
$table->sortable(true, 'fullname', SORT_ASC);
$table->collapsible(false);
$table->set_attribute('class', 'generaltable generalbox mod-videoplayer-report');
$table->setup();

$userfields = user_picture::fields('u', ['email']);
$sql = "SELECT {$userfields}, vv.progress, vv.completionpercentage, vv.completed,
               vv.lastpage, vv.totalpages, vv.visitedpages, vv.lastsecond,
               vv.totalseconds, vv.timespent, vv.timemodified
          FROM {videoplayer_views} vv
          JOIN {user} u ON u.id = vv.userid
         WHERE vv.videoplayerid = :videoplayerid
      ORDER BY u.lastname ASC, u.firstname ASC";
$records = $DB->get_records_sql($sql, ['videoplayerid' => $videoplayer->id]);

if (!$records) {
    echo $OUTPUT->notification(get_string('noprogressrecords', 'mod_videoplayer'), 'info');
} else {
    foreach ($records as $record) {
        $user = (object) $record;
        $profileurl = new moodle_url('/user/view.php', ['id' => $record->id, 'course' => $course->id]);
        $completed = !empty($record->completed)
            ? html_writer::span(get_string('yes'), 'badge badge-success bg-success')
            : html_writer::span(get_string('no'), 'badge badge-secondary bg-secondary');

        $progresslabel = format_time((int) round((float) $record->progress));
        $lastposition = '-';

        if ($ispdf) {
            $visitedpages = json_decode((string) ($record->visitedpages ?? ''), true);
            $validpages = [];
            if (is_array($visitedpages)) {
                foreach ($visitedpages as $page) {
                    $page = (int) $page;
                    if ($page > 0) {
                        $validpages[$page] = true;
                    }
                }
            }
            $progresslabel = get_string('pagesviewed', 'mod_videoplayer', count($validpages));
            if ((int) $record->totalpages > 0) {
                $lastposition = get_string('pageposition', 'mod_videoplayer', (object) [
                    'page' => (int) $record->lastpage,
                    'total' => (int) $record->totalpages,
                ]);
            }
        } else if ($isvideo) {
            $progresslabel = get_string(
                'secondswatched',
                'mod_videoplayer',
                format_float((float) $record->progress, 2)
            );
            if ((float) $record->totalseconds > 0) {
                $lastposition = get_string('secondposition', 'mod_videoplayer', (object) [
                    'second' => format_float((float) $record->lastsecond, 2),
                    'total' => format_float((float) $record->totalseconds, 2),
                ]);
            }
        }

        $table->add_data([
            html_writer::link($profileurl, fullname($user)),
            s($record->email),
            $progresslabel,
            format_float((float) $record->completionpercentage, 2) . '%',
            $completed,
            $lastposition,
            format_time((int) $record->timespent),
            userdate($record->timemodified),
        ]);
    }
    $table->finish_output();
}

echo html_writer::div(
    html_writer::link(
        new moodle_url('/mod/videoplayer/view.php', ['id' => $cm->id]),
        get_string('backtoresource', 'mod_videoplayer'),
        ['class' => 'btn btn-secondary mt-3']
    ),
    'mod-videoplayer-report-actions'
);

echo $OUTPUT->footer();
