<?php

namespace WHMCS\Module\Server\Driveresource;

use RuntimeException;

/**
 * Signed server-to-server connection probe for a configured Moodle site.
 */
final class MoodleConnectionProbe
{
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
                'message' => 'El servicio todavía no tiene una credencial Moodle válida.',
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
        ) {
            return [
                'connected' => false,
                'message' => 'La URL de Moodle no es HTTPS válida.',
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
                'message' => 'No se pudo contactar el aula virtual.',
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
                ? 'Drive Resource está instalado, pero la conexión WHMCS no está configurada en Moodle.'
                : 'Moodle respondió, pero Service ID, URL o token no coinciden.';

            return [
                'connected' => false,
                'message' => $message,
                'pluginversion' => 0,
            ];
        }

        return [
            'connected' => true,
            'message' => 'Conexión con Moodle verificada correctamente.',
            'pluginversion' => max(0, (int) ($decoded['pluginversion'] ?? 0)),
        ];
    }
}
