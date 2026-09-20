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

use mod_videoplayer\local\drive;

/**
 * Tests for Google Drive URL parsing.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_videoplayer\local\drive
 */
final class drive_test extends \advanced_testcase {
    /**
     * Supported Drive URLs resolve to the same file id.
     *
     * @return void
     */
    public function test_extract_file_id_from_supported_urls(): void {
        $id = '1_P32NzeKUZCkLVouwOtsrKEYRRxSPJzl';
        $urls = [
            'https://drive.google.com/file/d/' . $id . '/view?usp=sharing',
            'https://drive.google.com/open?id=' . $id,
            'https://drive.google.com/uc?id=' . $id . '&export=download',
            'https://docs.google.com/document/d/' . $id . '/edit',
            'https://docs.google.com/spreadsheets/d/' . $id . '/edit',
            'https://docs.google.com/presentation/d/' . $id . '/edit',
        ];

        foreach ($urls as $url) {
            $this->assertSame($id, drive::extract_file_id($url));
        }
    }

    /**
     * Non-Google hosts are rejected even when they contain an id parameter.
     *
     * @return void
     */
    public function test_is_supported_url_rejects_non_google_host(): void {
        $this->assertFalse(drive::is_supported_url('https://example.com/file?id=abc123'));
    }

    /**
     * Native Google document types are exported as PDF-compatible resources.
     *
     * @return void
     */
    public function test_pdf_compatible_types(): void {
        foreach (['pdf', 'document', 'spreadsheet', 'presentation'] as $type) {
            $this->assertTrue(drive::is_pdf_type($type));
        }
        $this->assertFalse(drive::is_pdf_type('video'));
    }
}
