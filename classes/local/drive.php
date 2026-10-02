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
 * Deprecated compatibility shim for pre-1.3 code.
 *
 * Runtime code must use resource_compatibility. This class remains only so
 * older extension code and serialized references do not fail during upgrade.
 *
 * @deprecated since Elearning Stream 1.3
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class drive {
    /** @deprecated Retired persisted source key. */
    public const SOURCE_GOOGLEDRIVE = resource_compatibility::SOURCE_RETIRED_REMOTE;

    /** @deprecated Use resource_compatibility::SOURCE_LOCALPDF. */
    public const SOURCE_LOCALPDF = resource_compatibility::SOURCE_LOCALPDF;

    /** @deprecated Use resource_compatibility::TYPE_AUTO. */
    public const TYPE_AUTO = resource_compatibility::TYPE_AUTO;

    /** @deprecated Use resource_compatibility::TYPE_VIDEO. */
    public const TYPE_VIDEO = resource_compatibility::TYPE_VIDEO;

    /** @deprecated Use resource_compatibility::TYPE_FILE. */
    public const TYPE_FILE = resource_compatibility::TYPE_FILE;

    /** @deprecated Use resource_compatibility::RESOURCE_TYPES. */
    public const RESOURCE_TYPES = resource_compatibility::RESOURCE_TYPES;

    /**
     * @deprecated Use resource_compatibility::resolve_record_type().
     * @param object $record Activity record.
     * @return string
     */
    public static function resolve_record_type(object $record): string {
        return resource_compatibility::resolve_record_type($record);
    }

    /**
     * @deprecated Use resource_compatibility::is_supported_configured_type().
     * @param string $type Resource type.
     * @return bool
     */
    public static function is_supported_configured_type(string $type): bool {
        return resource_compatibility::is_supported_configured_type($type);
    }

    /**
     * @deprecated Use resource_compatibility::is_pdf_type().
     * @param string $type Resource type.
     * @return bool
     */
    public static function is_pdf_type(string $type): bool {
        return resource_compatibility::is_pdf_type($type);
    }

    /**
     * @deprecated Use resource_compatibility::default_mimetype().
     * @param string $type Resource type.
     * @return string
     */
    public static function default_mimetype(string $type): string {
        return resource_compatibility::default_mimetype($type);
    }
}
