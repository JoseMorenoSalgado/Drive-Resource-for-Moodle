# Drive Resource database model

## `videoplayer`

Stores one activity instance. Runtime-critical fields include course, name, source, Drive URL, canonical type, protection/presentation flags and completion threshold.

A set of historical columns is retained for upgrade/backup compatibility with earlier `mod_videoplayer` releases. New runtime code should not add dependencies to obsolete player/viewer fields.

## `videoplayer_views`

One row per `(videoplayerid, userid)` enforced by a unique key.

Key fields:

| Field | Purpose |
| --- | --- |
| `progress` | Generic/max progress value retained for compatibility |
| `completed` | Persisted completion transition |
| `completionpercentage` | Highest recorded completion percentage |
| `lastpage` | Current PDF resume page |
| `totalpages` | Known PDF page count |
| `timespent` | Cumulative active seconds |
| `lastposition` | Current video/audio resume position in seconds |
| `duration` | Known media duration in seconds |
| `points` | Optional gamification total |
| `timemodified` | Last progress write |

`lastposition` and `duration` were added in schema version `2026092013`.

## `videoplayer_rewards`

Stores optional one-time gamification rewards keyed by activity, user, reward type and reward key.

## Data lifecycle

Activity deletion removes related views/rewards and Moodle File API data. Privacy API operations can export/delete user-owned view/reward records by module context. Backup & Restore includes user data only when the backup is configured to include it.
