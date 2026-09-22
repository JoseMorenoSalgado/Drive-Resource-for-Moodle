<?php
// This file is part of Moodle - http://moodle.org/

/**
 * WHMCS media gateway client.
 *
 * Bunny Stream credentials deliberately never exist in Moodle. Moodle only
 * authenticates to the WHMCS gateway with a service-scoped token. WHMCS then
 * returns a short-lived, video-scoped TUS signature that is safe to expose to
 * the teacher browser for a direct upload.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoplayer\local;

defined('MOODLE_INTERNAL') || die();

use moodle_exception;

/**
 * Client for the Drive Resource WHMCS media gateway.
 */
final class whmcs_gateway_client {
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

        if ($this->baseurl === '' || $this->serviceid <= 0 || $this->servicetoken === '') {
            throw new moodle_exception('whmcsgatewaynotconfigured', 'mod_videoplayer');
        }

        $parts = parse_url($this->baseurl);
        if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !empty($parts['user']) || !empty($parts['pass'])) {
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

        if (!preg_match('/^[a-f0-9-]{32,64}$/i', $videoid)
            || !preg_match('/^[a-f0-9-]{20,64}$/i', $uploadid)
            || !preg_match('/^\d{1,20}$/', $libraryid)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)
            || $expiration <= time()
            || $expiration > time() + DAYSECS) {
            throw new moodle_exception('whmcsgatewayinvalidresponse', 'mod_videoplayer');
        }

        $endpointparts = parse_url($endpoint);
        if (!$endpointparts
            || strtolower((string)($endpointparts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($endpointparts['host'] ?? '')) !== 'video.bunnycdn.com'
            || rtrim((string)($endpointparts['path'] ?? ''), '/') !== '/tusupload'
            || !empty($endpointparts['user'])
            || !empty($endpointparts['pass'])) {
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
        if (!$endpointparts
            || strtolower((string)($endpointparts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($endpointparts['host'] ?? '')) !== 'video.bunnycdn.com'
            || rtrim((string)($endpointparts['path'] ?? ''), '/') !== '/tusupload') {
            throw new moodle_exception('whmcsgatewayinvaliduploadendpoint', 'mod_videoplayer');
        }

        $signature = strtolower(trim((string)$response['signature']));
        $expiration = (int)$response['expiration'];
        if (!preg_match('/^[a-f0-9]{64}$/', $signature)
            || $expiration <= time()
            || $expiration > time() + DAYSECS) {
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
