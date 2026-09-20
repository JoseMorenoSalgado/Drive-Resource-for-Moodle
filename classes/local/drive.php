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
 * Google Drive URL helper.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class drive {
    /** @var string Google Drive file source. */
    public const SOURCE_GOOGLEDRIVE = 'googledrive';

    /** @var string Generic media type. */
    public const TYPE_AUTO = 'auto';

    /**
     * Extract a Google Drive file ID from supported sharing URLs.
     *
     * @param string $url
     * @return string|null
     */
    public static function extract_file_id(string $url): ?string {
        $url = trim($url);

        $patterns = [
            '~drive\.google\.com/file/d/([a-zA-Z0-9_-]+)~',
            '~drive\.google\.com/open\?id=([a-zA-Z0-9_-]+)~',
            '~drive\.google\.com/uc\?id=([a-zA-Z0-9_-]+)~',
            '~docs\.google\.com/(document|spreadsheets|presentation)/d/([a-zA-Z0-9_-]+)~',
            '~[?&]id=([a-zA-Z0-9_-]+)~',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                $id = end($matches);
                return clean_param($id, PARAM_ALPHANUMEXT);
            }
        }

        return null;
    }

    /**
     * Detect resource type from a Google Drive URL.
     *
     * @param string $url
     * @return string
     */
    public static function detect_type(string $url): string {
        $lowerurl = strtolower($url);

        if (strpos($lowerurl, 'docs.google.com/document') !== false) {
            return 'document';
        }
        if (strpos($lowerurl, 'docs.google.com/spreadsheets') !== false) {
            return 'spreadsheet';
        }
        if (strpos($lowerurl, 'docs.google.com/presentation') !== false) {
            return 'presentation';
        }
        if (preg_match('/\.pdf([?#].*)?$/i', $lowerurl) || strpos($lowerurl, 'type=pdf') !== false) {
            return 'pdf';
        }
        if (preg_match('/\.(mp4|webm|mov|m4v)([?#].*)?$/i', $lowerurl)) {
            return 'video';
        }
        if (preg_match('/\.(mp3|m4a|aac|ogg|wav)([?#].*)?$/i', $lowerurl)) {
            return 'audio';
        }
        if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)([?#].*)?$/i', $lowerurl)) {
            return 'image';
        }

        return 'file';
    }

    /**
     * Build the Google Drive content URL used by the protected proxy.
     *
     * This URL is never rendered in the Moodle page. It is used server-side by
     * protected.php after Moodle access checks have passed.
     *
     * @param string $fileid Google Drive file id.
     * @param string $type Resource type.
     * @return string|null
     */
    public static function protected_content_url(string $fileid, string $type): ?string {
        $fileid = clean_param($fileid, PARAM_ALPHANUMEXT);
        if ($fileid === '') {
            return null;
        }

        if (in_array($type, ['document', 'spreadsheet', 'presentation'], true)) {
            return self::google_docs_export_url($fileid, $type);
        }

        // Google Drive's legacy /uc endpoint may return an HTML confirmation
        // page instead of media bytes. For video, use the usercontent download
        // endpoint so byte-range requests reach the actual file more reliably.
        if (in_array($type, ['video', 'audio'], true)) {
            return 'https://drive.usercontent.google.com/download?id=' . rawurlencode($fileid)
                . '&export=download&authuser=0&confirm=t';
        }

        return 'https://drive.google.com/uc?export=download&id=' . rawurlencode($fileid);
    }

    /**
     * Return the default MIME type for a resource type.
     *
     * @param string $type Resource type.
     * @return string
     */
    public static function default_mimetype(string $type): string {
        $types = [
            'pdf' => 'application/pdf',
            'video' => 'video/mp4',
            'audio' => 'audio/mpeg',
            'image' => 'image/jpeg',
            'document' => 'application/pdf',
            'spreadsheet' => 'application/pdf',
            'presentation' => 'application/pdf',
            'file' => 'application/octet-stream',
        ];

        return $types[$type] ?? 'application/octet-stream';
    }

    /**
     * Validate whether the URL is a supported Google Drive URL.
     *
     * @param string $url
     * @return bool
     */
    public static function is_supported_url(string $url): bool {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $host = strtolower($host);
        if (!in_array($host, ['drive.google.com', 'docs.google.com'], true)) {
            return false;
        }

        return self::extract_file_id($url) !== null;
    }

    /**
     * Whether the resource type is a PDF-compatible resource.
     *
     * @param string $type Resource type.
     * @return bool
     */
    public static function is_pdf_type(string $type): bool {
        return in_array($type, ['pdf', 'document', 'spreadsheet', 'presentation'], true);
    }

    /**
     * Build Google Docs export URL.
     *
     * @param string $fileid File id.
     * @param string $type Resource type.
     * @return string
     */
    private static function google_docs_export_url(string $fileid, string $type): string {
        if ($type === 'spreadsheet') {
            return 'https://docs.google.com/spreadsheets/d/' . rawurlencode($fileid) . '/export?format=pdf';
        }
        if ($type === 'presentation') {
            return 'https://docs.google.com/presentation/d/' . rawurlencode($fileid) . '/export/pdf';
        }

        return 'https://docs.google.com/document/d/' . rawurlencode($fileid) . '/export?format=pdf';
    }
}
