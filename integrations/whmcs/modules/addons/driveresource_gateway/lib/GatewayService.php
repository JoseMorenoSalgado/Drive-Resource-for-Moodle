<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use Throwable;
use WHMCS\Database\Capsule;

/**
 * Quota, reservation and provider-asset orchestration.
 */
final class GatewayService
{
    private BunnyClient $bunny;

    public function __construct()
    {
        $this->bunny = new BunnyClient();
    }

    /**
     * Reserve quota, create the Bunny video, and return a scoped TUS signature.
     *
     * @param object $service Authenticated service row.
     * @param array $payload Request body.
     * @return array
     */
    public function authorizeUpload(object $service, array $payload): array
    {
        $this->requireBackendCapability($service, BackendRegistry::CAP_DIRECT_UPLOAD);
        $filename = basename(trim((string) ($payload['filename'] ?? '')));
        $filesize = (int) ($payload['filesize'] ?? 0);
        $title = trim((string) ($payload['title'] ?? ''));
        $courseId = max(0, (int) ($payload['courseid'] ?? 0));

        if ($filename === '' || strlen($filename) > 255 || $filesize <= 0 || $filesize > 1099511627776) {
            throw new GatewayException('Invalid video upload metadata.', 422);
        }

        $uploadId = bin2hex(random_bytes(16));
        $now = time();
        $expiresAt = $now + Config::tusTtl();

        $quota = Capsule::connection()->transaction(function () use (
            $service,
            $filesize,
            $filename,
            $courseId,
            $uploadId,
            $now,
            $expiresAt
        ): array {
            $locked = Capsule::table('mod_driveresource_services')
                ->where('service_id', (int) $service->service_id)
                ->lockForUpdate()
                ->first();

            if (!$locked || (string) $locked->status !== 'active') {
                throw new GatewayException('Drive Resource service is not active.', 403);
            }

            $used = (int) $locked->used_bytes;
            $reserved = (int) $locked->reserved_bytes;
            $included = (int) $locked->quota_bytes;
            $projected = $used + $reserved + $filesize;
            $overage = max(0, $projected - $included);

            if ($overage > 0 && !(bool) $locked->overage_allowed) {
                throw new GatewayException('The storage quota has been reached and this plan does not allow overage.', 409);
            }

            Capsule::table('mod_driveresource_uploads')->insert([
                'upload_id' => $uploadId,
                'service_id' => (int) $locked->service_id,
                'video_id' => null,
                'filename' => $filename,
                'source_size' => $filesize,
                'accounted_bytes' => 0,
                'status' => 'reserved',
                'course_id' => $courseId,
                'bound_instance_id' => 0,
                'expires_at' => $expiresAt,
                'delete_after' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'completed_at' => null,
            ]);

            Capsule::table('mod_driveresource_services')
                ->where('service_id', (int) $locked->service_id)
                ->update([
                    'reserved_bytes' => $reserved + $filesize,
                    'updated_at' => $now,
                ]);

            return [
                'includedbytes' => $included,
                'usedbytes' => $used,
                'reservedbytes' => $reserved + $filesize,
                'projectedbytes' => $projected,
                'overagebytes' => $overage,
                'overageallowed' => (bool) $locked->overage_allowed,
            ];
        });

        try {
            $videoId = $this->bunny->createVideo($title !== '' ? $title : $filename);
        } catch (Throwable $exception) {
            $this->cancelReservation($uploadId);
            throw new GatewayException('Elearning Stream could not create the video resource.', 502);
        }

        try {
            Capsule::table('mod_driveresource_uploads')
                ->where('upload_id', $uploadId)
                ->where('service_id', (int) $service->service_id)
                ->update([
                    'video_id' => $videoId,
                    'status' => 'authorized',
                    'updated_at' => time(),
                ]);
        } catch (Throwable $exception) {
            try {
                $this->bunny->deleteVideo($videoId);
            } catch (Throwable $ignored) {
            }
            $this->cancelReservation($uploadId);
            throw new GatewayException('Upload reservation could not be finalized.', 500);
        }

        return [
            'uploadid' => $uploadId,
            'videoid' => $videoId,
            'libraryid' => (string) $this->bunny->libraryId(),
            'endpoint' => 'https://video.bunnycdn.com/tusupload',
            'signature' => $this->bunny->tusSignature($videoId, $expiresAt),
            'expiration' => $expiresAt,
            'quota' => $quota,
        ];
    }

    /**
     * Renew a short-lived TUS signature for the same reserved Bunny video.
     *
     * This is used by long-running/resumed uploads. It never creates another
     * video and therefore does not reserve quota twice.
     *
     * @param object $service Authenticated service row.
     * @param array $payload Request body.
     * @return array
     */
    public function refreshUploadAuthorization(object $service, array $payload): array
    {
        $this->requireBackendCapability($service, BackendRegistry::CAP_DIRECT_UPLOAD);
        $uploadId = strtolower(trim((string) ($payload['uploadid'] ?? '')));
        $videoId = strtolower(trim((string) ($payload['videoid'] ?? '')));
        $upload = $this->requireUpload((int) $service->service_id, $uploadId, $videoId);

        if (!in_array((string) $upload->status, ['authorized'], true)) {
            throw new GatewayException('This upload can no longer refresh its direct-upload authorization.', 409);
        }

        try {
            $this->bunny->getVideo($videoId);
        } catch (Throwable $exception) {
            throw new GatewayException('Elearning Stream could not verify the upload target.', 502);
        }

        $expiration = time() + Config::tusTtl();
        Capsule::table('mod_driveresource_uploads')
            ->where('service_id', (int) $service->service_id)
            ->where('upload_id', $uploadId)
            ->update([
                'expires_at' => $expiration,
                'updated_at' => time(),
            ]);

        return [
            'uploadid' => $uploadId,
            'videoid' => $videoId,
            'libraryid' => (string) $this->bunny->libraryId(),
            'endpoint' => 'https://video.bunnycdn.com/tusupload',
            'signature' => $this->bunny->tusSignature($videoId, $expiration),
            'expiration' => $expiration,
        ];
    }

    /**
     * Verify the uploaded provider asset and convert reservation into usage.
     *
     * @param object $service Authenticated service row.
     * @param array $payload Request body.
     * @return array
     */
    public function completeUpload(object $service, array $payload): array
    {
        $this->requireBackendCapability($service, BackendRegistry::CAP_DIRECT_UPLOAD);
        $uploadId = strtolower(trim((string) ($payload['uploadid'] ?? '')));
        $videoId = strtolower(trim((string) ($payload['videoid'] ?? '')));
        $fileSize = (int) ($payload['filesize'] ?? 0);

        $upload = $this->requireUpload((int) $service->service_id, $uploadId, $videoId);
        if ($fileSize !== (int) $upload->source_size) {
            throw new GatewayException('Uploaded size does not match the quota reservation.', 409);
        }

        try {
            $video = $this->bunny->getVideo($videoId);
        } catch (Throwable $exception) {
            throw new GatewayException('Elearning Stream could not verify the uploaded video.', 502);
        }

        $providerBytes = max(0, (int) ($video['storageSize'] ?? 0));
        $accountedBytes = $providerBytes > 0 ? $providerBytes : (int) $upload->source_size;
        $providerStatus = (int) ($video['status'] ?? 0);
        $encodeProgress = max(0, (int) ($video['encodeProgress'] ?? 0));
        $availableResolutions = trim((string) ($video['availableResolutions'] ?? ''));
        $status = ($providerStatus === 4 || ($encodeProgress >= 100 && $availableResolutions !== ''))
            ? 'ready'
            : 'processing';
        $now = time();

        Capsule::connection()->transaction(function () use (
            $service,
            $uploadId,
            $accountedBytes,
            $status,
            $now
        ): void {
            $upload = Capsule::table('mod_driveresource_uploads')
                ->where('upload_id', $uploadId)
                ->where('service_id', (int) $service->service_id)
                ->lockForUpdate()
                ->first();
            if (!$upload) {
                throw new GatewayException('Upload reservation was not found.', 404);
            }

            if (in_array((string) $upload->status, ['processing', 'ready', 'bound'], true)) {
                return;
            }

            $serviceRow = Capsule::table('mod_driveresource_services')
                ->where('service_id', (int) $service->service_id)
                ->lockForUpdate()
                ->first();

            $reserved = max(0, (int) $serviceRow->reserved_bytes - (int) $upload->source_size);
            $used = max(0, (int) $serviceRow->used_bytes + $accountedBytes);

            Capsule::table('mod_driveresource_services')
                ->where('service_id', (int) $service->service_id)
                ->update([
                    'reserved_bytes' => $reserved,
                    'used_bytes' => $used,
                    'updated_at' => $now,
                ]);

            Capsule::table('mod_driveresource_uploads')
                ->where('upload_id', $uploadId)
                ->update([
                    'accounted_bytes' => $accountedBytes,
                    'status' => $status,
                    'completed_at' => $now,
                    'delete_after' => $now + Config::unboundGraceSeconds(),
                    'updated_at' => $now,
                ]);
        });

        return ['status' => $status];
    }

    /**
     * Register an existing provider video for this WHMCS service.
     *
     * The browser supplies only a video GUID parsed by Moodle. This method
     * verifies the asset against the configured provider library, enforces
     * single-service ownership, accounts provider storage and returns a normal
     * upload reference so the existing bind lifecycle can be reused.
     *
     * @param object $service Authenticated service row.
     * @param array $payload Request body.
     * @return array
     */
    public function importAsset(object $service, array $payload): array
    {
        $this->requireBackendCapability($service, BackendRegistry::CAP_MANAGED_VIDEO);
        $videoId = strtolower(trim((string) ($payload['videoid'] ?? '')));
        $courseId = max(0, (int) ($payload['courseid'] ?? 0));

        if (!preg_match('/^[a-f0-9-]{32,64}$/i', $videoId)) {
            throw new GatewayException('Invalid Elearning Stream video identifier.', 422);
        }

        $otherOwner = Capsule::table('mod_driveresource_uploads')
            ->where('video_id', $videoId)
            ->where('service_id', '<>', (int) $service->service_id)
            ->whereIn('status', ['processing', 'ready', 'bound'])
            ->first();
        if ($otherOwner) {
            throw new GatewayException('This Elearning Stream video is already assigned to another service.', 403);
        }

        try {
            $video = $this->bunny->getVideo($videoId);
        } catch (Throwable $exception) {
            throw new GatewayException('Elearning Stream could not verify this video in the configured library.', 404);
        }

        $providerBytes = max(0, (int) ($video['storageSize'] ?? 0));
        $providerStatus = (int) ($video['status'] ?? 0);
        $encodeProgress = max(0, (int) ($video['encodeProgress'] ?? 0));
        $availableResolutions = trim((string) ($video['availableResolutions'] ?? ''));
        $status = ($providerStatus === 4 || ($encodeProgress >= 100 && $availableResolutions !== ''))
            ? 'ready'
            : 'processing';
        $title = trim((string) ($video['title'] ?? ''));
        $filename = mb_substr($title !== '' ? $title : 'Elearning Stream video', 0, 255);
        $now = time();

        return Capsule::connection()->transaction(function () use (
            $service,
            $videoId,
            $courseId,
            $providerBytes,
            $status,
            $filename,
            $now
        ): array {
            $locked = Capsule::table('mod_driveresource_services')
                ->where('service_id', (int) $service->service_id)
                ->lockForUpdate()
                ->first();

            if (!$locked || (string) $locked->status !== 'active') {
                throw new GatewayException('Drive Resource service is not active.', 403);
            }

            $existing = Capsule::table('mod_driveresource_uploads')
                ->where('service_id', (int) $service->service_id)
                ->where('video_id', $videoId)
                ->whereIn('status', ['processing', 'ready', 'bound'])
                ->orderBy('created_at', 'asc')
                ->first();

            if ($existing) {
                return [
                    'uploadid' => (string) $existing->upload_id,
                    'videoid' => $videoId,
                    'filesize' => max(0, (int) $existing->accounted_bytes),
                    'status' => (string) $existing->status === 'bound'
                        ? 'ready'
                        : (string) $existing->status,
                    'quota' => [
                        'includedbytes' => (int) $locked->quota_bytes,
                        'usedbytes' => (int) $locked->used_bytes,
                        'reservedbytes' => (int) $locked->reserved_bytes,
                        'projectedbytes' => (int) $locked->used_bytes + (int) $locked->reserved_bytes,
                        'overagebytes' => max(
                            0,
                            (int) $locked->used_bytes + (int) $locked->reserved_bytes - (int) $locked->quota_bytes
                        ),
                        'overageallowed' => (bool) $locked->overage_allowed,
                    ],
                ];
            }

            $projected = (int) $locked->used_bytes + (int) $locked->reserved_bytes + $providerBytes;
            $overage = max(0, $projected - (int) $locked->quota_bytes);
            if ($overage > 0 && !(bool) $locked->overage_allowed) {
                throw new GatewayException(
                    'The storage quota has been reached and this plan does not allow overage.',
                    409
                );
            }

            $uploadId = bin2hex(random_bytes(16));
            Capsule::table('mod_driveresource_uploads')->insert([
                'upload_id' => $uploadId,
                'service_id' => (int) $locked->service_id,
                'video_id' => $videoId,
                'filename' => $filename,
                'source_size' => $providerBytes,
                'accounted_bytes' => $providerBytes,
                'status' => $status,
                'course_id' => $courseId,
                'bound_instance_id' => 0,
                'expires_at' => $now + Config::unboundGraceSeconds(),
                'delete_after' => $now + Config::unboundGraceSeconds(),
                'created_at' => $now,
                'updated_at' => $now,
                'completed_at' => $now,
            ]);

            Capsule::table('mod_driveresource_services')
                ->where('service_id', (int) $locked->service_id)
                ->update([
                    'used_bytes' => (int) $locked->used_bytes + $providerBytes,
                    'updated_at' => $now,
                ]);

            return [
                'uploadid' => $uploadId,
                'videoid' => $videoId,
                'filesize' => $providerBytes,
                'status' => $status,
                'quota' => [
                    'includedbytes' => (int) $locked->quota_bytes,
                    'usedbytes' => (int) $locked->used_bytes + $providerBytes,
                    'reservedbytes' => (int) $locked->reserved_bytes,
                    'projectedbytes' => $projected,
                    'overagebytes' => $overage,
                    'overageallowed' => (bool) $locked->overage_allowed,
                ],
            ];
        });
    }

    /**
     * Authorize short-lived Elearning Stream playback for Moodle proxying.
     *
     * Provider URLs never go to the learner. This endpoint is authenticated
     * with the service-scoped WHMCS channel and only returns playback for an
     * asset already accounted to the requesting service.
     *
     * @param object $service Authenticated service row.
     * @param array $payload Request body.
     * @return array
     */
    public function authorizePlayback(object $service, array $payload): array
    {
        $this->requireBackendCapability($service, BackendRegistry::CAP_PROTECTED_PLAYBACK);
        $videoId = strtolower(trim((string) ($payload['videoid'] ?? '')));
        if (!preg_match('/^[a-f0-9-]{32,64}$/i', $videoId)) {
            throw new GatewayException('Invalid Elearning Stream video identifier.', 422);
        }

        if ((string) ($service->status ?? '') !== 'active') {
            throw new GatewayException('Drive Resource service is not active.', 403);
        }

        $owned = Capsule::table('mod_driveresource_uploads')
            ->where('service_id', (int) $service->service_id)
            ->where('video_id', $videoId)
            ->whereIn('status', ['processing', 'ready', 'bound'])
            ->first();
        if (!$owned) {
            throw new GatewayException(
                'This Elearning Stream video does not belong to the requesting service.',
                403
            );
        }

        try {
            $playback = $this->bunny->playbackUrl($videoId);
        } catch (Throwable $exception) {
            throw new GatewayException(
                'Elearning Stream playback is not ready for this video.',
                409
            );
        }

        return [
            'videoid' => $videoId,
            'url' => (string) $playback['url'],
            'expires' => (int) $playback['expires'],
            'resolution' => (int) $playback['resolution'],
        ];
    }

    /**
     * Bind a completed upload to a Moodle activity.
     *
     * @param object $service Service row.
     * @param string $siteUrl Authenticated Moodle site.
     * @param array $payload Request body.
     * @return array
     */
    public function bindAsset(object $service, string $siteUrl, array $payload): array
    {
        $this->requireBackendCapability($service, BackendRegistry::CAP_MANAGED_VIDEO);
        $uploadId = strtolower(trim((string) ($payload['uploadid'] ?? '')));
        $videoId = strtolower(trim((string) ($payload['videoid'] ?? '')));
        $instanceId = (int) ($payload['instanceid'] ?? 0);
        $courseId = (int) ($payload['courseid'] ?? 0);

        $upload = $this->requireUpload((int) $service->service_id, $uploadId, $videoId);

        // The browser may have finished the TUS transfer while the Moodle ->
        // WHMCS completion callback was interrupted. Recover server-side by
        // verifying the provider object and finalising the reservation once.
        if ((string) $upload->status === 'authorized') {
            $this->completeUpload($service, [
                'uploadid' => $uploadId,
                'videoid' => $videoId,
                'filesize' => (int) $upload->source_size,
            ]);
            $upload = $this->requireUpload((int) $service->service_id, $uploadId, $videoId);
        }

        if ($instanceId <= 0 || !in_array((string) $upload->status, ['processing', 'ready', 'bound'], true)) {
            throw new GatewayException('Video is not ready to be bound to a Moodle activity.', 409);
        }

        $this->upsertReference((int) $service->service_id, $siteUrl, $videoId, $instanceId, $courseId);
        Capsule::table('mod_driveresource_uploads')
            ->where('upload_id', $uploadId)
            ->update([
                'bound_instance_id' => $instanceId,
                'status' => ((string) $upload->status === 'ready') ? 'ready' : 'bound',
                'delete_after' => null,
                'updated_at' => time(),
            ]);

        return ['status' => 'bound'];
    }

    /**
     * Reconcile a restored Moodle reference without trusting the backup alone.
     *
     * @param object $service Service row.
     * @param string $siteUrl Authenticated Moodle site.
     * @param array $payload Request body.
     * @return array
     */
    public function reconcileAsset(object $service, string $siteUrl, array $payload): array
    {
        $this->requireBackendCapability($service, BackendRegistry::CAP_MANAGED_VIDEO);
        $videoId = strtolower(trim((string) ($payload['videoid'] ?? '')));
        $instanceId = (int) ($payload['instanceid'] ?? 0);
        $courseId = (int) ($payload['courseid'] ?? 0);

        if ($instanceId <= 0 || !preg_match('/^[a-f0-9-]{32,64}$/i', $videoId)) {
            throw new GatewayException('Invalid restored asset reference.', 422);
        }

        $owned = Capsule::table('mod_driveresource_uploads')
            ->where('service_id', (int) $service->service_id)
            ->where('video_id', $videoId)
            ->whereIn('status', ['processing', 'ready', 'bound'])
            ->first();
        if (!$owned) {
            throw new GatewayException('The restored video does not belong to this WHMCS service.', 403);
        }

        try {
            $this->bunny->getVideo($videoId);
        } catch (Throwable $exception) {
            throw new GatewayException('The restored video no longer exists in Elearning Stream.', 404);
        }

        $this->upsertReference((int) $service->service_id, $siteUrl, $videoId, $instanceId, $courseId);
        Capsule::table('mod_driveresource_uploads')
            ->where('service_id', (int) $service->service_id)
            ->where('video_id', $videoId)
            ->update(['delete_after' => null, 'updated_at' => time()]);

        return ['status' => 'bound'];
    }

    /**
     * Release one Moodle reference. Physical deletion remains deferred.
     *
     * @param object $service Service row.
     * @param string $siteUrl Authenticated Moodle site.
     * @param array $payload Request body.
     * @return array
     */
    public function releaseAsset(object $service, string $siteUrl, array $payload): array
    {
        $this->requireBackendCapability($service, BackendRegistry::CAP_MANAGED_VIDEO);
        $videoId = strtolower(trim((string) ($payload['videoid'] ?? '')));
        $instanceId = (int) ($payload['instanceid'] ?? 0);
        if ($instanceId <= 0 || !preg_match('/^[a-f0-9-]{32,64}$/i', $videoId)) {
            throw new GatewayException('Invalid asset release request.', 422);
        }

        $siteHash = hash('sha256', $siteUrl);
        Capsule::table('mod_driveresource_asset_refs')
            ->where('service_id', (int) $service->service_id)
            ->where('site_hash', $siteHash)
            ->where('instance_id', $instanceId)
            ->where('video_id', $videoId)
            ->update(['active' => false, 'updated_at' => time()]);

        $remaining = Capsule::table('mod_driveresource_asset_refs')
            ->where('service_id', (int) $service->service_id)
            ->where('video_id', $videoId)
            ->where('active', true)
            ->count();

        if ($remaining === 0) {
            Capsule::table('mod_driveresource_uploads')
                ->where('service_id', (int) $service->service_id)
                ->where('video_id', $videoId)
                ->update([
                    'delete_after' => time() + ((int) $service->retention_days * 86400),
                    'updated_at' => time(),
                ]);
        }

        return ['status' => 'released', 'remainingrefs' => (int) $remaining];
    }

    /**
     * Remove an uncompleted quota reservation.
     *
     * @param string $uploadId Upload reservation.
     * @return void
     */
    /**
     * Enforce that the tenant backend supports the requested gateway action.
     *
     * Current endpoints implement the managed-video contract. Future
     * S3-compatible object-storage endpoints will advertise/use different
     * capabilities and cannot fall through to Elearning Stream operations.
     *
     * @param object $service Provisioned service row.
     * @param string $capability BackendRegistry capability.
     * @return void
     */
    private function requireBackendCapability(object $service, string $capability): void
    {
        try {
            BackendRegistry::requireServiceCapability($service, $capability);
        } catch (\RuntimeException $exception) {
            throw new GatewayException(
                'The storage backend assigned to this service does not support this operation.',
                409
            );
        }
    }

    private function cancelReservation(string $uploadId): void
    {
        Capsule::connection()->transaction(function () use ($uploadId): void {
            $upload = Capsule::table('mod_driveresource_uploads')
                ->where('upload_id', $uploadId)
                ->lockForUpdate()
                ->first();
            if (!$upload || (string) $upload->status !== 'reserved') {
                return;
            }

            $service = Capsule::table('mod_driveresource_services')
                ->where('service_id', (int) $upload->service_id)
                ->lockForUpdate()
                ->first();
            if ($service) {
                Capsule::table('mod_driveresource_services')
                    ->where('service_id', (int) $upload->service_id)
                    ->update([
                        'reserved_bytes' => max(0, (int) $service->reserved_bytes - (int) $upload->source_size),
                        'updated_at' => time(),
                    ]);
            }

            Capsule::table('mod_driveresource_uploads')
                ->where('upload_id', $uploadId)
                ->update(['status' => 'failed', 'updated_at' => time()]);
        });
    }

    /**
     * Resolve one upload owned by this service.
     *
     * @param int $serviceId Service id.
     * @param string $uploadId Upload id.
     * @param string $videoId Video id.
     * @return object
     */
    private function requireUpload(int $serviceId, string $uploadId, string $videoId): object
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)
            || !preg_match('/^[a-f0-9-]{32,64}$/i', $videoId)) {
            throw new GatewayException('Invalid upload identifier.', 422);
        }

        $upload = Capsule::table('mod_driveresource_uploads')
            ->where('service_id', $serviceId)
            ->where('upload_id', $uploadId)
            ->where('video_id', $videoId)
            ->first();
        if (!$upload) {
            throw new GatewayException('Upload reservation was not found.', 404);
        }

        return $upload;
    }

    /**
     * Insert/update one active asset reference.
     *
     * @param int $serviceId Service id.
     * @param string $siteUrl Site URL.
     * @param string $videoId Video id.
     * @param int $instanceId Moodle instance id.
     * @param int $courseId Moodle course id.
     * @return void
     */
    private function upsertReference(
        int $serviceId,
        string $siteUrl,
        string $videoId,
        int $instanceId,
        int $courseId
    ): void {
        $siteHash = hash('sha256', $siteUrl);
        $now = time();
        $existing = Capsule::table('mod_driveresource_asset_refs')
            ->where('service_id', $serviceId)
            ->where('site_hash', $siteHash)
            ->where('instance_id', $instanceId)
            ->first();

        $values = [
            'video_id' => $videoId,
            'site_url' => $siteUrl,
            'course_id' => max(0, $courseId),
            'active' => true,
            'updated_at' => $now,
        ];

        if ($existing) {
            Capsule::table('mod_driveresource_asset_refs')
                ->where('id', (int) $existing->id)
                ->update($values);
            return;
        }

        Capsule::table('mod_driveresource_asset_refs')->insert($values + [
            'service_id' => $serviceId,
            'site_hash' => $siteHash,
            'instance_id' => $instanceId,
            'created_at' => $now,
        ]);
    }
}
