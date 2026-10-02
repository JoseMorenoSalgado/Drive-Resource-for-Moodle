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
 * Persisted resource compatibility constants and type helpers.
 *
 * Provider-specific network resolution is deliberately excluded from this
 * class. Retired source values are treated only as migration metadata.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resource_compatibility {
    /** @var string Retired remote-source key retained only for migration. */
    public const SOURCE_RETIRED_REMOTE = 'googledrive';

    /** @var string Moodle-local protected PDF source. */
    public const SOURCE_LOCALPDF = 'localpdf';

    /** @var string Automatic resource type retained for older records. */
    public const TYPE_AUTO = 'auto';

    /** @var string Video resource type. */
    public const TYPE_VIDEO = 'video';

    /** @var string Generic unsupported file type. */
    public const TYPE_FILE = 'file';

    /** Canonical historical resource types accepted in persisted data. */
    public const RESOURCE_TYPES = [
        self::TYPE_VIDEO,
        'audio',
        'pdf',
        'image',
        'document',
        'spreadsheet',
        'presentation',
        self::TYPE_FILE,
    ];

    /**
     * Resolve the persisted resource type without inspecting an external URL.
     *
     * @param object $record Activity record.
     * @return string
     */
    public static function resolve_record_type(object $record): string {
        if (($record->source ?? '') === self::SOURCE_LOCALPDF) {
            return 'pdf';
        }

        $configured = clean_param((string)($record->type ?? self::TYPE_AUTO), PARAM_ALPHANUMEXT);
        if ($configured !== '' && $configured !== self::TYPE_AUTO) {
            return in_array($configured, self::RESOURCE_TYPES, true)
                ? $configured
                : self::TYPE_FILE;
        }

        return self::TYPE_VIDEO;
    }

    /**
     * Validate a persisted resource type.
     *
     * @param string $type Resource type.
     * @return bool
     */
    public static function is_supported_configured_type(string $type): bool {
        return $type === self::TYPE_AUTO || in_array($type, self::RESOURCE_TYPES, true);
    }

    /**
     * Whether the type uses the local PDF.js reader.
     *
     * @param string $type Resource type.
     * @return bool
     */
    public static function is_pdf_type(string $type): bool {
        return in_array($type, ['pdf', 'document', 'spreadsheet', 'presentation'], true);
    }

    /**
     * Default MIME type for retained resource types.
     *
     * @param string $type Resource type.
     * @return string
     */
    public static function default_mimetype(string $type): string {
        return match ($type) {
            'pdf', 'document', 'spreadsheet', 'presentation' => 'application/pdf',
            'video' => 'video/mp4',
            'audio' => 'audio/mpeg',
            'image' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
