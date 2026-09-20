# Drive Resource for Moodle

**Drive Resource** is a protected Moodle activity for publishing Google Drive and Moodle-private learning resources without exposing upstream file URLs in plugin-owned PDF and video viewers.

The internal Moodle component remains `mod_videoplayer` for upgrade, capability, database, privacy and backup compatibility. The commercial product name is **Drive Resource**.

## Supported platform

| Component | Supported |
|---|---|
| Moodle | 4.5, 5.0, 5.1 and 5.2 |
| Minimum Moodle build | `2024100700` |
| PHP | 8.2/8.3 according to the Moodle branch; Moodle 5.2 requires PHP 8.3 |
| Databases validated by CI | MariaDB 10.11 and PostgreSQL 16 |
| Browser libraries | Bundled locally; no runtime CDN |

`version.php` declares `$plugin->supported = [405, 502]`. Moodle 4.5 is the minimum supported Moodle branch.

## Main capabilities

- Protected Google Drive video, PDF, image, document, spreadsheet and presentation delivery.
- Local protected PDFs stored with Moodle File API.
- Moodle-owned `protected.php` endpoint with login, course-module, context and capability checks.
- Memory-bounded streaming with `HEAD`, `Range`, `If-Range`, `206 Partial Content` and `416` support.
- Local PDF.js rendering with zoom, fit, previous/next navigation, fullscreen and mobile swipe navigation.
- Local Plyr enhancement over native HTML5 video with iPhone/iPad inline playback and seeking.
- Same-origin video health monitor that distinguishes protected transport failures from browser codec/decoding failures and provides bounded user-triggered recovery.
- Fast-first-byte PDF proxying with deduplicated asynchronous cache warming.
- Progress, completion, Moodle events, Privacy API and Backup/Restore integration.
- Activity-only CSS isolation that does not modify themes or third-party course formats.

## Requirements

- Moodle 4.5 or newer within the declared supported range.
- PHP 8.2+ with the extensions required by the supported Moodle branch; Moodle 5.2 is validated only on PHP 8.3 because the Moodle 5.2 core requires PHP 8.3+.
- HTTPS in production.
- Moodle cron running frequently.
- Writable `$CFG->localcachedir`.

## Installation

Copy the complete plugin directory to:

```text
mod/videoplayer
```

Required viewer assets include:

```text
styles_activity.css
styles_pdf_mobile.css
amd/build/pdfjsloader.min.js
amd/build/pdfviewer.min.js
amd/build/videohealth.min.js
thirdpartylibs/pdfjs/pdf.min.mjs
thirdpartylibs/pdfjs/pdf.worker.min.mjs
thirdpartylibs/plyr/plyr.css
thirdpartylibs/plyr/plyr.min.js
```

Run from the Moodle root:

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

After deployment, reset PHP OPcache and invalidate Cloudflare, NGINX or other reverse-proxy caches for `/mod/videoplayer/`.

## Release 1.1.32-rc10

Release `1.1.32-rc10` restores the proven `drive.google.com/uc` route as the first server-side request for shared video files. Google large-file confirmation pages remain validated and may continue internally through `drive.usercontent.google.com`; neither upstream URL is rendered to the learner. This fixes the playback transport regression without changing the bundled Plyr player.

## Release 1.1.32-rc9

Release `1.1.32-rc9` adds a playback-health layer around the protected HTML5 video path. Media errors are classified separately from Moodle transport failures. When playback fails, the browser performs only a same-origin `bytes=0-1` diagnostic request to `protected.php`, reads the safe `X-Drive-Resource-Status` response and never receives an upstream Google Drive URL.

Network/protected-source failures can be retried from the player with a bounded recovery path that preserves the current playback position when metadata becomes available again. Decode/source errors with a healthy protected transport are presented as codec compatibility failures instead of an unexplained `00:00` player.

## Release 1.1.27-beta

Release `1.1.27-beta` restores one deterministic production PDF path:

```text
protected.php
↓
authenticated byte-range delivery
↓
local PDF.js module and worker
↓
PDF.js canvas viewer
↓
learner
```

Legacy `standard`, `ebook` and `book` database values are converted to `pdfjs` during upgrade and normalised again at runtime. The PageFlip and legacy book renderers are removed from the learner path, preventing JavaScript library source from appearing as visible document content on mobile devices.

Release `1.1.25-beta` introduced the native local `<script type="module">` loader required for modern PDF.js ES-module builds. The loader validates the PDF.js API before assigning the bundled worker URL.

## Protected delivery architecture

```text
Google Drive link / Moodle private PDF
↓
Drive Resource activity
↓
protected.php
↓
require_login + course module + context_module + capability
↓
protected_stream OR http_range_proxy
↓
local PDF.js / HTML5 video viewer
↓
learner
```

The browser receives Moodle URLs for protected PDF and video delivery. Raw Google Drive file IDs, direct download URLs and preview URLs remain server-side.

## PDF delivery

Cold Google Drive PDFs use this flow:

```text
PDF.js range request
↓
Moodle authorisation
↓
queue deduplicated precache task
↓
proxy requested bytes immediately
↓
cron warms the complete local cache
↓
later requests use the protected cache
```

The original PDF bytes are preserved. Drive Resource does not recompress, rasterise or transcode documents.

Cache path:

```text
$CFG->localcachedir/mod_videoplayer/pdf/
```

### PDF.js loading boundary

PDF.js and its worker are bundled locally as ES modules. The AMD viewer does not import `.mjs` through RequireJS. Instead, `mod_videoplayer/pdfjsloader` creates one same-origin module script, validates `window.pdfjsLib`, assigns the local worker and caches the loading promise.

```text
mod_videoplayer/pdfviewer
↓
mod_videoplayer/pdfjsloader
↓
<script type="module" src="pdf.min.mjs">
↓
window.pdfjsLib validation
↓
pdf.worker.min.mjs
```

A controlled viewer error is shown when the local module cannot initialise.

### PDF viewer

Every PDF uses the standard local PDF.js canvas viewer. It provides:

- previous and next page;
- page number and total pages;
- zoom in and out;
- fit to screen;
- fullscreen;
- responsive sizing;
- swipe navigation on mobile;
- saved last page, active time and completion progress.

The activity form no longer exposes alternative PDF renderers.

## Video delivery

Protected video uses an HTML5 `<video>` element and local Plyr enhancement. Native controls remain the fallback. The player uses metadata preload, keeps `playsinline`/`webkit-playsinline`, and relies on correct byte-range responses for Safari/iOS seeking.

## CSS isolation

Moodle compiles a module's root `styles.css` into the global theme bundle. Drive Resource intentionally keeps it free of viewer presentation.

Viewer presentation is loaded only from activity-scoped styles such as `styles_activity.css` and `styles_pdf_mobile.css`. Drive Resource does not patch Moodle themes or third-party course formats.

## Automated compatibility validation

GitHub Actions runs `.github/workflows/moodle-supported-ci.yml` against:

- Moodle `MOODLE_405_STABLE` and `MOODLE_500_STABLE` on PHP 8.2 and 8.3;
- Moodle `MOODLE_502_STABLE` on PHP 8.3;
- MariaDB 10.11 and PostgreSQL 16.

The workflow performs PHP lint, Moodle coding style, PHPDoc validation, plugin validation, upgrade-savepoint checks, Mustache validation, AMD/JavaScript validation, the PDF.js native-ESM loader contract, the stable PDF.js production-path contract and PHPUnit tests.

## Video runtime compatibility

Standard Google Drive sharing URLs such as `/file/d/{id}/view` do not expose a filename or MIME type. Drive Resource now centralizes resource-type resolution: explicit types always win, typed Google Docs/Sheets/Slides URLs are detected automatically, and opaque `auto` Drive links fall back to Video for backward compatibility with the original Video Player module. New activities default explicitly to Video.

On upgraded sites, missing plugin configuration is treated as the documented default rather than as a disabled feature. In particular, an absent `protectedmode` value no longer blocks all video requests. Byte-range retries abort ignored full-response bodies immediately before trying the next strategy, avoiding redundant full video transfers.

## RC8 video runtime hardening

Release `1.1.32-rc8` hardens Google Drive shared-video playback. Standard `/file/d/.../view?usp=drivesdk` links are supported, Drive confirmation responses can preserve current bounded confirmation fields and embedded `downloadUrl` variants, and byte-range responses are checked against the browser's requested range.

The audit confirmed that successful protected byte transport does not guarantee HTML5 decoding. Direct proxy mode serves the original protected media bytes; MP4 is only a container, so codecs such as TechSmith Screen Codec 2 (TSCC2) are not natively playable in mainstream browsers. For broad compatibility use H.264/AVC video with AAC audio, or deploy a separate asynchronous transcoding pipeline.

## RC7 XMLDB upgrade hotfix

Release `1.1.32-rc7` fixes upgrades on databases where Moodle protects indexed columns from DDL changes. The `videoplayer.type` default migration now temporarily removes the XMLDB `type_idx` index, changes the default to `video`, and recreates the index safely. A failed RC6 upgrade can be retried after deploying RC7; no manual database edit is required.

## Development

AMD sources are under `amd/src/` and production bundles under `amd/build/`.

```bash
npx grunt amd
```

Any AMD source change must include its rebuilt production bundle. The generated `pdfjsloader.min.js` must create a native module script and must not contain Moodle's dynamic-import transformer.

## Documentation

- `docs/architecture.md`
- `docs/developer-guide.md`
- `docs/installation.md`
- `docs/security.md`
- `docs/manual-test-checklist.md`
- `docs/moodle-4.5-compatibility.md`

## Release

- Release: `1.1.32-rc10`
- Moodle plugin version: `2026092004`
- Component: `mod_videoplayer`
- Product: Drive Resource
- Supported Moodle branches: 4.5–5.2
- Minimum PHP: 8.2

## License

GNU GPL v3 or later. Third-party libraries and licences are declared in `thirdpartylibs.xml`.

## Maintainer

Elearning Cloud  
https://elearningcloud.io

## Release 1.1.28-beta

This release improves physical-device playback and reading. PDFs always open on page 1, mobile zoom uses the real canvas dimensions without an oversized empty viewport, and protected videos require valid `206 Partial Content` responses for seeks. The proxy retries Range negotiation across Google redirects, uses a stable Moodle-facing ETag, disables reverse-proxy buffering and never exposes the upstream URL.

## Release 1.1.29-beta

The protected PDF.js viewer now uses a dedicated page stage. Pages that fit the screen are centred horizontally and vertically in fullscreen; pages enlarged beyond the viewport remain anchored to an accessible scroll origin and support smooth horizontal and vertical navigation. Overlay controls remain fixed, respect mobile safe areas and no longer move with the document.

## Release 1.1.30-beta

Drive Resource now declares Moodle 4.5 as the minimum supported branch and keeps Moodle 5.0–5.2 in the same production line. The CI gate installs the plugin on Moodle 4.5 and Moodle 5.0 with PHP 8.2/8.3 against MariaDB and PostgreSQL. PHPUnit tests carry compatibility metadata for both PHPUnit 9 used by Moodle 4.5 and PHPUnit 11 used by Moodle 5.0.

## Release 1.1.31-beta

Protected video now has a third byte-range strategy. If Google Drive ignores both normal Range forwarding strategies and returns a complete `200` response, Moodle synthesizes the requested `206 Partial Content` window from the streamed upstream response. The proxy never buffers the whole video in memory and keeps Drive URLs server-side.

Cross-version CI note: the shared Moodle 4.5–5.x PHPUnit suite uses docblock metadata for providers and coverage so the same tests run under PHPUnit 9 and PHPUnit 11 without version-specific test branches.

## 1.1.32 RC6 production gate

Drive Resource 1.1.32 RC6 is the production-candidate line for Moodle 4.5–5.2.

The learner-facing architecture does not use Google Drive preview iframes. Videos, PDFs, images, Google Docs, Google Sheets and Google Slides are requested through Moodle's protected endpoint after login, course-module and capability checks. PDF-compatible resources render with the bundled local PDF.js viewer; videos use the local Plyr-enhanced HTML5 player; images use a Moodle-owned protected viewer.

Video completion is derived from unique playback ranges actually watched and persists the last playback second for resume. PDF completion is derived from pages actually viewed and persists the last page. Privacy API and Backup/Restore include these progress fields.

Repository CI validates Moodle 4.5 and 5.0 on PHP 8.2/8.3, and Moodle 5.2 on PHP 8.3, with MariaDB and PostgreSQL. A stable release still requires the manual staging/device gate documented in `docs/manual-test-checklist.md`, including a real Google Drive asset and physical iPhone/Safari verification.
