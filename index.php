<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Course index page for mod_videoplayer.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_videoplayer\local\drive;

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

$instanceids = array_values(array_unique(array_map(
    static fn($cm): int => (int)$cm->instance,
    $cms
)));
$instances = $instanceids ? $DB->get_records_list('videoplayer', 'id', $instanceids) : [];

foreach ($cms as $cm) {
    if (!$cm->uservisible) {
        continue;
    }

    $context = context_module::instance($cm->id);
    if (!has_capability('mod/videoplayer:view', $context)) {
        continue;
    }

    $instance = $instances[$cm->instance] ?? null;
    if (!$instance) {
        continue;
    }

    $type = empty($instance->type) ? drive::TYPE_AUTO : clean_param($instance->type, PARAM_ALPHANUMEXT);
    if ($type === drive::TYPE_AUTO) {
        $type = drive::detect_type((string)($instance->videourl ?? ''));
    }
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
