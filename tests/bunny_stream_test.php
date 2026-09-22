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
 * Tests for the Bunny Stream provider value object helpers.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoplayer;

use mod_videoplayer\local\provider\bunny_stream;

/**
 * Bunny Stream provider helper tests.
 *
 * @covers \mod_videoplayer\local\provider\bunny_stream
 */
final class bunny_stream_test extends \advanced_testcase {
    /**
     * Valid Bunny-style GUIDs are accepted while arbitrary URLs are rejected.
     */
    public function test_asset_identifier_validation(): void {
        $this->assertTrue(bunny_stream::is_valid_asset_id('d4b3b9ce-531f-4f7a-a8db-847f47a889e9'));
        $this->assertTrue(bunny_stream::is_valid_asset_id('0123456789abcdef0123456789abcdef'));
        $this->assertFalse(bunny_stream::is_valid_asset_id('https://video.bunnycdn.com/video'));
        $this->assertFalse(bunny_stream::is_valid_asset_id('../video'));
        $this->assertFalse(bunny_stream::is_valid_asset_id(''));
    }

    /**
     * Upload reservations accept opaque server-generated identifiers only.
     */
    public function test_upload_identifier_validation(): void {
        $this->assertTrue(bunny_stream::is_valid_upload_id('0123456789abcdef0123456789abcdef'));
        $this->assertFalse(bunny_stream::is_valid_upload_id('short'));
        $this->assertFalse(bunny_stream::is_valid_upload_id('0123456789abcdef<script>alert(1)</script>'));
    }

    /**
     * Only states understood by Moodle are persisted.
     */
    public function test_status_normalisation(): void {
        $this->assertSame('uploaded', bunny_stream::normalise_status('uploaded'));
        $this->assertSame('processing', bunny_stream::normalise_status('processing'));
        $this->assertSame('ready', bunny_stream::normalise_status('ready'));
        $this->assertSame('', bunny_stream::normalise_status('deleted'));
        $this->assertSame('', bunny_stream::normalise_status('<script>'));
    }
}
