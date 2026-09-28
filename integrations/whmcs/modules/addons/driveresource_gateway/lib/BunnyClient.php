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
     * @param string|null $collectionId Optional virtual-classroom collection.
     * @return string Video GUID.
     */
    public function createVideo(string $title, ?string $collectionId = null): string
    {
        $body = [
            'title' => mb_substr(trim($title) ?: 'Elearning Stream video', 0, 255),
        ];
        if ($collectionId !== null && $collectionId !== '') {
            $this->assertProviderGuid($collectionId, 'collection');
            $body['collectionId'] = $collectionId;
        }

        $response = $this->request('POST', '/library/' . $this->libraryId . '/videos', $body);

        $videoId = trim((string) ($response['guid'] ?? ''));
        if (!preg_match('/^[a-f0-9-]{32,64}$/i', $videoId)) {
            throw new RuntimeException('Elearning Stream returned an invalid video GUID.');
        }

        return $videoId;
    }

    /**
     * Create one Bunny collection used as the service's virtual classroom.
     *
     * @param string $name Collection display name.
     * @return array{id:string,name:string}
     */
    public function createCollection(string $name): array
    {
        $name = mb_substr(trim($name), 0, 191);
        if ($name === '') {
            throw new RuntimeException('Virtual classroom collection name is required.');
        }

        $response = $this->request(
            'POST',
            '/library/' . $this->libraryId . '/collections',
            ['name' => $name]
        );
        $collectionId = trim((string) ($response['guid'] ?? $response['id'] ?? ''));
        $this->assertProviderGuid($collectionId, 'collection');

        return [
            'id' => strtolower($collectionId),
            'name' => trim((string) ($response['name'] ?? $name)),
        ];
    }

    /**
     * Delete a Bunny collection created by a losing concurrent initializer.
     *
     * @param string $collectionId Collection GUID.
     * @return void
     */
    public function deleteCollection(string $collectionId): void
    {
        $this->assertProviderGuid($collectionId, 'collection');
        $this->request(
            'DELETE',
            '/library/' . $this->libraryId . '/collections/' . rawurlencode($collectionId),
            null,
            [200, 204, 404]
        );
    }

    /**
     * Move one video into a virtual-classroom collection and attach safe tags.
     *
     * @param string $videoId Video GUID.
     * @param string $collectionId Collection GUID.
     * @param array<int,array{property:string,value:string}> $metaTags Non-secret metadata.
     * @return void
     */
    public function setVideoCollection(string $videoId, string $collectionId, array $metaTags = []): void
    {
        $this->assertProviderGuid($videoId, 'video');
        $this->assertProviderGuid($collectionId, 'collection');

        $body = ['collectionId' => strtolower($collectionId)];
        if ($metaTags !== []) {
            $body['metaTags'] = array_values($metaTags);
        }

        $this->request(
            'POST',
            '/library/' . $this->libraryId . '/videos/' . rawurlencode($videoId),
            $body,
            [200]
        );
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
     * Change a video title in the service-owned Bunny library.
     *
     * @param string $videoId Video GUID.
     * @param string $title New display title.
     * @return void
     */
    public function renameVideo(string $videoId, string $title): void
    {
        $this->assertProviderGuid($videoId, 'video');
        $this->request('POST', '/library/' . $this->libraryId . '/videos/' . rawurlencode($videoId),
            ['title' => $title], [200]);
    }

    /**
     * Delete a Bunny video.
     *
     * @param string $videoId Video GUID.
     * @return void
     */
    public function deleteVideo(string $videoId): void
    {
        $this->request(
            'DELETE',
            '/library/' . $this->libraryId . '/videos/' . rawurlencode($videoId),
            null,
            [200, 204, 404]
        );
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

        $url = 'https://' . Config::cdnHostname()
            . $path
            . '?token=' . rawurlencode($token)
            . '&expires=' . $expires;

        // Validate the exact URL that Moodle will proxy. This catches a wrong
        // CDN hostname/token key, disabled Direct Play, referrer restrictions,
        // or a missing MP4 object before the learner sees a generic player
        // failure. Only one byte is requested and the URL never leaves WHMCS.
        $this->assertPlaybackUrlAccessible($url);

        return [
            'url' => $url,
            'expires' => $expires,
            'resolution' => $resolution,
        ];
    }

    /**
     * Verify that Bunny accepts the signed progressive MP4 URL.
     *
     * The probe intentionally mirrors Moodle's server-side request: no browser
     * referrer is supplied, HTTPS is mandatory and redirects are rejected.
     * Downloaded bytes are discarded and at most the first byte is requested.
     *
     * @param string $url Signed provider playback URL.
     * @return void
     */
    private function assertPlaybackUrlAccessible(string $url): void
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException(
                'Elearning Stream CDN playback probe could not be initialized.'
            );
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RANGE => '0-0',
            CURLOPT_HTTPHEADER => [
                'Accept: video/mp4,application/octet-stream;q=0.9,*/*;q=0.1',
                'Accept-Encoding: identity',
            ],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $data): int {
                return strlen($data);
            },
        ]);

        $result = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = strtolower(trim((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
        curl_close($curl);

        if ($result === false || $error !== '') {
            throw new RuntimeException(
                'Elearning Stream CDN playback probe could not connect.'
            );
        }

        if (in_array($status, [200, 206], true)) {
            if (
                $contentType === ''
                || str_starts_with($contentType, 'video/')
                || str_starts_with($contentType, 'application/octet-stream')
            ) {
                return;
            }

            throw new RuntimeException(
                'Elearning Stream CDN returned an unexpected playback content type.'
            );
        }

        if (in_array($status, [401, 403], true)) {
            throw new RuntimeException(
                'Elearning Stream CDN rejected protected playback (HTTP '
                    . $status
                    . '). Verify the CDN hostname, CDN/embed token key, Direct Play, and referrer restrictions.'
            );
        }

        if ($status === 404) {
            throw new RuntimeException(
                'Elearning Stream CDN could not locate the MP4 fallback (HTTP 404). '
                    . 'Verify the CDN hostname, Direct Play, and the encoded fallback resolution.'
            );
        }

        throw new RuntimeException(
            'Elearning Stream CDN playback probe failed with HTTP ' . $status . '.'
        );
    }

    /**
     * Pick the highest encoded MP4 fallback resolution reported by provider.
     *
     * @param string $availableResolutions Comma-separated provider resolutions.
     * @return int Height in pixels, or zero when unavailable.
     */
    private function highestMp4Resolution(string $availableResolutions): int
    {
        preg_match_all('/\\b(\\d{2,4})p\\b/i', $availableResolutions, $matches);
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
     * Validate provider GUIDs before constructing management API paths.
     *
     * @param string $value Provider identifier.
     * @param string $kind Human-readable resource kind.
     * @return void
     */
    private function assertProviderGuid(string $value, string $kind): void
    {
        if (!preg_match('/^[a-f0-9-]{32,64}$/i', trim($value))) {
            throw new RuntimeException('Elearning Stream returned an invalid ' . $kind . ' GUID.');
        }
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
