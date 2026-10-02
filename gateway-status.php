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
 * Signed WHMCS-to-Moodle connection status endpoint.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

/**
 * Send one safe JSON response and terminate.
 *
 * @package    mod_videoplayer
 * @param int $status HTTP status.
 * @param array $payload JSON payload.
 * @return never
 */
function mod_videoplayer_gateway_status_response(int $status, array $payload): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    die;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mod_videoplayer_gateway_status_response(405, ['ok' => false]);
}

$serviceid = (int) get_config('mod_videoplayer', 'whmcsserviceid');
$token = trim((string) get_config('mod_videoplayer', 'whmcsservicetoken'));
if ($serviceid <= 0 || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    mod_videoplayer_gateway_status_response(503, [
        'ok' => false,
        'status' => 'not_configured',
    ]);
}

$rawbody = file_get_contents('php://input');
if (!is_string($rawbody) || strlen($rawbody) > 8192) {
    mod_videoplayer_gateway_status_response(400, ['ok' => false]);
}

try {
    $payload = json_decode($rawbody, true, 16, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    mod_videoplayer_gateway_status_response(400, ['ok' => false]);
}

if (!is_array($payload)) {
    mod_videoplayer_gateway_status_response(400, ['ok' => false]);
}

$requestservice = (int) ($payload['serviceid'] ?? 0);
$siteurl = rtrim(trim((string) ($payload['siteurl'] ?? '')), '/');
$requestid = strtolower(trim((string) ($payload['requestid'] ?? '')));
$timestamp = (int) ($_SERVER['HTTP_X_DRIVE_RESOURCE_TIMESTAMP'] ?? 0);
$headernonce = strtolower(trim((string) ($_SERVER['HTTP_X_DRIVE_RESOURCE_NONCE'] ?? '')));
$headerservice = (int) ($_SERVER['HTTP_X_DRIVE_RESOURCE_SERVICE'] ?? 0);
$headersite = rtrim(trim((string) ($_SERVER['HTTP_X_DRIVE_RESOURCE_SITE'] ?? '')), '/');
$signature = strtolower(trim((string) ($_SERVER['HTTP_X_DRIVE_RESOURCE_SIGNATURE'] ?? '')));

if (
    $requestservice !== $serviceid
    || $headerservice !== $serviceid
    || $siteurl !== rtrim($CFG->wwwroot, '/')
    || $headersite !== rtrim($CFG->wwwroot, '/')
    || !preg_match('/^[a-f0-9]{32}$/', $requestid)
    || !hash_equals($requestid, $headernonce)
    || !preg_match('/^[a-f0-9]{64}$/', $signature)
    || abs(time() - $timestamp) > 300
) {
    mod_videoplayer_gateway_status_response(401, ['ok' => false]);
}

$signaturebase = $timestamp . "\n" . $requestid . "\n" . hash('sha256', $rawbody);
$expected = hash_hmac('sha256', $signaturebase, $token);
if (!hash_equals($expected, $signature)) {
    mod_videoplayer_gateway_status_response(401, ['ok' => false]);
}

$noncecache = cache::make('mod_videoplayer', 'gatewaynonces');
$noncekey = sha1($serviceid . '|' . $requestid);
if ($noncecache->get($noncekey)) {
    mod_videoplayer_gateway_status_response(409, [
        'ok' => false,
        'status' => 'replay_rejected',
    ]);
}
$noncecache->set($noncekey, 1);

mod_videoplayer_gateway_status_response(200, [
    'ok' => true,
    'status' => 'connected',
    'serviceid' => $serviceid,
    'siteurl' => rtrim($CFG->wwwroot, '/'),
    'pluginversion' => (int) get_config('mod_videoplayer', 'version'),
    'timestamp' => time(),
]);
