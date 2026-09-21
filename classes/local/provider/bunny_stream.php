<?php
// This file is part of Moodle - http://moodle.org/

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

defined('MOODLE_INTERNAL') || die();

/**
 * Bunny Stream resource source.
 */
final class bunny_stream {
    /** Persisted source identifier. */
    public const SOURCE = 'bunnystream';

    /** Provider states that may be persisted after a successful byte upload. */
    public const PERSISTABLE_STATUSES = ['uploaded', 'processing', 'ready'];

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
     * Validate a WHMCS upload reservation identifier.
     *
     * @param string $value Upload reservation identifier.
     * @return bool
     */
    public static function is_valid_upload_id(string $value): bool {
        return preg_match('/^[a-f0-9-]{20,64}$/i', trim($value)) === 1;
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
