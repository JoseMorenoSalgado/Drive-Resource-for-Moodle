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
     * Elearning Stream CDN hostname used for server-side playback delivery.
     *
     * @return string
     */
    public static function cdnHostname(): string
    {
        $hostname = strtolower(rtrim(self::required('bunny_cdn_hostname'), '.'));
        if (
            !preg_match('/^[a-z0-9.-]+$/', $hostname)
            || !str_ends_with($hostname, '.b-cdn.net')
            || $hostname === 'b-cdn.net'
        ) {
            throw new RuntimeException('Invalid Elearning Stream CDN hostname.');
        }

        return $hostname;
    }

    /**
     * Customer-facing hostnames accepted when importing an existing video URL.
     *
     * These values are identifiers only; the gateway never fetches the pasted
     * URL. Ownership is still verified against the configured Video Library.
     *
     * @return string[]
     */
    public static function publicVideoHosts(): array
    {
        $hosts = [
            self::cdnHostname(),
            'video.bunnycdn.com',
            'iframe.mediadelivery.net',
        ];

        $raw = (string) (Setting::getSettingValueForModule(
            'driveresource_gateway',
            'bunny_public_aliases'
        ) ?: '');

        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            $host = strtolower(rtrim(trim((string) $candidate), '.'));
            if (
                $host === ''
                || strlen($host) > 253
                || filter_var($host, FILTER_VALIDATE_IP) !== false
                || !preg_match(
                    '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
                    $host
                )
            ) {
                throw new RuntimeException(
                    'Invalid Elearning Stream public hostname alias: ' . $candidate
                );
            }
            $hosts[] = $host;
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Whether a pasted video URL hostname is approved for import.
     *
     * @param string $host Candidate hostname.
     * @return bool
     */
    public static function isAllowedPublicVideoHost(string $host): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '') {
            return false;
        }

        if (in_array($host, self::publicVideoHosts(), true)) {
            return true;
        }

        return str_ends_with($host, '.mediadelivery.net');
    }

    /**
     * Elearning Stream playback token key.
     *
     * @return string
     */
    public static function playbackTokenKey(): string
    {
        return self::required('bunny_token_key');
    }

    /**
     * Short lifetime for provider playback URLs returned only to Moodle.
     *
     * @return int
     */
    public static function playbackTtl(): int
    {
        $value = (int) (Setting::getSettingValueForModule(
            'driveresource_gateway',
            'playback_ttl'
        ) ?: 300);

        return max(60, min(1800, $value));
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
