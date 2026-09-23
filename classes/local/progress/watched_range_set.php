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
 * Normalises and merges watched media ranges.
 *
 * Stored ranges are a compact JSON array of [start, end] second pairs. The
 * class deliberately accepts only finite positive intervals and keeps the
 * representation bounded so browser input cannot grow one progress row
 * without limit.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class watched_range_set {
    /** Maximum accepted number of distinct ranges before merging. */
    private const MAX_RANGES = 512;

    /** Maximum accepted JSON payload size. */
    private const MAX_JSON_BYTES = 65535;

    /**
     * Merge stored and incoming watched ranges.
     *
     * @param string $stored Stored JSON.
     * @param string $incoming Incoming JSON.
     * @param float $duration Media duration in seconds.
     * @return string Canonical JSON.
     */
    public static function merge(string $stored, string $incoming, float $duration): string {
        $ranges = array_merge(
            self::decode($stored, $duration),
            self::decode($incoming, $duration)
        );
        $ranges = self::normalise($ranges, $duration);

        return json_encode($ranges, JSON_PRESERVE_ZERO_FRACTION) ?: '[]';
    }

    /**
     * Return unique watched seconds represented by one JSON range set.
     *
     * @param string $json Range JSON.
     * @param float $duration Media duration.
     * @return float
     */
    public static function seconds(string $json, float $duration): float {
        $total = 0.0;
        foreach (self::normalise(self::decode($json, $duration), $duration) as $range) {
            $total += $range[1] - $range[0];
        }

        return max(0.0, $total);
    }

    /**
     * Decode and validate range JSON.
     *
     * @param string $json Range JSON.
     * @param float $duration Media duration.
     * @return array
     */
    private static function decode(string $json, float $duration): array {
        if ($json === '' || strlen($json) > self::MAX_JSON_BYTES) {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $ranges = [];
        foreach (array_slice($decoded, 0, self::MAX_RANGES) as $range) {
            if (!is_array($range) || count($range) < 2 || !is_numeric($range[0]) || !is_numeric($range[1])) {
                continue;
            }

            $start = max(0.0, (float)$range[0]);
            $end = max(0.0, (float)$range[1]);
            if ($duration > 0) {
                $start = min($start, $duration);
                $end = min($end, $duration);
            }

            if (is_finite($start) && is_finite($end) && $end > $start) {
                $ranges[] = [$start, $end];
            }
        }

        return $ranges;
    }

    /**
     * Sort and merge overlapping or adjacent ranges.
     *
     * @param array $ranges Raw ranges.
     * @param float $duration Media duration.
     * @return array
     */
    private static function normalise(array $ranges, float $duration): array {
        usort($ranges, static function (array $a, array $b): int {
            return $a[0] <=> $b[0];
        });

        $merged = [];
        foreach ($ranges as $range) {
            $start = max(0.0, (float)$range[0]);
            $end = max(0.0, (float)$range[1]);
            if ($duration > 0) {
                $start = min($start, $duration);
                $end = min($end, $duration);
            }
            if ($end <= $start) {
                continue;
            }

            $lastindex = count($merged) - 1;
            if ($lastindex >= 0 && $start <= $merged[$lastindex][1] + 0.25) {
                $merged[$lastindex][1] = max($merged[$lastindex][1], $end);
                continue;
            }

            if (count($merged) >= self::MAX_RANGES) {
                break;
            }
            $merged[] = [$start, $end];
        }

        return $merged;
    }
}
