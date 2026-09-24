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

## `videoplayer_transfer_events`

Operational queue for Elearning Stream bytes actually emitted through Moodle protected playback. It contains no Moodle user id.

| Field | Purpose |
| --- | --- |
| `serviceid` | WHMCS service identity active when bytes were delivered |
| `videoid` | Provider video GUID for operational attribution |
| `bytes` | Actual bytes emitted to the learner browser for one protected request |
| `periodkey` | UTC billing month `YYYY-MM` |
| `timecreated` | Queue timestamp |

The scheduled transfer task sends bounded idempotent batches to WHMCS and removes successfully acknowledged events. Events belonging to a stale Service ID are never reattributed to a newly configured service.

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
| `connection_status` | Pending/connected/failed Moodle validation state |
| `connection_checked_at` | Last signed Moodle probe time |
| `connection_message` | Bounded operational connection message |
| `transfer_period` | Current UTC transfer month |
| `transfer_bytes` | Protected playback bytes reported for that month |
| `transfer_updated_at` | Last transfer update |
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


### `mod_driveresource_usage_reports`

Deduplication ledger for Moodle transfer batches. The unique `(service_id, report_id)` constraint makes WHMCS ingestion idempotent when Moodle retries after a network timeout. Old report ids are pruned after the operational retention window.

Gateway 0.4.0 adds the connection-state and transfer-accounting fields plus this table.


### `mod_driveresource_audit`

WHMCS-only redacted control-plane audit table introduced in companion 0.4.1.

| Field | Purpose |
| --- | --- |
| `service_id` | Tenant/service affected by the action |
| `actor_type` | `admin`, `client` or `system` |
| `actor_id` | WHMCS actor id when available |
| `action` | Stable non-localised action key |
| `metadata_json` | Bounded redacted operational metadata |
| `created_at` | Audit timestamp |

Tokens, passwords, API keys, secrets, signatures and credentials must never be written to `metadata_json`.
