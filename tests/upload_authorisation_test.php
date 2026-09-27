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

/**
 * Tests for protected TUS upload authorisation validation.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoplayer;

use mod_videoplayer\local\gateway\upload_authorisation;
use moodle_exception;

/**
 * TUS upload authorisation validation tests.
 *
 * @covers \mod_videoplayer\local\gateway\upload_authorisation
 */
final class upload_authorisation_test extends \advanced_testcase {
    /**
     * A valid gateway capability is normalised without changing its identity.
     */
    public function test_normalise_accepts_valid_authorisation(): void {
        $response = $this->valid_response();

        $result = upload_authorisation::normalise(
            $response,
            $response['uploadid'],
            $response['videoid']
        );

        $this->assertSame($response['uploadid'], $result['uploadid']);
        $this->assertSame($response['videoid'], $result['videoid']);
        $this->assertSame($response['libraryid'], $result['libraryid']);
        $this->assertSame($response['endpoint'], $result['endpoint']);
        $this->assertSame($response['signature'], $result['signature']);
    }

    /**
     * Refresh responses cannot silently switch the upload reservation.
     */
    public function test_normalise_rejects_unexpected_upload_identifier(): void {
        $response = $this->valid_response();

        $this->assert_rejected(
            $response,
            'whmcsgatewayinvalidresponse',
            'ffffffffffffffffffffffffffffffff',
            $response['videoid']
        );
    }

    /**
     * Refresh responses cannot silently switch the provider video.
     */
    public function test_normalise_rejects_unexpected_video_identifier(): void {
        $response = $this->valid_response();

        $this->assert_rejected(
            $response,
            'whmcsgatewayinvalidresponse',
            $response['uploadid'],
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
        );
    }

    /**
     * Browser upload capabilities must target the exact approved HTTPS endpoint.
     */
    public function test_normalise_rejects_unsafe_endpoint_shapes(): void {
        foreach ([
            'https://user:pass@video.bunnycdn.com/tusupload',
            'https://video.bunnycdn.com:8443/tusupload',
            'https://video.bunnycdn.com/tusupload?redirect=1',
            'https://video.bunnycdn.com/tusupload#fragment',
            'https://video.bunnycdn.com.evil.example/tusupload',
        ] as $endpoint) {
            $response = $this->valid_response();
            $response['endpoint'] = $endpoint;

            $this->assert_rejected($response, 'whmcsgatewayinvaliduploadendpoint');
        }
    }

    /**
     * Library id, signature and expiration remain strict on refresh responses.
     */
    public function test_normalise_rejects_invalid_capability_metadata(): void {
        $cases = [
            ['libraryid', '12<script>'],
            ['signature', str_repeat('g', 64)],
            ['expiration', time() - 1],
            ['expiration', time() + DAYSECS + 60],
        ];

        foreach ($cases as [$field, $value]) {
            $response = $this->valid_response();
            $response[$field] = $value;

            $this->assert_rejected($response, 'whmcsgatewayinvalidresponse');
        }
    }

    /**
     * Assert that one malformed capability is rejected with the expected code.
     *
     * @param array $response Gateway response.
     * @param string $errorcode Expected Moodle exception code.
     * @param string|null $expecteduploadid Expected upload id.
     * @param string|null $expectedvideoid Expected video id.
     * @return void
     */
    private function assert_rejected(
        array $response,
        string $errorcode,
        ?string $expecteduploadid = null,
        ?string $expectedvideoid = null
    ): void {
        try {
            upload_authorisation::normalise($response, $expecteduploadid, $expectedvideoid);
            $this->fail('Expected malformed upload authorisation to be rejected.');
        } catch (moodle_exception $exception) {
            $this->assertSame($errorcode, $exception->errorcode);
        }
    }

    /**
     * Build a valid representative gateway response.
     *
     * @return array
     */
    private function valid_response(): array {
        return [
            'uploadid' => '0123456789abcdef0123456789abcdef',
            'videoid' => 'd4b3b9ce-531f-4f7a-a8db-847f47a889e9',
            'libraryid' => '123456',
            'endpoint' => 'https://video.bunnycdn.com/tusupload',
            'signature' => str_repeat('a', 64),
            'expiration' => time() + 300,
        ];
    }
}
