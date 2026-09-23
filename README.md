# Drive Resource for Moodle

Drive Resource is a Moodle activity module that publishes learning resources stored in Google Drive through Moodle-controlled viewers and protected delivery endpoints.

The historical Moodle component name remains `mod_videoplayer` to preserve upgrade compatibility. The product name shown to administrators, teachers and learners is **Drive Resource**.

## Release

- Product: Drive Resource
- Moodle component: `mod_videoplayer`
- Release: `1.2.0-beta1-m45`
- Target: Moodle 4.5 LTS
- PHP baseline: PHP 8.1+
- Video runtime: native HTML5 Media API
- PDF runtime: local PDF.js 6.0.227
- CDN dependencies: none
- Hardening gate: `.github/workflows/hardening.yml`

## Supported resources

Drive Resource supports Google Drive links for:

- video;
- audio;
- PDF;
- images;
- Google Docs;
- Google Sheets;
- Google Slides;
- generic files.

It also supports a PDF uploaded to Moodle private file storage.

Google Docs, Sheets and Slides are exported to PDF on the server side and rendered with the bundled PDF.js viewer. The browser is not sent a Google Drive viewer URL.

## Moodle 4.5 form compatibility

RC19 fixes the custom completion form integration introduced during RC18 hardening. Custom completion controls now use Moodle 4.5's `get_suffix()` API, so activity creation/editing no longer fails while `standard_coursemodule_elements()` builds completion settings.

## Deep cleanup status

The RC18 audit centralizes resource-type resolution in `drive::resolve_record_type()`, removes duplicate type-detection branches from runtime code, removes obsolete form plumbing, keeps the legacy download flag pinned to the protected-only architecture, and invalidates PDF cache state when an activity is deleted.

## Release hardening

The commercial release gate is defined in `docs/hardening-validation.md`. It adds explicit security, streaming, browser/device, PDF/document, Moodle API, performance/soak and packaging exit criteria on top of the normal Moodle CI matrix.

`Drive Resource Hardening Gate` continuously protects critical invariants such as Moodle-only browser URLs, no iframe/Google viewer regression, local PDF.js, bounded video-stall recovery and protected byte-range delivery.

## Architecture

```text
Google Drive link / Moodle private PDF
            |
            v
     Drive Resource activity
            |
            v
       protected.php
            |
            +-- require_login()
            +-- course module validation
            +-- context_module
            +-- capability check
            |
            v
 protected_resource_service
            |
       +----+--------------------+
       |                         |
       v                         v
local/cache stream        HTTP Range proxy
       |                         |
       +------------+------------+
                    v
          Moodle-owned viewer
                    |
                    v
                 learner
```

The learner-facing page only contains Moodle URLs such as `protected.php?id=<cmid>`. Raw Google Drive file IDs, temporary playback URLs and direct download URLs are kept on the server side.

## Video

Video playback uses the browser-native `<video>` element plus `amd/src/nativevideo.js`. Plyr and Video.js are not used. Completion is derived from the union of ranges actually reproduced, so seeking over content does not count skipped media as watched.

The player treats short `waiting` events as normal buffering, delays the loading overlay to avoid UI flicker, and automatically recovers persistent stalls. Recovery preserves the learner position, refreshes the short-lived server-side Drive playback URL through `protected.php`, and falls back to the protected source stream when required. Google URLs remain server-side throughout the recovery path.

The custom player provides:

- play/pause;
- seek;
- buffered-range indication;
- volume/mute;
- 0.5x to 2x speed;
- fullscreen;
- keyboard controls;
- responsive portrait/landscape sizing;
- loading, retry and error states;
- resume from the last saved second;
- active-time and completion tracking.

For Google Drive video, the server first attempts a short-lived progressive playback stream. If unavailable, the player retries through the protected source-file endpoint. Both paths remain behind Moodle authorization.

## PDF

PDF.js is bundled locally under:

```text
thirdpartylibs/pdfjs/pdf.min.mjs
thirdpartylibs/pdfjs/pdf.worker.min.mjs
```

The viewer provides page navigation, page number, zoom, fit, fullscreen, responsive layout, text search, smooth canvas scrolling/panning, resume and progress tracking.

Google Drive PDFs can be warmed asynchronously into `$CFG->localcachedir/mod_videoplayer/pdf/`. Cold requests are proxied immediately; they do not wait for the complete file to be cached.

## Protected streaming

Large resources are not loaded completely into PHP memory. The plugin supports the protocol elements required by HTML5 media and PDF.js, including:

- `Range`;
- `If-Range`;
- `Accept-Ranges`;
- `206 Partial Content`;
- `416 Range Not Satisfiable`;
- `Content-Range`;
- `Content-Length`;
- `Content-Type`;
- `HEAD` requests.

Upstream URLs are restricted to an explicit HTTPS Google host policy before they are proxied.

## Progress and completion

Per-user state is stored in `videoplayer_views` and includes:

- generic progress;
- completion percentage;
- active time;
- last PDF page / total pages;
- last media position in seconds;
- media duration;
- completed state;
- points when gamification is enabled.

The video and audio players save the current playback second and restore it on the next visit. Completion is integrated with Moodle Completion API. `progress_updated` and `resource_completed` events are emitted by the service layer.

## Security boundary

The security boundary is server side. Browser-side controls such as hiding download affordances, disabling the context menu and watermarking are deterrents, not DRM.

Every protected request validates the Moodle session, course module, course, module context and `mod/videoplayer:view` capability before bytes are served.

See [docs/security.md](docs/security.md).

## Installation

Install the directory as:

```text
<moodle>/mod/videoplayer
```

Then run:

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

Moodle cron must be configured for PDF cache warming/cleanup.

See [docs/installation.md](docs/installation.md).

## Development and QA

The repository includes a Moodle 4.5 CI workflow covering PHP 8.1, 8.2 and 8.3 with MariaDB and PostgreSQL jobs. Production AMD files live in `amd/build/`; sources live in `amd/src/`.

Before release, run the manual regression checklist in [docs/manual-test-checklist.md](docs/manual-test-checklist.md), especially the known-working Google Drive video path.

## Documentation

- [Architecture](docs/architecture.md)
- [Developer guide](docs/developer-guide.md)
- [Security](docs/security.md)
- [Installation](docs/installation.md)
- [Database](docs/database.md)
- [Manual test checklist](docs/manual-test-checklist.md)
- [RC17 refactor report](docs/refactor-report.md)

## License

GNU GPL v3 or later.

Bundled third-party components and their licenses are declared in `thirdpartylibs.xml`.

## 1.2.0-beta1: WHMCS-gated Bunny Stream ingestion

The 1.2 line introduces Bunny Stream as a managed video provider while preserving Google Drive and protected local PDF support.

For Bunny uploads, **Bunny management credentials never exist in Moodle**. Moodle stores only the WHMCS gateway URL, WHMCS service ID and a service-scoped gateway token. The upload flow is:

```text
Teacher browser
  -> Moodle capability/session check
  -> WHMCS Media Gateway
       -> active WHMCS service check
       -> exact Moodle site binding
       -> HMAC + timestamp + replay nonce validation
       -> quota / overage reservation
       -> Bunny Stream management API
  <- short-lived video-scoped TUS authorization
Teacher browser
  -> Bunny Stream TUS endpoint directly
```

Video bytes therefore do not traverse Moodle PHP or WHMCS. Large uploads are chunked and resumable, and long uploads can renew the short-lived TUS authorization without creating a second Bunny asset or reserving quota twice.

The commercial quota model is controlled in WHMCS. The provisioning module defaults to **7 GB included storage**, supports soft overage, and exposes a `video_storage_gb` snapshot metric for WHMCS Usage Billing. The gateway reserves concurrent uploads before issuing a Bunny authorization so simultaneous teachers cannot overrun quota based on stale usage.

This beta currently covers **provider provisioning, direct upload, accounting, lifecycle binding/release, Backup & Restore reconciliation, and retention**. Learner-facing Bunny HLS playback is intentionally not enabled yet; a Bunny-backed activity displays a processing/provider placeholder until the secure playback phase is completed and validated.

The WHMCS companion source is maintained under `integrations/whmcs/` in the development repository. It must be deployed to WHMCS separately from the Moodle plugin package.
