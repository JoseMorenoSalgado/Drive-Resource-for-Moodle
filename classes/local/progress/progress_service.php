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

namespace mod_videoplayer\local\progress;

/**
 * Persists learner progress, resume state and Moodle completion.
 *
 * Completion is derived only from supported runtime resources:
 * - managed video: union of media ranges actually reproduced;
 * - protected PDF: highest page reached over total pages.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progress_service {
    /** @var int Seconds to wait for a concurrent progress write. */
    private const LOCK_TIMEOUT_SECONDS = 10;

    /** @var int Maximum accepted active time on the first progress write. */
    private const INITIAL_TIMESPENT_LIMIT = 35;

    /** @var int Grace seconds added to elapsed server wall time. */
    private const TIMESPENT_GRACE_SECONDS = 5;

    /**
     * Save progress and return the persisted state.
     *
     * @param object $cm Course module record/info.
     * @param object $course Course record.
     * @param object $instance Elearning Stream instance.
     * @param \context_module $context Module context.
     * @param int $userid User id.
     * @param array $input Validated external input.
     * @return array
     */
    public function save_progress(
        object $cm,
        object $course,
        object $instance,
        \context_module $context,
        int $userid,
        array $input
    ): array {
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_videoplayer');
        $lockkey = 'progress_' . (int)$instance->id . '_' . $userid;
        $lock = $lockfactory->get_lock($lockkey, self::LOCK_TIMEOUT_SECONDS);

        if (!$lock) {
            throw new \moodle_exception('progresslocktimeout', 'mod_videoplayer');
        }

        try {
            return $this->save_progress_locked($cm, $course, $instance, $context, $userid, $input);
        } finally {
            $lock->release();
        }
    }

    /**
     * Persist progress while holding the per-user/activity lock.
     *
     * @param object $cm Course module record/info.
     * @param object $course Course record.
     * @param object $instance Activity instance.
     * @param \context_module $context Module context.
     * @param int $userid User id.
     * @param array $input Validated input.
     * @return array
     */
    private function save_progress_locked(
        object $cm,
        object $course,
        object $instance,
        \context_module $context,
        int $userid,
        array $input
    ): array {
        global $DB;

        $now = time();
        $lastpage = max(0, (int)($input['lastpage'] ?? 0));
        $totalpages = max(0, (int)($input['totalpages'] ?? 0));
        $clienttimespent = max(0, (int)($input['timespent'] ?? 0));
        $lastposition = max(0.0, (float)($input['lastposition'] ?? 0));
        $duration = max(0.0, (float)($input['duration'] ?? 0));
        $incomingranges = (string)($input['watchedranges'] ?? '');

        $conditions = [
            'videoplayerid' => (int)$instance->id,
            'userid' => $userid,
        ];

        $transaction = $DB->start_delegated_transaction();
        $record = $DB->get_record('videoplayer_views', $conditions);
        $wascompleted = $record ? !empty($record->completed) : false;

        $storedranges = (string)($record->watchedranges ?? '');
        $haswatchedranges = $incomingranges !== '' || $storedranges !== '';
        $watchedranges = $haswatchedranges
            ? watched_range_set::merge($storedranges, $incomingranges, $duration)
            : '';
        $watchedseconds = watched_range_set::seconds($watchedranges, $duration);
        $timespent = $this->bounded_timespent($clienttimespent, $record ?: null, $now);
        $derivedpercentage = $this->derive_percentage(
            $lastpage,
            $totalpages,
            $duration,
            $watchedseconds,
            $haswatchedranges
        );
        $requiredpercentage = max(1, min(100, (int)($instance->completionpercentage ?? 80)));
        $completed = $derivedpercentage >= $requiredpercentage;
        $progress = $duration > 0 ? $watchedseconds : (float)$lastpage;

        if ($record) {
            $record->progress = max((float)$record->progress, $progress);
            $record->completionpercentage = max(
                (float)$record->completionpercentage,
                $derivedpercentage
            );
            $record->completed = (!empty($record->completed) || $completed) ? 1 : 0;
            if ($lastpage > 0) {
                $record->lastpage = $lastpage;
            }
            $record->totalpages = max((int)($record->totalpages ?? 0), $totalpages);
            $record->timespent = max((int)($record->timespent ?? 0), $timespent);
            if ($duration > 0) {
                $record->lastposition = min($lastposition, $duration);
                $record->duration = max((float)($record->duration ?? 0), $duration);
            }
            if ($haswatchedranges) {
                $record->watchedranges = $watchedranges;
            }
            $record->timemodified = $now;
            $DB->update_record('videoplayer_views', $record);
        } else {
            $record = (object)[
                'videoplayerid' => (int)$instance->id,
                'userid' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
                'progress' => $progress,
                'completed' => $completed ? 1 : 0,
                'completionpercentage' => $derivedpercentage,
                'lastpage' => $lastpage,
                'totalpages' => $totalpages,
                'timespent' => $timespent,
                'lastposition' => $duration > 0 ? min($lastposition, $duration) : 0,
                'duration' => $duration,
                'watchedranges' => $haswatchedranges ? $watchedranges : null,
            ];
            $record->id = $DB->insert_record('videoplayer_views', $record);
        }

        $transaction->allow_commit();

        \mod_videoplayer\event\progress_updated::create([
            'objectid' => $record->id,
            'context' => $context,
            'userid' => $userid,
            'other' => [
                'videoplayerid' => (int)$instance->id,
                'completionpercentage' => (float)$record->completionpercentage,
                'lastpage' => (int)($record->lastpage ?? 0),
                'totalpages' => (int)($record->totalpages ?? 0),
            ],
        ])->trigger();

        if (!empty($record->completed) && !empty($instance->completionprogressenabled)) {
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm) === COMPLETION_TRACKING_AUTOMATIC) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
            }
        }

        if (!empty($record->completed) && !$wascompleted) {
            \mod_videoplayer\event\resource_completed::create([
                'objectid' => $record->id,
                'context' => $context,
                'userid' => $userid,
                'other' => [
                    'videoplayerid' => (int)$instance->id,
                    'completionpercentage' => (float)$record->completionpercentage,
                ],
            ])->trigger();
        }

        return $this->response($record);
    }

    /**
     * Derive completion for supported resource types.
     *
     * @param int $lastpage Last PDF page reached.
     * @param int $totalpages Total PDF pages.
     * @param float $duration Media duration.
     * @param float $watchedseconds Unique video seconds reproduced.
     * @param bool $haswatchedranges Whether range telemetry is present.
     * @return float
     */
    private function derive_percentage(
        int $lastpage,
        int $totalpages,
        float $duration,
        float $watchedseconds,
        bool $haswatchedranges
    ): float {
        if ($totalpages > 0 && $lastpage > 0) {
            return $this->clamp_percentage(($lastpage / $totalpages) * 100);
        }

        if ($duration > 0 && $haswatchedranges) {
            return $this->clamp_percentage((min($watchedseconds, $duration) / $duration) * 100);
        }

        return 0.0;
    }

    /**
     * Bound client-reported active time by server-observed wall time.
     *
     * @param int $clienttimespent Client cumulative active time.
     * @param object|null $record Existing progress record.
     * @param int $now Current server timestamp.
     * @return int
     */
    private function bounded_timespent(int $clienttimespent, ?object $record, int $now): int {
        if ($record === null) {
            return min($clienttimespent, self::INITIAL_TIMESPENT_LIMIT);
        }

        $stored = max(0, (int)($record->timespent ?? 0));
        $elapsed = max(0, $now - (int)($record->timemodified ?? $now));
        $maximum = $stored + $elapsed + self::TIMESPENT_GRACE_SECONDS;

        return max($stored, min($clienttimespent, $maximum));
    }

    /**
     * Clamp one percentage.
     *
     * @param float $value Percentage.
     * @return float
     */
    private function clamp_percentage(float $value): float {
        return max(0.0, min(100.0, $value));
    }

    /**
     * Build the external API response.
     *
     * @param object $record Persisted progress.
     * @return array
     */
    private function response(object $record): array {
        return [
            'status' => true,
            'completed' => !empty($record->completed),
            'progress' => (float)$record->progress,
            'completionpercentage' => (float)$record->completionpercentage,
            'lastpage' => (int)($record->lastpage ?? 0),
            'totalpages' => (int)($record->totalpages ?? 0),
            'timespent' => (int)($record->timespent ?? 0),
            'lastposition' => (float)($record->lastposition ?? 0),
            'duration' => (float)($record->duration ?? 0),
            'watchedranges' => (string)($record->watchedranges ?? '[]'),
            'timemodified' => (int)$record->timemodified,
        ];
    }
}
