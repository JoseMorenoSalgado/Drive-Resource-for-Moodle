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

use mod_videoplayer\local\resource_compatibility;

/**
 * Tests for persisted source compatibility identifiers.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @covers     \mod_videoplayer\local\resource_compatibility
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resource_compatibility_test extends \advanced_testcase {
    /**
     * Historical source keys must remain stable across upgrades.
     *
     * @return void
     */
    public function test_persisted_source_keys_are_stable(): void {
        $this->assertSame('googledrive', resource_compatibility::SOURCE_RETIRED_REMOTE);
        $this->assertSame('localpdf', resource_compatibility::SOURCE_LOCALPDF);
    }
}
