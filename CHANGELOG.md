# Changelog

## Elearning Stream Gateway 0.5.2 — 2026-09-25

- Fixes provider videos remaining in Bunny after the final Moodle activity reference is deleted when the product is configured for immediate deletion.
- `Retention Days = 0` now performs physical provider deletion as soon as the signed Moodle release task reaches the gateway.
- Reference counting protects videos still used by another Moodle activity.
- Uses a transient `deleting` state to prevent concurrent rebinding while deletion is in flight.
- Failed provider deletion is queued for WHMCS maintenance retry instead of being silently marked deleted.
- Provider DELETE is idempotent (an already-absent asset is treated as deleted), and maintenance also recovers interrupted transient `deleting` records.
- New Elearning Stream products now default to `Retention Days = 0`; positive values keep the recovery grace-period behavior.
- Gateway package version: `0.5.2`.

## Elearning Stream 1.2.0-rc5-m45 — 2026-09-25

- Fixes Bunny Stream assets being renamed to the teacher's local filename during TUS creation.
- Direct upload metadata now uses the Moodle activity **Nombre del recurso** as the Bunny video title.
- The original local filename remains only as file metadata for traceability.
- If the Moodle activity name is empty, the local filename is used only as a safe fallback.
- Includes RC4 playback initialization and all previous RC3/RC2 hotfixes.

## Elearning Stream 1.2.0-rc4-m45 — 2026-09-25

- Fixes uploaded Elearning Stream videos remaining permanently on **Loading video...** in Moodle.
- The learner view incorrectly excluded Elearning Stream resources from the `mod_videoplayer/nativevideo` AMD initialization even though the Mustache player relies on that module to assign the protected media URL.
- All video providers now initialize the Moodle-owned HTML5 player; Elearning Stream continues to stream only through `protected.php`.
- Includes RC3 gateway authentication, RC2 XMLDB migration, and direct-upload course-context hotfixes.

## Elearning Stream 1.2.0-rc3-m45 + Gateway 0.5.1 — 2026-09-25

- Fixes Moodle-to-gateway HTTP 401 on Apache/FastCGI stacks that strip the standard `Authorization` header before PHP receives it.
- Moodle now sends the exact 64-hex service token in `X-Drive-Resource-Token` in addition to the legacy Bearer header.
- Gateway 0.5.1 prefers `X-Drive-Resource-Token` and keeps Bearer authentication as a backward-compatible fallback.
- Tightens gateway token validation to the same exact 64-hex contract used during provisioning.
- Fixes Moodle gateway exceptions so the translated message substitutes `{$a}` instead of displaying the placeholder literally.
- Includes the RC2 XMLDB indexed-default repair and the direct-upload course-context fix.

## Elearning Stream 1.2.0-rc2-m45 — 2026-09-25

- Fixes Moodle upgrade failure `ddl_dependency_exception` when changing the default of indexed field `videoplayer.source`.
- Keeps historical savepoint `2026092401` schema-neutral and performs the default change in `2026092501`.
- Drops `source_idx` before `change_field_default()` and recreates the index immediately afterwards, as required by Moodle XMLDB dependency checks.
- Preserves all existing activity rows; only the default for future records changes to `bunnystream`.
- Includes the direct-upload course-context fix that prevents `course.id = 0` during video upload authorization.

## Elearning Stream 1.2.0-rc1-m45 + Gateway 0.5.0 — 2026-09-24

- Fixes direct video upload authorization passing course ID `0` because the activity form incorrectly treated Moodle's course id as an object. The uploader now uses `moodleform_mod::get_course()` and `get_coursemodule()`, preventing `invalidrecord` on the `course` table.
- Promotes the customer-facing product name to **Elearning Stream** while retaining the historical `mod_videoplayer` component for upgrade compatibility.
- Removes visible WHMCS implementation wording from Moodle settings and errors; Moodle now asks only for the Elearning Stream connection URL, Service ID and service token.
- Adds the branded **Public Gateway URL**, recommended as `https://stream.elearningcloud.io`, and shows that URL in the customer service portal instead of the internal Moodle site binding.
- Makes new Moodle activities video-first and hides the legacy Google Drive/local-PDF source selector. Existing legacy activities remain supported and editable.
- Changes the fresh-install/future-record source default to `bunnystream` without rewriting existing activity rows.
- Splits service provider identity into independent video-provider and protected-object-storage lanes.
- Adds a protected-PDF/object-storage control plane for Amazon S3, Cloudflare R2, Wasabi, Backblaze B2 S3, Hetzner Object Storage and custom S3-compatible endpoints.
- Keeps the S3 protected-PDF data plane gated until upload, signed delivery, range handling, lifecycle and usage reconciliation are fully validated.
- Adds a provider registry so future managed-video providers can be introduced without changing Moodle Service IDs or service tokens.
- WHMCS package: `elearning-stream-whmcs-0.5.0.zip`.
- Moodle package: `elearning-stream-1.2.0-rc1-m45.zip`.

All notable changes to Drive Resource are documented here. The Moodle component remains `mod_videoplayer` for upgrade compatibility.

## WHMCS companion 0.4.3 — 2026-09-23

- Changes the WHMCS installable ZIP to extract directly into the WHMCS document root with top-level `modules/`; removes the confusing `whmcs-root/` wrapper.
- Adds detailed installation/provisioning health diagnostics to the Drive Resource Media Gateway admin dashboard.
- Detects missing server module, products not assigned to `driveresource`, services assigned but not provisioned, generic WHMCS usernames and products using a hosting type instead of `Other`.
- Rejects generic WHMCS-generated passwords as Moodle service tokens; only Drive Resource 64-character hexadecimal tokens are exposed as valid connection credentials.
- Adds package-layout and provisioning-diagnostic CI invariants.
- Keeps Moodle at `1.2.0-beta10-m45`; this is a WHMCS packaging/provisioning diagnostics release.
- WHMCS companion version: `0.4.3`.

## WHMCS companion 0.4.2 — 2026-09-23

- Adds an official `ClientAreaProductDetailsOutput` fallback for WHMCS client themes that omit provisioning-module `ClientArea()` output.
- Verifies the logged-in client owns the service and the product uses the `driveresource` provisioning module before rendering.
- Adds a per-service DOM identity marker and removes the fallback automatically when the standard module dashboard is already present, preventing duplicate dashboards.
- Keeps Moodle at `1.2.0-beta10-m45`; this is a WHMCS client-area compatibility fix.
- WHMCS companion version: `0.4.2`.

## WHMCS companion 0.4.1 + Moodle 1.2.0-beta10-m45 — 2026-09-23

- Adds centrally configured Elearning Stream public hostname aliases for pasted existing-video URLs.
- Supports branded public video hostnames such as `video.elearningcloud.io` without persisting or fetching the pasted URL from Moodle.
- Moves authoritative pasted-video hostname validation to WHMCS while preserving provider Video Library ownership verification.
- Adds module-local English and Spanish dictionaries for the WHMCS customer portal and connection workflow.
- Adds a redacted WHMCS audit trail for Moodle URL changes, token provisioning/rotation, connection validation and video deletion.
- Adds the recent audit trail to the WHMCS multi-client administration dashboard.
- Audit metadata excludes token/password/secret/signature/API-key fields.
- Moodle release: `1.2.0-beta10-m45`, build `2026092209`.
- WHMCS companion version: `0.4.1`.

## WHMCS companion 0.4.0 + Moodle 1.2.0-beta9-m45 — 2026-09-23

- Adds a customer self-service WHMCS dashboard for each Elearning Stream service.
- Lets the customer set/change the authorised Moodle URL, generate/rotate the service key and run a signed connection validation against Moodle.
- Adds connection cards with Connected/Pending/Not connected state and last validation message.
- Adds storage/quota, monthly transfer and video-count cards.
- Adds a paginated customer video library with status, provider size, active Moodle-reference count and guarded permanent deletion.
- Prevents deleting videos still referenced by Moodle activities.
- Prevents changing the service Moodle URL while active media references exist.
- Adds signed `gateway-status.php` in Moodle with HMAC, exact site/service binding, timestamp validation and replay protection.
- Adds actual protected-proxy byte metering, a bounded Moodle transfer queue and a five-minute idempotent WHMCS synchronization task.
- Adds WHMCS `video_transfer_gb` as a monthly-period Usage Billing metric alongside `video_storage_gb`.
- Adds WHMCS 0.4 schema for connection state, monthly transfer counters and idempotent usage reports.
- Adds multi-client admin dashboard connection/transfer visibility.
- WHMCS companion version: `0.4.0`.
- Moodle release: `1.2.0-beta9-m45`, build `2026092208`.

## WHMCS companion 0.3.2 — 2026-09-23

- Adds an explicit **Generar/Reparar conexión Moodle** admin module action for services that exist in WHMCS but were never provisioned.
- Adds a separate **Rotar token Moodle** admin action for intentional credential rotation.
- Refactors `CreateAccount` to use the same idempotent provisioning path as the repair action.
- Preserves an existing valid service token when repairing; generates a new token only when the service password is missing or rotation is explicitly requested.
- Shows Moodle connection status, gateway URL, service ID and token in the WHMCS administrator service fields.
- WHMCS companion version: `0.3.2`.

## WHMCS companion 0.3.1 — 2026-09-23

- Removes the unnecessary WHMCS server requirement from the Elearning Stream provisioning module.
- Fixes services remaining unprovisioned with `Servidor: Ninguno`, empty username and empty password/token.
- Keeps provisioning product-driven: each customer service generates its own `dr-{service_id}` username and service-scoped Moodle token when **Module Commands → Create** runs.
- Adds administrator service fields for Moodle Gateway URL, Moodle Service ID and Moodle Service Token.
- Adds CI invariants preventing reintroduction of a fake WHMCS server dependency.
- WHMCS companion version: `0.3.1`.

## WHMCS companion 0.3.0 — 2026-09-23

- Converts the WHMCS companion into an explicit multi-tenant control plane for many customer Moodle services.
- Adds per-service `backend_key` and `backend_profile` with automatic upgrade of existing services to `elearningstream/default`.
- Adds a paginated WHMCS administration dashboard with customer, site, plan, quota, usage, video count, backend and service status.
- Adds a backend capability registry; Elearning Stream remains provisionable while S3-compatible object storage is reserved but disabled until its production adapter exists.
- Preserves the existing configoption1–3 contract and appends backend/profile as configoption4–5 so existing WHMCS products do not shift quota/overage/retention settings.
- Blocks unsafe provider changes while a service owns media or reserved/used bytes.
- Scopes Elearning Stream API and cron maintenance to services using that backend and lazy-loads provider credentials.
- Adds upgrade guards so a partial WHMCS 0.2 → 0.3 deployment fails with an actionable message instead of a database-column error.
- WHMCS addon version: `0.3.0`.

## 1.2.0-beta8-m45 — 2026-09-23

- Prevents activity-save exceptions when Elearning Stream is selected before Moodle has been connected to WHMCS.
- Adds Moodle-side gateway diagnostics for the required WHMCS gateway URL, service ID and service token.
- Validates Elearning Stream configuration in the activity form before attempting URL import or upload.
- Improves the administrator setting descriptions with the exact addon URL shape: `https://billing.example.com/modules/addons/driveresource_gateway`.
- Adds PHPUnit coverage for missing and complete gateway configuration.
- Release build: `2026092207`.

## 1.2.0-beta7-m45 — 2026-09-23

- Completes Elearning Stream learner playback using the existing Drive Resource HTML5 player.
- Adds authenticated WHMCS playback authorization and short-lived signed MP4 fallback URLs.
- Keeps provider CDN URLs and signing tokens server-side; learners receive only Moodle `protected.php` URLs.
- Proxies Elearning Stream video through the existing Range/206 streaming layer.
- Adds strict `*.b-cdn.net` upstream validation and SSRF regression coverage.
- Adds a short-lived Moodle application cache for playback authorization and honors `refresh=1` during stall recovery.
- Adds WHMCS settings for Elearning Stream CDN hostname, playback token key and playback TTL.
- Requires MP4 fallback for videos delivered through the native HTML5 player.
- Release build: `2026092206`.

## 1.2.0-beta6-m45 — 2026-09-23

- Rebrands the customer-facing video service as **Elearning Stream** in Moodle and WHMCS.
- Adds a teacher workflow to choose between direct upload and an existing Elearning Stream URL.
- Parses only the provider video GUID from supported HTTPS playback/embed URLs; the pasted URL itself is never stored.
- Adds an authenticated WHMCS `asset-import.php` endpoint that verifies the video in the configured library, prevents cross-service reuse, accounts storage/quota and reuses the normal bind lifecycle.
- Adds regression tests and CI invariants for URL parsing, ownership enforcement and branding.
- Release build: `2026092205`.

## 1.2.0-beta5-m45 — 2026-09-23

- Fixes `dmlreadexception: Unknown column 'completionprogressenabled'` on installations with partially applied RC/beta schemas.
- Makes `videoplayer_get_coursemodule_info()` detect available completion columns before building the course-cache query.
- Adds repair savepoint `2026092204` for `completionprogressenabled`, Bunny provider metadata and watched-progress fields.
- Recreates the Bunny provider index if the field exists but the index is missing.
- Keeps the repair independent of physical MySQL/MariaDB column ordering.
- Corrects the hardening maturity invariant to validate the current beta release line.
- Release build: `2026092204`.

## 1.2.0-beta4-m45 — 2026-09-23

- Rebuilds `nativevideo.min.js` and its source map with Moodle 4.5 so AMD source/build parity passes on every CI matrix job.
- Keeps the beta3 resilient DDL recovery for incomplete `videoplayer_views` schemas.
- Fixes ARIA container semantics for the custom video and PDF control surfaces.
- Removes the temporary CI artifact-capture instrumentation used to repair the stale AMD bundle.
- Release build: `2026092203`.

## 1.2.0-beta2-m45 - 2026-09-22

### Seek-safe progress integration

- Integrated watched-range video completion into the WHMCS-gated Bunny 1.2 beta line.
- Seeking changes the resume position but skipped video no longer increases completion evidence.
- Added the `watchedranges` persistence field, Backup & Restore support, Privacy API metadata and regression coverage.
- Added upgrade savepoint `2026092201` for sites upgrading from Bunny beta1.
- Rebuilt the native video AMD production bundle and source map from the RC19 progress source.
- Version: `2026092201`; release: `1.2.0-beta2-m45`.

## 1.1.33-rc19-m45 - 2026-09-20

### Moodle 4.5 completion-form hotfix

- Fixed a fatal error when creating or editing a Drive Resource activity with completion enabled: `mod_videoplayer_mod_form::get_suffixed_name()` does not exist in Moodle 4.5.
- Replaced the invalid helper with Moodle 4.5's supported `get_suffix()` contract for custom completion element names in preprocessing, postprocessing, rules and validation.
- Added a hardening invariant that fails CI if the unsupported helper is reintroduced.
- Version: `2026092016`; release: `1.1.33-rc19-m45`.

## Unreleased - commercial hardening and validation

- RC19 fixes HTML5 video completion so seeking no longer counts skipped media as watched.
- Video completion now uses the persisted union of media ranges actually reproduced; resume position remains independent.
- Added bounded watched-range validation, XMLDB upgrade state, Backup/Restore, Privacy API metadata and regression tests.

- Deep audit: centralized all runtime resource-type resolution through `drive::resolve_record_type()` and introduced canonical source/type constants.
- Fixed inconsistent `auto` behavior where opaque Drive `/file/d/.../view` links could be treated as generic files in some code paths while other paths treated them as videos.
- Removed redundant resource-type lists from the form/view layer and added a hardening invariant that blocks direct runtime use of `drive::detect_type()` outside the canonical resolver.
- Removed obsolete `displaymode` form plumbing and the misleading active download toggle while retaining legacy database/backup fields for compatibility.
- Reduced template-model noise by removing unused exported fields and repeated configuration reads.
- Activity deletion now invalidates its PDF cache entry instead of leaving stale cache until TTL cleanup.
- Fixed a production playback regression discovered during ASPETEN validation: the refactor had dropped Google Drive `resourcekey` propagation and large-file confirmation-page/cookie handling from the protected source fallback.
- Restored the previously proven server-side Drive confirmation transport while retaining the refactored authorization boundary, bounded video recovery, low-speed termination and abandoned-range cancellation.
- Restored regression coverage for Drive confirmation forms, embedded download URLs, resource keys, MIME validation and byte-range negotiation.
- Added `docs/hardening-validation.md` with release-blocker definitions, security/streaming/device/PDF/Moodle API/performance/packaging workstreams and evidence requirements.
- Added a scheduled/manual `Drive Resource Hardening Gate` for release-critical architecture invariants.
- Added a repository release-invariant script that detects browser-facing Google hosts, iframe/preview regressions, removed CDN/player dependencies, protected-endpoint boundary regressions, missing Range/stall controls and missing production assets.
- Expanded SSRF policy tests with deceptive Google-lookalike hosts, loopback IPv6, scheme-relative and non-HTTPS/non-network URL cases.

## 1.1.33-rc17-m45 - 2026-09-20

### Architecture

- Introduced `activity_context` as the single course-module/login/context/capability boundary for browser endpoints and the progress API.
- Introduced `resource_descriptor` as the canonical resource model; browser templates receive Moodle protected URLs instead of Google identifiers or upstream URLs.
- Introduced `protected_resource_service` to separate authorization from byte delivery and upstream resolution.
- Introduced `upstream_url_policy` to restrict server-side streaming targets to approved HTTPS Google hosts.
- Reduced `protected.php` to a small authenticated controller.
- Reworked rendering through `resource_view` and the plugin renderer.

### Video and audio

- Standardized video on native HTML5 `<video>` plus the Drive Resource AMD control layer; no Plyr or Video.js runtime remains.
- Added custom play/pause, seek, buffered state, volume, mute, fullscreen, keyboard controls and 0.5x–2x speed selection.
- Preserved the progressive Google Drive playback resolver that was validated on Moodle 4.5 in rc15.
- Preserved protected source-file fallback without rendering Google Drive controls.
- Added native HTML5 audio playback and resume/progress tracking.
- Fixed responsive video orientation class application and hardened fullscreen handling across browser implementations.
- Added resilient buffering recovery: delayed loading UI, persistent-stall detection, position-preserving stream reload, signed-URL refresh and protected source fallback.
- Rebuilt the production AMD bundle after the buffering-recovery changes so deployed Moodle sites execute the same logic as `amd/src/nativevideo.js`.

### PDF and resources

- Kept PDF.js as the only viewer dependency and bundled it locally; removed the obsolete StPageFlip/book/ebook viewer code paths.
- Added PDF search, zoom, fit, fullscreen, page navigation and resume data to the first-party viewer.
- Google Docs, Sheets and Slides continue to be exported server-side to PDF and displayed through PDF.js.
- Added protected first-party image and generic-resource presentations.

### Progress, completion and reporting

- Added `lastposition` and `duration` fields to persist the exact media resume point and media duration.
- Reworked progress persistence into `progress_service` with one writer per resource type and no duplicate video/PDF heartbeat path.
- Video/audio save the current playback position, active time and duration; PDF saves page state and active time.
- Completion transitions update Moodle Completion API and emit `resource_completed` once per first completion transition.
- Added a paginated teacher progress report and removed the previous N+1 query pattern from the activity index.

### Security and performance

- Protected delivery is now mandatory; the legacy administrator opt-out was removed from active settings.
- Raw Google file IDs, direct download URLs and temporary playback URLs are not rendered by plugin-owned viewers.
- Maintained streaming without whole-file PHP buffering, including `Range`, `206`, `416`, `If-Range` and `HEAD` behavior.
- Added low-speed upstream termination and immediate cancellation of abandoned range requests to avoid hung PHP workers during Drive/network stalls.
- Maintained asynchronous PDF cache warming and local cache delivery.
- Removed obsolete iframe/Google Drive viewer styling and dead player assets.

### Moodle APIs and maintainability

- Updated Privacy API metadata/export/delete for media position and duration.
- Updated Backup & Restore for the new progress fields while retaining compatibility with legacy instance fields.
- Added unit tests for Google Drive URL parsing/type detection and upstream host policy.
- Added Moodle 4.5 CI coverage for supported PHP/database combinations.
- Rebuilt and synchronized all Moodle AMD production bundles and source maps so Grunt validation matches `amd/src`.
- Updated README and architecture, developer, security, installation, database and regression-test documentation.
- Removed the invalid empty-string database default from `videourl` and added an upgrade step that preserves existing values.
- Version: `2026092014`; release: `1.1.33-rc17-m45`.

## 1.1.32-rc16-m45 - 2026-09-20

- Removed residual Plyr assets and declarations.
- Standardized the working rc15 video path on the native HTML5 player.
- Added upgrade savepoint `2026092012`.

## 1.1.32-rc15-m45 - 2026-09-20

- Added the current Google Workspace video playback resolver and progressive-transcode selection.
- Added support for signed Google media hosts used by Drive playback.
- Retained legacy `get_video_info` only as a compatibility fallback.
- Kept Google Drive UI hidden behind Moodle protected endpoints.

## 1.1.32-rc14-m45 - 2026-09-20

- Replaced the visible Google Drive player with a custom HTML5 control surface.
- Added protected transcoded/source fallback handling.

## 1.1.32-rc12-m45 - 2026-09-20

- Switched the Moodle 4.5 compatibility path from Plyr to native HTML5 media.
- Improved Drive source delivery and MIME normalization.

## 1.1.32-rc11-m45 - 2026-09-20

- Declared Moodle 4.5 LTS compatibility (`requires = 2024100700`, `supported = [405, 405]`).
- Added a safe upgrade savepoint for the Moodle 4.5 compatibility build.

## 1.2.0-beta1-m45 - 2026-09-21

### Bunny Stream / WHMCS ingestion foundation

- Added Bunny Stream as a first-class managed video source without placing Bunny API credentials in Moodle.
- Added a WHMCS media gateway boundary with service-scoped HMAC authentication, exact Moodle-site binding, timestamp validation and replay-nonce protection.
- Added browser-to-Bunny direct TUS uploads so video bytes bypass Moodle PHP and WHMCS.
- Added bounded chunk retry/resume and renewal of short-lived TUS authorization for long uploads.
- Added WHMCS provisioning lifecycle: create, suspend, unsuspend, terminate and package change.
- Added 7 GB default included-storage policy, atomic upload reservations, optional soft overage and a `video_storage_gb` WHMCS snapshot usage metric.
- Added provider storage reconciliation using Bunny `storageSize`, plus cleanup of abandoned uploads and retention-delayed deletion of unreferenced assets.
- Added Moodle provider metadata fields, lifecycle tasks, capability checks and server-side WHMCS binding/release.
- Added Backup & Restore handling that never exports transient upload reservations and revalidates restored Bunny asset ownership through WHMCS.
- Added a dedicated Bunny/WHMCS CI invariant gate.
- Bunny learner playback remains disabled in beta1 until the WHMCS-gated HLS playback contract is implemented and device-tested.

## 1.2.0-beta3-m45 — 2026-09-23

- Fixes the Moodle XMLDB upgrade failure `Unknown column 'duration' in 'videoplayer_views'` when adding `watchedranges`.
- Removes physical column-order coupling from the `watchedranges` migration.
- Adds repair savepoint `2026092202` to restore missing `lastposition`, `duration` and `watchedranges` fields idempotently.
- Adds CI invariants that reject reintroduction of an `AFTER duration` dependency.
- Existing learner progress rows are preserved; no manual SQL migration is required.
- Fixes ARIA container semantics for the custom video and PDF control surfaces so Moodle HTML validation no longer reports unlabeled generic `div` warnings.
