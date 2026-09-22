<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_videoplayer\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_videoplayer\local\whmcs_gateway_client;

/**
 * Refresh a short-lived direct Bunny Stream upload authorization.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class refresh_bunny_upload extends external_api {
    /**
     * Define parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'cmid' => new external_value(PARAM_INT, 'Existing course module ID, or 0 for a new activity', VALUE_DEFAULT, 0),
            'uploadid' => new external_value(PARAM_ALPHANUMEXT, 'WHMCS upload reservation identifier'),
            'videoid' => new external_value(PARAM_ALPHANUMEXT, 'Bunny Stream video GUID'),
        ]);
    }

    /**
     * Refresh the direct-upload signature.
     *
     * @param int $courseid Course id.
     * @param int $cmid Existing module id or 0.
     * @param string $uploadid WHMCS upload reservation.
     * @param string $videoid Bunny video GUID.
     * @return array
     */
    public static function execute(int $courseid, int $cmid, string $uploadid, string $videoid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'uploadid' => $uploadid,
            'videoid' => $videoid,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        require_login($course);
        $coursecontext = \context_course::instance($course->id);
        self::validate_context($coursecontext);
        require_capability('mod/videoplayer:uploadvideo', $coursecontext);

        if ($params['cmid'] > 0) {
            $cm = get_coursemodule_from_id('videoplayer', $params['cmid'], $course->id, false, MUST_EXIST);
            require_capability('mod/videoplayer:edit', \context_module::instance($cm->id));
        }

        return (new whmcs_gateway_client())->refresh_upload($params['uploadid'], $params['videoid']);
    }

    /**
     * Define return values.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'uploadid' => new external_value(PARAM_ALPHANUMEXT, 'WHMCS upload reservation identifier'),
            'videoid' => new external_value(PARAM_ALPHANUMEXT, 'Bunny Stream video GUID'),
            'libraryid' => new external_value(PARAM_RAW_TRIMMED, 'Bunny Stream library identifier'),
            'endpoint' => new external_value(PARAM_URL, 'Bunny TUS endpoint'),
            'signature' => new external_value(PARAM_ALPHANUM, 'Short-lived presigned upload signature'),
            'expiration' => new external_value(PARAM_INT, 'Signature expiration Unix timestamp'),
        ]);
    }
}
