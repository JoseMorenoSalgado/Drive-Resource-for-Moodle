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

namespace mod_videoplayer;

use mod_videoplayer\local\transfer_meter;

/**
 * Tests for protected Elearning Stream transfer metering.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_videoplayer\local\transfer_meter
 */
final class transfer_meter_test extends \advanced_testcase {
    /**
     * Valid emitted bytes are queued against the configured WHMCS service.
     *
     * @return void
     */
    public function test_valid_transfer_is_queued(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('whmcsserviceid', 321, 'mod_videoplayer');

        $videoid = 'd4b3b9ce-531f-4f7a-a8db-847f47a889e9';
        transfer_meter::record($videoid, 1048576);

        $record = $DB->get_record('videoplayer_transfer_events', [], '*', MUST_EXIST);
        $this->assertSame(321, (int) $record->serviceid);
        $this->assertSame($videoid, $record->videoid);
        $this->assertSame(1048576, (int) $record->bytes);
        $this->assertMatchesRegularExpression('/^20\d{2}-(0[1-9]|1[0-2])$/', $record->periodkey);
        $this->assertGreaterThan(0, (int) $record->timecreated);
    }

    /**
     * Invalid provider ids, empty service identity and zero bytes are ignored.
     *
     * @return void
     */
    public function test_invalid_transfer_is_not_queued(): void {
        global $DB;

        $this->resetAfterTest();
        unset_config('whmcsserviceid', 'mod_videoplayer');

        transfer_meter::record('d4b3b9ce-531f-4f7a-a8db-847f47a889e9', 1024);
        $this->assertSame(0, $DB->count_records('videoplayer_transfer_events'));

        set_config('whmcsserviceid', 321, 'mod_videoplayer');
        transfer_meter::record('not-a-video-id', 1024);
        transfer_meter::record('d4b3b9ce-531f-4f7a-a8db-847f47a889e9', 0);

        $this->assertSame(0, $DB->count_records('videoplayer_transfer_events'));
    }
}
