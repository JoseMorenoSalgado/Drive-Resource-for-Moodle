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

    /** @var string Video resource type. */
    public const TYPE_VIDEO = 'video';

    /** @var string Generic unsupported file type. */
    public const TYPE_FILE = 'file';

    /** @var array<string> Supported configured resource types. */
    private const CONFIGURED_TYPES = [
        self::TYPE_AUTO,
        self::TYPE_VIDEO,
        'pdf',
        'image',
        'document',
        'spreadsheet',
        'presentation',
        self::TYPE_FILE,
    ];

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
     * Extract an optional Google Drive resource key from a sharing URL.
     *
     * Resource keys are required by some link-shared files. The key remains
     * server-side and is only forwarded to Google's download endpoint.
     *
     * @param string $url Google Drive sharing URL.
     * @return string|null Resource key or null.
     */
    public static function extract_resource_key(string $url): ?string {
        $query = (string)parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return null;
        }

        parse_str($query, $params);
        $resourcekey = trim((string)($params['resourcekey'] ?? ''));
        if ($resourcekey === '') {
            return null;
        }

        $resourcekey = preg_replace('/[^a-zA-Z0-9_-]/', '', $resourcekey);
        return $resourcekey !== '' ? $resourcekey : null;
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
        if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)([?#].*)?$/i', $lowerurl)) {
            return 'image';
        }

        return 'file';
    }

    /**
     * Resolve the effective resource type for one activity record.
     *
     * Standard Google Drive sharing URLs such as /file/d/{id}/view do not
     * expose a filename or MIME type. Older versions of this plugin were
     * video-only and later migrated those records to type=auto, which caused
     * every normal Drive video URL to resolve as a generic unsupported file.
     *
     * Automatic mode therefore keeps URL-based detection for typed URLs and
     * Google Workspace resources, but uses video as the deterministic fallback
     * for an otherwise opaque Drive file link. New activities default to the
     * explicit video type, while PDF/image/document resources can still be
     * selected explicitly.
     *
     * @param object $record Activity record with source, type and videourl fields.
     * @return string Effective resource type.
     */
    public static function resolve_record_type(object $record): string {
        if (($record->source ?? self::SOURCE_GOOGLEDRIVE) === 'localpdf') {
            return 'pdf';
        }

        $configured = clean_param((string) ($record->type ?? self::TYPE_AUTO), PARAM_ALPHANUMEXT);
        if ($configured !== '' && $configured !== self::TYPE_AUTO) {
            return in_array($configured, self::CONFIGURED_TYPES, true) ? $configured : self::TYPE_FILE;
        }

        $detected = self::detect_type((string) ($record->videourl ?? ''));
        return $detected === self::TYPE_FILE ? self::TYPE_VIDEO : $detected;
    }

    /**
     * Validate a configured resource type.
     *
     * @param string $type Resource type.
     * @return bool
     */
    public static function is_supported_configured_type(string $type): bool {
        return in_array($type, self::CONFIGURED_TYPES, true);
    }

    /**
     * Build the Google Drive content URL used by the protected proxy.
     *
     * This URL is never rendered in the Moodle page. It is used server-side by
     * protected.php after Moodle access checks have passed.
     *
     * @param string $originalurl Original Google Drive URL.
     * @param string $fileid Google Drive file id.
     * @param string $type Resource type.
     * @return string|null
     */
    public static function protected_content_url(string $originalurl, string $fileid, string $type): ?string {
        $fileid = clean_param($fileid, PARAM_ALPHANUMEXT);
        if ($fileid === '') {
            return null;
        }

        $resourcekey = self::extract_resource_key($originalurl);
        if (in_array($type, ['document', 'spreadsheet', 'presentation'], true)) {
            return self::google_docs_export_url($fileid, $type, $resourcekey);
        }

        $params = [
            'id' => $fileid,
            'export' => 'download',
            'confirm' => 't',
        ];
        if ($resourcekey !== null) {
            $params['resourcekey'] = $resourcekey;
        }

        return 'https://drive.usercontent.google.com/download?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Resolve a Google Drive large-file confirmation page to its protected download URL.
     *
     * Google may return an HTML virus-scan warning for large public files even
     * when confirm=t is present. The warning contains a server-generated UUID
     * and other hidden fields that must be replayed before byte-range streaming
     * can begin. Only known Google Drive download hosts and a strict parameter
     * allow-list are accepted so the upstream response cannot turn the Moodle
     * proxy into an SSRF primitive.
     *
     * @param string $html Google Drive warning HTML.
     * @param string $fallbackurl Current trusted Google Drive URL.
     * @return string|null Confirmed Google Drive URL, or null when the HTML is not a valid warning form.
     */
    public static function resolve_download_warning_url(string $html, string $fallbackurl): ?string {
        if ($html === '' || stripos($html, '<form') === false || !class_exists('DOMDocument')) {
            return null;
        }

        $previouserrors = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return null;
            }

            $xpath = new \DOMXPath($document);
            $forms = $xpath->query('//form');
            if ($forms === false) {
                return null;
            }

            foreach ($forms as $form) {
                if (!$form instanceof \DOMElement) {
                    continue;
                }

                $action = trim($form->getAttribute('action'));
                $actionurl = self::normalize_download_action($action, $fallbackurl);
                if ($actionurl === null) {
                    continue;
                }

                $params = [];
                $actionquery = (string) parse_url($action, PHP_URL_QUERY);
                if ($actionquery !== '') {
                    $actionparams = [];
                    parse_str($actionquery, $actionparams);
                    foreach (['id', 'export', 'confirm', 'uuid', 'resourcekey'] as $name) {
                        if (!empty($actionparams[$name])) {
                            $params[$name] = (string) $actionparams[$name];
                        }
                    }
                }

                $inputs = $xpath->query('.//input[@name]', $form);
                if ($inputs === false) {
                    continue;
                }

                foreach ($inputs as $input) {
                    if (!$input instanceof \DOMElement) {
                        continue;
                    }

                    $name = strtolower(trim($input->getAttribute('name')));
                    if (!in_array($name, ['id', 'export', 'confirm', 'uuid', 'resourcekey'], true)) {
                        continue;
                    }

                    $value = trim($input->getAttribute('value'));
                    if ($value !== '') {
                        $params[$name] = $value;
                    }
                }

                $fallbackquery = (string) parse_url($fallbackurl, PHP_URL_QUERY);
                if ($fallbackquery !== '') {
                    $fallbackparams = [];
                    parse_str($fallbackquery, $fallbackparams);
                    foreach (['id', 'resourcekey'] as $name) {
                        if (empty($params[$name]) && !empty($fallbackparams[$name])) {
                            $params[$name] = (string) $fallbackparams[$name];
                        }
                    }
                }

                $fileid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($params['id'] ?? ''));
                if ($fileid === '') {
                    continue;
                }

                $safeparams = [
                    'id' => $fileid,
                    'export' => 'download',
                ];
                foreach (['confirm', 'uuid', 'resourcekey'] as $name) {
                    if (empty($params[$name])) {
                        continue;
                    }

                    $value = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $params[$name]);
                    if ($value !== '') {
                        $safeparams[$name] = $value;
                    }
                }

                if (empty($safeparams['confirm'])) {
                    continue;
                }

                return $actionurl . '?' . http_build_query($safeparams, '', '&', PHP_QUERY_RFC3986);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previouserrors);
        }

        return null;
    }

    /**
     * Normalize and validate a Drive warning form action.
     *
     * @param string $action Form action.
     * @param string $fallbackurl Trusted current URL.
     * @return string|null Absolute validated action URL.
     */
    private static function normalize_download_action(string $action, string $fallbackurl): ?string {
        if ($action === '') {
            return null;
        }

        if (strpos($action, '/') === 0 && strpos($action, '//') !== 0) {
            $scheme = (string) parse_url($fallbackurl, PHP_URL_SCHEME);
            $host = (string) parse_url($fallbackurl, PHP_URL_HOST);
            if ($scheme !== 'https' || $host === '') {
                return null;
            }
            $action = $scheme . '://' . $host . $action;
        }

        if (!filter_var($action, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($action, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($action, PHP_URL_HOST));
        if (
            $scheme !== 'https' ||
            !in_array(
                $host,
                ['drive.usercontent.google.com', 'drive.google.com', 'docs.google.com'],
                true
            )
        ) {
            return null;
        }

        $path = (string) parse_url($action, PHP_URL_PATH);
        if ($path === '') {
            return null;
        }

        return $scheme . '://' . $host . $path;
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
     * @param string|null $resourcekey Optional resource key.
     * @return string
     */
    private static function google_docs_export_url(
        string $fileid,
        string $type,
        ?string $resourcekey = null
    ): string {
        if ($type === 'spreadsheet') {
            $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($fileid) . '/export?format=pdf';
        } else if ($type === 'presentation') {
            $url = 'https://docs.google.com/presentation/d/' . rawurlencode($fileid) . '/export/pdf';
        } else {
            $url = 'https://docs.google.com/document/d/' . rawurlencode($fileid) . '/export?format=pdf';
        }

        if ($resourcekey === null) {
            return $url;
        }

        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . 'resourcekey=' . rawurlencode($resourcekey);
    }
}
