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

namespace mod_videoplayer\local;

/**
 * Queue transfer bytes delivered through Moodle protected streaming.
 *
 * One lightweight insert is recorded per successful protected media request.
 * A scheduled task batches those events and reports them idempotently to WHMCS.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class transfer_meter {
    /**
     * Persist delivered bytes for later WHMCS synchronization.
     *
     * @param string $videoid Provider video GUID.
     * @param int $bytes Actual bytes emitted to the browser.
     * @return void
     */
    public static function record(string $videoid, int $bytes): void {
        global $DB;

        $serviceid = (int) get_config('mod_videoplayer', 'whmcsserviceid');
        $videoid = strtolower(trim($videoid));
        if (
            $serviceid <= 0
            || $bytes <= 0
            || $bytes > 1099511627776
            || !preg_match('/^[a-f0-9-]{32,64}$/', $videoid)
        ) {
            return;
        }

        $record = (object) [
            'serviceid' => $serviceid,
            'videoid' => $videoid,
            'bytes' => $bytes,
            'periodkey' => gmdate('Y-m'),
            'timecreated' => time(),
        ];

        try {
            $DB->insert_record('videoplayer_transfer_events', $record, false);
        } catch (\Throwable $exception) {
            debugging(
                'Drive Resource could not queue transfer usage: ' . $exception->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }
}
