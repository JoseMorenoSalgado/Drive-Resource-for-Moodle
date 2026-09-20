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
 * Authenticated protected resource endpoint.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');

use mod_videoplayer\local\access\activity_context;
use mod_videoplayer\local\resource\resource_descriptor;
use mod_videoplayer\local\stream\protected_resource_service;

$cmid = required_param('id', PARAM_INT);
$streammode = optional_param('stream', 'auto', PARAM_ALPHA);

$activity = activity_context::require_from_cmid($cmid);
$resource = resource_descriptor::from_instance($activity->instance(), $activity->context());

// Streaming responses can live for minutes. Release the PHP session lock before
// connecting to Google so the same learner can continue navigating Moodle.
\core\session\manager::write_close();
\core_php_time_limit::raise(0);
while (ob_get_level()) {
    ob_end_clean();
}

(new protected_resource_service())->send($resource, $activity->instance(), $streammode);
