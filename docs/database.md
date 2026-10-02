# Elearning Stream database model

## Moodle tables

### `videoplayer`

One row per activity. The table name is historical and remains unchanged for Moodle upgrade compatibility.

Production activities use the managed Elearning Stream video source. Historical Moodle-local PDF rows remain supported. Historical remote Google source values may remain in upgraded data but are not executable runtime providers.

### `videoplayer_views`

One row per `(videoplayerid, userid)`.

Important fields:

| Field | Purpose |
| --- | --- |
| `progress` | compatibility progress value |
| `completed` | persisted completion state |
| `completionpercentage` | highest saved completion percentage |
| `timespent` | cumulative active seconds |
| `lastposition` | media resume position |
| `duration` | known media duration |
| `watchedranges` | canonical union of actually reproduced video intervals |
| `lastpage` / `totalpages` | local-PDF reading progress |
| `points` | optional gamification total |

### `videoplayer_transfer_events`

Queue of protected playback bytes emitted by Moodle. Events are aggregated and sent to WHMCS with idempotent report ids.

## WHMCS control-plane tables

### `mod_driveresource_services`

Compatibility aggregate keyed by WHMCS `service_id`. It retains provider assignment, aggregate storage/reservations, transfer counters and historical primary connection fields.

Gateway 0.6.0 no longer treats this row as one Moodle site.

### `mod_driveresource_accounts`

Commercial account row keyed by `service_id`.

| Field | Purpose |
| --- | --- |
| `client_id` | WHMCS customer |
| `billing_mode` | `free`, `payg`, or migration-only `legacy` |
| `activation_verified` | commercial service activation completed |
| `balance_microusd` | prepaid wallet balance |
| `free_storage_bytes` | included storage |
| `free_transfer_bytes` | included monthly transfer |
| `storage_rate_microusd_per_gb` | PAYG storage rate |
| `transfer_rate_microusd_per_gb` | PAYG transfer rate |
| `minimum_recharge_microusd` | PAYG promotion threshold |
| `free_installation_limit` | FREE Moodle limit |
| `paid_installation_limit` | PAYG limit; 0 means unlimited |
| `status` | commercial enforcement state |

### `mod_driveresource_installations`

Independent Moodle identities under one commercial account.

Unique constraints bind one site and token hash per service. Plaintext secondary tokens are never persisted.

### `mod_driveresource_wallet_ledger`

Append-only financial ledger using integer micro-USD.

Each entry has a globally unique `idempotency_key`, signed amount and resulting balance.

### `mod_driveresource_wallet_orders`

Maps customer recharge requests to WHMCS invoices. The wallet is credited only after the corresponding invoice reaches Paid state.

### `mod_driveresource_usage_daily`

UTC daily usage snapshots and computed PAYG charges.

### `mod_driveresource_uploads`

Upload reservations and provider assets. Gateway 0.6.0 adds nullable `installation_id` for attribution while ownership remains account-wide by `service_id`.

### `mod_driveresource_asset_refs`

Activity references keyed by account, site, Moodle instance and provider video. Physical deletion is allowed only when no active reference remains.

### `mod_driveresource_usage_reports`

Idempotency ledger for Moodle transfer batches. Gateway 0.6.0 adds optional installation attribution.

### `mod_driveresource_nonces`

Short-lived HMAC request replay protection.

### `mod_driveresource_audit`

Redacted control-plane audit events.

## Migration rule

Schema upgrades must be idempotent and must never reset an existing account's billing mode, wallet balance, installation limits or paid status. Pre-0.6.0 services are created as `legacy` only when no account row exists.
