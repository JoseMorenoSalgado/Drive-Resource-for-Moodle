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

namespace mod_videoplayer\local;

use mod_videoplayer\local\stream\upstream_url_policy;


/**
 * Resolve a short-lived progressive Google Drive playback stream.
 *
 * The resolver prefers the Workspace video playback service used by the
 * current Drive web player and falls back to the legacy get_video_info
 * response for older/public files. The browser never receives these upstream
 * URLs directly: protected.php proxies them after Moodle capability checks.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class drive_stream_resolver {
    /** Public browser API key used by the Google Drive web playback client. */
    private const WORKSPACE_VIDEO_API_KEY = 'AIzaSyDVQw45DwoYh632gvsP5vPDqEKvb-Ywnb8';

    /** @var array<int, array{ext:string,height:int}> Legacy progressive formats. */
    private const KNOWN_FORMATS = [
        18 => ['ext' => 'mp4', 'height' => 360],
        59 => ['ext' => 'mp4', 'height' => 480],
        22 => ['ext' => 'mp4', 'height' => 720],
        37 => ['ext' => 'mp4', 'height' => 1080],
        43 => ['ext' => 'webm', 'height' => 360],
        44 => ['ext' => 'webm', 'height' => 480],
        45 => ['ext' => 'webm', 'height' => 720],
        46 => ['ext' => 'webm', 'height' => 1080],
    ];

    /**
     * Resolve the best browser-compatible progressive stream.
     *
     * @param string $fileid Google Drive file id.
     * @param bool $forcerefresh Bypass and replace any cached signed stream URL.
     * @return string|null
     */
    public static function resolve(string $fileid, bool $forcerefresh = false): ?string {
        $fileid = clean_param($fileid, PARAM_ALPHANUMEXT);
        if ($fileid === '') {
            return null;
        }

        $cache = null;
        try {
            $cache = \cache::make('mod_videoplayer', 'drivestream');
            if ($forcerefresh) {
                $cache->delete($fileid);
            } else {
                $cached = $cache->get($fileid);
                if (is_string($cached) && upstream_url_policy::is_allowed($cached)) {
                    return $cached;
                }
            }
        } catch (\Throwable $exception) {
            debugging('Drive Resource stream cache unavailable: ' . $exception->getMessage(), DEBUG_DEVELOPER);
        }

        // Current Drive playback API. This is substantially more reliable than
        // get_video_info for public videos processed by modern Google Drive.
        $streamurl = self::resolve_workspace_playback($fileid);

        // Compatibility path for older/public Drive objects.
        if ($streamurl === null) {
            $streamurl = self::resolve_legacy_video_info($fileid);
        }

        if ($streamurl !== null && $cache !== null) {
            try {
                $cache->set($fileid, $streamurl);
            } catch (\Throwable $exception) {
                debugging('Drive Resource stream cache write failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return $streamurl;
    }

    /**
     * Resolve progressive transcodes from the current Workspace video API.
     *
     * @param string $fileid
     * @return string|null
     */
    private static function resolve_workspace_playback(string $fileid): ?string {
        $endpoint = 'https://content-workspacevideo-pa.googleapis.com/v1/drive/media/'
            . rawurlencode($fileid) . '/playback?key=' . rawurlencode(self::WORKSPACE_VIDEO_API_KEY);

        $body = self::fetch($endpoint, true);
        if ($body === null || $body === '') {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            debugging('Drive Resource workspace playback returned invalid JSON.', DEBUG_DEVELOPER);
            return null;
        }

        $progressive = $data['mediaStreamingData']['formatStreamingData']['progressiveTranscodes'] ?? [];
        if (!is_array($progressive) || !$progressive) {
            return null;
        }

        $candidates = [];
        foreach ($progressive as $format) {
            if (!is_array($format)) {
                continue;
            }

            $url = isset($format['url']) && is_string($format['url'])
                ? self::normalise_stream_url($format['url'])
                : null;
            if ($url === null) {
                continue;
            }

            $metadata = isset($format['transcodeMetadata']) && is_array($format['transcodeMetadata'])
                ? $format['transcodeMetadata']
                : [];

            $mimetype = strtolower((string)($metadata['mimeType'] ?? ''));
            if ($mimetype !== '' && !str_starts_with($mimetype, 'video/mp4')) {
                continue;
            }

            // Progressive transcodes should be muxed. Avoid a video-only entry
            // if metadata explicitly reports no audio codec.
            $audiocodec = strtolower((string)($metadata['audioCodecString'] ?? ''));
            if ($audiocodec === 'none') {
                continue;
            }

            $candidates[] = [
                'url' => $url,
                'height' => max(0, (int)($metadata['height'] ?? 0)),
                'bitrate' => max(0, (int)($metadata['bitrate'] ?? $format['bitrate'] ?? 0)),
            ];
        }

        return self::pick_candidate($candidates);
    }

    /**
     * Legacy get_video_info resolver retained as a compatibility fallback.
     *
     * @param string $fileid
     * @return string|null
     */
    private static function resolve_legacy_video_info(string $fileid): ?string {
        $endpoints = [
            'https://docs.google.com/get_video_info?docid=' . rawurlencode($fileid),
            'https://drive.google.com/get_video_info?docid=' . rawurlencode($fileid),
        ];

        foreach ($endpoints as $endpoint) {
            $body = self::fetch($endpoint, false);
            if ($body === null || $body === '') {
                continue;
            }

            $streamurl = self::parse_legacy_stream_url($body);
            if ($streamurl !== null) {
                return $streamurl;
            }
        }

        return null;
    }

    /**
     * Fetch an upstream metadata/playback response.
     *
     * @param string $url
     * @param bool $json Whether JSON is expected.
     * @return string|null
     */
    private static function fetch(string $url, bool $json): ?string {
        $currenturl = $url;
        $accept = $json ? 'application/json,text/plain,*/*;q=0.8' : 'text/plain,*/*;q=0.8';

        for ($redirects = 0; $redirects <= 4; $redirects++) {
            if (!upstream_url_policy::is_allowed($currenturl)) {
                debugging('Drive Resource stream lookup rejected an upstream URL.', DEBUG_DEVELOPER);
                return null;
            }

            $location = '';
            $ch = curl_init($currenturl);
            if ($ch === false) {
                return null;
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'DriveResourceMoodleResolver/1.1.33',
                CURLOPT_REFERER => 'https://drive.google.com/',
                CURLOPT_HTTPHEADER => [
                    'Accept: ' . $accept,
                    'Accept-Language: en-US,en;q=0.8',
                    'Origin: https://drive.google.com',
                ],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
                    $length = strlen($header);
                    if (preg_match('/^Location:\\s*(.+)$/i', trim($header), $matches)) {
                        $location = trim($matches[1]);
                    }
                    return $length;
                },
            ];

            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            }

            curl_setopt_array($ch, $options);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($status >= 300 && $status < 400) {
                $redirecturl = upstream_url_policy::resolve_redirect($currenturl, $location);
                if ($redirecturl === null || $redirects >= 4) {
                    debugging('Drive Resource stream lookup rejected an upstream redirect.', DEBUG_DEVELOPER);
                    return null;
                }
                $currenturl = $redirecturl;
                continue;
            }

            if (!is_string($body) || $status < 200 || $status >= 300) {
                debugging('Drive Resource stream lookup failed: HTTP ' . $status . ' ' . $error, DEBUG_DEVELOPER);
                return null;
            }

            return $body;
        }

        return null;
    }

    /**
     * Parse legacy fmt_stream_map and pick a progressive MP4 stream.
     *
     * @param string $body
     * @return string|null
     */
    private static function parse_legacy_stream_url(string $body): ?string {
        $info = [];
        parse_str($body, $info);

        $streammap = isset($info['fmt_stream_map']) && is_string($info['fmt_stream_map'])
            ? $info['fmt_stream_map']
            : '';
        if ($streammap === '') {
            return null;
        }

        $resolutions = [];
        $fmtlist = isset($info['fmt_list']) && is_string($info['fmt_list']) ? $info['fmt_list'] : '';
        foreach (explode(',', $fmtlist) as $format) {
            if (preg_match('/^(\d+)\/(\d+)[xX](\d+)/', $format, $matches)) {
                $resolutions[(int)$matches[1]] = (int)$matches[3];
            }
        }

        $candidates = [];
        foreach (explode(',', $streammap) as $entry) {
            $parts = explode('|', $entry, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $itag = (int)$parts[0];
            $url = self::normalise_stream_url($parts[1]);
            if ($url === null) {
                continue;
            }

            $known = self::KNOWN_FORMATS[$itag] ?? ['ext' => '', 'height' => 0];
            $height = $resolutions[$itag] ?? (int)$known['height'];
            $ext = (string)$known['ext'];

            if ($ext !== 'mp4' && !preg_match('/(?:^|[?&])mime=(?:video%2Fmp4|video\/mp4)(?:&|$)/i', $url)) {
                continue;
            }

            $candidates[] = [
                'url' => $url,
                'height' => $height,
                'bitrate' => 0,
            ];
        }

        return self::pick_candidate($candidates);
    }

    /**
     * Choose a compatible quality, preferring 720p then 480p then 360p.
     *
     * @param array $candidates
     * @return string|null
     */
    private static function pick_candidate(array $candidates): ?string {
        if (!$candidates) {
            return null;
        }

        usort($candidates, static function (array $a, array $b): int {
            $arank = self::quality_rank((int)($a['height'] ?? 0));
            $brank = self::quality_rank((int)($b['height'] ?? 0));
            if ($arank !== $brank) {
                return $arank <=> $brank;
            }

            $heightcompare = ((int)($b['height'] ?? 0)) <=> ((int)($a['height'] ?? 0));
            if ($heightcompare !== 0) {
                return $heightcompare;
            }

            return ((int)($b['bitrate'] ?? 0)) <=> ((int)($a['bitrate'] ?? 0));
        });

        return isset($candidates[0]['url']) && is_string($candidates[0]['url'])
            ? $candidates[0]['url']
            : null;
    }

    /**
     * Rank resolutions for mobile-friendly startup without forcing 1080p.
     *
     * @param int $height
     * @return int
     */
    private static function quality_rank(int $height): int {
        if ($height === 720) {
            return 0;
        }
        if ($height === 480) {
            return 1;
        }
        if ($height === 360) {
            return 2;
        }
        if ($height > 0 && $height < 720) {
            return 3;
        }
        if ($height > 720) {
            return 4;
        }
        return 5;
    }

    /**
     * Normalise and validate a signed Google stream URL.
     *
     * @param string $value
     * @return string|null
     */
    private static function normalise_stream_url(string $value): ?string {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(
            ['\\u003d', '\\u0026', '\\u0025', '\\/'],
            ['=', '&', '%', '/'],
            $value
        );

        if (!str_starts_with($value, 'https://')) {
            return null;
        }

        return upstream_url_policy::is_allowed($value) ? $value : null;
    }
}
