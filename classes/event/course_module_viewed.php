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

namespace mod_videoplayer\event;

/**
 * Event triggered when a videoplayer activity is viewed.
 *
 * @package    mod_videoplayer
 * @copyright  2025 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_module_viewed extends \core\event\course_module_viewed {

    /**
     * Initialise event data.
     */
    protected function init(): void {
        parent::init();
        $this->data['objecttable'] = 'videoplayer';
    }

    /**
     * Return object mapping information.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'videoplayer', 'restore' => 'videoplayer'];
    }
}
