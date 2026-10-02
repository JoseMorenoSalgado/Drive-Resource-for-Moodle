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
 * Protected local-file delivery for Elearning Stream.
 *
 * Remote HTTP delivery is owned exclusively by http_range_proxy. This class
 * only serves Moodle File API content and never contacts an upstream host.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class protected_stream {
    /** @var int Private browser cache lifetime for authorised local resources. */
    private const PRIVATE_CACHE_SECONDS = 300;

    /** @var int Stream chunk size in bytes. */
    private const STREAM_CHUNK_SIZE = 262144;

    /**
     * Resolve the physical Moodle File API path when directly readable.
     *
     * @param \stored_file $file Stored file.
     * @return string|null
     */
    private static function stored_file_path(\stored_file $file): ?string {
        global $CFG;

        $hash = $file->get_contenthash();
        if ($hash === '' || strlen($hash) < 4) {
            return null;
        }

        $path = $CFG->dataroot
            . '/filedir/' . substr($hash, 0, 2)
            . '/' . substr($hash, 2, 2)
            . '/' . $hash;

        return is_readable($path) ? $path : null;
    }

    /**
     * Check that a local file contains a PDF signature.
     *
     * @param string $path Absolute path.
     * @return bool
     */
    private static function is_pdf_file(string $path): bool {
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

        try {
            $header = fread($handle, 1024);
        } finally {
            fclose($handle);
        }

        return is_string($header) && strpos($header, '%PDF-') !== false;
    }

    /**
     * Send a Moodle-local protected PDF with byte-range support.
     *
     * @param \stored_file $file Stored Moodle file.
     * @param string $filename Safe browser filename.
     * @return never
     */
    public static function send_stored_pdf(\stored_file $file, string $filename): never {
        $path = self::stored_file_path($file);
        if ($path === null) {
            $tmpdir = make_request_directory();
            $path = $tmpdir . '/' . sha1(
                $file->get_contenthash() . ':' . $file->get_timemodified()
            ) . '.pdf';
            $file->copy_content_to($path);
        }

        if (!self::is_pdf_file($path)) {
            debugging(
                'Elearning Stream local PDF failed signature validation.',
                DEBUG_DEVELOPER
            );
            throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
        }

        self::send_file(
            $path,
            $filename,
            'application/pdf',
            $file->get_contenthash(),
            (int)$file->get_timemodified()
        );
    }

    /**
     * Send one local file without loading it fully into PHP memory.
     *
     * Supports closed, open-ended and suffix byte ranges.
     *
     * @param string $path Absolute file path.
     * @param string $filename Safe browser filename.
     * @param string $contenttype MIME type.
     * @param string $etag Stable entity tag.
     * @param int $lastmodified Unix timestamp.
     * @return never
     */
    private static function send_file(
        string $path,
        string $filename,
        string $contenttype,
        string $etag,
        int $lastmodified
    ): never {
        if (!is_readable($path)) {
            throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
        }

        [$start, $end, $status] = self::resolve_range($size);
        $length = $end - $start + 1;
        $safefilename = str_replace(["\r", "\n", '"'], '', $filename);
        $lastmodified = $lastmodified > 0 ? $lastmodified : (filemtime($path) ?: time());

        http_response_code($status);
        header('Content-Type: ' . $contenttype);
        header(
            'Content-Disposition: inline; filename="' . $safefilename
                . '"; filename*=UTF-8\'\'' . rawurlencode($safefilename)
        );
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header('Vary: Range');
        self::send_private_cache_headers($etag, $lastmodified);
        header('X-Elearning-Stream-Source: LOCAL');

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
     * Resolve a validated HTTP Range request.
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
     * Return a safe single byte-range header or an empty string.
     *
     * @return string
     */
    private static function request_range_header(): string {
        if (empty($_SERVER['HTTP_RANGE'])) {
            return '';
        }

        $candidate = trim((string)$_SERVER['HTTP_RANGE']);
        return preg_match('/^bytes=\d*-\d*$/', $candidate) ? $candidate : '';
    }

    /**
     * Send RFC-compatible 416 response.
     *
     * @param int $size File size.
     * @return never
     */
    private static function send_range_not_satisfiable(int $size): never {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        header('Cache-Control: no-store, no-cache, must-revalidate, no-transform');
        header('X-Content-Type-Options: nosniff');
        die;
    }

    /**
     * Stream a file segment in bounded chunks.
     *
     * @param string $path File path.
     * @param int $start Start byte.
     * @param int $length Number of bytes.
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
            while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
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
     * Send private cache validators for authorised content.
     *
     * @param string $etag Stable entity tag.
     * @param int $lastmodified Unix timestamp.
     * @return void
     */
    private static function send_private_cache_headers(string $etag, int $lastmodified): void {
        header(
            'Cache-Control: private, max-age=' . self::PRIVATE_CACHE_SECONDS
                . ', must-revalidate, no-transform'
        );
        header(
            'Expires: '
                . gmdate('D, d M Y H:i:s', time() + self::PRIVATE_CACHE_SECONDS)
                . ' GMT'
        );
        header('ETag: "' . preg_replace('/[^a-zA-Z0-9_\-.]/', '', $etag) . '"');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastmodified) . ' GMT');
    }
}
