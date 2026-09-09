# Drive Resource manual test checklist

Run these tests on a staging Moodle site with developer debugging enabled before a commercial release.

## Environment

- Moodle 4.5 or Moodle 5.x target staging version installed.
- PHP 8.2 or 8.3.
- PHP cURL enabled.
- Moodle cron and ad-hoc tasks running.
- `thirdpartylibs/pdfjs/pdf.min.mjs` installed.
- `thirdpartylibs/pdfjs/pdf.worker.min.mjs` installed.
- `amd/build/pdfjsloader.min.js` installed.
- `amd/build/pdfviewer.min.js` installed.
- Plyr installed locally.
- Browser, Moodle, PHP OPcache and reverse-proxy caches purged before cold-cache tests.

## Fresh installation

Run this section once on Moodle 4.5 and once on a supported Moodle 5.x site.

- Install the plugin from a clean database state.
- Confirm no XMLDB errors.
- Confirm `thirdpartylibs.xml` is accepted.
- Confirm capabilities are available in role definitions.
- Confirm the activity appears as Drive Resource.
- Confirm `videoplayer.displaymode` defaults to `pdfjs`.

## Moodle 4.5 compatibility smoke test

- Confirm Administration accepts the plugin with minimum core build `2024100700`.
- Create and edit a Google Drive PDF activity.
- Create and edit a protected video activity.
- Verify `protected.php` authorisation and byte ranges.
- Verify progress, completion, Backup/Restore and Privacy API behavior.
- Repeat with developer debugging enabled and confirm no new warnings.

## Upgrade from 1.1.26-beta

- Install the previous release.
- Create Google Drive PDF, local PDF and video activities.
- Ensure test PDF activities include legacy `ebook`, `book` and `standard` display-mode values.
- Upgrade to `1.1.27-beta`.
- Confirm the Moodle upgrade completes without warnings.
- Confirm administration reports `1.1.27-beta` and `2026080600`.
- Confirm all existing PDF activities now store or resolve to `pdfjs`.
- Confirm activities, files, progress, completion and rewards remain present.

## Authentication and authorisation

- Access `protected.php?id=<cmid>` while logged out: denied.
- Access as guest: denied by default.
- Access as an unenrolled user without capability: denied.
- Access as an enrolled learner: allowed according to role.
- Access as a teacher: allowed according to role.
- Confirm protected viewer HTML contains no raw Google Drive file ID, download URL or preview URL.

## Local protected PDF

- Create a Drive Resource using Local protected PDF.
- Confirm the PDF is stored outside the web root.
- Open it as a learner.
- Confirm the source URL points to `protected.php`.
- Confirm the first visible page renders through PDF.js.
- Confirm progress and the last page are saved.

### Local byte-range protocol

Test authenticated requests for:

```text
bytes=0-0
bytes=0-1023
bytes=1024-
bytes=-500
```

Verify:

- valid ranges return HTTP `206`;
- `Content-Range` start/end values are correct;
- `Content-Length` equals the returned segment length;
- `bytes=-500` returns the final 500 bytes;
- an unsatisfiable range returns HTTP `416` and `Content-Range: bytes */<size>`;
- `HEAD` returns headers without a body;
- a request for the beginning of the file returns bytes beginning with `%PDF-`.

## Google Drive PDF cold-cache performance

- Clear the plugin PDF cache for the test resource.
- Open the PDF as a learner.
- Confirm the first protected response reports `MISS_QUEUED` or `MISS` when diagnostic headers are enabled.
- Confirm the first page begins loading without waiting for the complete cache download.
- Confirm a deduplicated `precache_pdf` ad-hoc task is queued.
- Run cron/ad-hoc tasks.
- Reopen the PDF.
- Confirm the protected response reports `HIT` when diagnostic headers are enabled.
- Confirm visual quality and bytes are unchanged before and after warming.
- Confirm no rasterisation, recompression or transcoding artifacts.

## PDF.js native ES-module loading

Verify in browser Network and Sources panels:

```text
amd/build/pdfjsloader.min.js
thirdpartylibs/pdfjs/pdf.min.mjs
thirdpartylibs/pdfjs/pdf.worker.min.mjs
```

Confirm:

- `pdf.min.mjs` loads as a same-origin JavaScript module;
- `pdf.worker.min.mjs` loads from the plugin;
- `pdf.min.mjs` is not requested by RequireJS;
- only one PDF.js module script exists after repeated viewer initialization;
- page rendering begins after PDF.js is validated;
- no `workerSrc` TypeError appears;
- a deliberately missing `pdf.min.mjs` produces the controlled viewer error;
- restoring the file and purging caches recovers without recreating the activity.

## PageFlip incident regression

For activities that previously displayed PageFlip source code:

- clear Moodle, PHP OPcache, CDN/reverse-proxy and browser site caches;
- open the activity on the affected physical Android device;
- confirm the first page renders as a PDF canvas;
- confirm text such as `flipController`, `flipPrev`, `flipToPage` or `getPageCollection` is not visible;
- confirm no request is made to `thirdpartylibs/pageflip/`;
- confirm `view.php` loads `mod_videoplayer/pdfviewer` only;
- confirm the protected document response starts with `%PDF-` and is not JavaScript;
- repeat on a physical iPhone or iPad Safari browser.

## PDF viewer controls

Test small, medium and large PDFs, including portrait and landscape pages.

Verify:

- previous and next page;
- current and total page number;
- zoom in and out within configured limits;
- fit to screen;
- fullscreen enter and exit;
- current page preserved after resize/orientation change;
- no horizontal clipping at default fit on a phone;
- page rendering remains sharp at supported device pixel ratios;
- browser console remains clean.

## Mobile swipe and scrolling

On physical Android and iPhone devices:

- swipe horizontally to move forward and backward;
- scroll vertically without accidental page changes;
- rotate portrait to landscape and back;
- background and restore the browser;
- enter and exit fullscreen;
- reload the activity and confirm resume from the saved page;
- confirm repeated navigation does not cause continuous memory growth.

## PDF memory and progressive loading

Open a PDF over 100 pages with browser Performance and Memory panels:

- confirm only the active page has a rendered canvas;
- confirm adjacent pages may be prefetched without creating canvases for the complete document;
- confirm the complete file is not loaded into PHP memory;
- navigate repeatedly and verify memory stabilises;
- zoom, fit and resize repeatedly and verify obsolete render state does not corrupt the canvas.

## Protected video desktop

- Create a Google Drive video activity.
- Confirm the browser source points only to `protected.php`.
- Play and pause.
- Seek forward and backward several times.
- Change playback speed.
- Enter and exit fullscreen.
- Confirm no Google Drive UI appears.
- Confirm no raw Drive URL appears in HTML.

### Video range protocol

Test authenticated protected endpoint requests for:

```text
Range: bytes=0-1
Range: bytes=<middle>-<middle+1023>
```

Verify, when the upstream supports ranges:

- HTTP `206` is preserved;
- `Content-Range` is present and correct;
- `Content-Length` matches the requested range;
- `Accept-Ranges: bytes` is present;
- only one outgoing range is sent upstream;
- upstream 4xx/5xx or HTML bodies are not exposed as successful media.

## iPhone/iPad Safari video

Test on a physical iPhone:

- initial play from a user tap;
- inline playback;
- pause and resume;
- seek forward and backward;
- repeated seeking after several minutes;
- playback speed where supported;
- native/Plyr fullscreen behavior;
- portrait-to-landscape rotation;
- lock and unlock while paused/playing;
- network transition between Wi-Fi and mobile data when practical;
- native controls remain usable if Plyr initialization is intentionally blocked.

## Gamification

- Enable gamification where available.
- Reach configured milestones and completion.
- Confirm rewards are not duplicated after refresh.
- Confirm total points update.
- Confirm reward events are present in Moodle logs.

## Completion

- Configure a completion percentage.
- Read/view past the threshold.
- Navigate backward and confirm saved completion does not regress unexpectedly.
- Confirm Moodle completion is marked.
- Confirm `resource_completed` fires only once per completion transition.

## Backup and Restore

- Back up a course with a local PDF Drive Resource.
- Include user data.
- Restore into another course.
- Confirm the PDF file restores.
- Confirm activity settings restore and resolve to `pdfjs`.
- Confirm progress restores when user data is included.
- Confirm rewards restore when user data is included.

## Privacy API

- Run privacy export for a user with progress and rewards.
- Confirm progress fields are exported.
- Confirm rewards are exported.
- Delete user data for the activity context.
- Confirm `videoplayer_views` and `videoplayer_rewards` records are removed.

## Course-format isolation

- Open a standard-format and a Tiles/Mosaico course before Drive Resource.
- Confirm navigation works.
- Open video and PDF activities.
- Return to the course.
- Confirm navigation, cards, course index and modals remain clickable.
- Confirm no Drive Resource overlay remains attached above unrelated Moodle UI.

## Final security checks

- Confirm no full-file PHP buffering for large resources.
- Confirm local/cache suffix ranges are correct.
- Confirm PDF.js and worker load only from same-origin plugin paths.
- Confirm no PageFlip or legacy book assets load in learner sessions.
- Confirm no direct source URL or Drive file ID leaks.
- Confirm malformed range and header inputs are rejected safely.
- Confirm Moodle developer debugging has no warnings/errors.
- Confirm browser console is clean on desktop and physical mobile devices.
