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

use mod_videoplayer\local\stream\upstream_url_policy;

/**
 * SSRF allow-list tests.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_videoplayer\local\stream\upstream_url_policy
 */
final class upstream_url_policy_test extends \advanced_testcase {
    /**
     * Google-owned media endpoints are accepted.
     *
     * @return void
     */
    public function test_google_media_hosts_are_allowed(): void {
        $this->assertTrue(upstream_url_policy::is_allowed('https://drive.google.com/uc?id=abc'));
        $this->assertTrue(upstream_url_policy::is_allowed('https://drive.usercontent.google.com/download?id=abc'));
        $this->assertTrue(upstream_url_policy::is_allowed('https://r1---sn-test.googlevideo.com/videoplayback?id=abc'));
        $this->assertTrue(upstream_url_policy::is_allowed('https://doc-00-00-docs.googleusercontent.com/docs/securesc/abc'));
        $this->assertTrue(upstream_url_policy::is_allowed('https://lh3.c.drive.google.com/videoplayback?id=abc'));
        $this->assertTrue(upstream_url_policy::is_allowed(
            'https://content-workspacevideo-pa.googleapis.com/v1/drive/media/abc/playback'
        ));
    }

    /**
     * Non-HTTPS and lookalike hosts are rejected.
     *
     * @return void
     */
    public function test_untrusted_hosts_are_rejected(): void {
        $this->assertFalse(upstream_url_policy::is_allowed('http://drive.google.com/uc?id=abc'));
        $this->assertFalse(upstream_url_policy::is_allowed('https://drive.google.com.evil.example/uc?id=abc'));
        $this->assertFalse(upstream_url_policy::is_allowed('https://googlevideo.com.evil.example/videoplayback?id=abc'));
        $this->assertFalse(upstream_url_policy::is_allowed('https://evilgooglevideo.com/videoplayback?id=abc'));
        $this->assertFalse(upstream_url_policy::is_allowed('https://googleusercontent.com.evil.example/file?id=abc'));
        $this->assertFalse(upstream_url_policy::is_allowed('https://127.0.0.1/internal'));
        $this->assertFalse(upstream_url_policy::is_allowed('https://[::1]/internal'));
        $this->assertFalse(upstream_url_policy::is_allowed('file:///etc/passwd'));
        $this->assertFalse(upstream_url_policy::is_allowed('//drive.google.com/uc?id=abc'));
        $this->assertFalse(upstream_url_policy::is_allowed('https://user:pass@drive.google.com/uc?id=abc'));
        $this->assertFalse(upstream_url_policy::is_allowed('https://drive.google.com:8443/uc?id=abc'));
    }

    /**
     * Redirects must remain on allow-listed Google HTTPS endpoints.
     *
     * @return void
     */
    public function test_redirect_resolution_stays_inside_allow_list(): void {
        $base = 'https://drive.google.com/uc?id=abc';

        $this->assertSame(
            'https://drive.usercontent.google.com/download?id=abc',
            upstream_url_policy::resolve_redirect(
                $base,
                'https://drive.usercontent.google.com/download?id=abc'
            )
        );
        $this->assertSame(
            'https://drive.google.com/download?id=abc',
            upstream_url_policy::resolve_redirect($base, '/download?id=abc')
        );
        $this->assertSame(
            'https://r1---sn-test.googlevideo.com/videoplayback?id=abc',
            upstream_url_policy::resolve_redirect(
                $base,
                '//r1---sn-test.googlevideo.com/videoplayback?id=abc'
            )
        );

        $this->assertNull(upstream_url_policy::resolve_redirect($base, 'https://evil.example/file'));
        $this->assertNull(upstream_url_policy::resolve_redirect($base, '//127.0.0.1/internal'));
        $this->assertNull(upstream_url_policy::resolve_redirect($base, '../relative/path'));
        $this->assertNull(upstream_url_policy::resolve_redirect($base, "https://drive.google.com\r\nX-Test: injected"));
    }
}
