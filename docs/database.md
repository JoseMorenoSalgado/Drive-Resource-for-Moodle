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
| `watchedranges` | Bounded JSON union of video intervals actually reproduced; completion ignores skipped seek gaps |
| `points` | Optional gamification total |
| `timemodified` | Last progress write |

`lastposition` and `duration` were added in schema version `2026092013`. `watchedranges` was added in `2026092016` to separate resume position from seek-safe video completion.

## `videoplayer_rewards`

Stores optional one-time gamification rewards keyed by activity, user, reward type and reward key.

## Data lifecycle

Activity deletion removes related views/rewards and Moodle File API data. Privacy API operations can export/delete user-owned view/reward records by module context. Backup & Restore includes user data only when the backup is configured to include it.


## WHMCS companion tables

The WHMCS companion owns a separate commercial/control-plane schema inside the WHMCS database. These tables are not Moodle tables and are not included in Moodle backup/restore.

### `mod_driveresource_services`

One row per provisioned WHMCS service. This is the tenant boundary for one independently authenticated Moodle installation.

Important fields:

| Field | Purpose |
| --- | --- |
| `service_id` | WHMCS service id; primary tenant key |
| `site_url` / `site_hash` | Exact Moodle site binding |
| `token_hash` | Hash of the service-scoped Moodle↔WHMCS token |
| `status` | Active/suspended/terminated control-plane state |
| `backend_key` | Provider-neutral backend identity; defaults to `elearningstream` |
| `backend_profile` | Backend profile selector; defaults to `default` |
| `quota_bytes` | Included storage allowance |
| `used_bytes` | Authoritative accounted storage |
| `reserved_bytes` | Storage reserved by in-progress uploads |
| `overage_allowed` | Whether service may exceed included storage |
| `retention_days` | Orphan retention policy |

Gateway 0.3.0 adds `backend_key` and `backend_profile` idempotently. Existing services are normalized to `elearningstream/default`.

### `mod_driveresource_uploads`

Tracks upload reservations and provider assets by `service_id`, including source size, accounted bytes, state, binding and retention timestamps.

### `mod_driveresource_asset_refs`

Tracks active Moodle activity references to provider assets. The unique service/site/instance tuple prevents reference collisions while allowing the central WHMCS gateway to serve many customers.

### `mod_driveresource_nonces`

Stores short-lived per-service request nonces for replay protection.

The future S3-compatible adapter will reuse the same service/tenant/accounting boundary; provider-specific object metadata may be added in a backend-specific table rather than overloading Moodle activity data.
