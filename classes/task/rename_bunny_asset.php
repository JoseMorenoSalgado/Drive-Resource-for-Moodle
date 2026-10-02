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
 * Update a Bunny title only while the originating activity still owns it.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rename_bunny_asset extends \core\task\adhoc_task {
    /**
     * Send the current activity name to the authenticated WHMCS gateway.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        if (empty($data->instanceid) || empty($data->videoid) || !isset($data->name)) {
            return;
        }
        $instance = $DB->get_record(
            'videoplayer',
            ['id' => (int)$data->instanceid],
            'id, source, providerassetid, name',
            IGNORE_MISSING
        );
        if (
            !$instance || (string)$instance->source !== bunny_stream::SOURCE
            || !hash_equals((string)$instance->providerassetid, (string)$data->videoid)
            || (string)$instance->name !== (string)$data->name
        ) {
            return;
        }

        (new whmcs_gateway_client())->rename_asset(
            (string)$data->videoid,
            (int)$data->instanceid,
            (string)$instance->name
        );
    }
}
