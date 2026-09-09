# Drive Resource installation

## Supported platform

- Moodle 4.5, 5.0, 5.1 or 5.2.
- Minimum core version `2024100700`.
- PHP 8.2 or 8.3.
- PHP cURL and the standard extensions required by the installed supported Moodle branch.
- HTTPS, working Moodle cron and writable `$CFG->localcachedir`.

Moodle 4.5 is supported from release `1.1.30-beta`. Moodle 4.4 and older remain unsupported.

## Install or upgrade

Copy the complete plugin directory to:

```text
mod/videoplayer
```

Verify these required production files exist:

```text
mod/videoplayer/version.php
mod/videoplayer/protected.php
mod/videoplayer/templates/pdfjs.mustache
mod/videoplayer/amd/build/pdfjsloader.min.js
mod/videoplayer/amd/build/pdfviewer.min.js
mod/videoplayer/thirdpartylibs/pdfjs/pdf.min.mjs
mod/videoplayer/thirdpartylibs/pdfjs/pdf.worker.min.mjs
mod/videoplayer/thirdpartylibs/plyr/plyr.css
mod/videoplayer/thirdpartylibs/plyr/plyr.min.js
```

From the Moodle application root run:

```bash
php admin/cli/maintenance.php --enable
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
php admin/cli/maintenance.php --disable
```

For cPanel PHP 8.3:

```bash
/opt/cpanel/ea-php83/root/usr/bin/php admin/cli/maintenance.php --enable
/opt/cpanel/ea-php83/root/usr/bin/php admin/cli/upgrade.php --non-interactive
/opt/cpanel/ea-php83/root/usr/bin/php admin/cli/purge_caches.php
/opt/cpanel/ea-php83/root/usr/bin/php admin/cli/maintenance.php --disable
```

After replacing JavaScript, Mustache or CSS assets:

1. reset PHP OPcache or restart the active PHP-FPM service;
2. purge Cloudflare, NGINX or other reverse-proxy caches for `/mod/videoplayer/`;
3. clear stored site data in the affected mobile browser;
4. reopen the activity in a new authenticated session.

## Upgrade to 1.1.27-beta

Release `1.1.27-beta` restores the local PDF.js viewer as the only production PDF renderer.

The `2026080600` database upgrade:

- changes the `videoplayer.displaymode` default to `pdfjs`;
- converts existing `standard`, `ebook`, `book`, empty or other legacy values to `pdfjs`;
- preserves activity URLs, local files, progress, completion and rewards.

The activity form no longer offers PageFlip or legacy book modes. Existing activities do not need to be recreated.

## Google Drive access requirements

For protected Google Drive video or PDF delivery, the file must:

- be accessible through its sharing link unless a future authenticated Drive API integration is configured;
- allow viewers to retrieve the file bytes;
- remain a normal Drive blob file for video, audio, image or PDF delivery;
- retain the complete sharing URL when it includes a `resourcekey`.

Drive Resource cannot bypass an owner or organisation policy that disables file retrieval. A file requiring Google account login returns an access page rather than media bytes and is rejected by the protected proxy.

Do not remove sharing query parameters before saving the activity. Drive Resource keeps required identifiers server-side and never renders them to the learner.

## Local libraries

All runtime viewer dependencies are local. No CDN is required or permitted.

```text
thirdpartylibs/pdfjs/pdf.min.mjs
thirdpartylibs/pdfjs/pdf.worker.min.mjs
thirdpartylibs/plyr/plyr.css
thirdpartylibs/plyr/plyr.min.js
```

Native HTML5 video controls remain available when Plyr enhancement cannot initialise.

## PDF.js ES-module loading

PDF.js is an ES module and must not be requested as a RequireJS/AMD module.

```text
pdfviewer
↓
mod_videoplayer/pdfjsloader
↓
<script type="module" src="pdf.min.mjs">
↓
window.pdfjsLib validation
↓
pdf.worker.min.mjs
```

The production bundle must exist at:

```text
amd/build/pdfjsloader.min.js
```

A valid bundle creates a native module script and does not contain `_systemImportTransformerGlobalIdentifier`.

## PDF viewer capabilities

Every PDF uses one responsive PDF.js canvas viewer with:

- previous and next page;
- current and total page number;
- zoom in and out;
- fit to screen;
- fullscreen;
- mobile swipe navigation;
- resume from the last saved page;
- active-time and completion tracking;
- optional watermark and reward integration.

No request to `thirdpartylibs/pageflip/` should appear in the browser network panel.

## Cron and PDF cache

Cold Google Drive PDFs proxy requested ranges immediately and queue a deduplicated ad-hoc task to warm the complete cache.

```text
$CFG->localcachedir/mod_videoplayer/pdf/
```

Cron must process ad-hoc tasks frequently. The PHP/web user must be able to create directories and files under `$CFG->localcachedir`.

## Functional validation

After installation or upgrade, verify:

1. Administration reports Drive Resource `1.1.30-beta` and version `2026090900`.
2. A teacher can create and edit a Drive Resource.
3. The saved `displaymode` is `pdfjs`.
4. An enrolled learner can open the activity.
5. Guest and unauthorised users are denied.
6. A local protected PDF renders through `protected.php`.
7. A Google Drive PDF renders through `protected.php`.
8. Previous/next, page counter, zoom, fit and fullscreen work.
9. Swipe navigation works on a physical phone without blocking normal vertical page scrolling.
10. Portrait/landscape rotation keeps the viewer usable and preserves the current page.
11. The browser loads `pdf.min.mjs` and `pdf.worker.min.mjs` from the plugin origin.
12. No request is made for PageFlip or a legacy book viewer.
13. Raw Google Drive identifiers and URLs are absent from learner HTML.
14. Cold PDF ranges begin without waiting for the complete cache warm-up.
15. Later requests use the protected verified cache.
16. A protected video starts, reports duration, pauses and seeks.
17. Progress and completion persist.
18. Backup and Restore preserve configuration and local files.
19. Privacy export/delete completes.
20. Standard and third-party course formats remain fully interactive.

## Mobile PDF.js troubleshooting

### JavaScript source appears instead of the PDF

This indicates an obsolete plugin build, cached viewer route or old PageFlip execution path.

Verify:

```text
Drive Resource release: 1.1.30-beta
Plugin version: 2026090900
```

Then:

1. run the Moodle upgrade;
2. purge Moodle caches;
3. reset PHP OPcache;
4. purge CDN/reverse-proxy caches;
5. clear the mobile browser's stored site data;
6. confirm no PageFlip request appears in the network panel.

The document response from `protected.php` must begin with:

```text
%PDF-
```

and return:

```text
Content-Type: application/pdf
Accept-Ranges: bytes
```

### `workerSrc` or PDF.js initialization error

The browser should load:

```text
mod/videoplayer/amd/build/pdfjsloader.min.js
mod/videoplayer/thirdpartylibs/pdfjs/pdf.min.mjs
mod/videoplayer/thirdpartylibs/pdfjs/pdf.worker.min.mjs
```

`pdf.min.mjs` must load as a JavaScript module, not through RequireJS. A missing or invalid module should produce the controlled PDF viewer error instead of exposing source code or an unhandled exception.

## Protected video troubleshooting

A player showing `00:00 / 00:00` means the browser did not receive usable media metadata. Inspect the authenticated protected endpoint:

```text
mod/videoplayer/protected.php?id=<cmid>
```

Expected successful response:

```text
HTTP 200 or 206
Content-Type: video/*
X-Drive-Resource-Status: MEDIA
```

Upstream failures should return a non-success status such as `502` and must not expose upstream HTML bodies as successful media.

## Range validation

Authenticated requests to `protected.php` should support:

```text
Range: bytes=0-1
Range: bytes=<middle>-<middle+chunk>
```

Expected valid response:

```text
HTTP 206
Accept-Ranges: bytes
Content-Range: bytes start-end/total
Content-Length: requested-length
```

Unsatisfiable ranges must return `416` with `Content-Range: bytes */<size>` where the total size is known.

## Automated validation

`.github/workflows/moodle-supported-ci.yml` tests Moodle `MOODLE_405_STABLE` and `MOODLE_500_STABLE` with:

- PHP 8.2 and 8.3;
- MariaDB 10.11 and PostgreSQL 15;
- PHP lint, Moodle Coding Style, PHPDoc, plugin validation and XMLDB savepoints;
- Mustache, Grunt/AMD and PHPUnit;
- PDF.js native-ESM loader assertions;
- PDF.js-only production-path assertions.

A green workflow is required before commercial release, but staging and physical-device tests remain mandatory.

## Recovery after a failed upgrade

Do not delete courses, activities or database tables. Keep maintenance mode enabled, restore the previous complete `mod/videoplayer` directory, purge caches and inspect the first PHP/XMLDB error. Drive Resource does not require editing themes or course-format plugins.

## Upgrade verification for 1.1.28-beta

After upgrade and cache purge, test a protected MP4 on Android Chrome and iPhone Safari. Seeking to the middle must return `206 Partial Content`, preserve the selected timestamp and avoid a restart at zero. Test a PDF at 100% and above 100%; it must open at page 1, avoid a large empty area below the page and allow horizontal scrolling when zoomed.

## Verification for 1.1.29-beta

After upgrading and purging caches, open a portrait and landscape PDF on Android Chrome and iPhone Safari. At fit-to-screen, confirm the page is centred vertically and horizontally. Increase zoom until both axes overflow and confirm the top-left document origin remains reachable, scrolling works in both directions, controls stay fixed and exiting fullscreen preserves the current page.

## Moodle 4.5 verification for 1.1.30-beta

On a clean Moodle 4.5 staging site running PHP 8.2 or 8.3, install the same plugin package used for Moodle 5.x. Confirm installation and upgrade complete without XMLDB or developer-debug warnings, create a Drive Resource, open a protected PDF and video, test byte-range seeking, progress/completion, Backup/Restore and Privacy API operations. No separate Moodle 4.5 package is required.
