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

use mod_videoplayer\local\whmcs_gateway_client;

/**
 * Bind an uploaded Bunny asset to a persisted Moodle activity in WHMCS.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bind_bunny_asset extends \core\task\adhoc_task {
    /**
     * Execute the bind request.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        if (empty($data->instanceid) || empty($data->courseid) || empty($data->videoid) || empty($data->uploadid)) {
            return;
        }

        $client = new whmcs_gateway_client();
        $client->bind_asset(
            (string)$data->uploadid,
            (string)$data->videoid,
            (int)$data->instanceid,
            (int)$data->courseid
        );

        // The reservation ID is needed only until WHMCS confirms the durable
        // activity-to-asset reference. Remove it from Moodle afterwards.
        $instance = $DB->get_record(
            'videoplayer',
            ['id' => (int)$data->instanceid],
            'id, name, providerassetid, provideruploadid',
            IGNORE_MISSING
        );
        if (
            $instance
            && hash_equals((string)$instance->providerassetid, (string)$data->videoid)
            && hash_equals((string)$instance->provideruploadid, (string)$data->uploadid)
        ) {
            // Ensure imported videos and completed uploads use the final
            // Moodle activity name, not a stale provider/local filename.
            if (trim((string)$instance->name) !== '') {
                try {
                    $client->update_asset_title(
                        (string)$data->videoid,
                        (int)$data->instanceid,
                        (string)$instance->name
                    );
                } catch (\Throwable $exception) {
                    $retry = new sync_bunny_asset_metadata();
                    $retry->set_component('mod_videoplayer');
                    $retry->set_custom_data([
                        'instanceid' => (int)$data->instanceid,
                        'videoid' => (string)$data->videoid,
                    ]);
                    \core\task\manager::queue_adhoc_task($retry, true);
                }
            }

            $DB->set_field('videoplayer', 'provideruploadid', null, ['id' => (int)$instance->id]);
        }
    }
}
