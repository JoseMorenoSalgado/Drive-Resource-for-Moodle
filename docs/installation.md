# Drive Resource installation and upgrade

## Supported platform for 1.1.33-rc17-m45

- Moodle 4.5 LTS
- PHP 8.1+
- PHP cURL extension
- MariaDB/MySQL or PostgreSQL supported by Moodle 4.5
- HTTPS for production
- Moodle cron enabled
- writable `$CFG->localcachedir`

This compatibility package declares Moodle 4.5 only. Do not install it on Moodle 5.x without a separately tested release.

## Filesystem installation

Place the plugin at:

```text
<moodle-root>/mod/videoplayer
```

Verify the local PDF.js files exist:

```text
mod/videoplayer/thirdpartylibs/pdfjs/pdf.min.mjs
mod/videoplayer/thirdpartylibs/pdfjs/pdf.worker.min.mjs
```

There is no Plyr, Video.js, StPageFlip or CDN requirement.

## Upgrade commands

On staging first:

```bash
php admin/cli/maintenance.php --enable
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
php admin/cli/maintenance.php --disable
```

If the site already runs rc16, rc17 applies database version `2026092013` and adds `lastposition` and `duration` to `videoplayer_views`.

Do not manually edit Moodle database columns for this release; use the upgrade script.

## Web-server/PHP considerations

Large video/PDF delivery is streamed by PHP. Ensure:

- reverse proxy and web-server timeouts are compatible with long media requests;
- PHP cURL is enabled;
- output compression/buffering rules do not rewrite range responses;
- HTTPS certificate validation works from the Moodle server;
- Moodle/PHP can write `$CFG->localcachedir`;
- reverse proxies do not buffer or rewrite byte-range video responses in a way that prevents timely delivery;
- infrastructure timeouts are longer than normal range requests but do not mask a broken upstream indefinitely; the plugin itself aborts effectively stalled upstream transfers.

The plugin does not require increasing PHP memory to the size of a video because the proxy streams chunks rather than buffering the full object.

## Google Drive sharing

The Moodle server must be able to access the linked Drive resource using the sharing permissions configured for that resource. A link that only works for a different authenticated Google account cannot be made accessible by the plugin without an authenticated Drive API integration.

Paste a normal supported Drive/Docs sharing URL into the activity. The learner must not be given the direct source URL separately.

## Post-upgrade validation

After installing rc17:

1. purge Moodle caches;
2. hard-refresh the browser;
3. open the exact video known to work in rc15/rc16;
4. verify play, seek, fullscreen, speed and resume;
5. while playing, throttle/interrupt the network long enough to trigger buffering and confirm playback recovers near the same second without exposing Google UI;
6. verify the page source/network requests show Moodle `protected.php`, not a Google viewer;
7. test a PDF and a Google Doc/Sheet/Slide export;
8. run cron so PDF cache tasks can execute;
9. verify teacher progress report;
10. test backup/restore and Privacy API on staging.

Use `docs/manual-test-checklist.md` for the complete gate.

## PDF cache

With caching enabled, complete PDFs are stored beneath:

```text
$CFG->localcachedir/mod_videoplayer/pdf/
```

This directory must be writable by the PHP/cron user and must remain outside the public web root.

Cache diagnostics may include `X-Drive-Resource-Cache` values such as `HIT`, `MISS`, `MISS_QUEUED`, `LOCAL` or `BYPASS`.

## Rollback

Before upgrading a commercial production site, take a database backup and code backup. If a regression is found, restore both database and code from the same pre-upgrade point; do not simply downgrade plugin code after a schema upgrade and assume the database is compatible.

## Hardening validation before production promotion

For a commercial production rollout, installation success is necessary but not sufficient. Execute the complete gate in `docs/hardening-validation.md` on staging, including physical mobile-device playback, network interruption/recovery, byte-range protocol checks, long-play soak, PDF/document export paths, Privacy/Backup/Completion verification and production-like concurrency measurements.

Record the tested commit SHA and environment. Do not promote an RC build while any release-blocking hardening criterion remains unresolved.
