# Drive Resource developer guide

## Component identity

The Moodle component remains `mod_videoplayer`. Do not rename it: installed sites depend on this identifier for upgrades, capabilities, database tables, files, Privacy API and Backup/Restore mappings.

## Supported development target

- Moodle 4.5–5.2.
- Compatibility baselines `MOODLE_405_STABLE`, `MOODLE_500_STABLE`, `MOODLE_501_STABLE` and `MOODLE_502_STABLE`.
- Minimum Moodle version `2024100700`.
- PHP 8.2 and 8.3 for Moodle 4.5/5.0/5.1; Moodle 5.2 CI uses PHP 8.3.
- CI databases MariaDB 10.11 and PostgreSQL 16.

Moodle 4.5 is supported from the shared release branch. Older Moodle 4.x branches remain unsupported.

## Engineering rules

- Moodle Coding Style takes precedence.
- Use Moodle APIs rather than direct filesystem or database shortcuts.
- Keep public endpoints thin and delegate business logic.
- Bundle browser libraries locally; runtime CDN dependencies are prohibited.
- Keep streaming memory-bounded.
- Keep functions cohesive and small.
- Update AMD source and compiled production bundles together.
- Review File API, Privacy API, Backup/Restore, Events and Completion for each data-model change.
- Do not patch themes or course-format plugins to fix Drive Resource defects.

## Main paths

```text
amd/src/                  AMD source
amd/build/                production AMD bundles
backup/moodle2/           Backup and Restore
classes/event/            Moodle events
classes/external/         AJAX/External API
classes/local/            streaming and application services
classes/privacy/          Privacy API
db/                       XMLDB, capabilities, services and tasks
tests/                    Moodle PHPUnit tests
thirdpartylibs/pdfjs/      local PDF.js module and worker
thirdpartylibs/plyr/       local media-player enhancement
styles.css                intentionally presentation-free global stylesheet
styles_activity.css       activity-only base presentation
styles_pdf_mobile.css     mobile PDF presentation
```

## Protected delivery contract

`protected.php` must execute this order:

```text
required activity id
→ course module
→ course
→ activity instance
→ require_login
→ context_module
→ require_capability
→ close session write lock
→ protected_stream or http_range_proxy
```

Never return raw Google Drive IDs, direct download URLs, preview URLs or upstream error bodies.

`protected_stream` owns Moodle-private/cache files. `http_range_proxy` owns upstream HTTP streaming. Do not send both a manually constructed `Range` header and `CURLOPT_RANGE` for the same upstream request.

## Video playback health contract

The native `<video>` element is the playback authority; Plyr is presentation enhancement only. `mod_videoplayer/videohealth` must remain usable even when Plyr fails to load.

The health module may probe only the already-authorised Moodle `protected.php` URL. Keep the diagnostic request bounded to a minimal byte range and same-origin credentials. Use the safe `X-Drive-Resource-Status` header to distinguish protected delivery failures from browser decode/source errors.

Do not attempt to infer a codec from the MP4 container extension or MIME type. A transport-success + media decode/source error must be treated as a compatibility failure. Arbitrary codec support requires a real transcoding subsystem.

Recovery must be user-triggered, bounded and preserve the last usable playback second when possible. Never retry by exposing, redirecting the browser to, or reconstructing a Google Drive URL.

## PDF renderer contract

Every production PDF must use:

```text
mod_videoplayer/pdfviewer
```

`view.php` must not load:

```text
mod_videoplayer/ebookviewer
mod_videoplayer/bookviewer
thirdpartylibs/pageflip/*
```

`videoplayer_get_safe_pdf_displaymode()` is the single runtime normalisation point. It currently returns only `pdfjs`. Historical values such as `standard`, `ebook` and `book` must remain safely migratable to `pdfjs`.

Do not reintroduce an animated page-turn renderer directly into the learner path. A future experimental renderer would require all of the following before consideration:

- an explicit disabled-by-default feature flag;
- complete isolation from the stable PDF.js path;
- deterministic PDF.js fallback;
- physical iPhone and Android regression testing;
- range, memory and accessibility validation;
- CI coverage proving that JavaScript asset URLs cannot become document URLs;
- product approval before enabling it on existing activities.

## PDF.js module loading

PDF.js is shipped locally as ES modules:

```text
thirdpartylibs/pdfjs/pdf.min.mjs
thirdpartylibs/pdfjs/pdf.worker.min.mjs
```

Do not call `import(PDFJS_URL)` directly from AMD source. Moodle's Babel build can transform dynamic imports into RequireJS requests, but `.mjs` is not an AMD module.

All protected PDF rendering must depend on:

```text
mod_videoplayer/pdfjsloader
```

The loader must:

- create a same-origin `<script type="module">` for `pdf.min.mjs`;
- validate `window.pdfjsLib.getDocument` and `GlobalWorkerOptions`;
- configure only the bundled `pdf.worker.min.mjs`;
- cache one loading promise per page;
- remove/recover from a failed module element before retrying;
- reject through a controlled Promise error;
- never accept URLs from request data or activity configuration;
- never use CDN, `eval`, arbitrary imports or unsafe runtime code generation.

After changing `amd/src/pdfjsloader.js`, rebuild the production bundle with Moodle Grunt:

```bash
npx grunt amd --root=mod/videoplayer
```

The generated `amd/build/pdfjsloader.min.js` must contain native module-script creation and must not contain `_systemImportTransformerGlobalIdentifier`.

## PDF viewer behavior

`amd/src/pdfviewer.js` must preserve:

- idempotent initialization;
- previous and next page controls;
- current and total page display;
- zoom boundaries and fit-to-screen;
- fullscreen with a controlled CSS fallback;
- touch-swipe navigation without blocking vertical scrolling;
- responsive rendering on resize/orientation change;
- bounded device-pixel-ratio scaling;
- progress persistence;
- adjacent-page prefetch only;
- controlled error UI.

Do not render all pages into canvases. Render the active page and allow PDF.js to fetch only what is needed through the protected range endpoint.

## PDF performance

- Keep the first visible page on the critical path.
- Use `rangeChunkSize` suitable for progressive PDF loading.
- Prefetch only adjacent pages.
- Preserve original PDF bytes.
- Proxy cold ranges immediately.
- Warm verified complete PDFs asynchronously through a deduplicated ad-hoc task.
- Do not buffer complete PDFs in PHP memory.
- Bound canvas backing dimensions through a device-pixel-ratio ceiling.
- Re-render only after meaningful resize, zoom or fullscreen changes.

## Video development

- Keep native HTML5 controls as fallback.
- Preserve `playsinline` and `webkit-playsinline`.
- Use metadata preload unless profiling demonstrates a better safe option.
- Preserve valid `206 Partial Content` metadata for Safari/iOS seeking.
- Do not force a MIME type that conflicts with the protected response.
- Avoid loading the complete video into PHP memory.

## CSS isolation

Root `styles.css` is global in Moodle and must not contain viewer presentation.

Viewer rules must be loaded explicitly from activity-scoped CSS and use `mod-videoplayer` or `drive-resource` prefixes. Generic fullscreen, overlay, loading and active-state selectors are prohibited.

## Database upgrades

For schema or persisted-default changes:

1. update `db/install.xml`;
2. add an idempotent `db/upgrade.php` step;
3. migrate existing values safely;
4. handle dependent indexes before changing indexed fields;
5. update Backup/Restore and Privacy API when data shape changes;
6. bump `version.php`;
7. run Moodle savepoint validation.

The `2026080600` upgrade changes `displaymode` to `pdfjs` for all existing records. Do not remove that compatibility step.

## Automated tests

Current contracts include:

- `tests/drive_test.php` for supported URLs, file IDs, resource detection and protected endpoints;
- `tests/http_range_proxy_test.php` for byte-range behavior;
- `tests/platform_compatibility_test.php` for Moodle 4.5–5.2 metadata and required APIs;
- `tests/pdf_displaymode_test.php` for legacy display-mode normalisation and PDF.js-only routing.

The CI workflow must pass:

- PHP lint;
- Moodle Coding Style;
- PHPDoc;
- plugin validation;
- XMLDB upgrade savepoints;
- Mustache validation;
- Grunt/AMD validation;
- PDF.js native-ESM loader contract;
- PDF.js-only production-path contract;
- PHPUnit on the supported matrix.

## Commercial release gate

A release is not approved until CI passes and staging verifies:

- fresh installation and upgrade from the previous release;
- local and Google Drive PDF opening;
- no PageFlip request in the browser network panel;
- no JavaScript source displayed as PDF content;
- PDF.js initialization on physical Android and iPhone browsers;
- previous/next, zoom, fit, fullscreen and swipe navigation;
- correct valid and invalid byte ranges;
- video start, seek and resume on physical iPhone Safari;
- progress and completion persistence;
- Backup/Restore;
- Privacy API export/delete;
- no leaked Google Drive URLs;
- normal operation with standard and third-party course formats;
- no developer-debug warnings or browser console errors.

## Mobile media regression rules

Keep `amd/src/plyr.js` and `amd/build/plyr.min.js` synchronized through Moodle Grunt. Do not reload the video source to recover a seek. The client may retry the requested `currentTime` a bounded number of times, while `http_range_proxy` remains the authoritative fix. PDF templates must receive `initialpage = 1`; reading progress may still store `lastpage` for reporting.

## Fullscreen PDF layout contract

Keep PDF controls outside `.mod-videoplayer-pdfjs-canvas-wrap`. The canvas and watermark must remain inside `.mod-videoplayer-pdfjs-canvas-stage`. Do not centre an oversized canvas with `align-items: center` or transforms: doing so can create negative, unreachable scroll regions on mobile browsers. The stage owns centring through auto margins and the viewport owns scrolling.

## Cross-version PHPUnit contract

Moodle 4.5 uses PHPUnit 9 while Moodle 5.0 uses PHPUnit 11. Cross-version tests must use docblock metadata (`@dataProvider`, `@covers`, `@coversNothing`) that both generations can parse; do not add PHPUnit 10/11-only attributes to shared tests. Do not introduce a test-only dependency that prevents the same plugin package from being validated on both core branches.

## Synthetic range fallback

`http_range_proxy` must prefer native upstream range support. `RANGE_MODE_SYNTHETIC` is a last-resort compatibility path for Google responses that ignore `Range`. `resolve_range_window()` validates open, bounded and suffix ranges against a known total size. Do not replace this with full-file buffering or temporary whole-video downloads. For bounded requests, stop the cURL transfer after the requested window has been emitted.

## 1.1.32 RC engineering invariants

Production changes must preserve these invariants:

- Never pass a Google Drive file ID, direct download URL or preview URL into learner-facing HTML/JavaScript.
- Never reintroduce `templates/resource.mustache`, the legacy native PDF iframe template, or Google preview rendering.
- Keep `protected.php` authorization and the Range/206 proxy as the only learner delivery boundary for remote binary content.
- PDF completion is based on the union of observed pages; video completion is based on the union of normalized watched playback ranges.
- Changes to persisted progress fields must be reflected in XMLDB upgrade/install definitions, External API, Privacy API, Backup/Restore, reporting and language strings.
- AMD source and production bundles must be regenerated together for release packaging.

The supported CI matrix covers Moodle 4.5, 5.0, 5.1 and 5.2, PHP 8.2/8.3, MariaDB and PostgreSQL. Do not promote `MATURITY_RC` to `MATURITY_STABLE` until the manual staging/device release gate passes.

## Debugging Google Drive videos that stay at 0:00

When a Drive video renders but metadata never loads, inspect the protected endpoint response rather than the Plyr UI first. A healthy initial request should return media with `Content-Type: video/*` (or an inferred safe video type), `Accept-Ranges: bytes`, and either HTTP 200 for a normal request or HTTP 206 with a valid `Content-Range` for a range request.

The proxy recognizes Drive large-file confirmation HTML and automatically follows the validated confirmation form with its generated parameters and cookies. Tests for this behavior live in `tests/drive_test.php` and `tests/http_range_proxy_test.php`. Do not reintroduce direct Google URLs, iframe previews or client-side confirmation handling.

## Video runtime invariants

Do not implement resource-type detection independently in an entry point. Use `drive::resolve_record_type()` everywhere so rendering, streaming, progress and reporting agree on the same type.

Plugin checkbox settings must distinguish an absent config value from an explicitly disabled value. Settings whose documented default is enabled should use `(string) $value === '0'` only to detect an explicit disable, or ensure the upgrade step seeds the default first.

When a browser Range request receives upstream HTTP 200, retry strategies must not consume the complete response body. The first incompatible full-response chunk is intentionally aborted before the next strategy. The synthetic strategy remains the only path allowed to consume the upstream full stream for a requested byte window.
## DDL dependency rule

Never call `change_field_type()`, `change_field_default()` or related field-altering XMLDB methods on a field that still has an index/key dependency. Define the dependency with `xmldb_index`/`xmldb_key`, drop it through Moodle's database manager, perform the alteration, then restore it. For critical upgrades use `try/finally` so an exception does not leave the site with a missing index. RC7 applies this rule to `videoplayer.type` and `type_idx`.

## Video failure diagnosis

Treat a black player or `00:00 / 00:00` as a symptom, not a single failure class. Diagnose the protected transport independently from browser decoding.

- A successful protected request returning HTTP `200/206` with `X-Drive-Resource-Status: MEDIA` means Moodle authorization and protected byte delivery succeeded.
- A non-success protected response belongs to Drive resolution, permissions, MIME validation or byte-range handling.
- If protected transport succeeds but the browser still raises a media decode error, inspect the source codec. An `.mp4` extension identifies a container, not guaranteed browser-compatible video.

The browser must never receive the raw Drive URL as part of troubleshooting. Keep all upstream identifiers server-side. For broad direct playback compatibility, normalize source media to H.264/AVC video with AAC audio. Arbitrary codec support requires a real server-side transcoding layer rather than a frontend workaround.
