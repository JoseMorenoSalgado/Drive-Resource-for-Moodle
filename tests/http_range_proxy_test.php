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

use mod_videoplayer\local\http_range_proxy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for protected upstream MIME and byte-range validation.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @coversDefaultClass \mod_videoplayer\local\http_range_proxy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(http_range_proxy::class)]
final class http_range_proxy_test extends \advanced_testcase {
    /**
     * Compatible media types must be accepted.
     *
     * @param string $candidate Upstream MIME type.
     * @param string $fallback Expected viewer MIME type.
     * @covers ::is_compatible_content_type
     * @dataProvider compatible_type_provider
     */
    #[DataProvider('compatible_type_provider')]
    public function test_accepts_compatible_types(string $candidate, string $fallback): void {
        $this->assertTrue(http_range_proxy::is_compatible_content_type($candidate, $fallback));
    }

    /**
     * Compatible MIME type data provider.
     *
     * @return array<string, array{string, string}>
     */
    public static function compatible_type_provider(): array {
        return [
            'mp4' => ['video/mp4', 'video/mp4'],
            'webm' => ['video/webm; charset=binary', 'video/mp4'],
            'generic binary video' => ['application/octet-stream', 'video/mp4'],
            'missing upstream type' => ['', 'video/mp4'],
            'pdf' => ['application/pdf', 'application/pdf'],
            'image' => ['image/webp', 'image/jpeg'],
            'audio' => ['audio/mpeg', 'audio/mpeg'],
        ];
    }

    /**
     * HTML, JSON and unrelated media types must be rejected.
     *
     * @param string $candidate Upstream MIME type.
     * @param string $fallback Expected viewer MIME type.
     * @covers ::is_compatible_content_type
     * @dataProvider incompatible_type_provider
     */
    #[DataProvider('incompatible_type_provider')]
    public function test_rejects_incompatible_types(string $candidate, string $fallback): void {
        $this->assertFalse(http_range_proxy::is_compatible_content_type($candidate, $fallback));
    }

    /**
     * Incompatible MIME type data provider.
     *
     * @return array<string, array{string, string}>
     */
    public static function incompatible_type_provider(): array {
        return [
            'Drive warning page' => ['text/html; charset=utf-8', 'video/mp4'],
            'Drive JSON error' => ['application/json', 'video/mp4'],
            'plain text error' => ['text/plain', 'application/pdf'],
            'image sent to video' => ['image/jpeg', 'video/mp4'],
            'video sent to pdf' => ['video/mp4', 'application/pdf'],
        ];
    }

    /**
     * Browser ranges can be synthesized safely from a known full response.
     *
     * @covers ::resolve_range_window
     */
    public function test_resolves_range_windows(): void {
        $this->assertSame(
            ['start' => 0, 'end' => 4095, 'total' => 4096, 'length' => 4096],
            http_range_proxy::resolve_range_window('bytes=0-', 4096)
        );
        $this->assertSame(
            ['start' => 1024, 'end' => 2047, 'total' => 4096, 'length' => 1024],
            http_range_proxy::resolve_range_window('bytes=1024-2047', 4096)
        );
        $this->assertSame(
            ['start' => 3596, 'end' => 4095, 'total' => 4096, 'length' => 500],
            http_range_proxy::resolve_range_window('bytes=-500', 4096)
        );
        $this->assertNull(http_range_proxy::resolve_range_window('bytes=4096-', 4096));
        $this->assertNull(http_range_proxy::resolve_range_window('bytes=100-99', 4096));
        $this->assertNull(http_range_proxy::resolve_range_window('invalid', 4096));
        $this->assertNull(http_range_proxy::resolve_range_window('bytes=0-', 0));
    }

    /**
     * Range requests require a valid HTTP 206 response and Content-Range.
     *
     * @covers ::is_range_response_usable
     */
    public function test_range_requests_require_valid_partial_content(): void {
        $this->assertTrue(http_range_proxy::is_range_response_usable('', 200, ''));
        $this->assertTrue(http_range_proxy::is_range_response_usable('', 206, 'bytes 0-1023/4096'));
        $this->assertTrue(
            http_range_proxy::is_range_response_usable('bytes=1024-', 206, 'bytes 1024-4095/4096')
        );

        $this->assertFalse(http_range_proxy::is_range_response_usable('bytes=1024-', 200, ''));
        $this->assertFalse(http_range_proxy::is_range_response_usable('bytes=1024-', 206, ''));
        $this->assertFalse(
            http_range_proxy::is_range_response_usable('bytes=1024-', 206, 'bytes */4096')
        );
    }
}
