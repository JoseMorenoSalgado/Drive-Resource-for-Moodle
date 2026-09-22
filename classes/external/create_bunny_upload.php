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

namespace mod_videoplayer\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_videoplayer\local\whmcs_gateway_client;

/**
 * Create a short-lived direct Bunny Stream upload session through WHMCS.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class create_bunny_upload extends external_api {
    /**
     * Define parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'cmid' => new external_value(PARAM_INT, 'Existing course module ID, or 0 for a new activity', VALUE_DEFAULT, 0),
            'filename' => new external_value(PARAM_FILE, 'Original video filename'),
            'filesize' => new external_value(PARAM_INT, 'Video size in bytes'),
            'mimetype' => new external_value(PARAM_RAW_TRIMMED, 'Browser-reported MIME type'),
            'title' => new external_value(PARAM_TEXT, 'Video title'),
        ]);
    }

    /**
     * Request a TUS upload authorisation.
     *
     * @param int $courseid Course id.
     * @param int $cmid Existing module id or 0.
     * @param string $filename Original filename.
     * @param int $filesize Size in bytes.
     * @param string $mimetype MIME type.
     * @param string $title Display title.
     * @return array
     */
    public static function execute(
        int $courseid,
        int $cmid,
        string $filename,
        int $filesize,
        string $mimetype,
        string $title
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'filename' => $filename,
            'filesize' => $filesize,
            'mimetype' => $mimetype,
            'title' => $title,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        require_login($course);
        $coursecontext = \context_course::instance($course->id);
        self::validate_context($coursecontext);
        require_capability('mod/videoplayer:uploadvideo', $coursecontext);

        if (empty($USER->id) || isguestuser()) {
            throw new \moodle_exception('guestsarenotallowed', 'error');
        }

        if ($params['cmid'] > 0) {
            $cm = get_coursemodule_from_id('videoplayer', $params['cmid'], $course->id, false, MUST_EXIST);
            require_capability('mod/videoplayer:edit', \context_module::instance($cm->id));
        }

        if ($params['filesize'] <= 0 || $params['filesize'] > 1099511627776) {
            throw new \moodle_exception('bunnyuploadinvalidsize', 'mod_videoplayer');
        }

        $mimetype = strtolower(trim($params['mimetype']));
        if ($mimetype !== '' && strpos($mimetype, 'video/') !== 0 && $mimetype !== 'application/octet-stream') {
            throw new \moodle_exception('bunnyuploadinvalidtype', 'mod_videoplayer');
        }

        $result = (new whmcs_gateway_client())->create_upload([
            'courseid' => $course->id,
            'cmid' => $params['cmid'],
            'userid' => (int)$USER->id,
            'filename' => $params['filename'],
            'filesize' => $params['filesize'],
            'mimetype' => $mimetype,
            'title' => $params['title'],
        ]);

        return [
            'uploadid' => $result['uploadid'],
            'videoid' => $result['videoid'],
            'libraryid' => $result['libraryid'],
            'endpoint' => $result['endpoint'],
            'signature' => $result['signature'],
            'expiration' => $result['expiration'],
            'quota' => $result['quota'],
        ];
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
            'quota' => new external_single_structure([
                'includedbytes' => new external_value(PARAM_INT, 'Included storage quota in bytes'),
                'usedbytes' => new external_value(PARAM_INT, 'Accounted storage usage in bytes'),
                'reservedbytes' => new external_value(PARAM_INT, 'Pending upload reservations in bytes'),
                'projectedbytes' => new external_value(PARAM_INT, 'Projected usage after this upload'),
                'overagebytes' => new external_value(PARAM_INT, 'Projected billable overage in bytes'),
                'overageallowed' => new external_value(PARAM_BOOL, 'Whether the WHMCS plan permits overage'),
            ]),
        ]);
    }
}
