<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use Throwable;
use WHMCS\Database\Capsule;

/**
 * Quota, reservation and provider-asset orchestration.
 */
final class GatewayService
{
    private ?BunnyClient $bunny = null;

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
            $videoId = $this->streamClient($service)->createVideo($title !== '' ? $title : $filename);
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
                $this->streamClient($service)->deleteVideo($videoId);
            } catch (Throwable $ignored) {
            }
            $this->cancelReservation($uploadId);
            throw new GatewayException('Upload reservation could not be finalized.', 500);
        }

        return [
            'uploadid' => $uploadId,
            'videoid' => $videoId,
            'libraryid' => (string) $this->streamClient($service)->libraryId(),
            'endpoint' => 'https://video.bunnycdn.com/tusupload',
            'signature' => $this->streamClient($service)->tusSignature($videoId, $expiresAt),
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
            $this->streamClient($service)->getVideo($videoId);
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
            'libraryid' => (string) $this->streamClient($service)->libraryId(),
            'endpoint' => 'https://video.bunnycdn.com/tusupload',
            'signature' => $this->streamClient($service)->tusSignature($videoId, $expiration),
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
            $video = $this->streamClient($service)->getVideo($videoId);
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
        $videoId = $this->resolveImportedVideoId($payload);
        $courseId = max(0, (int) ($payload['courseid'] ?? 0));

        $otherOwner = Capsule::table('mod_driveresource_uploads')
            ->where('video_id', $videoId)
            ->where('service_id', '<>', (int) $service->service_id)
            ->whereIn('status', ['processing', 'ready', 'bound'])
            ->first();
        if ($otherOwner) {
            throw new GatewayException('This Elearning Stream video is already assigned to another service.', 403);
        }

        try {
            $video = $this->streamClient($service)->getVideo($videoId);
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
            $playback = $this->streamClient($service)->playbackUrl($videoId);
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
            $this->streamClient($service)->getVideo($videoId);
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
     * Release one Moodle reference and apply the product deletion policy.
     *
     * A video is never deleted while another active Moodle reference exists.
     * retention_days=0 means immediate provider deletion after the final
     * reference disappears. A positive value keeps the existing grace-period
     * behaviour and lets daily maintenance perform the physical deletion.
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

        $serviceId = (int) $service->service_id;
        $siteHash = hash('sha256', $siteUrl);
        $retentionDays = max(0, min(365, (int) ($service->retention_days ?? 0)));
        $deleteNow = false;
        $uploadId = '';
        $previousStatus = '';
        $remaining = 0;
        $now = time();

        Capsule::connection()->transaction(function () use (
            $serviceId,
            $siteHash,
            $instanceId,
            $videoId,
            $retentionDays,
            $now,
            &$deleteNow,
            &$uploadId,
            &$previousStatus,
            &$remaining
        ): void {
            Capsule::table('mod_driveresource_asset_refs')
                ->where('service_id', $serviceId)
                ->where('site_hash', $siteHash)
                ->where('instance_id', $instanceId)
                ->where('video_id', $videoId)
                ->update(['active' => false, 'updated_at' => $now]);

            $remaining = (int) Capsule::table('mod_driveresource_asset_refs')
                ->where('service_id', $serviceId)
                ->where('video_id', $videoId)
                ->where('active', true)
                ->count();

            if ($remaining > 0) {
                return;
            }

            $upload = Capsule::table('mod_driveresource_uploads')
                ->where('service_id', $serviceId)
                ->where('video_id', $videoId)
                ->whereIn('status', ['processing', 'ready', 'bound'])
                ->lockForUpdate()
                ->first();

            if (!$upload) {
                return;
            }

            if ($retentionDays > 0) {
                Capsule::table('mod_driveresource_uploads')
                    ->where('upload_id', (string) $upload->upload_id)
                    ->update([
                        'delete_after' => $now + ($retentionDays * 86400),
                        'updated_at' => $now,
                    ]);
                return;
            }

            $deleteNow = true;
            $uploadId = (string) $upload->upload_id;
            $previousStatus = (string) $upload->status;

            // Lock the asset against a concurrent rebind while the provider
            // deletion is in flight.
            Capsule::table('mod_driveresource_uploads')
                ->where('upload_id', $uploadId)
                ->update([
                    'status' => 'deleting',
                    'delete_after' => null,
                    'updated_at' => $now,
                ]);
        });

        if (!$deleteNow) {
            return [
                'status' => $remaining > 0 ? 'released' : 'scheduled',
                'remainingrefs' => $remaining,
            ];
        }

        try {
            $this->streamClient($service)->deleteVideo($videoId);
        } catch (Throwable $exception) {
            Capsule::table('mod_driveresource_uploads')
                ->where('service_id', $serviceId)
                ->where('upload_id', $uploadId)
                ->update([
                    'status' => $previousStatus,
                    // Make DailyCronJob retry a failed provider deletion.
                    'delete_after' => time(),
                    'updated_at' => time(),
                ]);

            throw new GatewayException(
                'Elearning Stream could not delete the unreferenced video. Deletion was queued for retry.',
                502
            );
        }

        Capsule::connection()->transaction(function () use ($serviceId, $uploadId): void {
            Capsule::table('mod_driveresource_uploads')
                ->where('service_id', $serviceId)
                ->where('upload_id', $uploadId)
                ->update([
                    'status' => 'deleted',
                    'accounted_bytes' => 0,
                    'delete_after' => null,
                    'updated_at' => time(),
                ]);

            $used = (int) Capsule::table('mod_driveresource_uploads')
                ->where('service_id', $serviceId)
                ->whereIn('status', ['processing', 'ready', 'bound'])
                ->sum('accounted_bytes');

            Capsule::table('mod_driveresource_services')
                ->where('service_id', $serviceId)
                ->update([
                    'used_bytes' => max(0, $used),
                    'updated_at' => time(),
                ]);
        });

        return ['status' => 'deleted', 'remainingrefs' => 0];
    }

    /**
     * Remove an uncompleted quota reservation.
     *
     * @param string $uploadId Upload reservation.
     * @return void
     */
    /**
     * Record one idempotent Moodle transfer-usage batch.
     *
     * @param object $service Authenticated service row.
     * @param array $payload Request body.
     * @return array
     */
    public function recordTransfer(object $service, array $payload): array
    {
        $reportId = strtolower(trim((string) ($payload['reportid'] ?? '')));
        $period = trim((string) ($payload['period'] ?? ''));
        $bytes = max(0, (int) ($payload['bytes'] ?? 0));

        if (!preg_match('/^[a-f0-9]{64}$/', $reportId)) {
            throw new GatewayException('Invalid transfer report id.', 422);
        }
        if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $period)) {
            throw new GatewayException('Invalid transfer billing period.', 422);
        }
        if ($bytes <= 0 || $bytes > 1099511627776) {
            throw new GatewayException('Invalid transfer byte count.', 422);
        }

        $now = time();
        Capsule::connection()->transaction(function () use (
            $service,
            $reportId,
            $period,
            $bytes,
            $now
        ): void {
            $existing = Capsule::table('mod_driveresource_usage_reports')
                ->where('service_id', (int) $service->service_id)
                ->where('report_id', $reportId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return;
            }

            $serviceRow = Capsule::table('mod_driveresource_services')
                ->where('service_id', (int) $service->service_id)
                ->lockForUpdate()
                ->first();
            if (!$serviceRow || (string) $serviceRow->status === 'terminated') {
                throw new GatewayException('Drive Resource service is not available.', 403);
            }

            $currentPeriod = trim((string) ($serviceRow->transfer_period ?? ''));
            $currentBytes = max(0, (int) ($serviceRow->transfer_bytes ?? 0));
            $nextBytes = $currentPeriod === $period ? $currentBytes + $bytes : $bytes;

            Capsule::table('mod_driveresource_usage_reports')->insert([
                'service_id' => (int) $service->service_id,
                'report_id' => $reportId,
                'period_key' => $period,
                'bytes' => $bytes,
                'created_at' => $now,
            ]);

            Capsule::table('mod_driveresource_services')
                ->where('service_id', (int) $service->service_id)
                ->update([
                    'transfer_period' => $period,
                    'transfer_bytes' => $nextBytes,
                    'transfer_updated_at' => $now,
                    'updated_at' => $now,
                ]);
        });

        return [
            'status' => 'recorded',
            'period' => $period,
        ];
    }

    /**
     * Resolve an import payload to a provider video GUID.
     *
     * New clients send the pasted URL so WHMCS can enforce the centrally
     * configured public hostname aliases. Legacy clients may still submit a
     * bare GUID.
     *
     * @param array $payload Request payload.
     * @return string
     */
    private function resolveImportedVideoId(array $payload): string
    {
        $url = trim((string) ($payload['url'] ?? ''));
        if ($url !== '') {
            return $this->extractVideoIdFromPublicUrl($url);
        }

        $videoId = strtolower(trim((string) ($payload['videoid'] ?? '')));
        if (!preg_match('/^[a-f0-9-]{32,64}$/i', $videoId)) {
            throw new GatewayException('Invalid Elearning Stream video identifier.', 422);
        }

        return $videoId;
    }

    /**
     * Validate a customer-facing Elearning Stream URL without fetching it.
     *
     * @param string $url Pasted video URL.
     * @return string Provider video GUID.
     */
    private function extractVideoIdFromPublicUrl(string $url): string
    {
        if (strlen($url) > 2048) {
            throw new GatewayException('Elearning Stream video URL is too long.', 422);
        }

        $parts = parse_url($url);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new GatewayException('Invalid Elearning Stream video URL.', 422);
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (!Config::isAllowedPublicVideoHost($host)) {
            throw new GatewayException(
                'This Elearning Stream public hostname is not authorised by WHMCS.',
                422
            );
        }

        $segments = array_values(array_filter(
            explode('/', trim((string) ($parts['path'] ?? ''), '/')),
            static fn(string $segment): bool => $segment !== ''
        ));

        foreach (array_reverse($segments) as $segment) {
            $candidate = strtolower(rawurldecode($segment));
            if (preg_match('/^[a-f0-9-]{32,64}$/i', $candidate)) {
                return $candidate;
            }
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (['videoid', 'videoId', 'guid'] as $key) {
            $candidate = strtolower(trim((string) ($query[$key] ?? '')));
            if (preg_match('/^[a-f0-9-]{32,64}$/i', $candidate)) {
                return $candidate;
            }
        }

        throw new GatewayException(
            'The Elearning Stream URL does not contain a valid video identifier.',
            422
        );
    }

    /**
     * Resolve the Elearning Stream client only for services that need it.
     *
     * @param object $service Provisioned service row.
     * @return BunnyClient
     */
    private function streamClient(object $service): BunnyClient
    {
        $this->requireBackendCapability($service, BackendRegistry::CAP_MANAGED_VIDEO);
        if ($this->bunny === null) {
            $this->bunny = new BunnyClient();
        }

        return $this->bunny;
    }

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
