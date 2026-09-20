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
 * Restore steps for mod_videoplayer.
 *
 * @package    mod_videoplayer
 * @category   backup
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


use mod_videoplayer\local\drive;

/**
 * Restore structure step for the videoplayer activity.
 */
class restore_videoplayer_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define restore paths.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('videoplayer', '/activity/videoplayer');

        if ($userinfo) {
            $paths[] = new restore_path_element('videoplayer_view', '/activity/videoplayer/views/view');
            $paths[] = new restore_path_element('videoplayer_reward', '/activity/videoplayer/rewards/reward');
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the activity instance.
     *
     * @param array|stdClass $data
     */
    protected function process_videoplayer($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        unset($data->id);

        $data->course = $this->get_courseid();

        $source = clean_param(
            (string)($data->source ?? drive::SOURCE_GOOGLEDRIVE),
            PARAM_ALPHANUMEXT
        );
        $data->source = in_array(
            $source,
            [drive::SOURCE_GOOGLEDRIVE, drive::SOURCE_LOCALPDF],
            true
        ) ? $source : drive::SOURCE_GOOGLEDRIVE;

        $type = clean_param((string)($data->type ?? drive::TYPE_AUTO), PARAM_ALPHANUMEXT);
        if ($data->source === drive::SOURCE_LOCALPDF) {
            $data->type = 'pdf';
        } else {
            $data->type = drive::is_supported_configured_type($type) ? $type : drive::TYPE_AUTO;
        }

        $data->displaymode = 'standard';
        $data->disabledownload = 1;
        if (!isset($data->disablecontextmenu)) {
            $data->disablecontextmenu = 1;
        }
        if (!isset($data->enablewatermark)) {
            $data->enablewatermark = 0;
        }
        if (!isset($data->enablegamification)) {
            $data->enablegamification = 0;
        }
        if (!isset($data->pointsperpage)) {
            $data->pointsperpage = 1;
        }
        if (!isset($data->completionpercentage) || $data->completionpercentage === '') {
            $data->completionpercentage = 80;
        }
        if ($data->source === drive::SOURCE_LOCALPDF) {
            $data->videourl = '';
        }

        $newitemid = $DB->insert_record('videoplayer', $data);
        $this->set_mapping('videoplayer', $oldid, $newitemid, true);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restore user view/progress records.
     *
     * @param array|stdClass $data
     */
    protected function process_videoplayer_view($data) {
        global $DB;

        $data = (object) $data;
        unset($data->id);
        $data->videoplayerid = $this->get_new_parentid('videoplayer');
        $data->userid = $this->get_mappingid('user', $data->userid);

        if (empty($data->userid)) {
            return;
        }

        $data->lastpage = $data->lastpage ?? 0;
        $data->totalpages = $data->totalpages ?? 0;
        $data->timespent = $data->timespent ?? 0;
        $data->lastposition = $data->lastposition ?? 0;
        $data->duration = $data->duration ?? 0;
        $data->points = $data->points ?? 0;

        $DB->insert_record('videoplayer_views', $data);
    }

    /**
     * Restore user gamification rewards.
     *
     * @param array|stdClass $data
     */
    protected function process_videoplayer_reward($data) {
        global $DB;

        $data = (object) $data;
        unset($data->id);
        $data->videoplayerid = $this->get_new_parentid('videoplayer');
        $data->userid = $this->get_mappingid('user', $data->userid);

        if (empty($data->userid)) {
            return;
        }

        $DB->insert_record('videoplayer_rewards', $data);
    }

    /**
     * Run after restore execution.
     */
    protected function after_execute() {
        $this->add_related_files('mod_videoplayer', 'intro', null);
        $this->add_related_files('mod_videoplayer', 'localpdf', null);
    }
}
