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

namespace mod_videoplayer\local;

/**
 * Asynchronous browser-compatible video normalization service.
 *
 * Compatible H.264/AAC sources remain on the direct protected proxy path.
 * Unsupported sources are transcoded to H.264/AAC MP4 in Moodle's private
 * cache and are subsequently served by protected.php with byte ranges.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class video_normalizer {
    /** @var int Default normalized video cache TTL: 30 days. */
    private const DEFAULT_CACHE_TTL = 2592000;

    /** @var int Failed attempt retry delay: 30 minutes. */
    private const FAILED_RETRY_DELAY = 1800;

    /** @var string Ready normalized file suffix. */
    private const NORMALIZED_SUFFIX = '.mp4';

    /** @var string Direct-play marker suffix. */
    private const PASSTHROUGH_SUFFIX = '.passthrough';

    /** @var string Failed marker suffix. */
    private const FAILED_SUFFIX = '.failed';

    /**
     * Whether normalization is administratively enabled and executable.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return (string) get_config('mod_videoplayer', 'videonormalizationenabled') !== '0'
            && self::ffmpeg_path() !== null
            && self::ffprobe_path() !== null
            && function_exists('proc_open');
    }

    /**
     * Return configured FFmpeg executable when valid.
     *
     * @return string|null
     */
    public static function ffmpeg_path(): ?string {
        $path = trim((string) get_config('mod_videoplayer', 'ffmpegpath'));
        return $path !== '' && is_file($path) && is_executable($path) ? $path : null;
    }

    /**
     * Return FFprobe beside FFmpeg when valid.
     *
     * @return string|null
     */
    public static function ffprobe_path(): ?string {
        $ffmpeg = self::ffmpeg_path();
        if ($ffmpeg === null) {
            return null;
        }

        $candidate = dirname($ffmpeg) . DIRECTORY_SEPARATOR . 'ffprobe';
        return is_file($candidate) && is_executable($candidate) ? $candidate : null;
    }

    /**
     * Return normalized video cache TTL.
     *
     * @return int
     */
    public static function cache_ttl(): int {
        $ttl = (int) get_config('mod_videoplayer', 'videocachettl');
        return $ttl > 0 ? $ttl : self::DEFAULT_CACHE_TTL;
    }

    /**
     * Return private video cache directory.
     *
     * @return string
     */
    public static function cache_dir(): string {
        global $CFG;

        $path = $CFG->cachedir . '/mod_videoplayer/video';
        if (!is_dir($path)) {
            make_writable_directory($path);
        }
        return $path;
    }

    /**
     * Stable cache key for one Drive activity source.
     *
     * @param object $record Activity record.
     * @return string|null
     */
    public static function cache_key_for(object $record): ?string {
        $fileid = drive::extract_file_id((string) ($record->videourl ?? ''));
        if ($fileid === null) {
            return null;
        }

        $resourcekey = drive::extract_resource_key((string) ($record->videourl ?? '')) ?? '';
        return hash('sha256', $fileid . ':video:' . $resourcekey);
    }

    /**
     * Get a fresh normalized file when available.
     *
     * @param object $record Activity record.
     * @return string|null
     */
    public static function normalized_file_for(object $record): ?string {
        $key = self::cache_key_for($record);
        if ($key === null) {
            return null;
        }

        $path = self::cache_dir() . '/' . $key . self::NORMALIZED_SUFFIX;
        $modified = is_file($path) ? filemtime($path) : false;
        if (
            is_readable($path) &&
            $modified !== false &&
            $modified + self::cache_ttl() > time() &&
            filesize($path) > 0
        ) {
            return $path;
        }

        return null;
    }

    /**
     * Whether this source was verified as direct-browser compatible.
     *
     * @param object $record Activity record.
     * @return bool
     */
    public static function is_passthrough(object $record): bool {
        $path = self::marker_path($record, self::PASSTHROUGH_SUFFIX);
        if ($path === null || !is_file($path)) {
            return false;
        }

        $modified = filemtime($path);
        return $modified !== false && $modified + self::cache_ttl() > time();
    }

    /**
     * Queue one deduplicated normalization task when required.
     *
     * @param object $record Activity record.
     * @return bool True when a task was queued.
     */
    public static function queue_if_needed(object $record): bool {
        if (!self::should_process($record) || !self::is_available()) {
            return false;
        }
        if (self::normalized_file_for($record) !== null || self::is_passthrough($record)) {
            return false;
        }

        $failed = self::marker_path($record, self::FAILED_SUFFIX);
        if ($failed !== null && is_file($failed)) {
            $modified = filemtime($failed);
            if ($modified !== false && $modified + self::FAILED_RETRY_DELAY > time()) {
                return false;
            }
        }

        $task = new \mod_videoplayer\task\normalize_video();
        $task->set_component('mod_videoplayer');
        $task->set_custom_data(['instanceid' => (int) $record->id]);
        \core\task\manager::queue_adhoc_task($task, true);
        return true;
    }

    /**
     * Download, inspect and normalize one activity.
     *
     * @param object $record Activity record.
     * @return bool
     */
    public static function normalize(object $record): bool {
        if (!self::should_process($record) || !self::is_available()) {
            return false;
        }

        $key = self::cache_key_for($record);
        $ffmpeg = self::ffmpeg_path();
        $ffprobe = self::ffprobe_path();
        if ($key === null || $ffmpeg === null || $ffprobe === null) {
            return false;
        }

        $cachedir = self::cache_dir();
        $lockpath = $cachedir . '/' . $key . '.lock';
        $lock = fopen($lockpath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return false;
        }

        $source = $cachedir . '/' . $key . '.source.' . getmypid() . '.tmp';
        $output = $cachedir . '/' . $key . '.output.' . getmypid() . '.mp4';
        $final = $cachedir . '/' . $key . self::NORMALIZED_SUFFIX;

        try {
            clearstatcache(true, $final);
            if (self::normalized_file_for($record) !== null || self::is_passthrough($record)) {
                return true;
            }

            self::delete_if_file($source);
            self::delete_if_file($output);

            $fileid = drive::extract_file_id((string) $record->videourl);
            if ($fileid === null) {
                self::mark_failed($record, 'invalid_file_id');
                return false;
            }

            $url = drive::protected_content_url((string) $record->videourl, $fileid, drive::TYPE_VIDEO);
            if ($url === null) {
                self::mark_failed($record, 'unresolved_source');
                return false;
            }

            $download = drive_downloader::download($url, $source);
            if (!$download['ok'] || !is_readable($source) || filesize($source) <= 0) {
                self::mark_failed($record, 'download_failed');
                return false;
            }

            $probe = self::probe_file($ffprobe, $source);
            if ($probe !== null && self::probe_is_web_compatible($probe)) {
                self::mark_passthrough($record);
                self::clear_failed($record);
                return true;
            }

            $args = [
                $ffmpeg,
                '-hide_banner',
                '-loglevel',
                'error',
                '-nostdin',
                '-y',
                '-i',
                $source,
                '-map',
                '0:v:0',
                '-map',
                '0:a:0?',
                '-c:v',
                'libx264',
                '-preset',
                'veryfast',
                '-crf',
                '23',
                '-pix_fmt',
                'yuv420p',
                '-c:a',
                'aac',
                '-b:a',
                '128k',
                '-movflags',
                '+faststart',
                $output,
            ];

            if (self::run_process($args) !== 0 || !is_readable($output) || filesize($output) <= 0) {
                self::mark_failed($record, 'ffmpeg_failed');
                return false;
            }

            $outputprobe = self::probe_file($ffprobe, $output);
            if ($outputprobe === null || !self::probe_is_web_compatible($outputprobe)) {
                self::mark_failed($record, 'output_probe_failed');
                return false;
            }

            if (!@rename($output, $final)) {
                self::mark_failed($record, 'atomic_rename_failed');
                return false;
            }

            self::clear_marker($record, self::PASSTHROUGH_SUFFIX);
            self::clear_failed($record);
            return true;
        } finally {
            self::delete_if_file($source);
            self::delete_if_file($output);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Determine whether FFprobe output is safe for broad HTML5 playback.
     *
     * @param array $probe Decoded FFprobe JSON.
     * @return bool
     */
    public static function probe_is_web_compatible(array $probe): bool {
        $streams = $probe['streams'] ?? null;
        $formatname = strtolower((string) ($probe['format']['format_name'] ?? ''));
        if (!is_array($streams) || $formatname === '') {
            return false;
        }

        // FFprobe reports ISO BMFF variants as a comma-separated family such
        // as "mov,mp4,m4a,3gp,3g2,mj2". Require that family so H.264/AAC
        // inside Matroska, MPEG-TS or another less portable container is
        // normalized rather than incorrectly marked for browser passthrough.
        $formats = array_filter(array_map('trim', explode(',', $formatname)));
        if (!in_array('mp4', $formats, true) && !in_array('mov', $formats, true)) {
            return false;
        }

        $video = null;
        $audio = null;
        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            if (($stream['codec_type'] ?? '') === 'video' && $video === null) {
                $video = $stream;
            } else if (($stream['codec_type'] ?? '') === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        if (!is_array($video) || strtolower((string) ($video['codec_name'] ?? '')) !== 'h264') {
            return false;
        }

        $pixfmt = strtolower((string) ($video['pix_fmt'] ?? ''));
        if ($pixfmt !== 'yuv420p') {
            return false;
        }

        return $audio === null || strtolower((string) ($audio['codec_name'] ?? '')) === 'aac';
    }

    /**
     * Delete cached artifacts for one activity source.
     *
     * @param object $record Activity record.
     * @return void
     */
    public static function purge(object $record): void {
        $key = self::cache_key_for($record);
        if ($key === null) {
            return;
        }

        $suffixes = [
            self::NORMALIZED_SUFFIX,
            self::PASSTHROUGH_SUFFIX,
            self::FAILED_SUFFIX,
            '.lock',
        ];
        foreach ($suffixes as $suffix) {
            self::delete_if_file(self::cache_dir() . '/' . $key . $suffix);
        }
    }

    /**
     * Remove expired normalized files and stale work files.
     *
     * @return void
     */
    public static function cleanup(): void {
        $directory = self::cache_dir();
        $files = glob($directory . '/*');
        if (!$files) {
            return;
        }

        $now = time();
        $ttl = self::cache_ttl();
        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }
            $modified = filemtime($path);
            if ($modified === false) {
                continue;
            }

            $name = basename($path);
            $isfinal = preg_match('/\.(mp4|passthrough|failed)$/', $name) === 1;
            $iswork = strpos($name, '.source.') !== false || strpos($name, '.output.') !== false;
            if (($isfinal && $modified + $ttl < $now) || ($iswork && $modified + 86400 < $now)) {
                self::delete_if_file($path);
            }
        }
    }

    /**
     * Check whether a record is a Google Drive video eligible for normalization.
     *
     * @param object $record Activity record.
     * @return bool
     */
    private static function should_process(object $record): bool {
        return !empty($record->id)
            && ($record->source ?? drive::SOURCE_GOOGLEDRIVE) === drive::SOURCE_GOOGLEDRIVE
            && drive::resolve_record_type($record) === drive::TYPE_VIDEO;
    }

    /**
     * Probe a local media file.
     *
     * @param string $ffprobe FFprobe executable.
     * @param string $path Local media path.
     * @return array|null
     */
    private static function probe_file(string $ffprobe, string $path): ?array {
        $stdout = tempnam(self::cache_dir(), 'probe-out-');
        $stderr = tempnam(self::cache_dir(), 'probe-err-');
        if ($stdout === false || $stderr === false) {
            return null;
        }

        try {
            $exit = self::run_process([
                $ffprobe,
                '-v',
                'error',
                '-show_entries',
                'format=format_name:stream=codec_type,codec_name,pix_fmt',
                '-of',
                'json',
                $path,
            ], $stdout, $stderr);

            if ($exit !== 0) {
                return null;
            }

            $json = file_get_contents($stdout);
            $decoded = is_string($json) ? json_decode($json, true) : null;
            return is_array($decoded) ? $decoded : null;
        } finally {
            self::delete_if_file($stdout);
            self::delete_if_file($stderr);
        }
    }

    /**
     * Execute a command without invoking a shell.
     *
     * @param array $command Argument vector, first element must be executable.
     * @param string|null $stdoutpath Optional stdout path.
     * @param string|null $stderrpath Optional stderr path.
     * @return int Process exit code.
     */
    private static function run_process(array $command, ?string $stdoutpath = null, ?string $stderrpath = null): int {
        $ownstdout = $stdoutpath === null;
        $ownstderr = $stderrpath === null;
        $stdoutpath = $stdoutpath ?? tempnam(self::cache_dir(), 'process-out-');
        $stderrpath = $stderrpath ?? tempnam(self::cache_dir(), 'process-err-');
        if ($stdoutpath === false || $stderrpath === false) {
            return 1;
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutpath, 'wb'],
            2 => ['file', $stderrpath, 'wb'],
        ];

        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            if ($ownstdout) {
                self::delete_if_file($stdoutpath);
            }
            if ($ownstderr) {
                self::delete_if_file($stderrpath);
            }
            return 1;
        }

        fclose($pipes[0]);
        $exit = proc_close($process);

        if ($ownstdout) {
            self::delete_if_file($stdoutpath);
        }
        if ($ownstderr) {
            self::delete_if_file($stderrpath);
        }

        return is_int($exit) ? $exit : 1;
    }

    /**
     * Mark a source as direct-browser compatible.
     *
     * @param object $record Activity record.
     * @return void
     */
    private static function mark_passthrough(object $record): void {
        self::write_marker($record, self::PASSTHROUGH_SUFFIX, 'compatible');
    }

    /**
     * Mark a failed normalization attempt.
     *
     * @param object $record Activity record.
     * @param string $reason Safe reason.
     * @return void
     */
    private static function mark_failed(object $record, string $reason): void {
        self::write_marker($record, self::FAILED_SUFFIX, preg_replace('/[^a-z0-9_\-]/i', '', $reason));
    }

    /**
     * Clear failed marker.
     *
     * @param object $record Activity record.
     * @return void
     */
    private static function clear_failed(object $record): void {
        self::clear_marker($record, self::FAILED_SUFFIX);
    }

    /**
     * Write one private marker atomically.
     *
     * @param object $record Activity record.
     * @param string $suffix Marker suffix.
     * @param string $content Marker value.
     * @return void
     */
    private static function write_marker(object $record, string $suffix, string $content): void {
        $path = self::marker_path($record, $suffix);
        if ($path === null) {
            return;
        }
        $tmp = $path . '.tmp.' . getmypid();
        file_put_contents($tmp, $content, LOCK_EX);
        @rename($tmp, $path);
        self::delete_if_file($tmp);
    }

    /**
     * Remove one marker.
     *
     * @param object $record Activity record.
     * @param string $suffix Marker suffix.
     * @return void
     */
    private static function clear_marker(object $record, string $suffix): void {
        $path = self::marker_path($record, $suffix);
        if ($path !== null) {
            self::delete_if_file($path);
        }
    }

    /**
     * Build marker path.
     *
     * @param object $record Activity record.
     * @param string $suffix Suffix.
     * @return string|null
     */
    private static function marker_path(object $record, string $suffix): ?string {
        $key = self::cache_key_for($record);
        return $key === null ? null : self::cache_dir() . '/' . $key . $suffix;
    }

    /**
     * Delete a regular file.
     *
     * @param string $path Path.
     * @return void
     */
    private static function delete_if_file(string $path): void {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
