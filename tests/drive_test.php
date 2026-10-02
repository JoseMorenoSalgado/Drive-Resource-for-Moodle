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
 * Tests for retained resource compatibility helpers.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @covers     \mod_videoplayer\local\drive
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class drive_test extends \advanced_testcase {
    /**
     * Local protected PDFs always resolve to PDF.
     *
     * @return void
     */
    public function test_local_pdf_always_resolves_to_pdf(): void {
        $record = (object)[
            'source' => drive::SOURCE_LOCALPDF,
            'type' => drive::TYPE_AUTO,
        ];

        $this->assertSame('pdf', drive::resolve_record_type($record));
    }

    /**
     * Automatic historical media is resolved without external URL inspection.
     *
     * @return void
     */
    public function test_auto_legacy_record_uses_video_compatibility_type(): void {
        $record = (object)[
            'source' => drive::SOURCE_GOOGLEDRIVE,
            'type' => drive::TYPE_AUTO,
            'videourl' => 'https://example.invalid/ignored',
        ];

        $this->assertSame('video', drive::resolve_record_type($record));
    }

    /**
     * Explicit persisted types are preserved and invalid values fail closed.
     *
     * @return void
     */
    public function test_persisted_type_validation(): void {
        foreach (drive::RESOURCE_TYPES as $type) {
            $record = (object)['source' => 'legacy', 'type' => $type];
            $this->assertSame($type, drive::resolve_record_type($record));
            $this->assertTrue(drive::is_supported_configured_type($type));
        }

        $invalid = (object)['source' => 'legacy', 'type' => 'iframe'];
        $this->assertSame(drive::TYPE_FILE, drive::resolve_record_type($invalid));
        $this->assertFalse(drive::is_supported_configured_type('iframe'));
    }

    /**
     * MIME defaults remain deterministic for supported local presentation.
     *
     * @return void
     */
    public function test_default_mimetypes(): void {
        $this->assertSame('video/mp4', drive::default_mimetype('video'));
        $this->assertSame('application/pdf', drive::default_mimetype('pdf'));
        $this->assertSame('application/octet-stream', drive::default_mimetype('file'));
    }
}
