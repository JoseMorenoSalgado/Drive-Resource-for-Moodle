<?php
// This file is part of Moodle - http://moodle.org/

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

        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        $exact = [
            'drive.google.com',
            'docs.google.com',
            'drive.usercontent.google.com',
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
