# Changelog

All notable changes to Drive Resource are documented here. The Moodle component remains `mod_videoplayer` for upgrade compatibility.

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
