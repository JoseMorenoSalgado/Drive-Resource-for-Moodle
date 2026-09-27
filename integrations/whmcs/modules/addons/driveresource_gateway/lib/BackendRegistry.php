<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use RuntimeException;

/**
 * Registry of media providers available to provisioned services.
 *
 * Video and object-storage providers are intentionally independent. A service
 * can therefore use Elearning Stream for video and an S3-compatible provider
 * for protected PDFs without changing its Moodle service identity or token.
 */
final class BackendRegistry
{
    public const NONE = 'none';
    public const ELEARNING_STREAM = 'elearningstream';
    public const S3_COMPATIBLE = 's3compatible';

    public const KIND_VIDEO = 'video';
    public const KIND_OBJECT = 'object';

    public const CAP_MANAGED_VIDEO = 'managed_video';
    public const CAP_DIRECT_UPLOAD = 'direct_upload';
    public const CAP_PROTECTED_PLAYBACK = 'protected_playback';
    public const CAP_OBJECT_STORAGE = 'object_storage';

    /**
     * Provider metadata.
     *
     * S3 is assignable at the commercial/control-plane layer now, while its
     * protected-PDF data-plane adapter remains separately gated.
     *
     * @return array<string,array{label:string,kind:string,assignable:bool,operational:bool,capabilities:string[]}>
     */
    public static function definitions(): array
    {
        return [
            self::ELEARNING_STREAM => [
                'label' => 'Elearning Stream',
                'kind' => self::KIND_VIDEO,
                'assignable' => true,
                'operational' => true,
                'capabilities' => [
                    self::CAP_MANAGED_VIDEO,
                    self::CAP_DIRECT_UPLOAD,
                    self::CAP_PROTECTED_PLAYBACK,
                ],
            ],
            self::NONE => [
                'label' => 'Disabled',
                'kind' => self::KIND_OBJECT,
                'assignable' => true,
                'operational' => true,
                'capabilities' => [],
            ],
            self::S3_COMPATIBLE => [
                'label' => 'S3-compatible Object Storage',
                'kind' => self::KIND_OBJECT,
                'assignable' => true,
                'operational' => false,
                'capabilities' => [
                    self::CAP_OBJECT_STORAGE,
                ],
            ],
        ];
    }

    /**
     * Video-provider options safe to assign to a product.
     *
     * @return array<string,string>
     */
    public static function videoOptions(): array
    {
        return self::optionsForKind(self::KIND_VIDEO);
    }

    /**
     * Object-storage options safe to assign to a product.
     *
     * @return array<string,string>
     */
    public static function objectOptions(): array
    {
        return self::optionsForKind(self::KIND_OBJECT);
    }

    /**
     * Backward-compatible alias for the old single-backend selector.
     *
     * @return array<string,string>
     */
    public static function provisionableOptions(): array
    {
        return self::videoOptions();
    }

    /**
     * @param string $kind Provider kind.
     * @return array<string,string>
     */
    private static function optionsForKind(string $kind): array
    {
        $options = [];
        foreach (self::definitions() as $key => $definition) {
            if ($definition['kind'] === $kind && $definition['assignable']) {
                $options[$key] = $definition['label'];
            }
        }

        return $options;
    }

    /**
     * Normalize a legacy/empty video provider key.
     *
     * @param string|null $key Provider key.
     * @return string
     */
    public static function normalize(?string $key): string
    {
        return self::normalizeVideo($key);
    }

    /**
     * Normalize a video provider key.
     *
     * @param string|null $key Provider key.
     * @return string
     */
    public static function normalizeVideo(?string $key): string
    {
        $key = strtolower(trim((string) $key));
        return $key !== '' ? $key : self::ELEARNING_STREAM;
    }

    /**
     * Normalize an object-storage provider key.
     *
     * @param string|null $key Provider key.
     * @return string
     */
    public static function normalizeObject(?string $key): string
    {
        $key = strtolower(trim((string) $key));
        return $key !== '' ? $key : self::NONE;
    }

    /**
     * Resolve a human-readable provider label.
     *
     * @param string|null $key Provider key.
     * @return string
     */
    public static function label(?string $key): string
    {
        $key = strtolower(trim((string) $key));
        return self::definitions()[$key]['label'] ?? 'Unknown provider (' . $key . ')';
    }

    /**
     * Whether a provider can be assigned in the control plane.
     *
     * @param string|null $key Provider key.
     * @return bool
     */
    public static function isProvisionable(?string $key): bool
    {
        $key = strtolower(trim((string) $key));
        return !empty(self::definitions()[$key]['assignable']);
    }

    /**
     * Whether a provider's data plane is currently operational.
     *
     * @param string|null $key Provider key.
     * @return bool
     */
    public static function isOperational(?string $key): bool
    {
        $key = strtolower(trim((string) $key));
        return !empty(self::definitions()[$key]['operational']);
    }

    /**
     * Enforce that a video provider is assignable.
     *
     * @param string|null $key Provider key.
     * @return string
     */
    public static function requireProvisionable(?string $key): string
    {
        $key = self::normalizeVideo($key);
        $definition = self::definitions()[$key] ?? null;
        if (
            $definition === null
            || $definition['kind'] !== self::KIND_VIDEO
            || !$definition['assignable']
        ) {
            throw new RuntimeException(
                'Video provider "' . $key . '" is not provisionable in this release.'
            );
        }

        return $key;
    }

    /**
     * Whether a provider advertises one capability.
     *
     * @param string|null $key Provider key.
     * @param string $capability Capability constant.
     * @return bool
     */
    public static function supports(?string $key, string $capability): bool
    {
        $key = strtolower(trim((string) $key));
        $definition = self::definitions()[$key] ?? null;

        return $definition !== null
            && in_array($capability, $definition['capabilities'], true);
    }

    /**
     * Require a capability from a provisioned service video provider.
     *
     * @param object $service Provisioned service row.
     * @param string $capability Capability constant.
     * @return string Normalized provider key.
     */
    public static function requireServiceCapability(object $service, string $capability): string
    {
        $key = self::requireProvisionable((string) (
            $service->video_backend_key
            ?? $service->backend_key
            ?? ''
        ));

        if (!self::supports($key, $capability)) {
            throw new RuntimeException(
                'Video provider "' . $key . '" does not support capability "' . $capability . '".'
            );
        }

        return $key;
    }
}
