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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Teacher-only protected managed-video download endpoint.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Access is enforced immediately by activity_context::require_from_cmid().
// phpcs:ignore moodle.Files.RequireLogin.Missing
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
