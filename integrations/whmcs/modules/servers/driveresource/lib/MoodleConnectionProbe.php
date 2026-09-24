<?php

namespace WHMCS\Module\Server\Driveresource;

use RuntimeException;

/**
 * Signed server-to-server connection probe for a configured Moodle site.
 */
final class MoodleConnectionProbe
{
    /** @var Translator Module-local translator. */
    private Translator $translator;

    /**
     * @param Translator $translator Module translator.
     */
    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Validate the Moodle plugin/service binding.
     *
     * @param int $serviceId WHMCS service id.
     * @param string $siteUrl Exact Moodle wwwroot.
     * @param string $token Service-scoped gateway token.
     * @return array{connected:bool,message:string,pluginversion:int}
     */
    public function probe(int $serviceId, string $siteUrl, string $token): array
    {
        if ($serviceId <= 0 || strlen($token) < 32) {
            return [
                'connected' => false,
                'message' => $this->translator->t('connection_credentials_missing'),
                'pluginversion' => 0,
            ];
        }

        $siteUrl = rtrim(trim($siteUrl), '/');
        $parts = parse_url($siteUrl);
        if (
            !$parts
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || !empty($parts['user'])
            || !empty($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            return [
                'connected' => false,
                'message' => $this->translator->t('connection_invalid_url'),
                'pluginversion' => 0,
            ];
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        try {
            $publicIp = $this->resolvePublicIp($host);
        } catch (RuntimeException $exception) {
            return [
                'connected' => false,
                'message' => $exception->getMessage(),
                'pluginversion' => 0,
            ];
        }

        $requestId = bin2hex(random_bytes(16));
        $payload = [
            'serviceid' => $serviceId,
            'siteurl' => $siteUrl,
            'requestid' => $requestId,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signatureBase = $timestamp . "\n" . $requestId . "\n" . hash('sha256', $body);
        $signature = hash_hmac('sha256', $signatureBase, $token);

        $curl = curl_init($siteUrl . '/mod/videoplayer/gateway-status.php');
        if ($curl === false) {
            throw new RuntimeException('Unable to initialise Moodle connection probe.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [
                $host . ':443:' . (str_contains($publicIp, ':') ? '[' . $publicIp . ']' : $publicIp),
            ],
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Drive-Resource-Service: ' . $serviceId,
                'X-Drive-Resource-Site: ' . $siteUrl,
                'X-Drive-Resource-Timestamp: ' . $timestamp,
                'X-Drive-Resource-Nonce: ' . $requestId,
                'X-Drive-Resource-Signature: ' . $signature,
            ],
        ]);

        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($raw === false || $error !== '') {
            return [
                'connected' => false,
                'message' => $this->translator->t('connection_unreachable'),
                'pluginversion' => 0,
            ];
        }

        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (
            $status !== 200
            || !is_array($decoded)
            || ($decoded['ok'] ?? false) !== true
            || (int) ($decoded['serviceid'] ?? 0) !== $serviceId
            || rtrim((string) ($decoded['siteurl'] ?? ''), '/') !== $siteUrl
        ) {
            $message = $status === 503
                ? $this->translator->t('connection_plugin_not_configured')
                : $this->translator->t('connection_mismatch');

            return [
                'connected' => false,
                'message' => $message,
                'pluginversion' => 0,
            ];
        }

        return [
            'connected' => true,
            'message' => $this->translator->t('connection_verified'),
            'pluginversion' => max(0, (int) ($decoded['pluginversion'] ?? 0)),
        ];
    }

    /**
     * Resolve and pin a public IP for the customer-supplied Moodle hostname.
     *
     * Rejecting every private/reserved DNS answer prevents the connection
     * probe from becoming an SSRF primitive against WHMCS internal networks.
     *
     * @param string $host Moodle hostname.
     * @return string Public IPv4 or IPv6 address.
     */
    private function resolvePublicIp(string $host): string
    {
        if (
            $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
        ) {
            throw new RuntimeException($this->translator->t('connection_invalid_url'));
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || $records === []) {
            throw new RuntimeException($this->translator->t('connection_dns_invalid'));
        }

        $addresses = [];
        foreach ($records as $record) {
            $ip = trim((string) ($record['ip'] ?? $record['ipv6'] ?? ''));
            if ($ip === '') {
                continue;
            }

            $public = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($public === false) {
                throw new RuntimeException(
                    $this->translator->t('connection_private_network')
                );
            }
            $addresses[] = $ip;
        }

        if ($addresses === []) {
            throw new RuntimeException($this->translator->t('connection_dns_invalid'));
        }

        return $addresses[0];
    }
}
