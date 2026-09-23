# Drive Resource architecture

## Scope

Drive Resource is a Moodle 4.5 activity module that presents Google Drive learning resources without delegating the learner experience to the Google Drive viewer. The component name remains `mod_videoplayer`; the architecture is resource-oriented rather than video-only.

## Design goals

1. Moodle owns authorization and the browser-visible resource URL.
2. Large files are streamed; they are not loaded completely into PHP memory.
3. Video/audio use native HTML5 media APIs.
4. PDF-like content uses locally bundled PDF.js.
5. Google Drive identifiers and temporary upstream URLs remain server-side.
6. Progress/completion are handled by a service layer and Moodle APIs.
7. Resource-specific JavaScript has one responsibility and one progress writer.

## Request path

```text
view.php
  -> activity_context
  -> resource_descriptor
  -> resource_view
  -> renderer + type-specific Mustache template
  -> AMD viewer/player
  -> protected.php
       -> activity_context
       -> resource_descriptor
       -> protected_resource_service
            -> protected_stream (local/cache)
            -> drive_stream_resolver (video progressive stream)
            -> drive::protected_content_url (server-side fallback/export)
            -> http_range_proxy
```

### Access boundary

`classes/local/access/activity_context.php` centralizes:

- course-module lookup;
- course lookup;
- activity-instance lookup;
- `require_login()`;
- `context_module` resolution;
- capability enforcement.

`protected.php` and the external progress API use this boundary rather than duplicating access checks.

### Resource model

`classes/local/resource/resource_descriptor.php` normalizes source and type. It is the only object the presentation/service layers need to determine whether the resource is video, audio, image, PDF-like or generic.

The descriptor generates only Moodle `protected.php` URLs for templates. It does not provide a browser-facing Google URL.

## Video

`templates/video.mustache` contains an HTML5 `<video>` element without native browser controls. `amd/src/nativevideo.js` owns the Drive Resource control surface and uses the HTML5 Media API.

Playback order:

```text
protected.php?stream=transcoded
  -> drive_stream_resolver
  -> progressive MP4 playback URL
  -> http_range_proxy

if unavailable/error:

protected.php?stream=source
  -> drive::protected_content_url
  -> http_range_proxy
```

The browser sees only Moodle protected URLs.

Persistent buffering is handled as a recovery state rather than an immediate fatal error. `nativevideo.js` waits through short buffer underruns, then reloads the protected progressive endpoint with a server-side refresh flag while preserving `currentTime`. `drive_stream_resolver` bypasses its short-lived signed-URL cache for that recovery request. If the refreshed progressive stream remains unavailable, the player switches to the protected source stream. No upstream Google URL is returned to the browser.

The current progressive resolver uses the public playback endpoint used by the Drive web client. It is intentionally isolated in `drive_stream_resolver` because it is an upstream compatibility integration and may need maintenance when Google changes its web playback service.

## Audio

`templates/audio.mustache` uses native `<audio controls>` with the source pointing to `protected.php`. `amd/src/nativeaudio.js` tracks active time and resume position.

## PDF-like resources

The following types are PDF-like:

- PDF;
- Google Docs;
- Google Sheets;
- Google Slides.

Docs/Sheets/Slides are converted to a server-side Google export URL and proxied as PDF. The browser renders the protected Moodle endpoint using locally bundled PDF.js.

`amd/src/pdfviewer.js` provides page navigation, zoom, fit, fullscreen, search and progress persistence.

### PDF cache

A cold Google Drive PDF is proxied immediately. When caching is enabled, an ad-hoc `precache_pdf` task warms the complete PDF into:

```text
$CFG->localcachedir/mod_videoplayer/pdf/
```

Subsequent requests can be served by `protected_stream` from the local cache. `cleanup_pdf_cache` removes stale cache artifacts on schedule.

## Byte streaming

`http_range_proxy` forwards a single validated byte range and streams received chunks directly to the response. It preserves safe protocol metadata required by media clients and PDF.js.

`protected_stream` implements equivalent range handling for trusted local/cache files.

Neither path uses `file_get_contents()` to buffer an entire learning resource before delivery.

## Progress and completion

```text
video -> nativevideo.js ----+
audio -> nativeaudio.js ----+--> mod_videoplayer_save_progress
PDF   -> pdfviewer.js -------+          |
generic -> progress.js ------+          v
                                 progress_service
                                      |
                        +-------------+--------------+
                        |                            |
                 videoplayer_views          Moodle Completion API
                        |
                 progress_updated
                 resource_completed
```

Video/audio persist `lastposition`, `duration` and active `timespent`. PDF persists `lastpage`, `totalpages` and active time. Generic resources use the generic progress tracker only.

## Database compatibility

Some legacy activity columns are retained in the schema and backup format to make upgrades/restores from older `mod_videoplayer` installations safe. New runtime code does not depend on obsolete player/viewer fields.

## Third-party dependencies

Only PDF.js is required by the learner viewer and is bundled locally. Video/audio have no player-library dependency. No CDN is used.

## Hardening gate

The architecture is guarded by `.github/scripts/release-invariants.sh` and `.github/workflows/hardening.yml`. These checks intentionally encode non-negotiable product invariants: browser-facing presentation must remain Moodle-only, Google viewer/iframe paths must not reappear, protected delivery must retain the centralized access boundary, and the HTTP proxy/player must retain byte-range and bounded stall-recovery behavior.

The complete promotion criteria are maintained in `docs/hardening-validation.md`.

## Canonical resource typing

Resource source/type normalization is owned by `classes/local/drive.php`. Runtime code must call `drive::resolve_record_type()`; it must not reimplement `auto` handling with direct calls to `drive::detect_type()`.

This prevents divergent behavior for opaque Drive sharing links. In particular, legacy/opaque `/file/d/{id}/view` records using `type=auto` retain the historical video fallback consistently in the learner view, course index, cache task and lifecycle callbacks.

`drive::RESOURCE_TYPES` is the canonical registry used by form options and persistence validation. `drive::SOURCE_GOOGLEDRIVE` and `drive::SOURCE_LOCALPDF` are the canonical source identifiers.


## Seek-safe HTML5 video completion

Video resume and video completion are intentionally separate concerns. `lastposition` records where playback should resume, while `watchedranges` stores a bounded canonical JSON union of media intervals that were actually reproduced. The browser adds only contiguous HTML5 playback intervals; seeking resets the contiguous sample. The server merges and clamps the ranges to the detected duration and derives completion from unique watched seconds rather than the furthest seek position.


## Moodle completion form integration

Custom activity-completion controls are namespaced using Moodle 4.5's `core_completion\form\form_trait::get_suffix()` API. Form preprocessing, postprocessing, rule creation and validation must concatenate the returned suffix to the base field name. The plugin must not call a non-core `get_suffixed_name()` helper.

## Managed Bunny Stream provider

Bunny video ingestion is a separate provider path; it does not use `protected.php` or the Google Drive resolver.

```text
Moodle activity form
  -> create_bunny_upload external function
  -> whmcs_gateway_client
  -> WHMCS Drive Resource Media Gateway
       -> authenticate service + Moodle site
       -> reserve quota atomically
       -> create Bunny video using WHMCS-only API key
       -> return scoped TUS signature
  -> teacher browser
       -> Bunny TUS upload directly
       -> complete_bunny_upload
  -> Moodle save
       -> bind_bunny_asset adhoc task
       -> WHMCS asset reference
```

The browser never receives the Bunny management API key. The presigned upload material is restricted to one Bunny library, one video GUID and an expiration timestamp. Moodle pins the TUS host to `video.bunnycdn.com` before returning authorization to the uploader.

WHMCS is the authoritative commercial control plane for managed video. It owns service state, quota, reservations, provider asset ownership, retention and usage accounting. Moodle owns course/context authorization and the activity-to-provider reference.

### Storage accounting

Quota decisions use:

```text
projected = provider-accounted usage + pending reservations + incoming source size
```

A reservation is created under a database lock before Bunny authorization is emitted. On upload completion, the reservation is converted into usage. Initial accounting may use source bytes while Bunny is still processing; WHMCS cron subsequently reconciles each asset to Bunny's provider-reported `storageSize`, which captures encoded representations as they become available.

### Provider asset lifecycle

A Bunny asset can have multiple Moodle references. Deleting or replacing an activity releases only that reference. Physical provider deletion is deferred until no active references remain and the WHMCS retention period expires. Course restore never trusts a copied provider GUID by itself: the restored reference is reconciled through WHMCS and is accepted only when the asset belongs to the same WHMCS service tenant.

## Resilient progress-schema evolution

Upgrade code treats database column order as non-contractual. Runtime behavior depends on field names and types, not on whether MySQL places one field physically after another. The `2026092202` repair migration verifies `lastposition`, `duration` and `watchedranges` independently and creates only missing fields.

This makes upgrades idempotent for sites that installed pre-release builds with partially applied progress schemas while preserving the canonical fresh-install definition in `db/install.xml`.


## Runtime compatibility during schema recovery

Course-cache callbacks must remain callable even when a pre-release installation has an advanced plugin version but a partially applied database schema. `videoplayer_get_coursemodule_info()` therefore builds its completion-field projection from columns that physically exist and applies backward-compatible defaults until XMLDB repair savepoint `2026092204` restores the canonical schema.

This compatibility path is temporary runtime protection, not a substitute for the database migration.


## Elearning Stream provider flow

The customer-facing provider name is **Elearning Stream**. The persisted source key and internal adapter class retain the historical `bunnystream` / `bunny_stream` identifiers for upgrade compatibility.

Existing-video URL flow:

```text
Teacher pastes Elearning Stream URL
        -> Moodle validates HTTPS/provider URL shape
        -> extract video GUID only
        -> WHMCS authenticated asset-import endpoint
        -> provider-library verification
        -> service ownership + quota/accounting
        -> normal asset bind lifecycle
```

The original pasted URL is form-only data and is removed before Moodle DML persistence.


### Elearning Stream protected playback

```text
Learner HTML5 player
      -> Moodle protected.php
      -> activity_context authorization
      -> protected_resource_service
      -> WHMCS service-scoped playback authorization
      -> short-lived signed provider MP4 URL (server-side only)
      -> http_range_proxy
      -> learner
```

The browser-facing `<video>` source remains a Moodle URL. WHMCS verifies that the asset belongs to the requesting service and signs a short-lived MP4 fallback URL. Moodle caches the authorization briefly and forwards byte ranges through the existing protected proxy. A player recovery request with `refresh=1` invalidates the cached authorization before retrying.


### Elearning Stream configuration boundary

Moodle does not contain provider management credentials. Its Elearning Stream control-plane configuration consists only of the WHMCS addon URL, provisioned WHMCS service ID and service-scoped token.

Activity-form validation checks that those three values exist before an Elearning Stream URL/import or upload workflow can proceed. This keeps configuration failures outside the instance persistence path and prevents partial activity creation.


## WHMCS multi-tenant backend architecture

The commercial control plane is independent from the Moodle activity lifecycle:

```text
                         WHMCS 9
                           |
              +------------+-------------+
              |            |             |
          service 101   service 102   service 103
          customer A    customer B    customer C
          Moodle A      Moodle B      Moodle C
          quota/token   quota/token   quota/token
              |            |             |
              +------+-----+-------------+
                     |
              BackendRegistry
                  /       \
                 /         \
      Elearning Stream     S3-compatible
      active backend       reserved/future
```

A WHMCS service is the tenant/security/accounting boundary. The service row stores `backend_key` and `backend_profile`; credentials remain backend-owned in WHMCS and are not copied to Moodle.

Current managed-video endpoints require backend capabilities before they execute. Elearning Stream is lazy-loaded only when a matching service invokes video upload/playback work. Daily maintenance also filters by backend, so a future S3-only tenant will not require Elearning Stream credentials.

The S3-compatible registry entry is intentionally non-provisionable. A future adapter can implement multipart/direct upload, signed protected delivery, authoritative object-size reconciliation and lifecycle deletion while reusing the existing service id, token authentication, quota, overage and billing model.


### Serverless WHMCS provisioning

The commercial service is a logical control-plane tenant, not a workload hosted on a WHMCS server. The provisioning module therefore declares `RequiresServer=false`.

`CreateAccount` derives the tenant from the WHMCS service itself, generates a cryptographically random service token, persists only its hash in the gateway tenant table, and stores the recoverable token in WHMCS's protected service password property for administrator handoff to Moodle.


## WHMCS customer self-service and transfer metering

Each WHMCS service exposes a client dashboard backed only by rows scoped to that `service_id`. It presents connection state, storage/quota, current-month transfer and a paginated video library.

The connection-validation flow is server-to-server:

```text
WHMCS Client Area
    -> ValidateMoodleConnection
    -> MoodleConnectionProbe
    -> POST https://moodle/mod/videoplayer/gateway-status.php
       headers: service/site/timestamp/nonce/HMAC
    -> Moodle validates configured service id + wwwroot + token HMAC
    -> replay nonce cache
    -> connected / failed
```

No browser session or learner credential is used by the probe. Redirects are not followed; the configured Moodle URL must be the exact public `$CFG->wwwroot`.

Per-service transfer is measured at the actual byte-delivery boundary:

```text
Elearning Stream signed MP4
    -> Moodle http_range_proxy
    -> bytes actually echoed to browser
    -> transfer_meter
    -> videoplayer_transfer_events
    -> scheduled sync every 5 minutes
    -> WHMCS authenticated usage-report.php
    -> idempotent report id
    -> service transfer_period / transfer_bytes
    -> video_transfer_gb monthly Usage Billing metric
```

HEAD responses, rejected content, discarded range responses and bytes never emitted to the browser are not counted. A Service ID change cannot reattribute queued events from an old service to a new one.

Client video deletion checks both tenant ownership and active Moodle references before invoking the provider delete operation. URL reassignment is blocked while active references exist; a content-bearing site/domain migration requires a dedicated migration workflow.
