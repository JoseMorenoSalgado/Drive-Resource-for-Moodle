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
    }

    /**
     * Non-HTTPS and lookalike hosts are rejected.
     *
     * @return void
     */
    public function test_untrusted_hosts_are_rejected(): void {
        $this->assertFalse(upstream_url_policy::is_allowed('http://drive.google.com/uc?id=abc'));
        $this->assertFalse(upstream_url_policy::is_allowed('https://drive.google.com.evil.example/uc?id=abc'));
        $this->assertFalse(upstream_url_policy::is_allowed('https://127.0.0.1/internal'));
        $this->assertFalse(upstream_url_policy::is_allowed('https://user:pass@drive.google.com/uc?id=abc'));
    }
}
