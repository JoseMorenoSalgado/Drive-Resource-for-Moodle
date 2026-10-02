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
 * @covers     \mod_videoplayer\local\stream\upstream_url_policy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upstream_url_policy_test extends \advanced_testcase {
    /**
     * Managed Elearning Stream CDN subdomains are accepted.
     *
     * @return void
     */
    public function test_elearning_stream_cdn_hosts_are_allowed(): void {
        $this->assertTrue(upstream_url_policy::is_allowed(
            'https://vz-example.b-cdn.net/video/play_720p.mp4?token=HS256-test&expires=1'
        ));
    }

    /**
     * Retired Google and arbitrary hosts are rejected.
     *
     * @return void
     */
    public function test_untrusted_and_retired_hosts_are_rejected(): void {
        $blocked = [
            'https://drive.google.com/uc?id=abc',
            'https://docs.google.com/document/d/abc',
            'https://drive.usercontent.google.com/download?id=abc',
            'https://r1---sn-test.googlevideo.com/videoplayback?id=abc',
            'https://content-workspacevideo-pa.googleapis.com/v1/drive/media/abc/playback',
            'https://127.0.0.1/internal',
            'https://[::1]/internal',
            'file:///etc/passwd',
            'https://b-cdn.net/video/play_720p.mp4',
            'https://evilb-cdn.net/video/play_720p.mp4',
            'https://vz-example.b-cdn.net.evil.example/video.mp4',
        ];

        foreach ($blocked as $url) {
            $this->assertFalse(upstream_url_policy::is_allowed($url), $url);
        }
    }

    /**
     * Redirects remain confined to the managed CDN allow-list.
     *
     * @return void
     */
    public function test_redirect_resolution_stays_inside_allow_list(): void {
        $base = 'https://vz-example.b-cdn.net/video/play_720p.mp4';

        $this->assertSame(
            'https://vz-example.b-cdn.net/video/segment.mp4',
            upstream_url_policy::resolve_redirect($base, '/video/segment.mp4')
        );
        $this->assertSame(
            'https://vz-other.b-cdn.net/video/segment.mp4',
            upstream_url_policy::resolve_redirect(
                $base,
                'https://vz-other.b-cdn.net/video/segment.mp4'
            )
        );
        $this->assertNull(
            upstream_url_policy::resolve_redirect(
                $base,
                'https://drive.google.com/uc?id=abc'
            )
        );
        $this->assertNull(upstream_url_policy::resolve_redirect($base, 'https://evil.example/file'));
        $this->assertNull(upstream_url_policy::resolve_redirect($base, '//127.0.0.1/internal'));
        $this->assertNull(upstream_url_policy::resolve_redirect($base, '../relative/path'));
    }
}
