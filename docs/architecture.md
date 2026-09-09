# Drive Resource architecture

Drive Resource is a Moodle activity module. Its stable internal component is `mod_videoplayer`; its commercial product name is **Drive Resource**.

## Compatibility boundary

- Moodle 4.5–5.2.
- Minimum Moodle build `2024100700`.
- PHP 8.2 and 8.3.
- MariaDB 10.11 and PostgreSQL 15 in CI.

The shared production branch is constrained to APIs available on both `MOODLE_405_STABLE` and `MOODLE_500_STABLE`. A newer Moodle-only API must be feature-detected or isolated before entering the shared release branch while Moodle 4.5 remains supported.

## Target flow

```text
Google Drive link or Moodle private PDF
↓
Drive Resource activity
↓
view.php
↓
protected.php
↓
require_login + course module + context_module + capability
↓
protected_stream OR http_range_proxy
↓
local PDF.js / HTML5 video / protected resource output
↓
progress + completion + Moodle events
↓
learner
```

Plugin-owned PDF and video viewers must not receive raw Google Drive file IDs, direct download URLs or Google preview URLs.

## Main responsibilities

### `view.php`

- Resolves the course module, course and activity instance.
- Enforces login and `mod/videoplayer:view`.
- Builds only Moodle-owned protected URLs.
- Selects the viewer by detected resource type.
- Loads `mod_videoplayer/pdfviewer` for every PDF.
- Loads local Plyr enhancement for video.
- Renders the appropriate Mustache template.

### `protected.php`

- Repeats the complete access-control boundary for every byte request.
- Closes the Moodle session write lock before long streaming operations.
- Delegates local/cache delivery to `protected_stream`.
- Delegates upstream range proxying to `http_range_proxy`.
- Never becomes a generic arbitrary-URL proxy.

### `classes/local/protected_stream.php`

Handles Moodle-private files and trusted cache files, including:

- PDF signature validation;
- `HEAD` requests;
- one validated byte range;
- `200`, `206` and `416` responses;
- `Content-Type`, `Content-Length`, `Content-Range` and `Accept-Ranges`;
- cache freshness and lifecycle;
- bounded streaming without loading the complete resource into PHP memory.

### `classes/local/http_range_proxy.php`

Handles Google Drive upstream delivery, including:

- validated Google-owned upstream destinations;
- one browser range forwarded upstream;
- allowlisted response metadata;
- rejection of HTML/login/error bodies presented as media;
- chunked cURL output;
- no complete-file PHP buffering.

## Stable PDF rendering boundary

Release `1.1.27-beta` defines one production PDF renderer:

```text
protected PDF URL
↓
mod_videoplayer/pdfviewer
↓
mod_videoplayer/pdfjsloader
↓
<script type="module" src="thirdpartylibs/pdfjs/pdf.min.mjs">
↓
validate window.pdfjsLib
↓
configure thirdpartylibs/pdfjs/pdf.worker.min.mjs
↓
render the requested page to canvas
```

The activity form stores `displaymode = pdfjs`. Runtime normalisation and the database upgrade convert historical `standard`, `ebook` and `book` values to `pdfjs`.

StPageFlip and the legacy book renderer are not part of the learner execution path. This prevents a JavaScript library asset from being mistaken for document content and removes inconsistent renderer selection across mobile and desktop browsers.

## PDF.js ES-module loading

PDF.js is bundled as an ES module. Moodle AMD source must not call `import(PDFJS_URL)` directly because the build can transform that expression into a RequireJS request, while `.mjs` is not an AMD module.

`mod_videoplayer/pdfjsloader` owns the loading contract:

- same-origin constant paths only;
- one cached loading promise per page;
- one local `<script type="module">` element;
- validation of `getDocument` and `GlobalWorkerOptions`;
- assignment of the bundled worker path only;
- controlled rejection when the module cannot initialise;
- no CDN, `eval`, arbitrary URL or unsafe runtime code generation.

## PDF fast-first-byte flow

```text
PDF.js byte-range request
↓
Moodle authorisation
↓
fresh protected cache?
├─ yes → serve requested local range
└─ no  → queue deduplicated precache task
          ↓
          proxy the requested range immediately
          ↓
          cron warms the complete verified PDF cache
```

Drive Resource preserves the original PDF bytes. It does not recompress, rasterise or transcode the document.

## PDF viewer state

`amd/src/pdfviewer.js` maintains:

- current page and total pages;
- zoom and fit-to-screen state;
- fullscreen state;
- touch-swipe navigation;
- last page reached;
- active time;
- completion percentage;
- optional points/rewards.

Only the active page is rendered. Adjacent pages may be prefetched through PDF.js without eagerly creating canvases for the complete document.

## Video viewer

`amd/src/plyr.js` progressively enhances a native HTML5 `<video>` element. Native controls remain the fallback. The protected response determines the media MIME type, and correct range metadata enables Safari/iOS seeking.

## CSS isolation

Moodle compiles root module CSS globally. Drive Resource keeps `styles.css` free of presentation rules.

Activity presentation is loaded explicitly from viewer-scoped files such as:

```text
styles_activity.css
styles_pdf_mobile.css
styles_pdf_overlay.css
styles_visual_refinements.css
```

Selectors, overlays and fullscreen states must remain scoped beneath Drive Resource roots. Themes and third-party course formats must never be modified to compensate for a plugin-local defect.

## Progress and completion

Viewer AMD modules call `mod_videoplayer_save_progress`. The external function revalidates context and capability before delegating to progress and optional reward services.

Persisted state includes:

- active time;
- progress value;
- completion percentage;
- last PDF page;
- total PDF pages;
- completion state;
- optional points and rewards.

## Events

- `course_module_viewed`
- `progress_updated`
- `resource_completed`
- `reward_awarded`

## Backup, restore and privacy

Backup and Restore include activity configuration, Moodle-local PDF files and optional user progress/reward data. The Privacy API declares, exports and deletes the personal data stored by progress and reward tables.

## Validation architecture

`.github/workflows/moodle-supported-ci.yml` is the executable compatibility gate. It runs lint, Moodle Coding Style, PHPDoc, plugin validation, XMLDB savepoint validation, Mustache, AMD/JavaScript and PHPUnit on Moodle 4.5 and Moodle 5.0 across the supported PHP/database matrix.

The workflow also enforces:

- the native local PDF.js ES-module loader contract;
- the PDF.js-only learner production path;
- absence of PageFlip and legacy book viewer references from `view.php`;
- `pdfjs` as the XMLDB display-mode default.

## Mobile media reliability in 1.1.28-beta

The protected stream boundary now treats browser Range requests as strict contracts. A seek request is emitted to the learner only when Google returns a valid `206` response with `Content-Range`; an upstream `200` is discarded and retried with an explicit Range header. Browser-facing validators are owned by Moodle so redirect-specific Google ETags cannot downgrade later seeks to complete responses. PDF rendering remains local PDF.js and starts from page 1.

## PDF fullscreen stage (1.1.29-beta)

The PDF template separates three layers: a fixed control overlay, a scrollable viewport and a `mod-videoplayer-pdfjs-canvas-stage` containing the canvas and watermark. Auto margins centre a page only while positive free space exists. When zoom creates overflow, those margins collapse to zero, preserving a reachable top-left scroll origin without JavaScript layout heuristics.

## Moodle 4.5 compatibility boundary (1.1.30-beta)

Moodle 4.5 becomes the lowest supported core branch. The plugin metadata, PHPUnit metadata and CI matrix are aligned to that boundary without forking the runtime architecture. Production code must continue using APIs present on Moodle 4.5 for authorisation, File API, Completion, Events, External API, Tasks, Privacy, Backup/Restore, XMLDB, AMD and session locking. The Moodle 5.x path must remain behaviorally identical unless a newer API is guarded by an explicit compatibility adapter.

## Protected video range recovery (1.1.31-beta)

The proxy uses three ordered strategies for browser byte ranges: libcurl `CURLOPT_RANGE`, an explicit `Range` header across redirects, and a synthetic range fallback. The synthetic fallback is used only when Google returns a complete `200` response with a known `Content-Length`; Moodle discards bytes before the requested offset and streams only the requested window as `206 Partial Content`. It does not buffer the complete video in PHP memory. MIME validation, login, module context and capability checks remain unchanged.
