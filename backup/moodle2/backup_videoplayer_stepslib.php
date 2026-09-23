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
 * Backup structure for mod_videoplayer.
 *
 * @package    mod_videoplayer
 * @category   backup
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Defines the complete backup structure for the videoplayer activity.
 */
class backup_videoplayer_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the backup structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $videoplayer = new backup_nested_element('videoplayer', ['id'], [
            'course',
            'name',
            'timecreated',
            'timemodified',
            'intro',
            'introformat',
            'source',
            'videourl',
            'providerassetid',
            'providerfilesize',
            'providerstatus',
            'type',
            'displaymode',
            'disabledownload',
            'disablecontextmenu',
            'enablewatermark',
            'enablegamification',
            'pointsperpage',
            'video',
            'endscreentext',
            'displayasstartscreen',
            'starttime',
            'endtime',
            'completionpercentage',
            'completionprogressenabled',
            'grade',
            'displayoptions',
            'posterimage',
            'extendedcompletion',
        ]);

        $views = new backup_nested_element('views');
        $view = new backup_nested_element('view', ['id'], [
            'userid',
            'timecreated',
            'timemodified',
            'progress',
            'completed',
            'completionpercentage',
            'lastpage',
            'totalpages',
            'timespent',
            'lastposition',
            'duration',
            'watchedranges',
            'points',
        ]);

        $rewards = new backup_nested_element('rewards');
        $reward = new backup_nested_element('reward', ['id'], [
            'userid',
            'rewardtype',
            'rewardkey',
            'points',
            'timecreated',
        ]);

        $videoplayer->add_child($views);
        $views->add_child($view);
        $videoplayer->add_child($rewards);
        $rewards->add_child($reward);

        $videoplayer->set_source_table('videoplayer', ['id' => backup::VAR_ACTIVITYID]);
        $videoplayer->annotate_files('mod_videoplayer', 'localpdf', null);

        if ($userinfo) {
            $view->set_source_table('videoplayer_views', ['videoplayerid' => backup::VAR_PARENTID]);
            $view->annotate_ids('user', 'userid');

            $reward->set_source_table('videoplayer_rewards', ['videoplayerid' => backup::VAR_PARENTID]);
            $reward->annotate_ids('user', 'userid');
        }

        return $this->prepare_activity_structure($videoplayer);
    }
}
