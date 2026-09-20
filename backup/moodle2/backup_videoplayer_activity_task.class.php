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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Backup task for the videoplayer activity.
 *
 * @package   mod_videoplayer
 * @category  backup
 * @copyright 2025 Jose Erasmo Moreno Salgado
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/videoplayer/backup/moodle2/backup_videoplayer_stepslib.php');

class backup_videoplayer_activity_task extends backup_activity_task {

    protected function define_my_settings() {
        // No specific settings for this module.
    }

    protected function define_my_steps() {
        $this->add_step(new backup_videoplayer_activity_structure_step('videoplayer_structure', 'videoplayer.xml'));
    }

    static public function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, "/");

        // Link to view page.
        $search = "/(" . $base . "\/mod\/videoplayer\/view\.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@VIDEOPLAYERVIEWBYID*$2@$', $content);

        return $content;
    }
}
