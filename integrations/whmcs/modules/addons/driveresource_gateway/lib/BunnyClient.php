<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use RuntimeException;

/**
 * Minimal Bunny Stream management client.
 */
final class BunnyClient
{
    private int $libraryId;
    private string $apiKey;

    public function __construct()
    {
        $this->libraryId = Config::libraryId();
        $this->apiKey = Config::bunnyApiKey();
    }

    /**
     * Create an empty Bunny video object for a direct TUS upload.
     *
     * @param string $title Video title.
     * @return string Video GUID.
     */
    public function createVideo(string $title): string
    {
        $response = $this->request('POST', '/library/' . $this->libraryId . '/videos', [
            'title' => mb_substr(trim($title) ?: 'Drive Resource video', 0, 255),
        ]);

        $videoId = trim((string) ($response['guid'] ?? ''));
        if (!preg_match('/^[a-f0-9-]{32,64}$/i', $videoId)) {
            throw new RuntimeException('Elearning Stream returned an invalid video GUID.');
        }

        return $videoId;
    }

    /**
     * Read Bunny's authoritative video state.
     *
     * @param string $videoId Video GUID.
     * @return array
     */
    public function getVideo(string $videoId): array
    {
        return $this->request('GET', '/library/' . $this->libraryId . '/videos/' . rawurlencode($videoId));
    }

    /**
     * Delete a Bunny video.
     *
     * @param string $videoId Video GUID.
     * @return void
     */
    public function deleteVideo(string $videoId): void
    {
        $this->request('DELETE', '/library/' . $this->libraryId . '/videos/' . rawurlencode($videoId), null, [200, 204]);
    }

    /**
     * Build Bunny's short-lived TUS authorization signature.
     *
     * Signature scope is one library + one video + one expiry timestamp.
     *
     * @param string $videoId Video GUID.
     * @param int $expiration Unix timestamp.
     * @return string
     */
    public function tusSignature(string $videoId, int $expiration): string
    {
        return hash('sha256', $this->libraryId . $this->apiKey . $expiration . $videoId);
    }

    /**
     * Build a short-lived MP4 fallback URL for Moodle server-side proxying.
     *
     * The signed provider URL is returned only to Moodle over the authenticated
     * WHMCS channel. Learner browsers receive the Moodle protected endpoint.
     *
     * @param string $videoId Provider video GUID.
     * @return array{url:string,expires:int,resolution:int}
     */
    public function playbackUrl(string $videoId): array
    {
        $video = $this->getVideo($videoId);
        if (empty($video['hasMP4Fallback'])) {
            throw new RuntimeException(
                'Elearning Stream MP4 fallback is not available for this video.'
            );
        }

        $resolution = $this->highestMp4Resolution(
            (string) ($video['availableResolutions'] ?? '')
        );
        if ($resolution <= 0) {
            throw new RuntimeException(
                'Elearning Stream did not report an MP4 playback resolution.'
            );
        }

        $expires = time() + Config::playbackTtl();
        $path = '/' . strtolower($videoId) . '/play_' . $resolution . 'p.mp4';
        $message = $path . $expires;
        $digest = hash_hmac(
            'sha256',
            $message,
            Config::playbackTokenKey(),
            true
        );
        $token = 'HS256-' . rtrim(
            strtr(base64_encode($digest), '+/', '-_'),
            '='
        );

        return [
            'url' => 'https://' . Config::cdnHostname()
                . $path
                . '?token=' . rawurlencode($token)
                . '&expires=' . $expires,
            'expires' => $expires,
            'resolution' => $resolution,
        ];
    }

    /**
     * Pick the highest encoded MP4 fallback resolution reported by provider.
     *
     * @param string $availableResolutions Comma-separated provider resolutions.
     * @return int Height in pixels, or zero when unavailable.
     */
    private function highestMp4Resolution(string $availableResolutions): int
    {
        preg_match_all('/(?:^|[,\\s])(\\d{2,4})p(?:$|[,\\s])/', $availableResolutions, $matches);
        $heights = array_map('intval', $matches[1] ?? []);
        $heights = array_values(array_filter(
            $heights,
            static fn(int $height): bool => $height >= 144 && $height <= 4320
        ));

        return $heights ? max($heights) : 0;
    }

    /**
     * Library id.
     *
     * @return int
     */
    public function libraryId(): int
    {
        return $this->libraryId;
    }

    /**
     * Perform a Bunny Stream management API request.
     *
     * @param string $method HTTP method.
     * @param string $path API path.
     * @param array|null $body Optional JSON body.
     * @param int[] $allowed Allowed response statuses.
     * @return array
     */
    private function request(string $method, string $path, ?array $body = null, array $allowed = [200, 201]): array
    {
        $curl = curl_init('https://video.bunnycdn.com' . $path);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialise Elearning Stream provider request.');
        }

        $headers = [
            'Accept: application/json',
            'AccessKey: ' . $this->apiKey,
        ];

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }

        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($raw === false || $error !== '') {
            throw new RuntimeException('Elearning Stream provider request failed.');
        }

        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        if (!in_array($status, $allowed, true)) {
            throw new RuntimeException('Elearning Stream provider rejected the request with HTTP ' . $status . '.');
        }
        if ($raw !== '' && !is_array($decoded)) {
            throw new RuntimeException('Elearning Stream provider returned malformed JSON.');
        }

        return is_array($decoded) ? $decoded : [];
    }
}
