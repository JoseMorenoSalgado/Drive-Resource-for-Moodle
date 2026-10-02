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

namespace mod_videoplayer\local\gateway;

use moodle_exception;

/**
 * Validates and normalises short-lived TUS upload authorisations.
 *
 * The gateway is authoritative for provider credentials, but Moodle still
 * validates every browser-exposed upload capability before returning it to
 * JavaScript. Refresh responses are additionally bound to the reservation and
 * video identifiers that Moodle requested.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upload_authorisation {
    /** @var string[] Required gateway response fields. */
    private const REQUIRED_FIELDS = [
        'uploadid',
        'videoid',
        'libraryid',
        'endpoint',
        'signature',
        'expiration',
    ];

    /**
     * Validate and normalise one gateway upload authorisation.
     *
     * @param array $response Gateway response.
     * @param string|null $expecteduploadid Reservation id expected on refresh.
     * @param string|null $expectedvideoid Video id expected on refresh.
     * @return array Sanitised browser-safe authorisation.
     */
    public static function normalise(
        array $response,
        ?string $expecteduploadid = null,
        ?string $expectedvideoid = null
    ): array {
        foreach (self::REQUIRED_FIELDS as $key) {
            if (!array_key_exists($key, $response)) {
                throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
            }
        }

        $uploadid = trim((string)$response['uploadid']);
        $videoid = trim((string)$response['videoid']);
        $libraryid = trim((string)$response['libraryid']);
        $endpoint = trim((string)$response['endpoint']);
        $signature = strtolower(trim((string)$response['signature']));
        $expiration = (int)$response['expiration'];
        $now = time();

        if (
            !preg_match('/^[a-f0-9]{32}$/', $uploadid)
            || !preg_match('/^[a-f0-9-]{32,64}$/i', $videoid)
            || !preg_match('/^\d{1,20}$/', $libraryid)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)
            || $expiration <= $now
            || $expiration > $now + DAYSECS
        ) {
            throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
        }

        self::require_expected_identifier($uploadid, $expecteduploadid);
        self::require_expected_identifier($videoid, $expectedvideoid);
        self::validate_endpoint($endpoint);

        return [
            'uploadid' => $uploadid,
            'videoid' => $videoid,
            'libraryid' => $libraryid,
            'endpoint' => $endpoint,
            'signature' => $signature,
            'expiration' => $expiration,
        ];
    }

    /**
     * Bind a refreshed authorisation to the identifier Moodle requested.
     *
     * @param string $actual Identifier returned by the gateway.
     * @param string|null $expected Identifier sent by Moodle.
     * @return void
     */
    private static function require_expected_identifier(string $actual, ?string $expected): void {
        if ($expected === null) {
            return;
        }

        $expected = trim($expected);
        if (
            $expected === ''
            || !hash_equals(strtolower($expected), strtolower($actual))
        ) {
            throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
        }
    }

    /**
     * Enforce the browser-facing Bunny TUS endpoint allowlist.
     *
     * @param string $endpoint Gateway-provided upload endpoint.
     * @return void
     */
    private static function validate_endpoint(string $endpoint): void {
        $parts = parse_url($endpoint);
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;

        if (
            !$parts
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'video.bunnycdn.com'
            || $port !== 443
            || rtrim((string)($parts['path'] ?? ''), '/') !== '/tusupload'
            || !empty($parts['user'])
            || !empty($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new moodle_exception('whmcsgatewayinvaliduploadendpoint', 'mod_videoplayer');
        }
    }
}
