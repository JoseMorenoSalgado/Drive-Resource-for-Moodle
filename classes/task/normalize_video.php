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

namespace mod_videoplayer\task;

use mod_videoplayer\local\video_normalizer;

/**
 * Ad-hoc task that normalizes incompatible Drive videos for HTML5 playback.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class normalize_video extends \core\task\adhoc_task {
    /**
     * Execute video normalization.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        @set_time_limit(0);

        $data = $this->get_custom_data();
        if (empty($data->instanceid)) {
            return;
        }

        $record = $DB->get_record('videoplayer', ['id' => (int) $data->instanceid]);
        if (!$record) {
            return;
        }

        video_normalizer::normalize($record);
    }
}
