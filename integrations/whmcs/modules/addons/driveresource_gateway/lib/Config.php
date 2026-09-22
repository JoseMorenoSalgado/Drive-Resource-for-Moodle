<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use RuntimeException;
use WHMCS\Module\Addon\Setting;

/**
 * Gateway configuration accessor.
 */
final class Config
{
    /**
     * Get a required addon setting.
     *
     * @param string $name Setting key.
     * @return string
     */
    public static function required(string $name): string
    {
        $value = trim((string) Setting::getSettingValueForModule('driveresource_gateway', $name));
        if ($value === '') {
            throw new RuntimeException('Missing Drive Resource gateway setting: ' . $name);
        }

        return $value;
    }

    /**
     * Bunny Stream library id.
     *
     * @return int
     */
    public static function libraryId(): int
    {
        $id = (int) self::required('bunny_library_id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid Bunny Stream Library ID.');
        }

        return $id;
    }

    /**
     * Bunny Stream API key.
     *
     * @return string
     */
    public static function bunnyApiKey(): string
    {
        return self::required('bunny_api_key');
    }

    /**
     * TUS authorisation lifetime.
     *
     * @return int
     */
    public static function tusTtl(): int
    {
        $value = (int) (Setting::getSettingValueForModule('driveresource_gateway', 'tus_ttl') ?: 21600);
        return max(300, min(86400, $value));
    }

    /**
     * Allowed timestamp drift for Moodle requests.
     *
     * @return int
     */
    public static function clockSkew(): int
    {
        $value = (int) (Setting::getSettingValueForModule('driveresource_gateway', 'clock_skew') ?: 300);
        return max(60, min(900, $value));
    }
    /**
     * Grace period for a completed upload that has not been bound to Moodle.
     *
     * @return int
     */
    public static function unboundGraceSeconds(): int
    {
        $hours = (int) (Setting::getSettingValueForModule(
            'driveresource_gateway',
            'unbound_grace_hours'
        ) ?: 24);
        $hours = max(1, min(168, $hours));

        return $hours * 3600;
    }
}
