<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.

namespace mod_videoplayer\local;

/**
 * Bounded Google Drive downloader for server-side cache and normalization jobs.
 *
 * Browser requests never use this class directly. Redirects and Drive warning
 * continuations remain restricted to trusted HTTPS Google download hosts.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class drive_downloader {
    /** @var int Download buffer size. */
    private const BUFFER_SIZE = 524288;

    /** @var int Maximum trusted redirects. */
    private const MAX_REDIRECTS = 8;

    /** @var int Maximum Drive warning confirmation continuations. */
    private const MAX_CONFIRMATION_HOPS = 2;

    /** @var int Maximum warning body bytes inspected. */
    private const MAX_WARNING_BYTES = 131072;

    /**
     * Download a trusted Drive resource to a local file without buffering it in PHP memory.
     *
     * @param string $url Trusted Drive download URL.
     * @param string $targetpath Absolute target path.
     * @return array{ok:bool,httpcode:int,error:string,contenttype:string,effectiveurl:string}
     */
    public static function download(string $url, string $targetpath): array {
        if (!drive::is_trusted_download_url($url)) {
            return self::failure('untrusted_download_url');
        }

        $directory = dirname($targetpath);
        if (!is_dir($directory)) {
            make_writable_directory($directory);
        }
        if (!is_writable($directory)) {
            return self::failure('target_directory_not_writable');
        }

        $cookiejar = $targetpath . '.cookies.' . getmypid();
        self::delete_if_file($cookiejar);

        $currenturl = $url;
        $redirects = 0;
        $confirmationhops = 0;
        $last = self::failure('download_not_started');

        try {
            while (true) {
                self::delete_if_file($targetpath);
                $last = self::request_to_file($currenturl, $targetpath, $cookiejar);

                $status = (int) $last['httpcode'];
                if ($status >= 300 && $status < 400) {
                    $location = (string) ($last['location'] ?? '');
                    $nexturl = drive::resolve_trusted_download_url($location, $currenturl);
                    if ($nexturl === null || ++$redirects > self::MAX_REDIRECTS) {
                        self::delete_if_file($targetpath);
                        return self::failure(
                            $nexturl === null ? 'untrusted_redirect' : 'too_many_redirects',
                            $status,
                            (string) $last['contenttype'],
                            $currenturl
                        );
                    }
                    $currenturl = $nexturl;
                    continue;
                }

                if (!$last['ok']) {
                    self::delete_if_file($targetpath);
                    return $last;
                }

                if (self::looks_like_warning($last['contenttype'])) {
                    $warning = self::read_prefix($targetpath, self::MAX_WARNING_BYTES);
                    $nexturl = drive::resolve_download_warning_url($warning, $currenturl);
                    if ($nexturl !== null && $confirmationhops < self::MAX_CONFIRMATION_HOPS) {
                        $confirmationhops++;
                        $currenturl = $nexturl;
                        continue;
                    }

                    self::delete_if_file($targetpath);
                    return self::failure(
                        'unexpected_drive_warning',
                        $status,
                        (string) $last['contenttype'],
                        $currenturl
                    );
                }

                return [
                    'ok' => true,
                    'httpcode' => $status,
                    'error' => '',
                    'contenttype' => (string) $last['contenttype'],
                    'effectiveurl' => $currenturl,
                ];
            }
        } finally {
            self::delete_if_file($cookiejar);
        }
    }

    /**
     * Execute one non-following HTTPS request into a file.
     *
     * @param string $url Trusted URL.
     * @param string $targetpath Target file.
     * @param string $cookiejar Cookie jar.
     * @return array{ok:bool,httpcode:int,error:string,contenttype:string,effectiveurl:string,location?:string}
     */
    private static function request_to_file(string $url, string $targetpath, string $cookiejar): array {
        $handle = fopen($targetpath, 'wb');
        if ($handle === false) {
            return self::failure('target_not_writable');
        }

        $headers = [];
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($handle);
            return self::failure('curl_init_failed');
        }

        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_BUFFERSIZE => self::BUFFER_SIZE,
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'Accept-Encoding: identity',
            ],
            CURLOPT_USERAGENT => 'DriveResourceMoodleDownloader/1.1.32',
            CURLOPT_COOKIEJAR => $cookiejar,
            CURLOPT_COOKIEFILE => $cookiejar,
            CURLOPT_FILE => $handle,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
                $length = strlen($header);
                $trimmed = trim($header);
                if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $trimmed, $matches)) {
                    $headers = ['status' => (int) $matches[1]];
                    return $length;
                }
                if (preg_match('/^Location:\s*(.+)$/i', $trimmed, $matches)) {
                    $headers['location'] = trim($matches[1]);
                } else if (preg_match('/^Content-Type:\s*(.+)$/i', $trimmed, $matches)) {
                    $headers['content-type'] = trim($matches[1]);
                }
                return $length;
            },
        ];

        if (defined('CURL_HTTP_VERSION_2TLS')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contenttype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($handle);

        if (!empty($headers['content-type'])) {
            $contenttype = (string) $headers['content-type'];
        }

        return [
            'ok' => $result !== false && $status >= 200 && $status < 300,
            'httpcode' => $status,
            'error' => $error,
            'contenttype' => $contenttype,
            'effectiveurl' => $url,
            'location' => (string) ($headers['location'] ?? ''),
        ];
    }

    /**
     * Whether a response is a Drive warning rather than the requested file.
     *
     * @param string $contenttype Content-Type header.
     * @return bool
     */
    private static function looks_like_warning(string $contenttype): bool {
        $type = strtolower(trim(explode(';', $contenttype, 2)[0]));
        return $type === 'text/html' || $type === 'application/json' || strpos($type, 'text/') === 0;
    }

    /**
     * Read only a bounded prefix from a local file.
     *
     * @param string $path File path.
     * @param int $limit Maximum bytes.
     * @return string
     */
    private static function read_prefix(string $path, int $limit): string {
        if (!is_readable($path)) {
            return '';
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        try {
            $data = fread($handle, $limit);
            return is_string($data) ? $data : '';
        } finally {
            fclose($handle);
        }
    }

    /**
     * Build a normalized failure response.
     *
     * @param string $error Safe internal error.
     * @param int $httpcode HTTP status.
     * @param string $contenttype Response MIME.
     * @param string $effectiveurl Effective trusted URL.
     * @return array{ok:bool,httpcode:int,error:string,contenttype:string,effectiveurl:string}
     */
    private static function failure(
        string $error,
        int $httpcode = 0,
        string $contenttype = '',
        string $effectiveurl = ''
    ): array {
        return [
            'ok' => false,
            'httpcode' => $httpcode,
            'error' => $error,
            'contenttype' => $contenttype,
            'effectiveurl' => $effectiveurl,
        ];
    }

    /**
     * Delete an existing regular file.
     *
     * @param string $path Path.
     * @return void
     */
    private static function delete_if_file(string $path): void {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
