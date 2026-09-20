# Drive Resource for Moodle

Drive Resource is a Moodle activity module that publishes learning resources stored in Google Drive through Moodle-controlled viewers and protected delivery endpoints.

The historical Moodle component name remains `mod_videoplayer` to preserve upgrade compatibility. The product name shown to administrators, teachers and learners is **Drive Resource**.

## Release

- Product: Drive Resource
- Moodle component: `mod_videoplayer`
- Release: `1.1.33-rc17-m45`
- Target: Moodle 4.5 LTS
- PHP baseline: PHP 8.1+
- Video runtime: native HTML5 Media API
- PDF runtime: local PDF.js 6.0.227
- CDN dependencies: none

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

Video playback uses the browser-native `<video>` element plus `amd/src/nativevideo.js`. Plyr and Video.js are not used.

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
