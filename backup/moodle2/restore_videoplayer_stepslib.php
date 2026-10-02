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

use mod_videoplayer\local\provider\bunny_stream;
use mod_videoplayer\local\resource_compatibility;

/**
 * Restore structure step for Elearning Stream.
 */
class restore_videoplayer_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define restore paths.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [
            new restore_path_element('videoplayer', '/activity/videoplayer'),
        ];

        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element('videoplayer_view', '/activity/videoplayer/views/view');
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the activity instance.
     *
     * Older backups may contain fields removed from the production schema.
     * They are intentionally discarded here so historical backups continue to
     * restore without reintroducing retired runtime features.
     *
     * @param array|stdClass $data Backup data.
     * @return void
     */
    protected function process_videoplayer($data): void {
        global $DB;

        $data = (object)$data;
        $oldid = (int)$data->id;
        unset($data->id);

        $data->course = $this->get_courseid();
        $source = clean_param(
            (string)($data->source ?? resource_compatibility::SOURCE_RETIRED_REMOTE),
            PARAM_ALPHANUMEXT
        );
        $allowedsources = [
            resource_compatibility::SOURCE_RETIRED_REMOTE,
            resource_compatibility::SOURCE_LOCALPDF,
            bunny_stream::SOURCE,
        ];
        $data->source = in_array($source, $allowedsources, true)
            ? $source
            : resource_compatibility::SOURCE_RETIRED_REMOTE;

        if (!isset($data->disablecontextmenu)) {
            $data->disablecontextmenu = 1;
        }
        if (!isset($data->enablewatermark)) {
            $data->enablewatermark = 0;
        }
        $data->completionpercentage = max(
            1,
            min(100, (int)($data->completionpercentage ?? 80))
        );
        $data->completionprogressenabled = empty($data->completionprogressenabled) ? 0 : 1;

        if ($data->source === bunny_stream::SOURCE) {
            $assetid = trim((string)($data->providerassetid ?? ''));
            $data->providerassetid = bunny_stream::is_valid_asset_id($assetid) ? $assetid : null;
            $data->providerfilesize = max(0, (int)($data->providerfilesize ?? 0));
            $status = bunny_stream::normalise_status((string)($data->providerstatus ?? ''));
            $data->providerstatus = $status !== '' ? $status : null;
        } else {
            $data->providerassetid = null;
            $data->providerfilesize = 0;
            $data->providerstatus = null;
        }

        // Upload reservations are transient and never portable.
        $data->provideruploadid = null;

        foreach ([
            'videourl',
            'type',
            'displaymode',
            'disabledownload',
            'enablegamification',
            'pointsperpage',
            'video',
            'endscreentext',
            'displayasstartscreen',
            'starttime',
            'endtime',
            'grade',
            'displayoptions',
            'posterimage',
            'extendedcompletion',
        ] as $obsoletefield) {
            unset($data->{$obsoletefield});
        }

        $newitemid = $DB->insert_record('videoplayer', $data);
        $this->set_mapping('videoplayer', $oldid, $newitemid, true);
        $this->apply_activity_instance($newitemid);

        if ($data->source === bunny_stream::SOURCE && !empty($data->providerassetid)) {
            $task = new \mod_videoplayer\task\reconcile_bunny_asset();
            $task->set_component('mod_videoplayer');
            $task->set_custom_data([
                'instanceid' => (int)$newitemid,
                'courseid' => (int)$data->course,
                'videoid' => (string)$data->providerassetid,
            ]);
            \core\task\manager::queue_adhoc_task($task, true);
        }
    }

    /**
     * Restore user progress.
     *
     * @param array|stdClass $data Backup data.
     * @return void
     */
    protected function process_videoplayer_view($data): void {
        global $DB;

        $data = (object)$data;
        unset($data->id, $data->points);
        $data->videoplayerid = $this->get_new_parentid('videoplayer');
        $data->userid = $this->get_mappingid('user', $data->userid);

        if (empty($data->userid)) {
            return;
        }

        $data->lastpage = max(0, (int)($data->lastpage ?? 0));
        $data->totalpages = max(0, (int)($data->totalpages ?? 0));
        $data->timespent = max(0, (int)($data->timespent ?? 0));
        $data->lastposition = max(0, (float)($data->lastposition ?? 0));
        $data->duration = max(0, (float)($data->duration ?? 0));
        $data->watchedranges = $data->watchedranges ?? null;

        $DB->insert_record('videoplayer_views', $data);
    }

    /**
     * Add restored Moodle files.
     *
     * @return void
     */
    protected function after_execute(): void {
        $this->add_related_files('mod_videoplayer', 'intro', null);
        $this->add_related_files('mod_videoplayer', 'localpdf', null);
    }
}
