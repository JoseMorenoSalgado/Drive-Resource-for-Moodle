<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_videoplayer\local\gamification;

/**
 * Handles Drive Resource gamification rewards.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reward_service {

    /**
     * Award milestone rewards without duplicates.
     *
     * @param object $videoplayer
     * @param object $record
     * @param int $userid
     * @param \context_module $context
     * @return array{rewards: array, totalpoints: int}
     */
    public function award_rewards(object $videoplayer, object $record, int $userid, \context_module $context): array {
        global $DB;

        $earned = [];
        $pointsperpage = max(0, (int)($videoplayer->pointsperpage ?? 1));
        $candidates = [];

        if ((int)($record->lastpage ?? 0) > 0) {
            $candidates[] = ['reading', 'first_page', $pointsperpage, get_string('rewardfirstpage', 'mod_videoplayer')];
        }

        foreach ([25, 50, 75] as $milestone) {
            if ((float)$record->completionpercentage >= $milestone) {
                $candidates[] = [
                    'milestone',
                    'percent_' . $milestone,
                    $milestone,
                    get_string('rewardpercent', 'mod_videoplayer', $milestone),
                ];
            }
        }

        if (!empty($record->completed)) {
            $candidates[] = ['completion', 'completed', 100, get_string('rewardcompleted', 'mod_videoplayer')];
        }

        foreach ($candidates as $candidate) {
            [$type, $key, $points, $label] = $candidate;
            $conditions = [
                'videoplayerid' => $videoplayer->id,
                'userid' => $userid,
                'rewardtype' => $type,
                'rewardkey' => $key,
            ];

            if ($DB->record_exists('videoplayer_rewards', $conditions)) {
                continue;
            }

            $reward = (object)[
                'videoplayerid' => $videoplayer->id,
                'userid' => $userid,
                'rewardtype' => $type,
                'rewardkey' => $key,
                'points' => $points,
                'timecreated' => time(),
            ];
            $reward->id = $DB->insert_record('videoplayer_rewards', $reward);

            \mod_videoplayer\event\reward_awarded::create([
                'objectid' => $reward->id,
                'context' => $context,
                'userid' => $userid,
                'other' => [
                    'videoplayerid' => $videoplayer->id,
                    'rewardtype' => $type,
                    'rewardkey' => $key,
                    'points' => $points,
                ],
            ])->trigger();

            $earned[] = [
                'key' => $key,
                'label' => $label,
                'points' => $points,
            ];
        }

        $totalpoints = (int)$DB->get_field_sql(
            'SELECT COALESCE(SUM(points), 0)
               FROM {videoplayer_rewards}
              WHERE videoplayerid = :videoplayerid AND userid = :userid',
            [
                'videoplayerid' => $videoplayer->id,
                'userid' => $userid,
            ]
        );

        return [
            'rewards' => $earned,
            'totalpoints' => $totalpoints,
        ];
    }
}
