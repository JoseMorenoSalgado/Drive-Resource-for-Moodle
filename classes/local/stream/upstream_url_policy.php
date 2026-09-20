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

namespace mod_videoplayer\local\stream;

/**
 * Google-owned upstream URL allow-list for protected proxy requests.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upstream_url_policy {
    /**
     * Resolve and validate one upstream redirect target.
     *
     * Only absolute HTTPS URLs and root-relative redirects are accepted. The
     * resulting URL must remain inside the Google-owned upstream allow-list.
     *
     * @param string $baseurl Current trusted URL.
     * @param string $location Raw Location header.
     * @return string|null
     */
    public static function resolve_redirect(string $baseurl, string $location): ?string {
        $location = trim(str_replace(["\r", "\n"], '', $location));
        if ($location === '') {
            return null;
        }

        if (str_starts_with($location, '//')) {
            $location = 'https:' . $location;
        } else if (str_starts_with($location, '/')) {
            $base = parse_url($baseurl);
            if (
                !is_array($base) ||
                strtolower((string)($base['scheme'] ?? '')) !== 'https' ||
                empty($base['host'])
            ) {
                return null;
            }

            $location = 'https://' . strtolower((string)$base['host']) . $location;
        } else if (!preg_match('~^https://~i', $location)) {
            return null;
        }

        return self::is_allowed($location) ? $location : null;
    }

    /**
     * Whether one HTTPS URL is safe for the Drive Resource proxy.
     *
     * @param string $url
     * @return bool
     */
    public static function is_allowed(string $url): bool {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            return false;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        $exact = [
            'drive.google.com',
            'docs.google.com',
            'drive.usercontent.google.com',
            'content-workspacevideo-pa.googleapis.com',
        ];
        if (in_array($host, $exact, true)) {
            return true;
        }

        foreach (['googlevideo.com', 'googleusercontent.com'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return str_ends_with($host, '.c.drive.google.com');
    }
}
