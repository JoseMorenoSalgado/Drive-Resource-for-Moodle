<?php
// This file is part of Moodle - http://moodle.org/

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
