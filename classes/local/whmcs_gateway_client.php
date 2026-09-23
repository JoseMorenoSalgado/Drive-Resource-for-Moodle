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
 * WHMCS media gateway client.
 *
 * Elearning Stream provider credentials deliberately never exist in Moodle. Moodle only
 * authenticates to the WHMCS gateway with a service-scoped token. WHMCS then
 * returns a short-lived, video-scoped TUS signature that is safe to expose to
 * the teacher browser for a direct upload.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoplayer\local;

use moodle_exception;

/**
 * Client for the Drive Resource WHMCS media gateway.
 */
final class whmcs_gateway_client {
    /**
     * Return required Moodle gateway settings that are currently missing.
     *
     * @return string[] Language-string keys for missing settings.
     */
    public static function missing_configuration(): array {
        $missing = [];

        if (trim((string)get_config('mod_videoplayer', 'whmcsgatewayurl')) === '') {
            $missing[] = 'setting_whmcsgatewayurl';
        }
        if ((int)get_config('mod_videoplayer', 'whmcsserviceid') <= 0) {
            $missing[] = 'setting_whmcsserviceid';
        }
        if (trim((string)get_config('mod_videoplayer', 'whmcsservicetoken')) === '') {
            $missing[] = 'setting_whmcsservicetoken';
        }

        return $missing;
    }

    /**
     * Whether Elearning Stream has all required Moodle-side WHMCS settings.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return self::missing_configuration() === [];
    }

    /**
     * Human-readable list of missing gateway settings.
     *
     * @return string
     */
    public static function missing_configuration_labels(): string {
        return implode(', ', array_map(
            static fn(string $key): string => get_string($key, 'mod_videoplayer'),
            self::missing_configuration()
        ));
    }

    /** @var string */
    private string $baseurl;

    /** @var int */
    private int $serviceid;

    /** @var string */
    private string $servicetoken;

    /** @var int */
    private int $timeout;

    /**
     * Constructor.
     *
     * @throws moodle_exception When the gateway is not configured securely.
     */
    public function __construct() {
        $this->baseurl = rtrim(trim((string)get_config('mod_videoplayer', 'whmcsgatewayurl')), '/');
        $this->serviceid = (int)get_config('mod_videoplayer', 'whmcsserviceid');
        $this->servicetoken = trim((string)get_config('mod_videoplayer', 'whmcsservicetoken'));
        $this->timeout = max(5, min(60, (int)(get_config('mod_videoplayer', 'whmcstimeout') ?: 15)));

        if (!self::is_configured()) {
            throw new moodle_exception(
                'whmcsgatewaynotconfigured',
                'mod_videoplayer',
                '',
                self::missing_configuration_labels()
            );
        }

        $parts = parse_url($this->baseurl);
        if (
            !$parts
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || !empty($parts['user'])
            || !empty($parts['pass'])
        ) {
            throw new moodle_exception('whmcsgatewayhttpsrequired', 'mod_videoplayer');
        }
    }

    /**
     * Request a short-lived direct Bunny Stream upload authorisation.
     *
     * @param array $payload Upload metadata.
     * @return array Sanitised upload authorisation.
     */
    public function create_upload(array $payload): array {
        $response = $this->post('/api/upload-authorize.php', $payload);

        foreach (['uploadid', 'videoid', 'libraryid', 'endpoint', 'signature', 'expiration'] as $key) {
            if (!array_key_exists($key, $response)) {
                throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
            }
        }

        $videoid = trim((string)$response['videoid']);
        $uploadid = trim((string)$response['uploadid']);
        $libraryid = trim((string)$response['libraryid']);
        $endpoint = trim((string)$response['endpoint']);
        $signature = strtolower(trim((string)$response['signature']));
        $expiration = (int)$response['expiration'];

        if (
            !preg_match('/^[a-f0-9-]{32,64}$/i', $videoid)
            || !preg_match('/^[a-f0-9-]{20,64}$/i', $uploadid)
            || !preg_match('/^\d{1,20}$/', $libraryid)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)
            || $expiration <= time()
            || $expiration > time() + DAYSECS
        ) {
            throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
        }

        $endpointparts = parse_url($endpoint);
        if (
            !$endpointparts
            || strtolower((string)($endpointparts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($endpointparts['host'] ?? '')) !== 'video.bunnycdn.com'
            || rtrim((string)($endpointparts['path'] ?? ''), '/') !== '/tusupload'
            || !empty($endpointparts['user'])
            || !empty($endpointparts['pass'])
        ) {
            throw new moodle_exception('whmcsgatewayinvaliduploadendpoint', 'mod_videoplayer');
        }

        $quota = is_array($response['quota'] ?? null) ? $response['quota'] : [];

        return [
            'uploadid' => $uploadid,
            'videoid' => $videoid,
            'libraryid' => $libraryid,
            'endpoint' => $endpoint,
            'signature' => $signature,
            'expiration' => $expiration,
            'quota' => [
                'includedbytes' => max(0, (int)($quota['includedbytes'] ?? 0)),
                'usedbytes' => max(0, (int)($quota['usedbytes'] ?? 0)),
                'reservedbytes' => max(0, (int)($quota['reservedbytes'] ?? 0)),
                'projectedbytes' => max(0, (int)($quota['projectedbytes'] ?? 0)),
                'overagebytes' => max(0, (int)($quota['overagebytes'] ?? 0)),
                'overageallowed' => !empty($quota['overageallowed']),
            ],
        ];
    }

    /**
     * Refresh a short-lived TUS authorization for an existing upload.
     *
     * @param string $uploadid WHMCS reservation identifier.
     * @param string $videoid Bunny video GUID.
     * @return array Sanitised TUS authorization.
     */
    public function refresh_upload(string $uploadid, string $videoid): array {
        $response = $this->post('/api/upload-refresh.php', [
            'uploadid' => $uploadid,
            'videoid' => $videoid,
        ]);

        foreach (['uploadid', 'videoid', 'libraryid', 'endpoint', 'signature', 'expiration'] as $key) {
            if (!array_key_exists($key, $response)) {
                throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
            }
        }

        $endpoint = trim((string)$response['endpoint']);
        $endpointparts = parse_url($endpoint);
        if (
            !$endpointparts
            || strtolower((string)($endpointparts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($endpointparts['host'] ?? '')) !== 'video.bunnycdn.com'
            || rtrim((string)($endpointparts['path'] ?? ''), '/') !== '/tusupload'
        ) {
            throw new moodle_exception('whmcsgatewayinvaliduploadendpoint', 'mod_videoplayer');
        }

        $signature = strtolower(trim((string)$response['signature']));
        $expiration = (int)$response['expiration'];
        if (
            !preg_match('/^[a-f0-9]{64}$/', $signature)
            || $expiration <= time()
            || $expiration > time() + DAYSECS
        ) {
            throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
        }

        return [
            'uploadid' => clean_param((string)$response['uploadid'], PARAM_ALPHANUMEXT),
            'videoid' => clean_param((string)$response['videoid'], PARAM_ALPHANUMEXT),
            'libraryid' => trim((string)$response['libraryid']),
            'endpoint' => $endpoint,
            'signature' => $signature,
            'expiration' => $expiration,
        ];
    }

    /**
     * Confirm that the browser completed the TUS upload.
     *
     * @param string $uploadid WHMCS reservation identifier.
     * @param string $videoid Bunny video GUID.
     * @param int $filesize Source file size.
     * @return array Gateway status.
     */
    public function complete_upload(string $uploadid, string $videoid, int $filesize): array {
        return $this->post('/api/upload-complete.php', [
            'uploadid' => $uploadid,
            'videoid' => $videoid,
            'filesize' => max(0, $filesize),
        ]);
    }

    /**
     * Register an existing Elearning Stream video with this WHMCS service.
     *
     * @param string $videoid Provider video GUID.
     * @param int $courseid Moodle course id.
     * @return array Sanitised provider/accounting state.
     */
    public function import_asset(string $videoid, int $courseid): array {
        $response = $this->post('/api/asset-import.php', [
            'videoid' => $videoid,
            'courseid' => max(0, $courseid),
        ]);

        $uploadid = clean_param((string)($response['uploadid'] ?? ''), PARAM_ALPHANUMEXT);
        $returnedvideoid = clean_param((string)($response['videoid'] ?? ''), PARAM_ALPHANUMEXT);
        $status = clean_param((string)($response['status'] ?? ''), PARAM_ALPHANUMEXT);
        $filesize = max(0, (int)($response['filesize'] ?? 0));

        if (
            !preg_match('/^[a-f0-9-]{20,64}$/i', $uploadid)
            || !preg_match('/^[a-f0-9-]{32,64}$/i', $returnedvideoid)
            || !in_array($status, ['processing', 'ready'], true)
            || !hash_equals(strtolower($videoid), strtolower($returnedvideoid))
        ) {
            throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
        }

        return [
            'uploadid' => $uploadid,
            'videoid' => $returnedvideoid,
            'filesize' => $filesize,
            'status' => $status,
            'quota' => is_array($response['quota'] ?? null) ? $response['quota'] : [],
        ];
    }

    /**
     * Resolve a short-lived Elearning Stream MP4 URL for server-side proxying.
     *
     * The returned URL must never be rendered into a learner template.
     *
     * @param string $videoid Provider video GUID.
     * @param bool $forcerefresh Ignore cached authorization.
     * @return string Validated provider playback URL.
     */
    public function playback_url(string $videoid, bool $forcerefresh = false): string {
        $videoid = strtolower(trim($videoid));
        if (!preg_match('/^[a-f0-9-]{32,64}$/', $videoid)) {
            throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
        }

        $cache = \cache::make('mod_videoplayer', 'streamplayback');
        $cachekey = sha1($this->serviceid . '|' . $videoid);
        if ($forcerefresh) {
            $cache->delete($cachekey);
        } else {
            $cached = $cache->get($cachekey);
            $decoded = is_string($cached) && $cached !== '' ? json_decode($cached, true) : null;
            if (
                is_array($decoded)
                && (int)($decoded['expires'] ?? 0) > time() + 30
                && $this->is_valid_playback_url((string)($decoded['url'] ?? ''), $videoid)
            ) {
                return (string)$decoded['url'];
            }
        }

        $response = $this->post('/api/playback-authorize.php', [
            'videoid' => $videoid,
        ]);
        $url = trim((string)($response['url'] ?? ''));
        $returnedvideoid = strtolower(trim((string)($response['videoid'] ?? '')));
        $expires = (int)($response['expires'] ?? 0);

        if (
            !hash_equals($videoid, $returnedvideoid)
            || $expires <= time() + 30
            || $expires > time() + HOURSECS
            || !$this->is_valid_playback_url($url, $videoid)
        ) {
            throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
        }

        $parts = parse_url($url);
        parse_str((string)($parts['query'] ?? ''), $query);
        if (
            (int)($query['expires'] ?? 0) !== $expires
            || !preg_match('/^HS256-[A-Za-z0-9_-]{20,}$/', (string)($query['token'] ?? ''))
        ) {
            throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
        }

        $cache->set($cachekey, json_encode([
            'url' => $url,
            'expires' => $expires,
        ], JSON_UNESCAPED_SLASHES));

        return $url;
    }

    /**
     * Validate a provider playback URL returned by the trusted WHMCS gateway.
     *
     * @param string $url Playback URL.
     * @param string $videoid Expected video GUID.
     * @return bool
     */
    private function is_valid_playback_url(string $url, string $videoid): bool {
        $parts = parse_url($url);
        if (
            !$parts
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || !empty($parts['user'])
            || !empty($parts['pass'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
        ) {
            return false;
        }

        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if ($host === 'b-cdn.net' || !str_ends_with($host, '.b-cdn.net')) {
            return false;
        }

        $path = (string)($parts['path'] ?? '');
        return preg_match(
            '#^/' . preg_quote($videoid, '#') . '/play_\\d{2,4}p\\.mp4$#',
            $path
        ) === 1;
    }

    /**
     * Report one idempotent protected-transfer usage batch to WHMCS.
     *
     * @param string $period Billing month in YYYY-MM format.
     * @param int $bytes Delivered bytes.
     * @param string $reportid Stable SHA-256 batch identifier.
     * @return void
     */
    public function report_transfer(string $period, int $bytes, string $reportid): void {
        if (
            !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $period)
            || $bytes <= 0
            || $bytes > 1099511627776
            || !preg_match('/^[a-f0-9]{64}$/', $reportid)
        ) {
            throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
        }

        $this->post('/api/usage-report.php', [
            'period' => $period,
            'bytes' => $bytes,
            'reportid' => $reportid,
        ]);
    }

    /**
     * Bind a completed provider asset to a Moodle activity instance.
     *
     * @param string $uploadid WHMCS reservation identifier.
     * @param string $videoid Bunny video GUID.
     * @param int $instanceid Moodle activity instance id.
     * @param int $courseid Moodle course id.
     * @return array Gateway status.
     */
    public function bind_asset(string $uploadid, string $videoid, int $instanceid, int $courseid): array {
        return $this->post('/api/asset-bind.php', [
            'uploadid' => $uploadid,
            'videoid' => $videoid,
            'instanceid' => $instanceid,
            'courseid' => $courseid,
        ]);
    }

    /**
     * Reconcile an externally referenced provider asset after restore.
     *
     * WHMCS must verify that the video belongs to this service before adding
     * a reference. This protects cross-tenant backup restores.
     *
     * @param string $videoid Bunny video GUID.
     * @param int $instanceid Moodle activity instance id.
     * @param int $courseid Moodle course id.
     * @return array Gateway status.
     */
    public function reconcile_asset(string $videoid, int $instanceid, int $courseid): array {
        return $this->post('/api/asset-reconcile.php', [
            'videoid' => $videoid,
            'instanceid' => $instanceid,
            'courseid' => $courseid,
        ]);
    }

    /**
     * Release a provider asset from a Moodle activity.
     *
     * WHMCS owns retention and any eventual destructive Bunny operation.
     *
     * @param string $videoid Bunny video GUID.
     * @param int $instanceid Moodle activity instance id.
     * @return array Gateway status.
     */
    public function release_asset(string $videoid, int $instanceid): array {
        return $this->post('/api/asset-release.php', [
            'videoid' => $videoid,
            'instanceid' => $instanceid,
        ]);
    }

    /**
     * Send a signed JSON POST request to WHMCS.
     *
     * @param string $path Relative API path.
     * @param array $payload Request payload.
     * @return array Decoded response.
     */
    private function post(string $path, array $payload): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $payload['serviceid'] = $this->serviceid;
        $payload['siteurl'] = $CFG->wwwroot;
        $payload['requestid'] = bin2hex(random_bytes(16));
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signaturebase = $timestamp . "\n" . $payload['requestid'] . "\n" . hash('sha256', $body);
        $requestsignature = hash_hmac('sha256', $signaturebase, $this->servicetoken);

        $curl = new \curl();
        $curl->setHeader([
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->servicetoken,
            'X-Drive-Resource-Service: ' . $this->serviceid,
            'X-Drive-Resource-Site: ' . $CFG->wwwroot,
            'X-Drive-Resource-Timestamp: ' . $timestamp,
            'X-Drive-Resource-Nonce: ' . $payload['requestid'],
            'X-Drive-Resource-Signature: ' . $requestsignature,
        ]);

        $raw = $curl->post($this->baseurl . $path, $body, [
            'CURLOPT_CONNECTTIMEOUT' => min(10, $this->timeout),
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_MAXREDIRS' => 0,
        ]);
        $info = $curl->get_info();
        $status = (int)($info['http_code'] ?? 0);

        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? clean_param((string)($decoded['message'] ?? ''), PARAM_TEXT) : '';
            throw new moodle_exception(
                'whmcsgatewayremoteerror',
                'mod_videoplayer',
                '',
                null,
                ($message !== '' ? $message : get_string('whmcsgatewayrequestfailed', 'mod_videoplayer'))
                    . ' [HTTP ' . $status . ']'
            );
        }

        if (($decoded['ok'] ?? false) !== true) {
            throw new moodle_exception(
                'whmcsgatewayremoteerror',
                'mod_videoplayer',
                '',
                null,
                clean_param((string)($decoded['message'] ?? ''), PARAM_TEXT)
            );
        }

        return $decoded;
    }
}
