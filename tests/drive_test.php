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
 * Tests for the Google Drive URL helper.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @covers     \mod_videoplayer\local\drive
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
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
            'drive sdk share link' => [
                'https://drive.google.com/file/d/1AbC_def-123/view?usp=drivesdk',
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
            'https://drive.google.com/uc?export=download&id=1AbC_def-123',
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
            'https://drive.google.com/uc?export=download&id=1AbC_def-123&resourcekey=0-AbC_def-456',
            drive::protected_content_url($originalurl, '1AbC_def-123', 'video')
        );
    }

    /**
     * Large-file confirmation forms must be converted to an allow-listed Drive URL.
     *
     * @covers ::resolve_download_warning_url
     */
    public function test_resolves_large_file_confirmation_form(): void {
        $html = '<!doctype html><html><body>' .
            '<form id="download-form" action="https://drive.usercontent.google.com/download" method="get">' .
            '<input type="hidden" name="id" value="1AbC_def-123">' .
            '<input type="hidden" name="export" value="download">' .
            '<input type="hidden" name="confirm" value="t">' .
            '<input type="hidden" name="uuid" value="19ebee42-9335-42ed-b652-68b7f0208782">' .
            '</form></body></html>';

        $this->assertSame(
            'https://drive.usercontent.google.com/download?id=1AbC_def-123&export=download&confirm=t' .
                '&uuid=19ebee42-9335-42ed-b652-68b7f0208782',
            drive::resolve_download_warning_url(
                $html,
                'https://drive.usercontent.google.com/download?id=1AbC_def-123&export=download&confirm=t'
            )
        );
    }

    /**
     * Drive confirmation tokens used by current share-link downloads must survive.
     *
     * @covers ::resolve_download_warning_url
     */
    public function test_preserves_current_confirmation_tokens(): void {
        $html = '<form id="download-form" action="https://drive.usercontent.google.com/download" method="get">' .
            '<input type="hidden" name="id" value="1AbC_def-123">' .
            '<input type="hidden" name="export" value="download">' .
            '<input type="hidden" name="confirm" value="t">' .
            '<input type="hidden" name="uuid" value="19ebee42-9335-42ed-b652-68b7f0208782">' .
            '<input type="hidden" name="authuser" value="0">' .
            '<input type="hidden" name="at" value="AN8xHOr_example-token_123">' .
            '</form>';

        $resolved = drive::resolve_download_warning_url(
            $html,
            'https://drive.usercontent.google.com/download?id=1AbC_def-123&export=download'
        );

        $this->assertNotNull($resolved);
        $this->assertStringContainsString('id=1AbC_def-123', $resolved);
        $this->assertStringContainsString('confirm=t', $resolved);
        $this->assertStringContainsString('authuser=0', $resolved);
        $this->assertStringContainsString('at=AN8xHOr_example-token_123', $resolved);
    }

    /**
     * Embedded downloadUrl responses must resolve without requiring a form.
     *
     * @covers ::resolve_download_warning_url
     */
    public function test_resolves_embedded_download_url(): void {
        $html = '<script>window.data={"downloadUrl":' .
            '"https:\\/\\/drive.usercontent.google.com\\/download?id=1AbC_def-123' .
            '\\u0026export=download\\u0026confirm=t\\u0026authuser=0"};</script>';

        $this->assertSame(
            'https://drive.usercontent.google.com/download?id=1AbC_def-123&export=download&confirm=t&authuser=0',
            drive::resolve_download_warning_url(
                $html,
                'https://drive.usercontent.google.com/download?id=1AbC_def-123'
            )
        );
    }

    /**
     * Confirmation forms must not be allowed to redirect the proxy off Google.
     *
     * @covers ::resolve_download_warning_url
     */
    public function test_rejects_untrusted_confirmation_action(): void {
        $html = '<form id="download-form" action="https://attacker.invalid/download">' .
            '<input name="id" value="1AbC_def-123">' .
            '<input name="confirm" value="t">' .
            '</form>';

        $this->assertNull(
            drive::resolve_download_warning_url(
                $html,
                'https://drive.usercontent.google.com/download?id=1AbC_def-123'
            )
        );
    }

    /**
     * Server-side download redirects must remain on trusted HTTPS hosts.
     *
     * @covers ::is_trusted_download_url
     * @covers ::resolve_trusted_download_url
     */
    public function test_trusted_download_redirects_are_bounded(): void {
        $this->assertTrue(drive::is_trusted_download_url(
            'https://drive.usercontent.google.com/download?id=1AbC_def-123'
        ));
        $this->assertTrue(drive::is_trusted_download_url(
            'https://doc-0k-7c-drive-data-export.googleusercontent.com/download/file.bin'
        ));
        $this->assertFalse(drive::is_trusted_download_url('http://drive.google.com/uc?id=1AbC_def-123'));
        $this->assertFalse(drive::is_trusted_download_url('https://drive.google.com.evil.invalid/uc?id=x'));
        $this->assertFalse(drive::is_trusted_download_url('https://drive.google.com:8443/uc?id=x'));

        $this->assertSame(
            'https://drive.usercontent.google.com/download?id=1AbC_def-123',
            drive::resolve_trusted_download_url(
                'https://drive.usercontent.google.com/download?id=1AbC_def-123',
                'https://drive.google.com/uc?export=download&id=1AbC_def-123'
            )
        );
        $this->assertSame(
            'https://drive.google.com/uc?export=download&id=1AbC_def-123',
            drive::resolve_trusted_download_url(
                '/uc?export=download&id=1AbC_def-123',
                'https://drive.google.com/file/d/1AbC_def-123/view'
            )
        );
        $this->assertNull(drive::resolve_trusted_download_url(
            'https://attacker.invalid/file',
            'https://drive.google.com/uc?export=download&id=1AbC_def-123'
        ));
    }

    /**
     * Standard Drive share URLs in automatic mode must keep video compatibility.
     *
     * @covers ::resolve_record_type
     */
    public function test_auto_standard_drive_link_falls_back_to_video(): void {
        $record = (object) [
            'source' => 'googledrive',
            'type' => 'auto',
            'videourl' => 'https://drive.google.com/file/d/1AbC_def-123/view?usp=sharing',
        ];

        $this->assertSame('video', drive::resolve_record_type($record));
    }

    /**
     * Explicit resource types must override the opaque Drive URL fallback.
     *
     * @covers ::resolve_record_type
     */
    public function test_explicit_type_is_preserved_for_standard_drive_link(): void {
        $record = (object) [
            'source' => 'googledrive',
            'type' => 'pdf',
            'videourl' => 'https://drive.google.com/file/d/1AbC_def-123/view?usp=sharing',
        ];

        $this->assertSame('pdf', drive::resolve_record_type($record));
    }

    /**
     * Google Workspace URLs remain automatically detectable.
     *
     * @covers ::resolve_record_type
     */
    public function test_auto_workspace_link_keeps_detected_type(): void {
        $record = (object) [
            'source' => 'googledrive',
            'type' => 'auto',
            'videourl' => 'https://docs.google.com/presentation/d/1AbC_def-123/edit',
        ];

        $this->assertSame('presentation', drive::resolve_record_type($record));
    }

    /**
     * Local protected PDFs must never depend on Drive URL detection.
     *
     * @covers ::resolve_record_type
     */
    public function test_local_pdf_always_resolves_to_pdf(): void {
        $record = (object) [
            'source' => 'localpdf',
            'type' => 'auto',
            'videourl' => '',
        ];

        $this->assertSame('pdf', drive::resolve_record_type($record));
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
