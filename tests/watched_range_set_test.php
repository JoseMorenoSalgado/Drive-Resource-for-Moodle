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

use mod_videoplayer\local\progress\watched_range_set;

/**
 * Watched range set tests.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \mod_videoplayer\local\progress\watched_range_set
 */
final class watched_range_set_test extends \advanced_testcase {
    /**
     * Overlapping ranges must merge and preserve unique watched time.
     *
     * @return void
     * @covers \\mod_videoplayer\\local\\progress\\watched_range_set::merge
     */
    public function test_merge_preserves_unique_watched_seconds(): void {
        $json = watched_range_set::merge('[[0,10],[20,30]]', '[[8,22],[40,45]]', 60.0);

        $this->assertEqualsWithDelta(35.0, watched_range_set::seconds($json, 60.0), 0.001);
    }

    /**
     * Seeking must not turn skipped media into watched progress.
     *
     * @return void
     * @covers \\mod_videoplayer\\local\\progress\\watched_range_set::merge
     */
    public function test_non_contiguous_ranges_do_not_fill_seek_gap(): void {
        $json = watched_range_set::merge('[]', '[[0,10],[50,60]]', 100.0);

        $this->assertEqualsWithDelta(20.0, watched_range_set::seconds($json, 100.0), 0.001);
    }

    /**
     * Malformed and out-of-bounds input must be ignored or clamped.
     *
     * @return void
     * @covers \\mod_videoplayer\\local\\progress\\watched_range_set::merge
     */
    public function test_invalid_ranges_are_bounded(): void {
        $json = watched_range_set::merge('not-json', '[[90,130],["bad",4],[-4,5]]', 100.0);

        $this->assertEqualsWithDelta(15.0, watched_range_set::seconds($json, 100.0), 0.001);
    }
}
