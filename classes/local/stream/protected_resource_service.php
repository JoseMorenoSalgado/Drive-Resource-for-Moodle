<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_videoplayer\local\stream;

use mod_videoplayer\local\drive;
use mod_videoplayer\local\drive_stream_resolver;
use mod_videoplayer\local\http_range_proxy;
use mod_videoplayer\local\protected_stream;
use mod_videoplayer\local\resource\resource_descriptor;
use mod_videoplayer\task\precache_pdf;

/**
 * Delivers authorised Drive Resource bytes to the browser.
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
     * @return never
     */
    public function send(resource_descriptor $resource, \stdClass $instance, string $streammode = 'auto'): never {
        if (!$resource->is_available()) {
            throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
        }

        if ($resource->source() === 'localpdf') {
            $file = $resource->local_file();
            if (!$file) {
                throw new \moodle_exception('protectedresourceunavailable', 'mod_videoplayer');
            }
            protected_stream::send_stored_pdf($file, $resource->filename());
        }

        $fileid = $resource->fileid();
        if (!$fileid) {
            throw new \moodle_exception('invaliddriveurl', 'mod_videoplayer');
        }

        $url = $this->resolve_upstream_url($resource, $instance, $streammode);
        if ($url === null) {
            if ($resource->is_video() && $streammode === 'transcoded') {
                $this->send_stream_unavailable();
            }
            throw new \moodle_exception('unsupportedprotectedresource', 'mod_videoplayer');
        }

        $cachestatus = 'BYPASS';
        if ($resource->is_pdf_like() && (string)get_config('mod_videoplayer', 'pdfcacheenabled') !== '0') {
            $cachefile = protected_stream::cache_file_for($fileid, $resource->type());
            if (protected_stream::is_fresh_pdf_cache($cachefile)) {
                protected_stream::send_file(
                    $cachefile,
                    $resource->filename(),
                    $resource->mimetype(),
                    protected_stream::cache_key($fileid, $resource->type()),
                    filemtime($cachefile) ?: time(),
                    'HIT'
                );
            }

            $cachestatus = $this->queue_pdf_cache((int)$instance->id) ? 'MISS_QUEUED' : 'MISS';
        }

        http_range_proxy::proxy($url, $resource->filename(), $resource->mimetype(), $cachestatus);
    }

    /**
     * Resolve one server-side upstream URL.
     *
     * @param resource_descriptor $resource
     * @param \stdClass $instance
     * @param string $streammode
     * @return string|null
     */
    private function resolve_upstream_url(
        resource_descriptor $resource,
        \stdClass $instance,
        string $streammode
    ): ?string {
        $fileid = $resource->fileid();
        if (!$fileid) {
            return null;
        }

        $streammode = in_array($streammode, ['auto', 'transcoded', 'source'], true) ? $streammode : 'auto';
        if ($resource->is_video() && $streammode !== 'source') {
            $resolved = drive_stream_resolver::resolve($fileid);
            if ($resolved !== null) {
                return $resolved;
            }
            if ($streammode === 'transcoded') {
                return null;
            }
        }

        return drive::protected_content_url($fileid, $resource->type());
    }

    /**
     * Queue asynchronous PDF cache warming.
     *
     * @param int $instanceid
     * @return bool
     */
    private function queue_pdf_cache(int $instanceid): bool {
        try {
            $task = new precache_pdf();
            $task->set_component('mod_videoplayer');
            $task->set_custom_data(['instanceid' => $instanceid]);
            \core\task\manager::queue_adhoc_task($task, true);
            return true;
        } catch (\Throwable $exception) {
            debugging('Drive Resource PDF cache task queue failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
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
