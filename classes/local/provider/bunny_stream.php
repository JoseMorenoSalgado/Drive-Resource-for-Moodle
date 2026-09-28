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

/**
 * Bunny Stream provider constants and persisted-state helpers.
 *
 * Provider credentials are deliberately not represented here. Bunny API keys
 * live only in the WHMCS media gateway.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoplayer\local\provider;

/**
 * Bunny Stream resource source.
 */
final class bunny_stream {
    /** Persisted source identifier. */
    public const SOURCE = 'bunnystream';

    /** Provider states that may be persisted after a successful byte upload. */
    public const PERSISTABLE_STATUSES = ['uploaded', 'processing', 'ready'];

    /** File extensions accepted by the managed-video ingestion path. */
    public const UPLOAD_EXTENSIONS = ['mp4', 'mov', 'm4v', 'webm', 'mkv', 'avi', 'mpeg', 'mpg'];

    /**
     * Validate a provider video GUID without accepting arbitrary URLs.
     *
     * @param string $value Provider asset identifier.
     * @return bool
     */
    public static function is_valid_asset_id(string $value): bool {
        return preg_match('/^[a-f0-9-]{32,64}$/i', trim($value)) === 1;
    }

    /**
     * Extract a provider video GUID from a pasted Elearning Stream URL.
     *
     * The URL itself is never persisted. Only a syntactically valid video GUID
     * is returned; WHMCS performs the authoritative library/service ownership
     * verification before the activity is saved.
     *
     * @param string $url Pasted provider playback/embed URL.
     * @return string|null
     */
    public static function extract_asset_id_from_url(string $url): ?string {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (
            !$parts
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || !empty($parts['user'])
            || !empty($parts['pass'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
        ) {
            return null;
        }

        $host = strtolower(rtrim((string)$parts['host'], '.'));
        $knownhost = $host === 'video.bunnycdn.com'
            || $host === 'iframe.mediadelivery.net'
            || str_ends_with($host, '.mediadelivery.net')
            || str_ends_with($host, '.b-cdn.net');
        if (!$knownhost) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', trim((string)($parts['path'] ?? ''), '/')),
            static fn(string $segment): bool => $segment !== ''
        ));

        foreach (array_reverse($segments) as $segment) {
            $candidate = rawurldecode($segment);
            if (self::is_valid_asset_id($candidate)) {
                return strtolower($candidate);
            }
        }

        parse_str((string)($parts['query'] ?? ''), $query);
        foreach (['videoid', 'videoId', 'guid'] as $key) {
            $candidate = trim((string)($query[$key] ?? ''));
            if (self::is_valid_asset_id($candidate)) {
                return strtolower($candidate);
            }
        }

        return null;
    }

    /**
     * Extract a syntactically valid GUID from any HTTPS public video URL.
     *
     * This helper is intentionally hostname-neutral. WHMCS is the authority
     * that validates the public hostname against configured aliases before the
     * video is imported. Moodle never fetches or persists the pasted URL.
     *
     * @param string $url Candidate public video URL.
     * @return string|null
     */
    public static function extract_candidate_asset_id_from_url(string $url): ?string {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            return null;
        }

        $parts = parse_url($url);
        if (
            !$parts
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || !empty($parts['user'])
            || !empty($parts['pass'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
        ) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', trim((string)($parts['path'] ?? ''), '/')),
            static fn(string $segment): bool => $segment !== ''
        ));

        foreach (array_reverse($segments) as $segment) {
            $candidate = rawurldecode($segment);
            if (self::is_valid_asset_id($candidate)) {
                return strtolower($candidate);
            }
        }

        parse_str((string)($parts['query'] ?? ''), $query);
        foreach (['videoid', 'videoId', 'guid'] as $key) {
            $candidate = trim((string)($query[$key] ?? ''));
            if (self::is_valid_asset_id($candidate)) {
                return strtolower($candidate);
            }
        }

        return null;
    }

    /**
     * Whether a pasted Elearning Stream URL contains a valid provider video id.
     *
     * @param string $url Pasted provider URL.
     * @return bool
     */
    public static function is_supported_url(string $url): bool {
        return self::extract_asset_id_from_url($url) !== null;
    }

    /**
     * Validate a source filename accepted by the managed-video upload path.
     *
     * Browser MIME types are advisory. The filename extension is checked again
     * server-side before WHMCS is asked to reserve provider capacity.
     *
     * @param string $filename Original source filename.
     * @return bool
     */
    public static function is_supported_upload_filename(string $filename): bool {
        $filename = basename(trim($filename));
        if ($filename === '' || strlen($filename) > 255) {
            return false;
        }

        $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, self::UPLOAD_EXTENSIONS, true);
    }

    /**
     * Validate a WHMCS upload reservation identifier.
     *
     * Reservations are generated as bin2hex(random_bytes(16)): exactly 32
     * lowercase hexadecimal characters.
     *
     * @param string $value Upload reservation identifier.
     * @return bool
     */
    public static function is_valid_upload_id(string $value): bool {
        return preg_match('/^[a-f0-9]{32}$/', trim($value)) === 1;
    }

    /**
     * Normalise a provider state.
     *
     * @param string $status Provider status.
     * @return string
     */
    public static function normalise_status(string $status): string {
        $status = clean_param($status, PARAM_ALPHANUMEXT);
        return in_array($status, self::PERSISTABLE_STATUSES, true) ? $status : '';
    }
}
