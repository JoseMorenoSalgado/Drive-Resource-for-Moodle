# Changelog

## 1.1.30-beta - 2026-09-09

### Added

- Official Moodle 4.5 support while retaining Moodle 5.0, 5.1 and 5.2 in the same plugin package.
- Cross-version GitHub Actions matrix for `MOODLE_405_STABLE` and `MOODLE_500_STABLE` on PHP 8.2/8.3 with MariaDB and PostgreSQL.
- Moodle 4.5 compatibility audit and manual staging checks.

### Changed

- Lowered the minimum Moodle build from `2025041400` to the Moodle 4.5 baseline `2024100700`.
- Expanded `$plugin->supported` from `[500, 502]` to `[405, 502]`.
- Replaced the Moodle 5-only compatibility test with a shared platform contract.
- Added PHPUnit 9-compatible data-provider and coverage metadata while retaining PHPUnit 11 attributes for Moodle 5.0.

### Compatibility

- The protected streaming, PDF.js, Plyr, Completion, Events, Privacy and Backup/Restore runtime paths remain shared across Moodle 4.5–5.2.
- Moodle 4.4 and older remain outside the supported release contract.
- Fixed Moodle 4.5 PHPCS coverage metadata so the cross-version CI gate can continue past coding-style validation and execute the full test suite.


## 1.1.29-beta - 2026-08-06

### Fixed

- Centred PDF pages horizontally and vertically in native and fallback fullscreen when the rendered page fits the viewport.
- Added a dedicated canvas stage so enlarged pages switch naturally to bidirectional scrolling without inaccessible negative offsets.
- Kept navigation and fullscreen controls fixed above the scrollable document and aligned mobile controls with device safe areas.
- Scoped the watermark to the rendered page instead of the complete fullscreen viewport.

## 1.1.28-beta - 2026-08-06

### Fixed

- Forced protected PDFs to open on page 1 while continuing to record reading progress.
- Removed the oversized empty mobile PDF viewport and restored real scrollable zoom.
- Hardened protected video byte-range delivery so seek requests cannot silently fall back to HTTP 200 and restart playback.
- Added adaptive video preload, stable proxy validators, NGINX buffering bypass and bounded client-side seek recovery.

## 1.1.27-beta - 2026-08-06

### Fixed

- Restored the local PDF.js viewer as the only production PDF rendering path.
- Prevented StPageFlip and legacy book assets from loading in learner sessions.
- Normalised legacy `standard`, `ebook` and `book` modes to `pdfjs`.
- Added upgrade handling, regression tests and deployment verification guidance.


All notable changes to **Drive Resource** are documented in this file.

The internal Moodle component is `mod_videoplayer` for compatibility with previous installations.

## v1.1.26-beta - 2026-08-05

### Fixed

- Restored the dedicated PageFlip responsive stylesheet on protected Ebook activity pages.
- Phones now remain in a single-page layout in portrait and landscape orientations.
- Desktop-sized viewers explicitly use a two-page facing spread.
- Replaced eager rendering of up to 80 PDF pages with lightweight placeholders and lazy visible-page rendering.
- Adjacent pages are prefetched only during browser idle time.
- Ebook completion percentage remains monotonic when a learner turns back.

### Experience

- Added paper texture, centre-gutter shading, page shadows, corner interaction and page-settle effects.
- Added responsive fullscreen sizing and reduced-motion support.
- The page indicator shows one page on phones and the visible page range on desktop spreads.

### Validation

- Rebuilt `amd/build/ebookviewer.min.js` and its source map with Moodle 5.0 Grunt.
- Added a permanent responsive Ebook contract to Moodle 5.0 CI.
- Release metadata bumped to `1.1.26-beta` with plugin version `2026080505`.

### Upgrade notes

- Deploy the complete plugin directory, including `styles_pageflip_fix.css`, `amd/src/ebookviewer.js` and its rebuilt `amd/build` files.
- Run Moodle upgrade and purge Moodle plus browser caches.
- Existing PDF activities do not need to be recreated.
- Physical mobile and desktop functional checks remain required because automated CI validates the implementation contract, not visual rendering on every device.

## v1.1.25-beta - 2026-08-05

### Fixed

- Fixed the mobile runtime error `Cannot set properties of undefined (setting 'workerSrc')` when opening protected PDF and Ebook activities.
- Replaced duplicated direct PDF.js imports in the Ebook, responsive book and standard PDF viewers with the shared `mod_videoplayer/pdfjsloader` AMD adapter.
- Prevented Moodle's AMD/Babel build from transforming the local PDF.js ES-module import into an incompatible RequireJS request.
- PDF.js now loads from the bundled `pdf.min.mjs` through a native local `<script type="module">` element and configures the bundled `pdf.worker.min.mjs` only after the API is validated.
- Failed PDF.js module loads now reject through the existing controlled viewer error path instead of attempting to write `workerSrc` on an undefined object.

### Changed

- Release metadata bumped to `1.1.25-beta` with plugin version `2026080504`.
- The three protected PDF viewers now share one cached PDF.js loading promise and one worker configuration path.

### Validation

- Rebuilt all affected AMD production bundles with Moodle 5.0 Grunt.
- Added a permanent CI contract that rejects generated PDF.js loader bundles containing Moodle's dynamic-import transformer.
- The generated loader must contain a native module script, the local worker path and no RequireJS conversion for `pdf.min.mjs`.

### Upgrade notes

- Deploy the complete plugin directory, including `amd/build/pdfjsloader.min.js` and its source map.
- Run Moodle upgrade and purge caches, then clear the browser/site cache on mobile devices before retesting.
- A physical-device test is still required on the affected Android/iOS browser because CI validates the bundle contract but does not emulate every mobile JavaScript engine.

## v1.1.24-beta - 2026-08-05

### Fixed

- Restored the protected Ebook/PageFlip execution path that had become disconnected when `view.php` began forcing the internal responsive book viewer.
- Restored the missing ebook stage in the PDF.js Mustache template so the bundled PageFlip library can create the page-turn experience.
- Prevented PageFlip from initialising against a hidden zero-dimension element, improving first render sizing and page proportions.
- Existing activities whose historical hidden field stored `displaymode = standard` are interpreted as Ebook activities again.
- The standard single-page PDF.js viewer now uses the explicit `pdfjs` display-mode value, avoiding ambiguity with historical records.
- The activity form once again exposes a supported PDF display-mode selector instead of overwriting the value with a hidden field.
- Resume rendering now uses the learner's saved last page instead of always returning to page 1.

### Changed

- Protected Ebook is the default PDF experience.
- Teachers can choose between Protected Ebook and Standard PDF.js when configuring the activity.
- PageFlip CSS and JavaScript are loaded only on Ebook activity pages.
- Release metadata bumped to `1.1.24-beta` with plugin version `2026080503`.

### Upgrade notes

- Deploy the complete plugin directory, including `templates/pdfjs.mustache` and `thirdpartylibs/pageflip/`.
- Run Moodle upgrade and purge all caches so the revised Mustache template and viewer routing are loaded.
- Existing PDF activities do not need to be recreated or edited.

## v1.1.23-beta - 2026-08-05

### Fixed

- Switched protected Google Drive blob delivery to the `drive.usercontent.google.com` content endpoint with an explicit download confirmation parameter.
- Preserved optional Google Drive `resourcekey` values from sharing URLs so protected files that require a resource key remain accessible to the Moodle server.
- Prevented Google Drive login, permission, warning or error HTML from being forwarded to HTML5 video, audio, image or PDF viewers.
- Generic binary upstream responses now use the viewer's expected MIME type while valid specific media MIME types continue to pass through.

### Security

- Added strict upstream MIME compatibility validation before protected response headers or bytes are sent to the learner.
- Added non-sensitive `X-Drive-Resource-Status` diagnostics without exposing Google Drive IDs, URLs or response bodies.

### Validation

- Added PHPUnit coverage for Drive resource-key handling, protected content URL generation and upstream MIME rejection.
- Release metadata bumped to `1.1.23-beta` with plugin version `2026080502`.

### Upgrade notes

- Deploy the complete plugin directory and run the normal Moodle upgrade and cache purge.
- Existing activities do not need to be recreated. Activities whose original sharing URL contains `resourcekey` will begin forwarding it server-side after upgrade.
- Google Drive files must permit link access and download; owner-level download restrictions cannot be bypassed by the plugin.

## v1.1.22-beta - 2026-08-05

### Fixed

- Corrected the Moodle 5.0 XMLDB definition of `videoplayer.videourl`: local protected resources can now store `NULL` instead of an artificial empty URL.
- Added an idempotent upgrade step that removes the obsolete `NOT NULL` and default-empty-string constraints while preserving every existing Google Drive URL.
- Resolved Moodle Coding Style violations in PHP file headers, Backup/Restore tasks, Privacy API, progress services, reports and event descriptions.

### Changed

- Release metadata bumped to `1.1.22-beta` with plugin version `2026080501`.
- The clean-install schema now matches the real domain model: Google Drive resources require a URL; Moodle-local PDFs do not.

### Validation

- Moodle 5.0 clean installation was exercised with PHP 8.2/8.3 against MariaDB and PostgreSQL.
- PHP syntax validation passed for the complete plugin PHP codebase.
- Full CI remains the release gate for PHPCS, PHPDoc, plugin validation, XMLDB savepoints, Mustache, AMD and PHPUnit.

## v1.1.21-beta - 2026-08-05

### Added

- Explicit Moodle supported range `[500, 502]`, covering Moodle 5.0, 5.1 and 5.2.
- GitHub Actions compatibility workflow under `.github/workflows/moodle-50-ci.yml`.
- Moodle 5.0 CI matrix for PHP 8.2/8.3 with MariaDB 10.11 and PostgreSQL 15.
- PHPUnit coverage for Google Drive URL validation, file IDs, resource detection and protected export endpoints.

### Changed

- Minimum Moodle version remains `2025041400`, the Moodle 5.0 branch baseline.
- Release metadata bumped to `1.1.21-beta` with plugin version `2026080500`.
- Backup and Restore activity task classes were normalised to current Moodle coding style and PHP type declarations.
- Documentation now defines Moodle 5.0–5.2 as the supported contract instead of incorrectly claiming Moodle 4.x compatibility.
- Removed the obsolete root `ci.yml` that tested Moodle 4.1 with unsupported PHP versions for the current product.

### Compatibility

- Reviewed External API, Completion API, Events API, Privacy API, Backup/Restore, XMLDB, File API, scheduled/ad-hoc tasks, AMD modules and protected streaming against Moodle 5.0 APIs.
- No dependency on a Moodle 5.1- or 5.2-only PHP or AMD API was identified.
- Production deployment still requires the automated workflow to complete successfully and a functional test on the target Moodle 5.0 site.

## v1.1.20-beta - 2026-07-24

### Fixed

- Removed all Drive Resource viewer rules from the globally compiled `styles.css` bundle.
- Prevented the activity viewer CSS from affecting third-party course formats such as Tiles/Mosaico, Moodle navigation, cards, modals, themes, or unrelated plugins.
- Preserved the complete PDF, ebook, video and fullscreen presentation by moving the former global rules to `styles_activity.css`, which is requested only by `mod/videoplayer/view.php`.

### Changed

- `styles.css` is intentionally limited to documentation comments so Moodle can compile it globally without introducing presentation or interaction rules.
- Release metadata bumped to `1.1.20-beta` with Moodle version `2026072401`.

### Upgrade notes

- Deploy the complete plugin directory so the new `styles_activity.css` file is present.
- Run the normal Moodle upgrade and purge all caches after deployment.
- Perform a browser hard refresh because the previous global module CSS may remain in the browser or theme cache until it is regenerated.
- No changes to `format_tiles` or any other third-party plugin are required.

## v1.1.19-beta - 2026-07-24

### Fixed

- Scoped the fallback fullscreen CSS rule to Drive Resource containers so the plugin cannot accidentally turn an unrelated theme or Moodle element into a fixed full-viewport layer.
- Prevented potential invisible overlays from intercepting clicks on course cards, activity links, breadcrumbs or other Moodle navigation controls when a theme reuses the generic `is-fallback-fullscreen` class.

### Changed

- Release metadata bumped to `1.1.19-beta` with Moodle version `2026072400` so Moodle registers the CSS hotfix and administrators can purge compiled theme caches after deployment.

### Upgrade notes

- After deploying this release, run the normal Moodle upgrade and purge all caches. Browser hard refresh may also be required because the affected rule is part of Moodle's compiled theme CSS.

## v1.1.18-beta - 2026-07-22

### Fixed

- Fixed Moodle XMLDB `ddldependencyerror` when upgrading legacy installations where `source_idx` already depends on the `videoplayer.source` field.
- Indexed `source` and `type` fields are now migrated safely by dropping their logical XMLDB indexes before field type/default changes and recreating the indexes afterwards.
- The same dependency-safe migration pattern is applied to the legacy `videoplayer_views.completed` field and its `completed_idx` index.
- Legacy nullable values are normalized before enforcing current `NOT NULL` definitions for `source`, `type`, completion and progress fields.
- Legacy progress normalization now checks field existence before issuing data updates, preserving compatibility with partially migrated tables.

### Changed

- Release metadata bumped to `1.1.18-beta` with Moodle version `2026072200`.
- `db/install.xml` metadata aligned to `20260722`.

### Upgrade notes

- Sites stopped by `ddl_dependency_exception` can deploy this release and rerun the normal Moodle upgrade process; the historical upgrade step is intentionally idempotent and recreates required indexes after the field migration.
- Administrators should not manually remove the physical database index when the corrected plugin files are available; XMLDB performs the dependency-safe index lifecycle using logical index definitions.

## v1.1.17-beta - 2026-07-17

### Added

- Dedicated `http_range_proxy` service for browser-facing protected upstream streaming.
- Explicit support for `HEAD`, `Range` and `If-Range` forwarding semantics required by modern media clients.
- Deduplicated ad-hoc PDF cache warming after an uncached first request.

### Changed

- Google Drive PDF cache misses now use a fast-first-byte strategy: the requested PDF range is proxied immediately while the complete cache is warmed asynchronously by Moodle cron.
- Protected upstream streaming no longer emits duplicate `Range` headers.
- Upstream `206 Partial Content`, `Content-Range`, `Content-Length`, `ETag`, `Last-Modified` and `Accept-Ranges` metadata are relayed safely where applicable.
- Upstream error bodies are no longer exposed as successful protected media responses.
- HTML5 video markup no longer hardcodes `video/mp4`, allowing Safari/iOS to negotiate the actual protected response MIME type.
- Video preload changed from `auto` to `metadata` to reduce initial bandwidth and improve mobile startup.
- Plyr initialization now preserves native seek and playback-rate capabilities and applies iOS-compatible inline playback attributes.
- Release metadata bumped to `1.1.17-beta` with Moodle version `2026071700`.

### Security

- Protected source URLs remain server-side only; the browser continues to receive only `protected.php` URLs.
- Relayed upstream header values are sanitized before being sent to the client.
- Protected proxy failures return a generic gateway response instead of leaking upstream response bodies or URLs.

### Performance

- PDF first-open latency no longer waits for a complete Google Drive PDF download when cache is cold.
- Duplicate cache-warming tasks are suppressed by Moodle's ad-hoc task queue.
- Video and PDF range requests stream without loading the complete resource into PHP memory.

## v1.1.16-beta - 2026-06-24

### Added

- Local protected PDF source stored through Moodle File API.
- Protected PDF delivery through `protected.php` for Moodle-local PDF files.
- Standard PDF.js viewer using local PDF.js assets.
- Protected ebook display mode.
- Optional local StPageFlip integration for realistic page turning.
- Fallback from ebook mode to protected PDF.js when PageFlip is unavailable.
- Reading resume support using last saved page.
- Reading progress by page, total pages, percentage and active time.
- Optional dynamic watermark deterrent.
- Optional gamification with personal milestones and points.
- `videoplayer_rewards` table for earned rewards.
- Progress and reward service layers.
- Moodle events: `progress_updated`, `resource_completed`, `reward_awarded`.
- Backup and Restore support for local PDF files, progress and rewards.
- Privacy API support for reading state and reward records.
- Local PDF.js, Plyr and optional StPageFlip declarations in `thirdpartylibs.xml`.
- Mobile PDF viewport stabilizer for iOS/Safari rendering edge cases.
- Protected responsive book viewer with desktop two-page spread and mobile one-page reading mode.
- In-memory rendered page cache and neighbour-page prefetch.
- Protected stream cache diagnostic header `X-Drive-Resource-Cache`.
- Internal `mod_videoplayer\local\protected_stream` service for protected byte-range delivery, cache warming and cleanup.

### Changed

- `protected.php` became a thin authorised endpoint delegating to internal streaming services.
- PDF resources render through the protected book viewer by default.
- Local Moodle PDFs stream directly from File API storage when possible.
- Google Drive PDFs cache outside the web root and preserve original bytes.

### Security

- Removed the default guest archetype from `mod/videoplayer:view`.
- Local PDF access requires Moodle login, module context and capability checks.
- Direct local PDF and Google Drive source URLs are not exposed by plugin-owned viewers.
- Viewer restrictions are documented as deterrents, not DRM.

## v1.0.0 - 2026-06-14

### Added

- New user-facing identity: **Drive Resource**.
- Google Drive videos, PDFs, images, documents, spreadsheets and presentations.
- Google Drive file ID extraction and resource-type detection.
- Protected endpoint with Moodle authorisation.
- Progress tracking, Completion API, Events API, Backup/Restore and Privacy API.
- English and Spanish language packs.
- Mustache templates and AMD modules.

### Security

- Removed plugin-owned open-in-Drive controls.
- Added iframe restrictions and no-referrer policy where legacy embeds remain.
- Added Moodle course and capability checks to protected delivery.
