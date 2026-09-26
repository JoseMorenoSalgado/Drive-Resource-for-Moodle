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

use mod_videoplayer\local\provider\bunny_stream;
use mod_videoplayer\local\whmcs_gateway_client;

/**
 * Retry provider metadata synchronisation for one Moodle activity.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sync_bunny_asset_metadata extends \core\task\adhoc_task {
    /**
     * Execute the metadata synchronisation.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $instanceid = (int)($data->instanceid ?? 0);
        $videoid = strtolower(trim((string)($data->videoid ?? '')));
        if ($instanceid <= 0 || !bunny_stream::is_valid_asset_id($videoid)) {
            return;
        }

        $instance = $DB->get_record(
            'videoplayer',
            ['id' => $instanceid],
            'id, source, name, providerassetid',
            IGNORE_MISSING
        );
        if (
            !$instance
            || ($instance->source ?? '') !== bunny_stream::SOURCE
            || !hash_equals(
                strtolower((string)($instance->providerassetid ?? '')),
                $videoid
            )
            || trim((string)$instance->name) === ''
        ) {
            return;
        }

        (new whmcs_gateway_client())->update_asset_title(
            $videoid,
            $instanceid,
            (string)$instance->name
        );
    }
}
