# Drive Resource hardening and validation phase

## Purpose

This phase converts the green Moodle 4.5 refactor into a release candidate that can be promoted to a commercial production release with evidence, not assumptions.

The phase starts from the merged `1.1.33-rc17-m45` baseline. No new feature work is accepted while a release-blocking defect is open. The priority order is security, correctness, resilience, compatibility, performance and release operations.

## Release policy

A defect is **P0 / release blocker** when it can cause any of the following:

- authorization bypass or resource access outside Moodle capability checks;
- SSRF or arbitrary upstream proxying;
- exposure of a raw Google Drive file id, direct Google URL, signed playback URL or Google viewer UI in plugin-owned presentation;
- corrupt, incomplete or incorrect byte-range responses;
- unrecoverable playback stalls under normal network interruption/recovery;
- upgrade or install failure on the supported Moodle 4.5 target;
- data-loss or incorrect user progress/completion transitions;
- a failure in the required CI or hardening gates.

P0 defects block merge to the release branch and block tagging.

## Workstreams

### H1 — Security boundary

Required evidence:

- logged-out `protected.php` access is denied or redirected by Moodle;
- enrolled/capability checks are enforced through `activity_context`;
- users without `mod/videoplayer:view` cannot fetch resource bytes;
- rendered HTML and browser requests contain only Moodle-owned protected resource URLs;
- no browser-controlled arbitrary upstream URL parameter exists;
- upstream hosts remain restricted by `upstream_url_policy`;
- source fallback and stream refresh remain inside the same authorization boundary;
- Privacy API export/delete paths are exercised with user data.

### H2 — Streaming and failure recovery

Required evidence for the known-good Drive video and at least one additional large MP4:

- cold start, warm start and repeated start;
- at least 25 forward/backward seeks;
- `Range`, `206`, `416`, `Content-Range`, `Content-Length`, `HEAD` and `If-Range` behavior;
- 30-minute continuous playback without an unrecovered stall;
- network interruption/throttling that causes `waiting`/buffer underrun and then recovers near the same second;
- signed progressive stream refresh before protected source fallback;
- abandoned browser range requests terminate server-side rather than occupying PHP workers indefinitely;
- no whole-video buffering into PHP memory.

### H3 — Browser and device compatibility

Physical-device gate where possible:

| Platform | Required scenarios |
| --- | --- |
| Chrome desktop | start, pause, seek, speed, volume, fullscreen, recovery, resume |
| Firefox desktop | start, pause, seek, speed, fullscreen, recovery, resume |
| Safari macOS | start, seek, fullscreen, recovery, resume |
| Android Chrome | playsinline, rotation, lock/unlock, seek, recovery, resume |
| iPhone/iPad Safari | playsinline, native fullscreen, rotation, lock/unlock, seek, recovery, resume |

The release is blocked if the known-good video regresses on the supported mobile browsers.

### H4 — PDF and document validation

Validate Drive PDF, Moodle-local PDF, Google Doc, Sheet and Slide export paths:

- local PDF.js only; no CDN;
- first page display and resume page;
- previous/next, page number, zoom, fit, fullscreen and search;
- responsive layout on desktop and mobile;
- cold Drive PDF does not wait for full cache warming;
- local/cache range tests include closed, open-ended and suffix ranges;
- cache HIT path returns the same document bytes/quality as the uncached path;
- Google viewer controls and direct URLs remain absent.

### H5 — Progress, completion and Moodle APIs

Validate:

- video/audio last position, duration, active time and completion percentage;
- PDF page state and active time;
- resume after reload/login renewal;
- `progress_updated` event;
- `resource_completed` only on the first incomplete-to-complete transition;
- Moodle Completion API threshold behavior;
- Backup & Restore with and without user data;
- Privacy API metadata/export/delete;
- teacher report correctness and pagination.

### H6 — Performance and soak

Run on staging with production-like PHP-FPM/web-server settings.

Minimum evidence:

- 20 concurrent protected media sessions for 15 minutes with zero plugin-generated 5xx responses;
- 50 concurrent mixed range requests as a stress pass, recording error rate, p95 response latency, PHP worker saturation, CPU and memory;
- PHP memory does not scale with source media file size;
- session lock release allows the same learner to navigate Moodle while video is streaming;
- PDF cache warming runs asynchronously through cron;
- no persistent temporary/cache-file growth after cleanup tasks.

Performance results are environmental and must be recorded with server specification, PHP-FPM limits, reverse proxy and network conditions. Do not publish generic capacity claims from a single server.

### H7 — Packaging and operational release gate

Before promotion from RC:

- Moodle 4.5 CI green on PHP 8.1, 8.2 and 8.3 with MariaDB and PostgreSQL;
- `Drive Resource Hardening Gate` green;
- clean install on Moodle 4.5;
- upgrade from the currently deployed RC succeeds without XMLDB errors;
- Moodle caches purged and cron executed after upgrade;
- package contains local PDF.js and compiled AMD assets;
- README, CHANGELOG and all architecture/developer/security/installation documentation match the release;
- no P0 issue remains open;
- final manual evidence is recorded in `docs/manual-test-checklist.md`.

## Automated hardening gate

`.github/workflows/hardening.yml` runs a static release-invariant guard on hardening branches, pull requests to `main`, manual dispatch and a weekly schedule.

The guard fails if critical architecture invariants regress, including:

- Google upstream hosts appearing in browser-facing templates/AMD source;
- iframe/Google preview paths returning to presentation code;
- CDN/Plyr/Video.js browser dependencies returning;
- protected access boundary removal;
- arbitrary URL input on `protected.php`;
- loss of Range or stalled-transfer controls;
- unbounded video recovery;
- missing local PDF.js or production AMD bundles.

This gate complements, not replaces, the full Moodle Plugin CI matrix.

## Evidence record

For every manual validation cycle record:

- date and commit SHA;
- Moodle version/build;
- PHP version;
- database and version;
- browser/device versions;
- web server/reverse proxy;
- test Drive resource sizes/types;
- pass/fail per scenario;
- captured HTTP status/headers for Range tests;
- load-test concurrency, duration and error rate;
- unresolved defects with GitHub issue links.

## Exit criteria

The hardening phase is complete only when all automated gates are green, every P0 criterion has evidence, the manual checklist is signed off on staging, and no open release-blocking defect remains.

Only then should `MATURITY_RC` be considered for promotion to a stable release.


### HTML5 completion integrity

- Seek from the beginning to near the end and confirm skipped media does not increase completion.
- Watch disjoint video segments and confirm only the union of actually reproduced ranges is counted.
- Reload the activity and confirm resume position and watched completion persist independently.
- Verify older audio/PDF progress remains unaffected by the video watched-range field.
