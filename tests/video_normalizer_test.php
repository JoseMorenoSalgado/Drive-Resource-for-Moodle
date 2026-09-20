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

use mod_videoplayer\local\video_normalizer;

/**
 * Tests for browser-compatible video normalization decisions.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @covers     \mod_videoplayer\local\video_normalizer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class video_normalizer_test extends \advanced_testcase {
    /**
     * H.264 yuv420p plus AAC is the direct-play baseline.
     *
     * @covers ::probe_is_web_compatible
     */
    public function test_h264_aac_is_web_compatible(): void {
        $probe = [
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'],
            'streams' => [
                [
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'pix_fmt' => 'yuv420p',
                ],
                [
                    'codec_type' => 'audio',
                    'codec_name' => 'aac',
                ],
            ],
        ];

        $this->assertTrue(video_normalizer::probe_is_web_compatible($probe));
    }

    /**
     * TSCC2 requires normalization even when stored in an MP4 container.
     *
     * @covers ::probe_is_web_compatible
     */
    public function test_tscc2_requires_normalization(): void {
        $probe = [
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'],
            'streams' => [
                [
                    'codec_type' => 'video',
                    'codec_name' => 'tscc2',
                    'pix_fmt' => 'yuv420p',
                ],
                [
                    'codec_type' => 'audio',
                    'codec_name' => 'aac',
                ],
            ],
        ];

        $this->assertFalse(video_normalizer::probe_is_web_compatible($probe));
    }

    /**
     * Non-AAC audio and non-yuv420 H.264 are normalized for broad browser support.
     *
     * @covers ::probe_is_web_compatible
     */
    public function test_nonportable_h264_variants_require_normalization(): void {
        $mp3audio = [
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'],
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
                ['codec_type' => 'audio', 'codec_name' => 'mp3'],
            ],
        ];
        $yuv444 = [
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'],
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'pix_fmt' => 'yuv444p'],
                ['codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
        ];

        $h264tenbit = [
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'],
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p10le'],
                ['codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
        ];
        $matroska = [
            'format' => ['format_name' => 'matroska,webm'],
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'pix_fmt' => 'yuv420p'],
                ['codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
        ];

        $this->assertFalse(video_normalizer::probe_is_web_compatible($mp3audio));
        $this->assertFalse(video_normalizer::probe_is_web_compatible($yuv444));
        $this->assertFalse(video_normalizer::probe_is_web_compatible($h264tenbit));
        $this->assertFalse(video_normalizer::probe_is_web_compatible($matroska));
    }
}
