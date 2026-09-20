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
 * Local protected streaming and PDF cache service for Drive Resource.
 *
 * This service owns trusted local/cache file delivery and PDF cache lifecycle.
 * Upstream HTTP delivery belongs exclusively to http_range_proxy.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class protected_stream {
    /** @var int Private browser cache lifetime for authorised protected streams. */
    private const PRIVATE_CACHE_SECONDS = 300;

    /** @var int Stream chunk size in bytes. */
    private const STREAM_CHUNK_SIZE = 262144;

    /** @var int Temporary cache file stale lifetime. */
    private const STALE_TMP_TTL = 3600;

    /** @var int Abort PDF cache downloads that remain effectively stalled. */
    private const LOW_SPEED_LIMIT = 1024;

    /** @var int Seconds below LOW_SPEED_LIMIT before aborting a cache download. */
    private const LOW_SPEED_TIME = 20;

    /** @var int Maximum validated redirects while warming the PDF cache. */
    private const MAX_REDIRECT_HOPS = 5;

    /** @var int Maximum Drive warning body read for confirmation resolution. */
    private const MAX_WARNING_HTML_BYTES = 1048576;

    /**
     * Return the configured PDF cache TTL.
     *
     * @return int Cache TTL in seconds.
     */
    public static function pdf_cache_ttl(): int {
        return plugin_config::pdf_cache_ttl();
    }

    /**
     * Return the plugin PDF cache directory, creating it when possible.
     *
     * @return string Absolute cache directory.
     */
    public static function pdf_cache_dir(): string {
        global $CFG;

        $cachedir = $CFG->localcachedir . '/mod_videoplayer/pdf';
        if (!is_dir($cachedir)) {
            make_writable_directory($cachedir);
        }

        return $cachedir;
    }

    /**
     * Build the stable cache key for a protected Drive PDF.
     *
     * @param string $fileid Google Drive file id.
     * @param string $type Resource type.
     * @return string Cache key.
     */
    public static function cache_key(string $fileid, string $type): string {
        return sha1($fileid . ':' . $type);
    }

    /**
     * Build the final PDF cache file path.
     *
     * @param string $fileid Google Drive file id.
     * @param string $type Resource type.
     * @return string Absolute cache file path.
     */
    public static function cache_file_for(string $fileid, string $type): string {
        return self::pdf_cache_dir() . '/' . self::cache_key($fileid, $type) . '.pdf';
    }

    /**
     * Invalidate one cached Google Drive PDF representation.
     *
     * @param string $fileid Google Drive file id.
     * @param string $type Resource type.
     * @return void
     */
    public static function invalidate_pdf_cache(string $fileid, string $type): void {
        if ($fileid === '') {
            return;
        }
        self::delete_if_file(self::cache_file_for($fileid, $type));
    }

    /**
     * Check whether a cache file is fresh and contains a PDF signature.
     *
     * @param string $path Absolute cache path.
     * @param int|null $ttl Optional TTL override.
     * @return bool
     */
    public static function is_fresh_pdf_cache(string $path, ?int $ttl = null): bool {
        $ttl = $ttl ?? self::pdf_cache_ttl();
        $modified = is_file($path) ? filemtime($path) : false;

        return is_readable($path)
            && $modified !== false
            && $modified + $ttl > time()
            && self::is_pdf_file($path);
    }

    /**
     * Resolve the physical Moodle File API path for a stored file when available.
     *
     * @param \stored_file $file Stored file.
     * @return string|null Absolute path or null when not readable.
     */
    public static function stored_file_path(\stored_file $file): ?string {
        global $CFG;

        $hash = $file->get_contenthash();
        if ($hash === '' || strlen($hash) < 4) {
            return null;
        }

        $path = $CFG->dataroot . '/filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        return is_readable($path) ? $path : null;
    }

    /**
     * Check whether a local file contains a PDF signature near the beginning.
     *
     * @param string $path Absolute path.
     * @return bool
     */
    public static function is_pdf_file(string $path): bool {
        if (!is_readable($path)) {
            return false;
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 1024);
        fclose($handle);

        return is_string($header) && strpos($header, '%PDF-') !== false;
    }

    /**
     * Send a stored Moodle PDF through the protected local streamer.
     *
     * @param \stored_file $file Stored Moodle file.
     * @param string $filename Safe filename.
     * @return never
     */
    public static function send_stored_pdf(\stored_file $file, string $filename): never {
        $path = self::stored_file_path($file);
        if ($path === null) {
            $tmpdir = make_request_directory();
            $path = $tmpdir . '/' . sha1($file->get_contenthash() . ':' . $file->get_timemodified()) . '.pdf';
            $file->copy_content_to($path);
        }

        if (!self::is_pdf_file($path)) {
            debugging('Drive Resource local PDF did not contain a PDF signature near the beginning.', DEBUG_DEVELOPER);
            throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
        }

        self::send_file(
            $path,
            $filename,
            'application/pdf',
            $file->get_contenthash(),
            (int)$file->get_timemodified(),
            'LOCAL'
        );
    }

    /**
     * Send a local file with single-byte-range support.
     *
     * Supports closed, open-ended and suffix byte ranges without loading the
     * complete file into PHP memory.
     *
     * @param string $path Absolute local path.
     * @param string $filename Safe filename.
     * @param string $contenttype MIME type.
     * @param string $etag Optional stable entity tag.
     * @param int $lastmodified Optional unix timestamp.
     * @param string $cachestatus Cache diagnostic status.
     * @return never
     */
    public static function send_file(
        string $path,
        string $filename,
        string $contenttype,
        string $etag = '',
        int $lastmodified = 0,
        string $cachestatus = 'LOCAL'
    ): never {
        if (!is_readable($path)) {
            throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
        }

        $lastmodified = $lastmodified > 0 ? $lastmodified : (filemtime($path) ?: time());
        $etag = $etag !== '' ? $etag : sha1($size . ':' . $lastmodified);
        [$start, $end, $status] = self::resolve_range($size);
        $length = $end - $start + 1;
        $safefilename = str_replace(["\r", "\n", '"'], '', $filename);

        http_response_code($status);
        header('Content-Type: ' . $contenttype);
        header('Content-Disposition: inline; filename="' . $safefilename . '"; filename*=UTF-8\'\'' . rawurlencode($safefilename));
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header('Vary: Range');
        self::send_private_cache_headers($etag, $lastmodified);
        self::send_cache_status($cachestatus);

        if ($status === 206) {
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            die;
        }

        self::stream_file_segment($path, $start, $length);
        die;
    }

    /**
     * Warm a Google Drive PDF into local cache.
     *
     * The full PDF is downloaded to a unique temporary file and atomically
     * renamed only after PDF signature validation succeeds.
     *
     * @param string $url Resolved upstream download URL.
     * @param string $cachefile Final cache file path.
     * @return bool Whether a valid PDF was cached.
     */
    public static function warm_drive_pdf_cache(string $url, string $cachefile): bool {
        if (!upstream_url_policy::is_allowed($url)) {
            debugging('Drive Resource PDF cache rejected a non-allowlisted upstream URL.', DEBUG_DEVELOPER);
            return false;
        }

        $cachedir = dirname($cachefile);
        if (!is_dir($cachedir)) {
            make_writable_directory($cachedir);
        }
        if (!is_writable($cachedir)) {
            debugging('Drive Resource PDF cache warm failed: cache directory is not writable.', DEBUG_DEVELOPER);
            return false;
        }

        $lockfile = $cachefile . '.lock';
        $lockhandle = fopen($lockfile, 'c');
        if ($lockhandle === false) {
            debugging('Drive Resource PDF cache warm failed: lock file is not writable.', DEBUG_DEVELOPER);
            return false;
        }

        if (!flock($lockhandle, LOCK_EX)) {
            fclose($lockhandle);
            debugging('Drive Resource PDF cache warm failed: lock could not be acquired.', DEBUG_DEVELOPER);
            return false;
        }

        try {
            clearstatcache(true, $cachefile);
            if (self::is_fresh_pdf_cache($cachefile)) {
                return true;
            }

            $tmpfile = $cachefile . '.tmp.' . getmypid();
            $cookiejar = $cachefile . '.cookies.' . getmypid();
            self::delete_if_file($tmpfile);
            self::delete_if_file($cookiejar);

            $download = self::download_to_file($url, $tmpfile, $cookiejar);
            $valid = $download['ok'] && self::is_pdf_file($tmpfile);

            if (!$valid && is_file($tmpfile)) {
                $warningbody = file_get_contents(
                    $tmpfile,
                    false,
                    null,
                    0,
                    self::MAX_WARNING_HTML_BYTES
                );
                $confirmedurl = is_string($warningbody)
                    ? drive::resolve_download_warning_url(
                        $warningbody,
                        (string)($download['effectiveurl'] ?? $url)
                    )
                    : null;

                if ($confirmedurl !== null) {
                    self::delete_if_file($tmpfile);
                    $download = self::download_to_file($confirmedurl, $tmpfile, $cookiejar);
                    $valid = $download['ok'] && self::is_pdf_file($tmpfile);
                }
            }

            if (!$valid) {
                self::delete_if_file($tmpfile);
                debugging(
                    'Drive Resource PDF cache warm failed: HTTP ' . ($download['httpcode'] ?? 0) . ' ' .
                    ($download['error'] ?? '') . ' content-type=' . ($download['contenttype'] ?? ''),
                    DEBUG_DEVELOPER
                );
                return false;
            }

            if (!@rename($tmpfile, $cachefile)) {
                self::delete_if_file($tmpfile);
                debugging('Drive Resource PDF cache warm failed: atomic cache rename failed.', DEBUG_DEVELOPER);
                return false;
            }

            return true;
        } finally {
            if (isset($cookiejar)) {
                self::delete_if_file($cookiejar);
            }
            flock($lockhandle, LOCK_UN);
            fclose($lockhandle);
        }
    }

    /**
     * Remove expired PDF cache, temporary and cookie files.
     *
     * @return void
     */
    public static function cleanup_pdf_cache(): void {
        $cachedir = self::pdf_cache_dir();
        if (!is_dir($cachedir)) {
            return;
        }

        $ttl = self::pdf_cache_ttl();
        $now = time();
        $files = glob($cachedir . '/*');
        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $modified = filemtime($file);
            if ($modified === false) {
                continue;
            }

            $basename = basename($file);
            $isexpiredpdf = preg_match('/\.pdf$/', $basename) && $modified + $ttl < $now;
            $isstaletmp = strpos($basename, '.tmp.') !== false && $modified + self::STALE_TMP_TTL < $now;
            $isstalecookie = strpos($basename, '.cookies.') !== false && $modified + self::STALE_TMP_TTL < $now;
            $isstalelock = str_ends_with($basename, '.lock') && $modified + self::STALE_TMP_TTL < $now;

            if ($isexpiredpdf || $isstaletmp || $isstalecookie) {
                self::delete_if_file($file);
            } else if ($isstalelock) {
                self::delete_stale_lock_file($file);
            }
        }
    }

    /**
     * Resolve the current request Range header.
     *
     * @return string Safe single Range header or empty string.
     */
    private static function request_range_header(): string {
        if (empty($_SERVER['HTTP_RANGE'])) {
            return '';
        }

        $candidate = trim((string)$_SERVER['HTTP_RANGE']);
        return preg_match('/^bytes=\d*-\d*$/', $candidate) ? $candidate : '';
    }

    /**
     * Resolve byte-range start, end and HTTP status.
     *
     * @param int $size File size.
     * @return array{0:int,1:int,2:int}
     */
    private static function resolve_range(int $size): array {
        $range = self::request_range_header();
        if ($range === '') {
            return [0, $size - 1, 200];
        }

        [$startpart, $endpart] = explode('-', substr($range, 6), 2);

        if ($startpart === '') {
            $suffixlength = (int)$endpart;
            if ($suffixlength <= 0) {
                self::send_range_not_satisfiable($size);
            }

            $suffixlength = min($suffixlength, $size);
            return [$size - $suffixlength, $size - 1, 206];
        }

        $start = (int)$startpart;
        $end = $endpart === '' ? $size - 1 : min((int)$endpart, $size - 1);

        if ($start < 0 || $start >= $size || $start > $end) {
            self::send_range_not_satisfiable($size);
        }

        return [$start, $end, 206];
    }

    /**
     * Send an RFC-compatible unsatisfied local range response.
     *
     * @param int $size File size.
     * @return never
     */
    private static function send_range_not_satisfiable(int $size): never {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        header('Cache-Control: no-store, no-cache, must-revalidate, no-transform');
        header('X-Content-Type-Options: nosniff');
        self::send_cache_status('RANGE_INVALID');
        die;
    }

    /**
     * Stream part of a file without loading it into memory.
     *
     * @param string $path Absolute file path.
     * @param int $start Start byte.
     * @param int $length Number of bytes to stream.
     * @return void
     */
    private static function stream_file_segment(string $path, int $start, int $length): void {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
        }

        try {
            if (fseek($handle, $start) !== 0) {
                throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
            }

            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(self::STREAM_CHUNK_SIZE, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                $remaining -= strlen($chunk);
                flush();
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Send private cache headers.
     *
     * @param string $etag Stable entity tag without quotes.
     * @param int $lastmodified Unix timestamp.
     * @return void
     */
    private static function send_private_cache_headers(string $etag = '', int $lastmodified = 0): void {
        header('Cache-Control: private, max-age=' . self::PRIVATE_CACHE_SECONDS . ', must-revalidate, no-transform');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + self::PRIVATE_CACHE_SECONDS) . ' GMT');
        if ($etag !== '') {
            header('ETag: "' . preg_replace('/[^a-zA-Z0-9_\-.]/', '', $etag) . '"');
        }
        if ($lastmodified > 0) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastmodified) . ' GMT');
        }
    }

    /**
     * Send cache diagnostic header.
     *
     * @param string $status Cache status.
     * @return void
     */
    private static function send_cache_status(string $status): void {
        header('X-Drive-Resource-Cache: ' . preg_replace('/[^A-Z_-]/', '', strtoupper($status)));
    }

    /**
     * Download an upstream URL to a file using a cookie jar.
     *
     * Redirects are followed manually so every hop is revalidated against the
     * upstream allow-list before a network request is made.
     *
     * @param string $url Download URL.
     * @param string $targetpath Target file path.
     * @param string $cookiejar Cookie jar path.
     * @return array{ok:bool,httpcode:int,error:string,contenttype:string,effectiveurl:string}
     */
    private static function download_to_file(string $url, string $targetpath, string $cookiejar): array {
        $currenturl = $url;

        for ($redirects = 0; $redirects <= self::MAX_REDIRECT_HOPS; $redirects++) {
            if (!upstream_url_policy::is_allowed($currenturl)) {
                return [
                    'ok' => false,
                    'httpcode' => 0,
                    'error' => 'upstream_url_rejected',
                    'contenttype' => '',
                    'effectiveurl' => $currenturl,
                ];
            }

            $handle = fopen($targetpath, 'wb');
            if ($handle === false) {
                return [
                    'ok' => false,
                    'httpcode' => 0,
                    'error' => 'target_not_writable',
                    'contenttype' => '',
                    'effectiveurl' => $currenturl,
                ];
            }

            $location = '';
            $ch = curl_init($currenturl);
            if ($ch === false) {
                fclose($handle);
                return [
                    'ok' => false,
                    'httpcode' => 0,
                    'error' => 'curl_init_failed',
                    'contenttype' => '',
                    'effectiveurl' => $currenturl,
                ];
            }

            $options = [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_BUFFERSIZE => self::STREAM_CHUNK_SIZE,
                CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT,
                CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME,
                CURLOPT_HTTPHEADER => ['Accept-Encoding: identity'],
                CURLOPT_USERAGENT => 'DriveResourceMoodleProxy/1.1.33',
                CURLOPT_COOKIEJAR => $cookiejar,
                CURLOPT_COOKIEFILE => $cookiejar,
                CURLOPT_FILE => $handle,
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
            $result = curl_exec($ch);
            $curlerror = curl_error($ch);
            $curlcode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contenttype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            fclose($handle);

            if ($curlcode >= 300 && $curlcode < 400) {
                $redirecturl = upstream_url_policy::resolve_redirect($currenturl, $location);
                if ($redirecturl === null || $redirects >= self::MAX_REDIRECT_HOPS) {
                    self::delete_if_file($targetpath);
                    return [
                        'ok' => false,
                        'httpcode' => $curlcode,
                        'error' => 'upstream_redirect_rejected',
                        'contenttype' => $contenttype,
                        'effectiveurl' => $currenturl,
                    ];
                }

                self::delete_if_file($targetpath);
                $currenturl = $redirecturl;
                continue;
            }

            return [
                'ok' => $result !== false && $curlcode >= 200 && $curlcode < 300,
                'httpcode' => $curlcode,
                'error' => $curlerror,
                'contenttype' => $contenttype,
                'effectiveurl' => $currenturl,
            ];
        }

        return [
            'ok' => false,
            'httpcode' => 0,
            'error' => 'too_many_redirects',
            'contenttype' => '',
            'effectiveurl' => $currenturl,
        ];
    }

    /**
     * Delete a path when it is an existing file.
     *
     * @param string $path File path.
     * @return void
     */
    private static function delete_if_file(string $path): void {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
