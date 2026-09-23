<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use RuntimeException;

/**
 * Registry of storage/media backends available to provisioned WHMCS services.
 *
 * The service row stores the backend key independently from provider
 * credentials. This allows one WHMCS installation to provision many customers
 * today and add S3-compatible object storage later without changing tenant
 * identity, quota, billing or Moodle authentication contracts.
 */
final class BackendRegistry
{
    public const ELEARNING_STREAM = 'elearningstream';
    public const S3_COMPATIBLE = 's3compatible';

    public const CAP_MANAGED_VIDEO = 'managed_video';
    public const CAP_DIRECT_UPLOAD = 'direct_upload';
    public const CAP_PROTECTED_PLAYBACK = 'protected_playback';
    public const CAP_OBJECT_STORAGE = 'object_storage';

    /**
     * Backend metadata.
     *
     * @return array<string,array{label:string,provisionable:bool,capabilities:string[]}>
     */
    public static function definitions(): array
    {
        return [
            self::ELEARNING_STREAM => [
                'label' => 'Elearning Stream',
                'provisionable' => true,
                'capabilities' => [
                    self::CAP_MANAGED_VIDEO,
                    self::CAP_DIRECT_UPLOAD,
                    self::CAP_PROTECTED_PLAYBACK,
                ],
            ],
            self::S3_COMPATIBLE => [
                'label' => 'S3-compatible Object Storage',
                'provisionable' => false,
                'capabilities' => [
                    self::CAP_OBJECT_STORAGE,
                ],
            ],
        ];
    }

    /**
     * Product configuration options currently safe to provision.
     *
     * @return array<string,string>
     */
    public static function provisionableOptions(): array
    {
        $options = [];
        foreach (self::definitions() as $key => $definition) {
            if ($definition['provisionable']) {
                $options[$key] = $definition['label'];
            }
        }

        return $options;
    }

    /**
     * Normalize an empty/legacy backend to the current default.
     *
     * @param string|null $key Backend key.
     * @return string
     */
    public static function normalize(?string $key): string
    {
        $key = strtolower(trim((string) $key));
        return $key !== '' ? $key : self::ELEARNING_STREAM;
    }

    /**
     * Resolve a human-readable backend label.
     *
     * @param string|null $key Backend key.
     * @return string
     */
    public static function label(?string $key): string
    {
        $key = self::normalize($key);
        return self::definitions()[$key]['label'] ?? 'Unknown backend (' . $key . ')';
    }

    /**
     * Whether the backend can be provisioned in this release.
     *
     * @param string|null $key Backend key.
     * @return bool
     */
    public static function isProvisionable(?string $key): bool
    {
        $key = self::normalize($key);
        return !empty(self::definitions()[$key]['provisionable']);
    }

    /**
     * Enforce that a service uses a backend supported by this release.
     *
     * @param string|null $key Backend key.
     * @return string Normalized backend key.
     */
    public static function requireProvisionable(?string $key): string
    {
        $key = self::normalize($key);
        if (!self::isProvisionable($key)) {
            throw new RuntimeException(
                'Storage backend "' . $key . '" is not provisionable in this release.'
            );
        }

        return $key;
    }

    /**
     * Whether a backend advertises one capability.
     *
     * @param string|null $key Backend key.
     * @param string $capability Capability constant.
     * @return bool
     */
    public static function supports(?string $key, string $capability): bool
    {
        $key = self::normalize($key);
        $definition = self::definitions()[$key] ?? null;
        return $definition !== null
            && in_array($capability, $definition['capabilities'], true);
    }

    /**
     * Require a capability from a provisioned service backend.
     *
     * @param object $service Provisioned service row.
     * @param string $capability Capability constant.
     * @return string Normalized backend key.
     */
    public static function requireServiceCapability(object $service, string $capability): string
    {
        $key = self::requireProvisionable((string) ($service->backend_key ?? ''));
        if (!self::supports($key, $capability)) {
            throw new RuntimeException(
                'Storage backend "' . $key . '" does not support capability "' . $capability . '".'
            );
        }

        return $key;
    }
}
