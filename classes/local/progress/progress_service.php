<?php
// This file is part of Moodle - http://moodle.org/.
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_videoplayer\local\progress;

use mod_videoplayer\local\drive;
use mod_videoplayer\local\gamification\reward_service;

/**
 * Handles Drive Resource progress persistence and completion integration.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_service {
    /** @var int Maximum PDF page identifiers accepted in one progress record. */
    private const MAX_VISITED_PAGES = 20000;

    /** @var int Maximum raw video ranges accepted before normalization. */
    private const MAX_WATCHED_RANGES = 5000;

    /** @var int Maximum JSON progress payload length. */
    private const MAX_JSON_LENGTH = 1048576;

    /**
     * Save progress and return the persisted state.
     *
     * PDF completion is derived from the union of pages actually reported as
     * observed. Video completion is derived from the union of watched playback
     * ranges. Client-provided percentages are therefore advisory only for
     * generic resource types.
     *
     * @param object $cm Course module information.
     * @param object $course Course record.
     * @param object $videoplayer Drive Resource activity record.
     * @param \context_module $context Module context.
     * @param int $userid User ID.
     * @param array $input Sanitised progress input.
     * @return array Persisted progress state.
     */
    public function save_progress(
        object $cm,
        object $course,
        object $videoplayer,
        \context_module $context,
        int $userid,
        array $input
    ): array {
        global $DB;

        $type = $this->resolve_resource_type($videoplayer);
        $ispdf = drive::is_pdf_type($type);
        $isvideo = $type === 'video';

        $inputpercentage = $this->clamp_percentage((float)($input['completionpercentage'] ?? 0));
        $inputlastpage = max(0, (int)($input['lastpage'] ?? 0));
        $inputtotalpages = max(0, (int)($input['totalpages'] ?? 0));
        $inputtimespent = max(0, (int)($input['timespent'] ?? 0));
        $inputprogress = max(0, (float)($input['progress'] ?? 0));
        $inputlastsecond = max(0, (float)($input['lastsecond'] ?? 0));
        $inputtotalseconds = max(0, (float)($input['totalseconds'] ?? 0));
        $inputvisitedpages = (string)($input['visitedpages'] ?? '');
        $inputwatchedranges = (string)($input['watchedranges'] ?? '');
        $clientcompleted = !empty($input['completed']);

        $conditions = [
            'videoplayerid' => $videoplayer->id,
            'userid' => $userid,
        ];
        $record = $DB->get_record('videoplayer_views', $conditions);
        $isnew = !$record;
        $wascompleted = $record ? !empty($record->completed) : false;
        $now = time();

        if (!$record) {
            $record = (object)[
                'videoplayerid' => $videoplayer->id,
                'userid' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
                'progress' => 0,
                'completed' => 0,
                'completionpercentage' => 0,
                'lastpage' => 0,
                'totalpages' => 0,
                'visitedpages' => null,
                'lastsecond' => 0,
                'totalseconds' => 0,
                'watchedranges' => null,
                'timespent' => 0,
                'points' => 0,
            ];
        }

        $completionpercentage = max((float)$record->completionpercentage, $inputpercentage);
        $progress = max((float)$record->progress, $inputprogress);
        $timespent = max((int)$record->timespent, $inputtimespent);
        $lastpage = (int)($record->lastpage ?? 0);
        $totalpages = max((int)($record->totalpages ?? 0), $inputtotalpages);
        $visitedpages = $this->decode_pages((string)($record->visitedpages ?? ''), $totalpages);
        $lastsecond = (float)($record->lastsecond ?? 0);
        $totalseconds = max((float)($record->totalseconds ?? 0), $inputtotalseconds);
        $watchedranges = $this->decode_ranges((string)($record->watchedranges ?? ''), $totalseconds);

        if ($ispdf) {
            $incomingpages = $this->decode_pages($inputvisitedpages, $totalpages);
            if ($inputlastpage > 0 && ($totalpages === 0 || $inputlastpage <= $totalpages)) {
                $incomingpages[] = $inputlastpage;
            }
            $visitedpages = $this->merge_pages($visitedpages, $incomingpages, $totalpages);
            if ($inputlastpage > 0) {
                $lastpage = $inputlastpage;
            }

            if ($totalpages > 0) {
                $exactpercentage = (count($visitedpages) / $totalpages) * 100;
                // Keep historical progress monotonic for upgraded records while
                // ensuring all new progress is derived from observed pages.
                $completionpercentage = max((float)$record->completionpercentage, $exactpercentage);
            }

            $progress = max($progress, $inputprogress);
            $record->visitedpages = $this->encode_json($visitedpages);
        } else if ($isvideo) {
            $incomingranges = $this->decode_ranges($inputwatchedranges, $totalseconds);
            $watchedranges = $this->merge_ranges($watchedranges, $incomingranges, $totalseconds);
            $watchedseconds = $this->ranges_duration($watchedranges);

            if ($totalseconds > 0) {
                $completionpercentage = max(
                    (float)$record->completionpercentage,
                    ($watchedseconds / $totalseconds) * 100
                );
            }

            $progress = max($progress, $watchedseconds);
            $timespent = max($timespent, (int)floor($watchedseconds));

            $hasvideostate = $inputwatchedranges !== '' || $inputtotalseconds > 0 || $inputlastsecond > 0;
            if ($hasvideostate) {
                $lastsecond = $inputlastsecond;
                if ($totalseconds > 0) {
                    $lastsecond = min($lastsecond, $totalseconds);
                }
            }

            $record->watchedranges = $this->encode_json($watchedranges);
        }

        $required = isset($videoplayer->completionpercentage) ? (int)$videoplayer->completionpercentage : 80;
        $completed = $wascompleted || $completionpercentage >= $required;
        if (!$ispdf && !$isvideo) {
            $completed = $completed || $clientcompleted;
        }

        $record->progress = $progress;
        $record->completionpercentage = $this->clamp_percentage($completionpercentage);
        $record->completed = $completed ? 1 : 0;
        $record->lastpage = $lastpage;
        $record->totalpages = $totalpages;
        $record->lastsecond = $lastsecond;
        $record->totalseconds = $totalseconds;
        $record->timespent = $timespent;
        $record->timemodified = $now;

        if ($isnew) {
            $record->id = $DB->insert_record('videoplayer_views', $record);
        } else {
            $DB->update_record('videoplayer_views', $record);
        }

        $rewarddata = [
            'rewards' => [],
            'totalpoints' => (int)($record->points ?? 0),
        ];
        if (!empty($videoplayer->enablegamification)) {
            $rewarddata = (new reward_service())->award_rewards($videoplayer, $record, $userid, $context);
            $record->points = $rewarddata['totalpoints'];
            $DB->set_field('videoplayer_views', 'points', $record->points, ['id' => $record->id]);
        }

        \mod_videoplayer\event\progress_updated::create([
            'objectid' => $record->id,
            'context' => $context,
            'userid' => $userid,
            'other' => [
                'videoplayerid' => $videoplayer->id,
                'completionpercentage' => (float)$record->completionpercentage,
                'lastpage' => (int)$record->lastpage,
                'totalpages' => (int)$record->totalpages,
            ],
        ])->trigger();

        if (!empty($record->completed) && !$wascompleted) {
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
            }

            \mod_videoplayer\event\resource_completed::create([
                'objectid' => $record->id,
                'context' => $context,
                'userid' => $userid,
                'other' => [
                    'videoplayerid' => $videoplayer->id,
                    'completionpercentage' => (float)$record->completionpercentage,
                ],
            ])->trigger();
        }

        return [
            'status' => true,
            'completed' => (bool)$record->completed,
            'progress' => (float)$record->progress,
            'completionpercentage' => (float)$record->completionpercentage,
            'lastpage' => (int)$record->lastpage,
            'totalpages' => (int)$record->totalpages,
            'visitedpages' => (string)($record->visitedpages ?? ''),
            'lastsecond' => (float)$record->lastsecond,
            'totalseconds' => (float)$record->totalseconds,
            'watchedranges' => (string)($record->watchedranges ?? ''),
            'timespent' => (int)$record->timespent,
            'points' => (int)($record->points ?? 0),
            'rewards' => $rewarddata['rewards'],
            'timemodified' => (int)$record->timemodified,
        ];
    }

    /**
     * Resolve the effective resource type for progress calculations.
     *
     * @param object $videoplayer Activity record.
     * @return string
     */
    private function resolve_resource_type(object $videoplayer): string {
        if (($videoplayer->source ?? 'googledrive') === 'localpdf') {
            return 'pdf';
        }

        $type = (string)($videoplayer->type ?? drive::TYPE_AUTO);
        if ($type === '' || $type === drive::TYPE_AUTO) {
            return drive::detect_type((string)($videoplayer->videourl ?? ''));
        }

        return clean_param($type, PARAM_ALPHANUMEXT);
    }

    /**
     * Decode and sanitize a JSON page array.
     *
     * @param string $json JSON payload.
     * @param int $totalpages Known total pages, or 0 when unknown.
     * @return array
     */
    private function decode_pages(string $json, int $totalpages): array {
        if ($json === '' || strlen($json) > self::MAX_JSON_LENGTH) {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $pages = [];
        foreach (array_slice($decoded, 0, self::MAX_VISITED_PAGES) as $page) {
            if (!is_numeric($page)) {
                continue;
            }
            $page = (int)$page;
            if ($page < 1 || ($totalpages > 0 && $page > $totalpages)) {
                continue;
            }
            $pages[$page] = $page;
        }

        ksort($pages, SORT_NUMERIC);
        return array_values($pages);
    }

    /**
     * Merge two page sets.
     *
     * @param array $existing Existing pages.
     * @param array $incoming Incoming pages.
     * @param int $totalpages Total pages.
     * @return array
     */
    private function merge_pages(array $existing, array $incoming, int $totalpages): array {
        return $this->decode_pages($this->encode_json(array_merge($existing, $incoming)), $totalpages);
    }

    /**
     * Decode and normalize watched video ranges.
     *
     * @param string $json JSON payload.
     * @param float $duration Known video duration.
     * @return array
     */
    private function decode_ranges(string $json, float $duration): array {
        if ($json === '' || strlen($json) > self::MAX_JSON_LENGTH) {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $ranges = [];
        foreach (array_slice($decoded, 0, self::MAX_WATCHED_RANGES) as $range) {
            if (!is_array($range) || count($range) < 2 || !is_numeric($range[0]) || !is_numeric($range[1])) {
                continue;
            }

            $start = max(0, (float)$range[0]);
            $end = max(0, (float)$range[1]);
            if (!is_finite($start) || !is_finite($end)) {
                continue;
            }
            if ($duration > 0) {
                $start = min($start, $duration);
                $end = min($end, $duration);
            }
            if ($end <= $start) {
                continue;
            }
            $ranges[] = [$start, $end];
        }

        return $this->merge_ranges([], $ranges, $duration);
    }

    /**
     * Merge overlapping/adjacent playback ranges.
     *
     * @param array $existing Existing ranges.
     * @param array $incoming Incoming ranges.
     * @param float $duration Known video duration.
     * @return array
     */
    private function merge_ranges(array $existing, array $incoming, float $duration): array {
        $ranges = array_merge($existing, $incoming);
        usort($ranges, static function (array $a, array $b): int {
            return $a[0] <=> $b[0];
        });

        $merged = [];
        foreach ($ranges as $range) {
            $start = max(0, (float)$range[0]);
            $end = max(0, (float)$range[1]);
            if ($duration > 0) {
                $start = min($start, $duration);
                $end = min($end, $duration);
            }
            if ($end <= $start) {
                continue;
            }

            $lastindex = count($merged) - 1;
            if ($lastindex >= 0 && $start <= $merged[$lastindex][1] + 0.5) {
                $merged[$lastindex][1] = max($merged[$lastindex][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return array_slice($merged, 0, self::MAX_WATCHED_RANGES);
    }

    /**
     * Calculate unique watched seconds represented by normalized ranges.
     *
     * @param array $ranges Normalized ranges.
     * @return float
     */
    private function ranges_duration(array $ranges): float {
        $seconds = 0.0;
        foreach ($ranges as $range) {
            $seconds += max(0, (float)$range[1] - (float)$range[0]);
        }
        return $seconds;
    }

    /**
     * Encode sanitized progress structures.
     *
     * @param array $value Value to encode.
     * @return string
     */
    private function encode_json(array $value): string {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '[]';
    }

    /**
     * Clamp a completion percentage.
     *
     * @param float $value Percentage.
     * @return float
     */
    private function clamp_percentage(float $value): float {
        return max(0, min(100, $value));
    }
}
