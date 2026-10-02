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

namespace mod_videoplayer\local\stream;

use mod_videoplayer\local\resource_compatibility;
use mod_videoplayer\local\http_range_proxy;
use mod_videoplayer\local\protected_stream;
use mod_videoplayer\local\resource\resource_descriptor;
use mod_videoplayer\local\transfer_meter;
use mod_videoplayer\local\whmcs_gateway_client;

/**
 * Delivers authorised Elearning Stream bytes from approved managed sources.
 *
 * The service does not perform authentication itself. Callers must construct it
 * only after Moodle login and capability checks have succeeded.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class protected_resource_service {
    /**
     * Stream one protected resource and terminate the request.
     *
     * @param resource_descriptor $resource
     * @param \stdClass $instance
     * @param string $streammode Video stream mode: auto, transcoded or source.
     * @param bool $forcerefresh Bypass the short-lived resolved stream cache.
     * @return never
     */
    public function send(
        resource_descriptor $resource,
        \stdClass $instance,
        string $streammode = 'auto',
        bool $forcerefresh = false
    ): never {
        if (!$resource->is_available()) {
            throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
        }

        if ($resource->source() === resource_compatibility::SOURCE_LOCALPDF) {
            $file = $resource->local_file();
            if (!$file) {
                throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
            }
            protected_stream::send_stored_pdf($file, $resource->filename());
        }

        if ($resource->is_bunny_stream()) {
            $videoid = $resource->provider_asset_id();
            if ($videoid === null) {
                throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
            }

            try {
                $url = (new whmcs_gateway_client())->playback_url(
                    $videoid,
                    (int)$instance->id,
                    $forcerefresh
                );
            } catch (\Throwable $exception) {
                debugging(
                    'Elearning Stream playback authorization failed: '
                        . $exception->getMessage(),
                    DEBUG_DEVELOPER
                );
                $this->send_stream_unavailable();
            }

            http_range_proxy::proxy(
                $url,
                $resource->filename(),
                'video/mp4',
                'ELEARNING_STREAM',
                static function (int $bytes) use ($videoid): void {
                    transfer_meter::record($videoid, $bytes);
                }
            );
        }

        // Historical Google-backed records intentionally fail closed.
        // The production runtime never resolves or proxies Google URLs.
        throw new \moodle_exception('unsupportedprotectedresource', 'mod_videoplayer');
    }

    /**
     * Return a small 502 response so the HTML5 player can immediately try the
     * protected source-file fallback.
     *
     * @return never
     */
    private function send_stream_unavailable(): never {
        http_response_code(502);
        header('Cache-Control: no-store, no-cache, must-revalidate, no-transform');
        header('X-Content-Type-Options: nosniff');
        die;
    }
}
