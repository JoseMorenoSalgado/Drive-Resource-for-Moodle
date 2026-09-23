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

use core\task\scheduled_task;
use mod_videoplayer\local\whmcs_gateway_client;

/**
 * Flush queued protected-transfer usage to WHMCS.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sync_transfer_usage extends scheduled_task {
    /** @var int Maximum events sent in one cron iteration. */
    private const MAX_EVENTS = 1000;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_synctransferusage', 'mod_videoplayer');
    }

    /**
     * Send bounded, idempotent usage batches.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        if (!whmcs_gateway_client::is_configured()) {
            return;
        }

        $configuredserviceid = (int)get_config('mod_videoplayer', 'whmcsserviceid');

        // Never report bytes collected under an old service identity using the
        // current service token. Stale events are discarded rather than
        // incorrectly attributing usage to another customer/service.
        $DB->delete_records_select(
            'videoplayer_transfer_events',
            'serviceid <> :serviceid',
            ['serviceid' => $configuredserviceid]
        );

        $records = $DB->get_records(
            'videoplayer_transfer_events',
            null,
            'id ASC',
            'id, serviceid, bytes, periodkey',
            0,
            self::MAX_EVENTS
        );
        if (!$records) {
            return;
        }

        $groups = [];
        foreach ($records as $record) {
            $key = (int)$record->serviceid . '|' . (string)$record->periodkey;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'serviceid' => (int)$record->serviceid,
                    'period' => (string)$record->periodkey,
                    'bytes' => 0,
                    'ids' => [],
                ];
            }
            $groups[$key]['bytes'] += max(0, (int)$record->bytes);
            $groups[$key]['ids'][] = (int)$record->id;
        }

        $client = new whmcs_gateway_client();
        foreach ($groups as $group) {
            if ($group['bytes'] <= 0 || !$group['ids']) {
                continue;
            }

            $reportid = hash(
                'sha256',
                $group['serviceid'] . '|' . $group['period'] . '|'
                    . min($group['ids']) . '|' . max($group['ids']) . '|'
                    . $group['bytes']
            );

            try {
                $client->report_transfer($group['period'], $group['bytes'], $reportid);
            } catch (\Throwable $exception) {
                mtrace(
                    'Drive Resource transfer sync deferred: '
                        . $exception->getMessage()
                );
                continue;
            }

            list($insql, $params) = $DB->get_in_or_equal($group['ids'], SQL_PARAMS_NAMED);
            $DB->delete_records_select('videoplayer_transfer_events', 'id ' . $insql, $params);
        }

        // Bound unsent queue growth if WHMCS remains unavailable for months.
        $cutoff = time() - (120 * DAYSECS);
        $DB->delete_records_select(
            'videoplayer_transfer_events',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );
    }
}
