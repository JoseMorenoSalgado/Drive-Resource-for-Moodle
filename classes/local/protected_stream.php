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

    /** @var int Default PDF cache lifetime: 30 days. */
    private const DEFAULT_PDF_CACHE_TTL = 2592000;

    /** @var int Temporary cache file stale lifetime. */
    private const STALE_TMP_TTL = 3600;

    /**
     * Return the configured PDF cache TTL.
     *
     * @return int Cache TTL in seconds.
     */
    public static function pdf_cache_ttl(): int {
        $ttl = (int) get_config('mod_videoplayer', 'pdfcachettl');
        return $ttl > 0 ? $ttl : self::DEFAULT_PDF_CACHE_TTL;
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
            (int) $file->get_timemodified(),
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
        header('X-Drive-Resource-Status: MEDIA');

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
            self::delete_if_file($tmpfile);

            $download = drive_downloader::download($url, $tmpfile);
            $valid = $download['ok'] && self::is_pdf_file($tmpfile);

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
            flock($lockhandle, LOCK_UN);
            fclose($lockhandle);
        }
    }

    /**
     * Remove expired PDF cache and temporary files.
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
            if ($isexpiredpdf || $isstaletmp) {
                self::delete_if_file($file);
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

        $candidate = trim((string) $_SERVER['HTTP_RANGE']);
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
            $suffixlength = (int) $endpart;
            if ($suffixlength <= 0) {
                self::send_range_not_satisfiable($size);
            }

            $suffixlength = min($suffixlength, $size);
            return [$size - $suffixlength, $size - 1, 206];
        }

        $start = (int) $startpart;
        $end = $endpart === '' ? $size - 1 : min((int) $endpart, $size - 1);

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
