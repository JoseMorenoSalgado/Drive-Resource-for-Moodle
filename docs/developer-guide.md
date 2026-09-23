# Drive Resource developer guide

## Component identity

- Product: Drive Resource
- Moodle component: `mod_videoplayer`
- Target branch for this package: Moodle 4.5 LTS
- PHP: 8.1+

Do not rename the Moodle component without a separate migration project; existing database tables, capabilities, backups and installed-site upgrade paths depend on it.

## Directory responsibilities

```text
classes/local/access/        authorization/request context
classes/local/resource/      normalized resource model
classes/local/stream/        protected delivery orchestration/policy
classes/local/progress/      progress/completion business logic
classes/output/              renderer/template models/report tables
classes/external/            AJAX/web-service API
classes/event/               Moodle events
classes/privacy/             Privacy API
classes/task/                PDF cache tasks
amd/src/                     JavaScript source
amd/build/                   production AMD modules
templates/                   Mustache presentation
db/                          schema, services, cache, tasks, capabilities
backup/moodle2/              Backup & Restore API
thirdpartylibs/pdfjs/         local PDF.js distribution
```

## Adding or changing a resource type

1. Add the canonical type to `drive::RESOURCE_TYPES`.
2. Add detection/resolution logic to `drive` only if needed; runtime callers must use `drive::resolve_record_type()`.
3. Add a descriptor predicate when the behavior warrants it.
4. Add a dedicated Mustache template/AMD module if the browser interaction differs materially.
5. Keep upstream URLs out of `export_for_template()`.
6. Add language strings, backup/privacy implications and tests.
7. Update documentation and the manual regression checklist.

## Protected endpoint rules

`protected.php` must remain a controller, not a business-logic container. It must:

- load Moodle;
- validate the course module through `activity_context`;
- construct the resource descriptor;
- release the PHP session lock before long streaming;
- delegate to `protected_resource_service`.

Do not add a direct URL parameter that accepts an arbitrary upstream URL.

## Streaming rules

When changing `http_range_proxy` or `protected_stream`:

- never read a complete large file into memory;
- preserve `Range`/`206` behavior;
- handle `HEAD` without a body;
- do not relay unsafe upstream headers;
- do not expose upstream errors/pages as successful media;
- validate server-side upstream hosts;
- test Safari/iOS seeking as well as Chromium;
- preserve cancellation/low-speed handling so abandoned or stalled upstream cURL transfers do not occupy PHP workers indefinitely;
- preserve the `refresh=1` recovery contract as a boolean server-side cache bypass only; never convert it into an arbitrary upstream URL parameter.

The progressive Drive stream resolver is upstream-dependent. Keep it isolated and preserve source fallback. Any resolver change must be regression-tested with the exact Google Drive video that previously worked on rc15. Persistent `waiting`/`stalled` recovery must retain the current playback position and must not loop indefinitely; the AMD player caps recovery attempts and resets the counter only after stable playback.

## JavaScript

Video is implemented by `amd/src/nativevideo.js`; audio by `nativeaudio.js`; PDF by `pdfviewer.js`.

There is no Plyr, Video.js or StPageFlip dependency.

After source changes, rebuild the Moodle AMD bundles in a Moodle development environment:

```bash
npx grunt amd
```

Commit both `amd/src/` and generated `amd/build/` artifacts expected by Moodle production deployments.

### Progress ownership

Do not attach multiple trackers to one resource:

- video -> `nativevideo.js`;
- audio -> `nativeaudio.js`;
- PDF-like -> `pdfviewer.js`;
- image/generic -> `progress.js` when tracking is enabled.

This avoids double-counted time and duplicate AJAX writes.

## Progress API

`mod_videoplayer_save_progress` is AJAX-enabled and delegates all persistence to `progress_service`.

The server, not the UI, decides the persisted completion transition. Do not directly update Moodle completion from JavaScript.

For video, `lastposition` is resume-only state. `watchedranges` is the completion authority. `nativevideo.js` must add only short contiguous playback intervals and must reset its contiguous sample on seeking. `watched_range_set` validates, bounds, merges and clamps browser telemetry before `progress_service` calculates completion.

When adding progress fields:

1. update `db/install.xml`;
2. add a monotonic version/savepoint to `db/upgrade.php`;
3. update external parameters/returns;
4. update Privacy API;
5. update Backup & Restore;
6. update reports/tests/docs.

## Database upgrades

Never edit a historical savepoint to represent a new schema change. Add a new `$plugin->version` and a new guarded block in `db/upgrade.php`.

When changing an indexed field, explicitly account for XMLDB index/key dependencies before calling type/default change methods. This plugin previously encountered `ddldependencyerror`; regression-test upgrades from older installations.

## Moodle coding conventions

Use Moodle Coding Style and PHPDoc. Keep classes final unless extension is a deliberate API. Prefer small single-purpose services and avoid accessing globals outside Moodle-facing infrastructure where practical.

## Testing

Static checks before packaging:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check amd/src/nativevideo.js
node --check amd/src/nativeaudio.js
node --check amd/src/pdfviewer.js
```

CI additionally runs Moodle Plugin CI checks and PHPUnit where a full Moodle environment is available.

Manual release testing is mandatory because Google Drive playback is an external integration. Follow `docs/manual-test-checklist.md`.

## Commercial hardening workflow

Changes intended for a stable commercial release must pass both the standard Moodle 4.5 CI matrix and the dedicated hardening gate. Run the invariant guard locally from the plugin root with:

```bash
bash .github/scripts/release-invariants.sh
```

Do not weaken an invariant simply to make the gate pass. If the architecture legitimately changes, update the implementation, security model, tests and `docs/hardening-validation.md` together, then document why the invariant changed.

### Type-resolution rule

Do not duplicate the `type=auto` decision in controllers, tasks, render models or lifecycle callbacks. The hardening gate intentionally fails if `drive::detect_type()` is called directly from those runtime paths. Direct detection belongs inside `drive` and tests only.

Legacy schema fields such as `displaymode` and `disabledownload` remain for upgrade/backup compatibility, but they are not active presentation switches. The commercial architecture is protected-only and does not expose a direct-download mode.


## Custom completion form compatibility

Moodle 4.5 provides `get_suffix()` through the completion form trait. When adding plugin-specific completion controls, build field names as `<base name> . $this->get_suffix()` in every form lifecycle method. Do not introduce `get_suffixed_name()`; it is not part of the Moodle 4.5 `moodleform_mod` API and causes a fatal error while the activity form is constructed. The release-invariant gate enforces this rule.

## Bunny Stream / WHMCS development rules

Managed video is intentionally split across two trust domains.

Moodle code may know the WHMCS gateway URL, service ID, service token, Bunny video GUID and short-lived TUS upload signature. Moodle must never contain or persist a Bunny Stream management `AccessKey` or playback signing key.

WHMCS companion code lives under `integrations/whmcs/` during development:

```text
modules/addons/driveresource_gateway/
  api/                     authenticated Moodle-facing gateway endpoints
  lib/BunnyClient.php      only Bunny management API client
  lib/GatewayService.php   quota/reservation/asset orchestration
  hooks.php                reconciliation/retention cron

modules/servers/driveresource/
  driveresource.php        product provisioning lifecycle
  lib/MetricsProvider.php  WHMCS Usage Billing metrics
```

When changing direct upload behavior:

1. preserve Moodle login/course/capability checks before any authorization is requested;
2. reserve quota in WHMCS before creating a usable upload authorization;
3. keep Bunny management credentials WHMCS-only;
4. scope TUS authorization to a single video and expiration;
5. pin the browser upload host;
6. keep retries bounded and resumable;
7. support authorization renewal for uploads that exceed the initial TTL;
8. never place `provideruploadid` in Moodle backups;
9. reconcile restored provider GUIDs through WHMCS tenant ownership;
10. update `.github/scripts/bunny-whmcs-invariants.sh` when an intentional security boundary changes.

Run the feature gate before promotion:

```bash
bash .github/scripts/bunny-whmcs-invariants.sh
node --check amd/src/bunnyupload.js
find . -name '*.php' -not -path './thirdpartylibs/*' -print0 | xargs -0 -n1 php -l
```

The production Moodle package must not accidentally install the WHMCS companion under `mod/videoplayer`; package the two deployables separately.

## DDL migration rule for optional predecessor fields

Do not use an XMLDB `previous` column argument for an upgrade field when the predecessor may be absent on an existing installation. A declaration such as `watchedranges ... AFTER duration` can fail on MySQL/MariaDB before Moodle reaches the savepoint.

For compatibility migrations, first check every required field with `field_exists()` and add missing fields without relying on physical ordering. The `2026092202` migration is the regression reference for this rule.


## Partial-schema compatibility rule

Any callback reachable while Moodle is building course caches must tolerate optional fields introduced by pre-release builds. Do not hard-select a newly introduced field from a hot-path callback unless the current schema is guaranteed. Use one cached schema inspection where required, provide a safe default, and pair it with a newer idempotent XMLDB repair savepoint.

For the current release, `completionprogressenabled` defaults to enabled and `completionpercentage` defaults to 80 only while the repair migration has not yet restored the physical columns.


## Elearning Stream compatibility

Use **Elearning Stream** in customer-facing text. Do not rename the persisted `bunnystream` source value, internal `bunny_stream` provider class, database fields or existing external-function names in this release; those identifiers are compatibility contracts.

When accepting an existing provider URL, parse only the video GUID and discard the original URL before DML. The WHMCS gateway remains authoritative for provider-library existence, service ownership and quota accounting.


## Elearning Stream playback rules

Elearning Stream learner playback must remain behind `protected.php`. Do not render the CDN hostname, MP4 fallback URL, token key or signed playback URL into Mustache/AMD configuration.

The WHMCS gateway is authoritative for service ownership and signs the provider MP4 path. Moodle validates the returned HTTPS `*.b-cdn.net` URL, caches it only until near expiry, and passes it to `http_range_proxy`. Provider MP4 fallback is required for the native HTML5 path.

The internal `bunny_*` setting and class identifiers are retained for compatibility and are not customer-facing naming.


## Elearning Stream gateway preflight rule

Any teacher workflow that calls `whmcs_gateway_client` must perform a Moodle-side configuration preflight before persistence. Use `whmcs_gateway_client::missing_configuration()` / `is_configured()` rather than duplicating configuration checks.

The required Moodle settings are the addon URL, WHMCS service ID and service-scoped token. The addon URL must target the deployed `modules/addons/driveresource_gateway` path because client endpoints are appended beneath its `api/` directory.


## WHMCS storage backend extension contract

WHMCS service identity must remain provider-neutral. Do not add provider credentials to Moodle and do not encode provider choice into a Moodle token.

The provisioning module appends backend selection after the legacy quota settings:

- `configoption1`: included storage GB;
- `configoption2`: overage allowed;
- `configoption3`: retention days;
- `configoption4`: backend key;
- `configoption5`: backend profile.

Never reorder these options in an upgrade because WHMCS passes module configuration by numbered position.

To add an S3-compatible implementation:

1. mark `s3compatible` provisionable only after the adapter is complete;
2. keep credentials/profile configuration in WHMCS;
3. implement direct multipart upload authorization rather than proxying large uploads through WHMCS/PHP;
4. implement server-side signed delivery compatible with the Moodle protected endpoint;
5. reconcile authoritative object size into existing service usage accounting;
6. enforce tenant prefixes/buckets and cross-tenant object ownership;
7. integrate lifecycle deletion/retention into backend-scoped maintenance;
8. add CI invariants and production tests before exposing the backend in product configuration.

Backend switching for a service with existing assets must use an explicit migration workflow. `ChangePackage` intentionally rejects an in-place backend change when media or bytes remain.


## WHMCS provisioning token contract

Do not set `RequiresServer=true` for the Elearning Stream provisioning module: no server hostname or server credential is part of the service contract.

The Moodle gateway token must be created by `driveresource_CreateAccount()`. Its plaintext copy belongs only in WHMCS protected service properties; the gateway database stores only SHA-256 of that token. Administrators may retrieve the service token through the module's administrator service fields to configure Moodle.

Do not allow operators to repair an unprovisioned service by inventing a password manually. Re-run the module Create action so the WHMCS password and gateway token hash are created atomically by the module.


## Idempotent WHMCS connection repair

`driveresource_CreateAccount()`, `driveresource_ProvisionMoodleConnection()` and explicit token rotation share one provisioning implementation.

Repair must preserve an existing valid service password/token. If WHMCS no longer has a plaintext service token, repair generates a new cryptographically random token and atomically replaces the gateway hash before persisting the new protected WHMCS service property.

Never expose a "repair" path that accepts an arbitrary operator-supplied token. Explicit rotation must be a separate administrator action.


## WHMCS client self-service contract

Client actions are exposed through WHMCS provisioning-module custom functions and remain bound to the service selected by WHMCS. Mutating actions must use POST and must never accept a caller-supplied WHMCS service id as the ownership authority.

The current self-service actions are:
- provision/repair Moodle connection;
- rotate Moodle service token;
- update Moodle URL;
- validate Moodle connection;
- delete an unreferenced provider video.

The Moodle URL stored in `mod_driveresource_services.site_url` is authoritative after provisioning. Changing it marks connection state pending and is rejected while active media references exist.

Provider deletion must remain idempotent and must refuse any video with active `mod_driveresource_asset_refs`.

## Transfer metering contract

`http_range_proxy` may receive an optional transfer callback. Increment usage only for bytes actually emitted to the browser. Do not count HEAD bodies, provider bytes discarded while retrying ranges, warning HTML, failed upstream requests or bytes after a client disconnect.

Moodle stores short-lived aggregateable events in `videoplayer_transfer_events`. The scheduled task groups at most 1000 events by service/month and sends an idempotent SHA-256 batch id to WHMCS. Events collected under an old Service ID must never be reported with the current token.

WHMCS stores the current UTC month in `transfer_period` and the accumulated bytes in `transfer_bytes`. Usage Billing exposes this as `video_transfer_gb` with `MetricInterface::TYPE_PERIOD_MONTH`; storage remains `TYPE_SNAPSHOT`.

The signed connection probe endpoint must remain cookie-free, HTTPS-exact, HMAC-authenticated and replay-protected.
