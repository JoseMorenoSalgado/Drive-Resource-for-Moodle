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
 * Course index page for mod_videoplayer.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);

$coursecontext = context_course::instance($course->id);
$modinfo = get_fast_modinfo($course);

$PAGE->set_url('/mod/videoplayer/index.php', ['id' => $course->id]);
$PAGE->set_title(get_string('modulenameplural', 'mod_videoplayer'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($coursecontext);
$PAGE->navbar->add(get_string('modulenameplural', 'mod_videoplayer'));

$event = \mod_videoplayer\event\course_module_instance_list_viewed::create([
    'context' => $coursecontext,
]);
$event->add_record_snapshot('course', $course);
$event->trigger();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_videoplayer'));

$cms = [];
if (isset($modinfo->instances['videoplayer'])) {
    $cms = $modinfo->instances['videoplayer'];
}

if (empty($cms)) {
    echo $OUTPUT->notification(get_string('noresources', 'mod_videoplayer'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('name'),
    get_string('resourcetype', 'mod_videoplayer'),
    get_string('sectionname', 'format_' . $course->format),
    get_string('lastmodified'),
];
$table->attributes['class'] = 'generaltable mod-videoplayer-index';

foreach ($cms as $cm) {
    if (!$cm->uservisible) {
        continue;
    }

    $context = context_module::instance($cm->id);
    if (!has_capability('mod/videoplayer:view', $context)) {
        continue;
    }

    $instance = $DB->get_record('videoplayer', ['id' => $cm->instance]);
    if (!$instance) {
        continue;
    }

    $type = empty($instance->type) ? 'auto' : clean_param($instance->type, PARAM_ALPHANUMEXT);
    $typestring = get_string_manager()->string_exists('type' . $type, 'mod_videoplayer')
        ? get_string('type' . $type, 'mod_videoplayer')
        : get_string('typefile', 'mod_videoplayer');

    $sectionname = '';
    if (isset($modinfo->sections[$cm->sectionnum])) {
        $sectioninfo = $modinfo->get_section_info($cm->sectionnum);
        $sectionname = get_section_name($course, $sectioninfo);
    }

    $table->data[] = [
        html_writer::link(new moodle_url('/mod/videoplayer/view.php', ['id' => $cm->id]), format_string($cm->name)),
        $typestring,
        format_string($sectionname),
        userdate($instance->timemodified),
    ];
}

if (empty($table->data)) {
    echo $OUTPUT->notification(get_string('noresourcesavailable', 'mod_videoplayer'), 'info');
} else {
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
