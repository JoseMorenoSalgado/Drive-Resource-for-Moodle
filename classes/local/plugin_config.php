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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace mod_videoplayer\local;

/**
 * Canonical runtime configuration for Drive Resource.
 *
 * Keeps admin-setting defaults and runtime fallbacks aligned so a fresh
 * installation behaves the same before and after the settings page is saved.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class plugin_config {
    /** Default active time required for generic resources, in seconds. */
    public const DEFAULT_REQUIRED_SECONDS = 300;

    /** Default completion percentage for new activities. */
    public const DEFAULT_COMPLETION_PERCENTAGE = 80;

    /** Default PDF cache lifetime, in seconds (30 days). */
    public const DEFAULT_PDF_CACHE_TTL = 2592000;

    /** Default player accent colour. */
    public const DEFAULT_PLAYER_COLOR = '#3b82f6';

    /**
     * Whether progress tracking is enabled globally.
     *
     * Missing configuration intentionally means enabled, matching the admin
     * setting default on a fresh installation.
     *
     * @return bool
     */
    public static function tracking_enabled(): bool {
        return (string)get_config('mod_videoplayer', 'enabletracking') !== '0';
    }

    /**
     * Required active time for generic protected resources.
     *
     * @return int
     */
    public static function required_seconds(): int {
        $configured = (int)get_config('mod_videoplayer', 'defaultrequiredseconds');
        return $configured > 0
            ? max(60, $configured)
            : self::DEFAULT_REQUIRED_SECONDS;
    }

    /**
     * Default completion percentage for new activities.
     *
     * @return int
     */
    public static function default_completion_percentage(): int {
        $configured = (int)get_config('mod_videoplayer', 'defaultcompletionpercentage');
        return $configured > 0
            ? max(1, min(100, $configured))
            : self::DEFAULT_COMPLETION_PERCENTAGE;
    }

    /**
     * Whether the detected/selected resource type is shown.
     *
     * @return bool
     */
    public static function show_resource_type(): bool {
        return (string)get_config('mod_videoplayer', 'showresourcetype') !== '0';
    }

    /**
     * Whether background PDF cache warming is enabled.
     *
     * @return bool
     */
    public static function pdf_cache_enabled(): bool {
        return (string)get_config('mod_videoplayer', 'pdfcacheenabled') !== '0';
    }

    /**
     * PDF cache lifetime.
     *
     * @return int
     */
    public static function pdf_cache_ttl(): int {
        $configured = (int)get_config('mod_videoplayer', 'pdfcachettl');
        return $configured > 0 ? $configured : self::DEFAULT_PDF_CACHE_TTL;
    }

    /**
     * Current player colour mode.
     *
     * @return string
     */
    public static function player_color_mode(): string {
        return get_config('mod_videoplayer', 'playercolormode') === 'custom'
            ? 'custom'
            : 'theme';
    }

    /**
     * Validated custom player colour.
     *
     * @return string
     */
    public static function player_color(): string {
        $configured = trim((string)get_config('mod_videoplayer', 'playercolor'));
        return preg_match('/^#[0-9a-fA-F]{6}$/', $configured)
            ? $configured
            : self::DEFAULT_PLAYER_COLOR;
    }
}
