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

namespace mod_videoplayer\event;

/**
 * Event triggered when a Drive Resource activity is completed.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_completed extends \core\event\base {
    /**
     * Initialise event data.
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'videoplayer_views';
    }

    /**
     * Return event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventresourcecompleted', 'mod_videoplayer');
    }

    /**
     * Return event description.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' completed Drive Resource with id '{$this->other['videoplayerid']}'.";
    }

    /**
     * Return related activity URL.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/videoplayer/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Return object mapping information.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'videoplayer_views', 'restore' => 'videoplayer_view'];
    }

    /**
     * Return other mapping information.
     *
     * @return array
     */
    public static function get_other_mapping(): array {
        return [
            'videoplayerid' => ['db' => 'videoplayer', 'restore' => 'videoplayer'],
        ];
    }
}
