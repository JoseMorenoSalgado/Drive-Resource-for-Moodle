<?php
// This file is part of Moodle - http://moodle.org/

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
