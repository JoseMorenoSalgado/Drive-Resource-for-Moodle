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

/**
 * Resilient HTTP byte-range proxy for protected Drive resources.
 *
 * Keeps Moodle as the only browser-visible endpoint, validates upstream
 * responses and streams one byte range without buffering the complete media
 * file in PHP memory.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class http_range_proxy {
    /** @var int cURL streaming buffer size in bytes. */
    private const STREAM_BUFFER_SIZE = 524288;

    /** @var int Private browser cache lifetime for authorised resources. */
    private const PRIVATE_CACHE_SECONDS = 300;

    /** @var string No Range header is required. */
    private const RANGE_MODE_NONE = 'none';

    /** @var string Let libcurl generate the Range header. */
    private const RANGE_MODE_CURL = 'curl';

    /** @var string Send one explicit Range header across redirects. */
    private const RANGE_MODE_HEADER = 'header';

    /** @var string Synthesize a browser range from an upstream full response. */
    private const RANGE_MODE_SYNTHETIC = 'synthetic';

    /**
     * Stream an upstream resource through Moodle.
     *
     * A browser Range request is never answered with an upstream HTTP 200
     * response. Returning the complete file for a seek request makes HTML5
     * video restart at zero, particularly in Safari and mobile Chromium.
     * The proxy retries once using a second cURL range strategy and fails in a
     * controlled way if the upstream server still refuses partial content.
     *
     * @param string $url Server-side upstream URL.
     * @param string $filename Safe browser filename.
     * @param string $fallbacktype Fallback MIME type.
     * @param string $cachestatus Cache diagnostic status.
     * @return never
     */
    public static function proxy(
        string $url,
        string $filename,
        string $fallbacktype,
        string $cachestatus = 'BYPASS'
    ): never {
        $range = self::request_range_header();
        $ishead = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
        $validator = self::stable_validator($url);
        $rangemodes = $range === ''
            ? [self::RANGE_MODE_NONE]
            : [self::RANGE_MODE_CURL, self::RANGE_MODE_HEADER, self::RANGE_MODE_SYNTHETIC];
        $lastresponse = null;

        foreach ($rangemodes as $rangemode) {
            $lastresponse = self::execute_attempt(
                $url,
                $filename,
                $fallbacktype,
                $cachestatus,
                $validator,
                $range,
                $rangemode,
                $ishead
            );

            if ($lastresponse['sent']) {
                die;
            }
            if ($lastresponse['invalidcontent']) {
                debugging(
                    'Drive Resource proxy rejected an incompatible upstream content type for ' . $fallbacktype . '.',
                    DEBUG_DEVELOPER
                );
                self::send_bad_gateway('UPSTREAM_CONTENT_REJECTED');
            }
            if ($lastresponse['status'] === 416) {
                self::send_range_not_satisfiable($lastresponse['headers'], $cachestatus);
            }

            // A 200 response to a Range request must not reach the media element.
            // Retry once with an explicit Range header that survives redirects.
            if ($range !== '' && $lastresponse['status'] === 200) {
                continue;
            }

            if ($lastresponse['result'] === false || $lastresponse['status'] >= 400) {
                break;
            }
        }

        $status = (int) ($lastresponse['status'] ?? 0);
        $curlerror = (string) ($lastresponse['error'] ?? '');
        debugging('Drive Resource proxy failed: HTTP ' . $status . ' ' . $curlerror, DEBUG_DEVELOPER);

        if ($range !== '' && $status === 200) {
            self::send_bad_gateway('UPSTREAM_RANGE_UNSUPPORTED');
        }
        self::send_bad_gateway('UPSTREAM_REQUEST_FAILED');
    }

    /**
     * Execute one upstream streaming attempt.
     *
     * @param string $url Server-side upstream URL.
     * @param string $filename Safe browser filename.
     * @param string $fallbacktype Fallback MIME type.
     * @param string $cachestatus Cache diagnostic status.
     * @param string $validator Stable proxy ETag.
     * @param string $range Validated browser Range header.
     * @param string $rangemode Range transmission strategy.
     * @param bool $ishead Whether this is a HEAD request.
     * @return array{sent: bool, result: bool, status: int, error: string, headers: array, invalidcontent: bool}
     */
    private static function execute_attempt(
        string $url,
        string $filename,
        string $fallbacktype,
        string $cachestatus,
        string $validator,
        string $range,
        string $rangemode,
        bool $ishead
    ): array {
        $requestheaders = [
            'Accept: */*',
            'Accept-Encoding: identity',
            'Connection: keep-alive',
        ];
        if ($range !== '' && $rangemode === self::RANGE_MODE_HEADER) {
            $requestheaders[] = 'Range: ' . $range;
        }

        $responseheaders = [];
        $headerssent = false;
        $discardbody = false;
        $invalidcontent = false;
        $syntheticwindow = null;
        $syntheticposition = 0;
        $syntheticrangeinvalid = false;

        $headercallback = static function (
            $curl,
            string $header
        ) use (
            &$responseheaders,
            &$discardbody,
            &$invalidcontent,
            &$syntheticwindow,
            &$syntheticrangeinvalid,
            $fallbacktype,
            $range,
            $rangemode
        ): int {
            $length = strlen($header);
            $trimmed = trim($header);
            if ($trimmed === '') {
                $status = (int) ($responseheaders['status'] ?? 0);
                if (in_array($status, [200, 206], true)) {
                    $candidate = (string) ($responseheaders['content-type'] ?? '');
                    if (!self::is_compatible_content_type($candidate, $fallbacktype)) {
                        $invalidcontent = true;
                        $discardbody = true;
                    } else if (
                        $rangemode === self::RANGE_MODE_SYNTHETIC &&
                        $range !== '' &&
                        $status === 200
                    ) {
                        $total = (int) ($responseheaders['content-length'] ?? 0);
                        $syntheticwindow = self::resolve_range_window($range, $total);
                        if ($syntheticwindow === null) {
                            $syntheticrangeinvalid = true;
                            $discardbody = true;
                        } else {
                            $discardbody = false;
                        }
                    } else if (
                        !self::is_range_response_usable(
                            $range,
                            $status,
                            (string) ($responseheaders['content-range'] ?? '')
                        )
                    ) {
                        $discardbody = true;
                    }
                }
                return $length;
            }

            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $trimmed, $matches)) {
                $status = (int) $matches[1];
                $responseheaders = ['status' => $status];
                if ($rangemode === self::RANGE_MODE_SYNTHETIC && $range !== '') {
                    $discardbody = $status >= 400 || !in_array($status, [200, 206], true);
                } else {
                    $discardbody = $status >= 400 || ($range !== '' && $status !== 206);
                }
                $invalidcontent = false;
                $syntheticwindow = null;
                $syntheticrangeinvalid = false;
                return $length;
            }

            $patterns = [
                'content-length' => '/^Content-Length:\s*(\d+)/i',
                'content-type' => '/^Content-Type:\s*(.+)$/i',
                'content-range' => '/^Content-Range:\s*(.+)$/i',
                'accept-ranges' => '/^Accept-Ranges:\s*(.+)$/i',
            ];

            foreach ($patterns as $key => $pattern) {
                if (preg_match($pattern, $trimmed, $matches)) {
                    $responseheaders[$key] = trim($matches[1]);
                    break;
                }
            }

            return $length;
        };

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'sent' => false,
                'result' => false,
                'status' => 0,
                'error' => 'CURL_INIT_FAILED',
                'headers' => [],
                'invalidcontent' => false,
            ];
        }

        $options = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_BUFFERSIZE => self::STREAM_BUFFER_SIZE,
            CURLOPT_HTTPHEADER => $requestheaders,
            CURLOPT_HEADERFUNCTION => $headercallback,
            CURLOPT_USERAGENT => 'DriveResourceMoodleProxy/1.1.31',
            CURLOPT_WRITEFUNCTION => static function (
                $curl,
                string $data
            ) use (
                &$headerssent,
                &$responseheaders,
                &$discardbody,
                &$syntheticwindow,
                &$syntheticposition,
                $fallbacktype,
                $filename,
                $cachestatus,
                $validator,
                $range,
                $rangemode
            ): int {
                $datalength = strlen($data);
                if ($discardbody) {
                    return $datalength;
                }

                $status = (int) ($responseheaders['status'] ?? 0);
                if (
                    $rangemode === self::RANGE_MODE_SYNTHETIC &&
                    $range !== '' &&
                    $status === 200 &&
                    is_array($syntheticwindow)
                ) {
                    $chunkstart = $syntheticposition;
                    $chunkend = $syntheticposition + $datalength - 1;
                    $emitstart = max($chunkstart, $syntheticwindow['start']);
                    $emitend = min($chunkend, $syntheticwindow['end']);

                    if ($emitstart <= $emitend) {
                        if (!$headerssent) {
                            $syntheticheaders = $responseheaders;
                            $syntheticheaders['status'] = 206;
                            $syntheticheaders['content-range'] = 'bytes ' .
                                $syntheticwindow['start'] . '-' .
                                $syntheticwindow['end'] . '/' .
                                $syntheticwindow['total'];
                            $syntheticheaders['content-length'] = $syntheticwindow['length'];
                            $syntheticheaders['accept-ranges'] = 'bytes';
                            self::send_response_headers(
                                $syntheticheaders,
                                $fallbacktype,
                                $filename,
                                $cachestatus,
                                $validator
                            );
                            $headerssent = true;
                        }

                        $offset = $emitstart - $chunkstart;
                        $emitlength = $emitend - $emitstart + 1;
                        echo substr($data, $offset, $emitlength);
                        flush();
                    }

                    $syntheticposition += $datalength;
                    if ($syntheticposition > $syntheticwindow['end']) {
                        return 0;
                    }

                    return $datalength;
                }

                if (
                    !$headerssent &&
                    self::is_range_response_usable(
                        $range,
                        $status,
                        (string) ($responseheaders['content-range'] ?? '')
                    )
                ) {
                    self::send_response_headers(
                        $responseheaders,
                        $fallbacktype,
                        $filename,
                        $cachestatus,
                        $validator
                    );
                    $headerssent = true;
                }

                if ($headerssent) {
                    echo $data;
                    flush();
                }

                return $datalength;
            },
        ];

        if (defined('CURL_HTTP_VERSION_2TLS')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        }
        if (defined('CURLOPT_TCP_KEEPALIVE')) {
            $options[CURLOPT_TCP_KEEPALIVE] = 1;
        }
        if ($range !== '' && $rangemode === self::RANGE_MODE_CURL) {
            $options[CURLOPT_RANGE] = substr($range, 6);
        }
        if ($ishead) {
            $options[CURLOPT_NOBODY] = true;
        }

        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $curlerror = curl_error($ch);
        $curlcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (
            $ishead &&
            $result !== false &&
            !$invalidcontent &&
            $rangemode === self::RANGE_MODE_SYNTHETIC &&
            $range !== '' &&
            $curlcode === 200 &&
            is_array($syntheticwindow)
        ) {
            $syntheticheaders = $responseheaders;
            $syntheticheaders['status'] = 206;
            $syntheticheaders['content-range'] = 'bytes ' .
                $syntheticwindow['start'] . '-' .
                $syntheticwindow['end'] . '/' .
                $syntheticwindow['total'];
            $syntheticheaders['content-length'] = $syntheticwindow['length'];
            $syntheticheaders['accept-ranges'] = 'bytes';
            self::send_response_headers(
                $syntheticheaders,
                $fallbacktype,
                $filename,
                $cachestatus,
                $validator
            );
            $headerssent = true;
        } else if (
            $ishead &&
            $result !== false &&
            !$invalidcontent &&
            self::is_range_response_usable(
                $range,
                $curlcode,
                (string) ($responseheaders['content-range'] ?? '')
            )
        ) {
            self::send_response_headers(
                $responseheaders,
                $fallbacktype,
                $filename,
                $cachestatus,
                $validator
            );
            $headerssent = true;
        }

        return [
            'sent' => $headerssent,
            'result' => $result !== false || $headerssent,
            'status' => $syntheticrangeinvalid ? 416 : $curlcode,
            'error' => $curlerror,
            'headers' => $responseheaders,
            'invalidcontent' => $invalidcontent,
        ];
    }

    /**
     * Determine whether an upstream MIME type is compatible with the viewer.
     *
     * Empty and generic binary responses are accepted because Google may omit
     * a specific media MIME type. HTML, JSON and unrelated text responses are
     * rejected so login, permission and download-warning pages never reach a
     * video, audio, image or PDF element.
     *
     * @param string $candidate Upstream Content-Type value.
     * @param string $fallback Expected viewer MIME type.
     * @return bool
     */
    public static function is_compatible_content_type(string $candidate, string $fallback): bool {
        $candidate = strtolower(trim(explode(';', $candidate, 2)[0]));
        $fallback = strtolower(trim(explode(';', $fallback, 2)[0]));

        if ($candidate === '' || in_array($candidate, ['application/octet-stream', 'binary/octet-stream'], true)) {
            return true;
        }

        if ($candidate === 'text/html' || $candidate === 'application/json' || strpos($candidate, 'text/') === 0) {
            return false;
        }

        if (strpos($fallback, 'video/') === 0) {
            return strpos($candidate, 'video/') === 0;
        }
        if (strpos($fallback, 'audio/') === 0) {
            return strpos($candidate, 'audio/') === 0;
        }
        if (strpos($fallback, 'image/') === 0) {
            return strpos($candidate, 'image/') === 0;
        }
        if ($fallback === 'application/pdf') {
            return $candidate === 'application/pdf';
        }

        return true;
    }

    /**
     * Validate that an upstream response can satisfy the browser request.
     *
     * @param string $range Validated browser Range header, or an empty string.
     * @param int $status Upstream HTTP status.
     * @param string $contentrange Upstream Content-Range header.
     * @return bool
     */
    public static function is_range_response_usable(string $range, int $status, string $contentrange): bool {
        if ($range === '') {
            return in_array($status, [200, 206], true);
        }

        return $status === 206 &&
            preg_match('/^bytes\s+\d+-\d+\/(?:\d+|\*)$/i', trim($contentrange)) === 1;
    }

    /**
     * Resolve one validated browser byte range against a known resource size.
     *
     * @param string $range Browser Range header.
     * @param int $total Total upstream resource size.
     * @return array{start: int, end: int, total: int, length: int}|null
     */
    public static function resolve_range_window(string $range, int $total): ?array {
        if ($total <= 0) {
            return null;
        }

        if (preg_match('/^bytes=(\d+)-(\d*)$/', $range, $matches)) {
            $start = (int) $matches[1];
            if ($start >= $total) {
                return null;
            }

            $end = $matches[2] === '' ? $total - 1 : min((int) $matches[2], $total - 1);
            if ($end < $start) {
                return null;
            }

            return [
                'start' => $start,
                'end' => $end,
                'total' => $total,
                'length' => $end - $start + 1,
            ];
        }

        if (preg_match('/^bytes=-(\d+)$/', $range, $matches)) {
            $suffixlength = (int) $matches[1];
            if ($suffixlength <= 0) {
                return null;
            }

            $length = min($suffixlength, $total);
            return [
                'start' => $total - $length,
                'end' => $total - 1,
                'total' => $total,
                'length' => $length,
            ];
        }

        return null;
    }

    /**
     * Return a validated single Range request header.
     *
     * @return string
     */
    private static function request_range_header(): string {
        if (empty($_SERVER['HTTP_RANGE'])) {
            return '';
        }

        $candidate = trim((string) $_SERVER['HTTP_RANGE']);
        return preg_match('/^bytes=(?:\d+-\d*|-\d+)$/', $candidate) ? $candidate : '';
    }

    /**
     * Build a stable browser-facing validator for this protected URL.
     *
     * Google may expose different validators across redirects. A proxy-owned
     * ETag prevents the browser from sending an upstream If-Range validator
     * that turns a seek request into a complete HTTP 200 response.
     *
     * @param string $url Upstream URL.
     * @return string
     */
    private static function stable_validator(string $url): string {
        return '"dr-' . substr(hash('sha256', $url), 0, 32) . '"';
    }

    /**
     * Send browser-safe headers for a successful proxied response.
     *
     * @param array $headers Captured upstream headers.
     * @param string $fallbacktype Fallback MIME type.
     * @param string $filename Safe filename.
     * @param string $cachestatus Cache diagnostic status.
     * @param string $validator Stable proxy ETag.
     * @return void
     */
    private static function send_response_headers(
        array $headers,
        string $fallbacktype,
        string $filename,
        string $cachestatus,
        string $validator
    ): void {
        $status = (int) ($headers['status'] ?? 200) === 206 ? 206 : 200;
        $contenttype = self::safe_content_type((string) ($headers['content-type'] ?? ''), $fallbacktype);
        $safefilename = str_replace(["\r", "\n", '"'], '', $filename);

        http_response_code($status);
        header('Content-Type: ' . $contenttype);
        header('Content-Disposition: inline; filename="' . $safefilename . '"; filename*=UTF-8\'\'' . rawurlencode($safefilename));
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Accel-Buffering: no');
        header('Cache-Control: private, max-age=' . self::PRIVATE_CACHE_SECONDS . ', no-transform');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + self::PRIVATE_CACHE_SECONDS) . ' GMT');
        header('Vary: Range');
        header('ETag: ' . $validator);
        header('X-Drive-Resource-Cache: ' . self::safe_cache_status($cachestatus));
        header('X-Drive-Resource-Status: MEDIA');

        $acceptranges = strtolower((string) ($headers['accept-ranges'] ?? ''));
        if ($status === 206 || $acceptranges === 'bytes' || !empty($headers['content-length'])) {
            header('Accept-Ranges: bytes');
        }

        if (!empty($headers['content-length'])) {
            header('Content-Length: ' . (int) $headers['content-length']);
        }
        if ($status === 206 && !empty($headers['content-range'])) {
            header('Content-Range: ' . self::safe_header_value((string) $headers['content-range']));
        }
    }

    /**
     * Relay a valid 416 response without exposing upstream details.
     *
     * @param array $headers Captured upstream headers.
     * @param string $cachestatus Cache diagnostic status.
     * @return never
     */
    private static function send_range_not_satisfiable(array $headers, string $cachestatus): never {
        http_response_code(416);
        header('Cache-Control: no-store, no-cache, must-revalidate, no-transform');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        header('X-Drive-Resource-Cache: ' . self::safe_cache_status($cachestatus));
        header('X-Drive-Resource-Status: RANGE_INVALID');
        if (!empty($headers['content-range'])) {
            header('Content-Range: ' . self::safe_header_value((string) $headers['content-range']));
        }
        die;
    }

    /**
     * Send a generic upstream failure response.
     *
     * @param string $status Safe diagnostic status.
     * @return never
     */
    private static function send_bad_gateway(string $status): never {
        http_response_code(502);
        header('Cache-Control: no-store, no-cache, must-revalidate, no-transform');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        header('X-Drive-Resource-Status: ' . self::safe_cache_status($status));
        die;
    }

    /**
     * Return a safe MIME type.
     *
     * @param string $candidate Upstream Content-Type value.
     * @param string $fallback Fallback MIME type.
     * @return string
     */
    private static function safe_content_type(string $candidate, string $fallback): string {
        $candidate = trim(str_replace(["\r", "\n"], '', $candidate));
        $basetype = strtolower(trim(explode(';', $candidate, 2)[0]));
        if (in_array($basetype, ['', 'application/octet-stream', 'binary/octet-stream'], true)) {
            return $fallback;
        }

        if (
            preg_match(
                '/^[a-zA-Z0-9.+-]+\/[a-zA-Z0-9.+-]+(?:\s*;\s*[a-zA-Z0-9._=-]+)*$/',
                $candidate
            )
        ) {
            return $candidate;
        }

        return $fallback;
    }

    /**
     * Remove CR/LF from a relayed header value.
     *
     * @param string $value Header value.
     * @return string
     */
    private static function safe_header_value(string $value): string {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    /**
     * Normalize the diagnostic cache status.
     *
     * @param string $status Cache status.
     * @return string
     */
    private static function safe_cache_status(string $status): string {
        return preg_replace('/[^A-Z_-]/', '', strtoupper($status));
    }
}
