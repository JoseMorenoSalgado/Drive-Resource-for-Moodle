<?php

namespace WHMCS\Module\Addon\Driveresource_gateway;

use Throwable;
use WHMCS\Database\Capsule;

/**
 * Authenticate Moodle-to-WHMCS gateway requests.
 */
final class RequestAuthenticator
{
    /**
     * Authenticate one JSON request and reserve its nonce.
     *
     * @param string $rawBody Exact request body.
     * @return array{service:object,payload:array,siteurl:string}
     */
    public function authenticate(string $rawBody): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new GatewayException('Method not allowed.', 405);
        }
        if (strlen($rawBody) > 65536) {
            throw new GatewayException('Request body is too large.', 413);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new GatewayException('Invalid JSON request body.', 400);
        }

        $serviceId = (int) $this->header('X-Drive-Resource-Service');
        $timestamp = (int) $this->header('X-Drive-Resource-Timestamp');
        $nonce = strtolower($this->header('X-Drive-Resource-Nonce'));
        $signature = strtolower($this->header('X-Drive-Resource-Signature'));
        $site = $this->canonicalSite($this->header('X-Drive-Resource-Site'));
        $authorization = $this->header('Authorization');

        if ($serviceId <= 0
            || !preg_match('/^[a-f0-9]{32}$/', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)
            || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $tokenMatch)) {
            throw new GatewayException('Missing or invalid gateway authentication.', 401);
        }

        $token = trim($tokenMatch[1]);
        if (strlen($token) < 32 || strlen($token) > 256) {
            throw new GatewayException('Invalid service token.', 401);
        }

        $now = time();
        if ($timestamp <= 0 || abs($now - $timestamp) > Config::clockSkew()) {
            throw new GatewayException('Request timestamp is outside the allowed window.', 401);
        }

        if ((int) ($payload['serviceid'] ?? 0) !== $serviceId
            || strtolower((string) ($payload['requestid'] ?? '')) !== $nonce
            || $this->canonicalSite((string) ($payload['siteurl'] ?? '')) !== $site) {
            throw new GatewayException('Signed request metadata does not match the body.', 401);
        }

        $service = Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->first();
        if (!$service) {
            throw new GatewayException('Drive Resource service was not found.', 404);
        }

        if ((string) $service->status !== 'active') {
            throw new GatewayException('Drive Resource service is not active.', 403);
        }

        if (!hash_equals((string) $service->site_hash, hash('sha256', $site))
            || !hash_equals((string) $service->site_url, $site)
            || !hash_equals((string) $service->token_hash, hash('sha256', $token))) {
            throw new GatewayException('Service identity does not match this Moodle site.', 401);
        }

        $hosting = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.id', $serviceId)
            ->select(['h.domainstatus', 'p.servertype'])
            ->first();
        if (!$hosting || (string) $hosting->servertype !== 'driveresource' || (string) $hosting->domainstatus !== 'Active') {
            throw new GatewayException('WHMCS service is not active.', 403);
        }

        $bodyHash = hash('sha256', $rawBody);
        $expected = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $bodyHash, $token);
        if (!hash_equals($expected, $signature)) {
            throw new GatewayException('Request signature validation failed.', 401);
        }

        Capsule::table('mod_driveresource_nonces')
            ->where('service_id', $serviceId)
            ->where('expires_at', '<', $now)
            ->delete();

        try {
            Capsule::table('mod_driveresource_nonces')->insert([
                'service_id' => $serviceId,
                'nonce' => $nonce,
                'expires_at' => $now + Config::clockSkew() + 60,
            ]);
        } catch (Throwable $exception) {
            throw new GatewayException('Replay request rejected.', 409);
        }

        return [
            'service' => $service,
            'payload' => $payload,
            'siteurl' => $site,
        ];
    }

    /**
     * Read a request header from the PHP server array.
     *
     * @param string $name Header name.
     * @return string
     */
    private function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (strcasecmp($name, 'Authorization') === 0 && empty($_SERVER[$key])) {
            return trim((string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        }

        return trim((string) ($_SERVER[$key] ?? ''));
    }

    /**
     * Canonicalise a Moodle site URL for strict tenant binding.
     *
     * @param string $url Site URL.
     * @return string
     */
    private function canonicalSite(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new GatewayException('Moodle site URL must be HTTPS.', 400);
        }
        if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
            throw new GatewayException('Moodle site URL contains unsupported components.', 400);
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return 'https://' . strtolower((string) $parts['host']) . $port . $path;
    }
}
