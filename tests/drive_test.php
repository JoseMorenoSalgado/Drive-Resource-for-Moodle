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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the Google Drive URL helper.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @coversDefaultClass \mod_videoplayer\local\drive
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(drive::class)]
final class drive_test extends \advanced_testcase {
    /**
     * Supported sharing URL formats must resolve to the same file identifier.
     *
     * @param string $url Sharing URL.
     * @param string $expectedid Expected Drive identifier.
     * @covers ::extract_file_id
     * @covers ::is_supported_url
     * @dataProvider supported_url_provider
     */
    #[DataProvider('supported_url_provider')]
    public function test_extract_file_id(string $url, string $expectedid): void {
        $this->assertSame($expectedid, drive::extract_file_id($url));
        $this->assertTrue(drive::is_supported_url($url));
    }

    /**
     * URL data provider.
     *
     * @return array<string, array{string, string}>
     */
    public static function supported_url_provider(): array {
        return [
            'drive file' => [
                'https://drive.google.com/file/d/1AbC_def-123/view?usp=sharing',
                '1AbC_def-123',
            ],
            'drive open' => [
                'https://drive.google.com/open?id=1AbC_def-123',
                '1AbC_def-123',
            ],
            'document' => [
                'https://docs.google.com/document/d/1AbC_def-123/edit',
                '1AbC_def-123',
            ],
            'spreadsheet' => [
                'https://docs.google.com/spreadsheets/d/1AbC_def-123/edit',
                '1AbC_def-123',
            ],
            'presentation' => [
                'https://docs.google.com/presentation/d/1AbC_def-123/edit',
                '1AbC_def-123',
            ],
        ];
    }

    /**
     * Unsupported hosts and malformed links must be rejected.
     *
     * @covers ::is_supported_url
     * @covers ::extract_file_id
     */
    public function test_rejects_unsupported_urls(): void {
        $this->assertFalse(drive::is_supported_url('https://example.com/file/d/1AbC_def-123'));
        $this->assertFalse(drive::is_supported_url('not-a-url'));
        $this->assertNull(drive::extract_file_id('https://drive.google.com/drive/my-drive'));
    }

    /**
     * Resource keys must be retained only when they are valid.
     *
     * @covers ::extract_resource_key
     */
    public function test_extract_resource_key(): void {
        $url = 'https://drive.google.com/file/d/1AbC_def-123/view?resourcekey=0-AbC_def-456&usp=sharing';

        $this->assertSame('0-AbC_def-456', drive::extract_resource_key($url));
        $this->assertNull(drive::extract_resource_key('https://drive.google.com/file/d/1AbC_def-123/view'));
    }

    /**
     * Google Workspace resources must use PDF export endpoints server-side.
     *
     * @covers ::protected_content_url
     */
    public function test_protected_content_url_uses_expected_exports(): void {
        $fileid = '1AbC_def-123';

        $this->assertSame(
            'https://docs.google.com/document/d/1AbC_def-123/export?format=pdf',
            drive::protected_content_url('', $fileid, 'document')
        );
        $this->assertSame(
            'https://docs.google.com/spreadsheets/d/1AbC_def-123/export?format=pdf',
            drive::protected_content_url('', $fileid, 'spreadsheet')
        );
        $this->assertSame(
            'https://docs.google.com/presentation/d/1AbC_def-123/export/pdf',
            drive::protected_content_url('', $fileid, 'presentation')
        );
        $this->assertSame(
            'https://drive.usercontent.google.com/download?id=1AbC_def-123&export=download&confirm=t',
            drive::protected_content_url('', $fileid, 'video')
        );
    }

    /**
     * Protected URLs must preserve a Drive resource key server-side.
     *
     * @covers ::protected_content_url
     */
    public function test_protected_content_url_preserves_resource_key(): void {
        $originalurl = 'https://drive.google.com/file/d/1AbC_def-123/view?resourcekey=0-AbC_def-456';

        $this->assertSame(
            'https://drive.usercontent.google.com/download?id=1AbC_def-123&export=download&confirm=t' .
                '&resourcekey=0-AbC_def-456',
            drive::protected_content_url($originalurl, '1AbC_def-123', 'video')
        );
    }

    /**
     * Resource detection must stay deterministic for typed Workspace URLs.
     *
     * @covers ::detect_type
     */
    public function test_detect_type(): void {
        $this->assertSame('document', drive::detect_type('https://docs.google.com/document/d/example/edit'));
        $this->assertSame('spreadsheet', drive::detect_type('https://docs.google.com/spreadsheets/d/example/edit'));
        $this->assertSame('presentation', drive::detect_type('https://docs.google.com/presentation/d/example/edit'));
        $this->assertSame('pdf', drive::detect_type('https://drive.google.com/file.pdf?download=1'));
        $this->assertSame('video', drive::detect_type('https://drive.google.com/video.mp4'));
        $this->assertSame('image', drive::detect_type('https://drive.google.com/image.webp'));
        $this->assertSame('file', drive::detect_type('https://drive.google.com/file/d/example/view'));
    }
}
