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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_videoplayer\local\access\activity_context;
use mod_videoplayer\local\progress\progress_service;

/**
 * External API for saving Drive Resource progress.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class save_progress extends external_api {
    /**
     * Define parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'progress' => new external_value(PARAM_FLOAT, 'Generic progress value', VALUE_DEFAULT, 0),
            'completed' => new external_value(PARAM_BOOL, 'Client completion hint', VALUE_DEFAULT, false),
            'completionpercentage' => new external_value(PARAM_FLOAT, 'Completion percentage from 0 to 100', VALUE_DEFAULT, 0),
            'lastpage' => new external_value(PARAM_INT, 'Last read PDF page', VALUE_DEFAULT, 0),
            'totalpages' => new external_value(PARAM_INT, 'Total PDF pages', VALUE_DEFAULT, 0),
            'timespent' => new external_value(PARAM_INT, 'Cumulative active time in seconds', VALUE_DEFAULT, 0),
            'lastposition' => new external_value(PARAM_FLOAT, 'Current media position in seconds', VALUE_DEFAULT, 0),
            'duration' => new external_value(PARAM_FLOAT, 'Media duration in seconds', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Save progress.
     *
     * @param int $cmid
     * @param float $progress
     * @param bool $completed
     * @param float $completionpercentage
     * @param int $lastpage
     * @param int $totalpages
     * @param int $timespent
     * @param float $lastposition
     * @param float $duration
     * @return array
     */
    public static function execute(
        int $cmid,
        float $progress = 0,
        bool $completed = false,
        float $completionpercentage = 0,
        int $lastpage = 0,
        int $totalpages = 0,
        int $timespent = 0,
        float $lastposition = 0,
        float $duration = 0
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'progress' => $progress,
            'completed' => $completed,
            'completionpercentage' => $completionpercentage,
            'lastpage' => $lastpage,
            'totalpages' => $totalpages,
            'timespent' => $timespent,
            'lastposition' => $lastposition,
            'duration' => $duration,
        ]);

        $activity = activity_context::require_from_cmid((int)$params['cmid'], 'mod/videoplayer:view', false);
        self::validate_context($activity->context());

        if (isguestuser() || empty($USER->id)) {
            throw new \moodle_exception('guestsarenotallowed', 'error');
        }

        return (new progress_service())->save_progress(
            $activity->cm(),
            $activity->course(),
            $activity->instance(),
            $activity->context(),
            (int)$USER->id,
            $params
        );
    }

    /**
     * Define return values.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Operation status'),
            'completed' => new external_value(PARAM_BOOL, 'Completion state'),
            'progress' => new external_value(PARAM_FLOAT, 'Saved generic progress'),
            'completionpercentage' => new external_value(PARAM_FLOAT, 'Saved completion percentage'),
            'lastpage' => new external_value(PARAM_INT, 'Last read page'),
            'totalpages' => new external_value(PARAM_INT, 'Total pages'),
            'timespent' => new external_value(PARAM_INT, 'Active time spent'),
            'lastposition' => new external_value(PARAM_FLOAT, 'Saved media position'),
            'duration' => new external_value(PARAM_FLOAT, 'Saved media duration'),
            'points' => new external_value(PARAM_INT, 'Total points'),
            'rewards' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Reward key'),
                'label' => new external_value(PARAM_TEXT, 'Reward label'),
                'points' => new external_value(PARAM_INT, 'Awarded points'),
            ])),
            'timemodified' => new external_value(PARAM_INT, 'Last modification timestamp'),
        ]);
    }
}
