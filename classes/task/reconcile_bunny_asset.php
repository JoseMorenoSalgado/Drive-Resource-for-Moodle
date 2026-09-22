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
 * Reconcile a restored Bunny asset with the WHMCS service tenant.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reconcile_bunny_asset extends \core\task\adhoc_task {
    /**
     * Execute the reconciliation request.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (empty($data->instanceid) || empty($data->courseid) || empty($data->videoid)) {
            return;
        }

        (new whmcs_gateway_client())->reconcile_asset(
            (string)$data->videoid,
            (int)$data->instanceid,
            (int)$data->courseid
        );
    }
}
