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

declare(strict_types=1);

namespace mod_videoplayer\completion;

use core_completion\activity_custom_completion;

/**
 * Moodle custom completion rules for Drive Resource.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class custom_completion extends activity_custom_completion {
    /** Custom completion rule key. */
    public const RULE_PROGRESS = 'completionprogress';

    /**
     * Fetch completion state for one configured custom rule.
     *
     * @param string $rule Completion rule.
     * @return int Moodle completion state.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $threshold = (int)(
            $this->cm->customdata['customcompletionrules'][self::RULE_PROGRESS] ?? 0
        );
        if ($threshold <= 0) {
            return COMPLETION_INCOMPLETE;
        }

        $percentage = (float)$DB->get_field(
            'videoplayer_views',
            'completionpercentage',
            [
                'videoplayerid' => (int)$this->cm->instance,
                'userid' => (int)$this->userid,
            ]
        );

        return $percentage >= $threshold
            ? COMPLETION_COMPLETE
            : COMPLETION_INCOMPLETE;
    }

    /**
     * Define Drive Resource custom completion rules.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return [self::RULE_PROGRESS];
    }

    /**
     * Human-readable custom completion descriptions.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        $threshold = (int)(
            $this->cm->customdata['customcompletionrules'][self::RULE_PROGRESS] ?? 0
        );

        return [
            self::RULE_PROGRESS => get_string(
                'completionprogressdesc',
                'mod_videoplayer',
                $threshold
            ),
        ];
    }

    /**
     * Order core and custom completion rules in the activity UI.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            self::RULE_PROGRESS,
        ];
    }
}
