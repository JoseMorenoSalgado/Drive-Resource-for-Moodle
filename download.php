<?php
// This file is part of Moodle - http://moodle.org/
//
// Teacher-only managed video download endpoint.

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');

use mod_videoplayer\local\access\activity_context;
use mod_videoplayer\local\provider\bunny_stream;
use mod_videoplayer\local\resource\resource_descriptor;
use mod_videoplayer\local\stream\protected_resource_service;

$cmid = required_param('id', PARAM_INT);
$activity = activity_context::require_from_cmid($cmid);
$instance = $activity->instance();
$context = $activity->context();

require_capability('mod/videoplayer:downloadvideo', $context);

if (
    ($instance->source ?? '') !== bunny_stream::SOURCE
    || empty($instance->allowteacherdownload)
) {
    throw new moodle_exception('teacherdownloadnotallowed', 'mod_videoplayer');
}

$resource = resource_descriptor::from_instance($instance, $context);
if (!$resource->is_available() || !$resource->is_bunny_stream()) {
    throw new moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
}

\core\session\manager::write_close();
\core_php_time_limit::raise(0);
while (ob_get_level()) {
    ob_end_clean();
}

(new protected_resource_service())->send($resource, $instance, 'managed', false, true);
