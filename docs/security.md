# Drive Resource security model

Drive Resource is a protected Moodle delivery layer. Browser restrictions are deterrents; Moodle server-side authorisation is the enforceable boundary.

## Supported security baseline

The current release supports Moodle 4.5–5.2 and PHP 8.2/8.3. Security validation is anchored to both `MOODLE_405_STABLE` and `MOODLE_500_STABLE`. Running the plugin on an undeclared Moodle or PHP branch is unsupported because API behavior and security fixes may differ.

## Authorisation

Every protected request must validate:

- the course module belongs to `mod_videoplayer`;
- course and activity instance records exist;
- `require_login($course, true, $cm)` succeeds;
- `context_module::instance($cm->id)` is used;
- the user has `mod/videoplayer:view`.

Guest access is not granted by the default capability archetypes.

## URL and data exposure

Plugin-owned viewers must never expose:

- raw Google Drive file IDs;
- direct Google Drive download URLs;
- Google preview URLs;
- open-in-Drive controls;
- upstream authentication/error bodies;
- PageFlip or other JavaScript assets as document content.

Learner-facing protected URLs point to Moodle `protected.php`.

## Storage

Local PDFs use Moodle File API outside the web root. Google Drive PDF cache files remain under:

```text
$CFG->localcachedir/mod_videoplayer/pdf/
```

Every browser request is reauthorised before bytes are returned. A cache hit does not bypass login, enrolment or capability checks.

## Streaming boundaries

`protected_stream` handles trusted local/cache files, PDF signature verification, local ranges and cache lifecycle.

`http_range_proxy` handles upstream HTTP delivery, accepts one validated range, relays allowlisted headers, sanitises values and streams without buffering the complete resource.

The endpoint must never become a generic URL proxy. Upstream destinations must originate from validated activity data and explicit Google host rules.

## Response hardening

Protected responses use:

- validated MIME types;
- inline disposition with sanitised filenames;
- `X-Content-Type-Options: nosniff`;
- private/no-store or controlled private caching as appropriate;
- no-index directives;
- `no-transform` where needed;
- correct `Content-Length`, `Content-Range` and `Accept-Ranges` metadata.

Valid ranges preserve `206`. Unsatisfiable ranges return `416` without leaking upstream details. HTML login/error responses must never be returned as successful PDF or media bytes.

## Stable PDF asset boundary

PDF.js and its worker are local, same-origin ES modules:

```text
thirdpartylibs/pdfjs/pdf.min.mjs
thirdpartylibs/pdfjs/pdf.worker.min.mjs
```

`mod_videoplayer/pdfjsloader` may load only constant plugin-owned paths. It must never accept a URL from activity data, request parameters or user input.

The loader must:

- create a same-origin `<script type="module">`;
- validate the expected PDF.js API;
- configure only the bundled worker path;
- avoid CDN, `eval`, inline executable source and arbitrary dynamic imports;
- expose failures through the controlled viewer error path.

The production learner path loads only `mod_videoplayer/pdfviewer`. It does not load StPageFlip or the legacy book renderer. This reduces the attack surface and prevents third-party library source from being interpreted as learner-visible PDF content.

The Moodle Content Security Policy must allow same-origin module scripts and workers. Drive Resource does not require external script origins or `unsafe-eval`.

## Document-response validation

Before a response is treated as a PDF, server-side delivery must reject content that does not satisfy the expected PDF contract. A successful PDF response should:

- use `Content-Type: application/pdf` after validation;
- begin with the `%PDF-` signature when the start of the file is available;
- reject HTML, JSON and upstream error documents;
- preserve byte-range semantics;
- never substitute a script or stylesheet URL as the document URL.

Only the Moodle-owned protected resource URL may be supplied to PDF.js.

## Task and cache safety

PDF cache warming uses Moodle ad-hoc tasks with duplicate suppression. Complete files are written to temporary paths, verified as PDFs and atomically renamed. Lock, cookie and temporary files must be cleaned up.

Cache keys must not expose raw Drive identifiers to the browser. Cache contents remain outside the web root and are always served through authorised endpoints.

## UI isolation

Moodle compiles root module CSS globally. Drive Resource keeps `styles.css` free of viewer rules and loads activity-scoped CSS only on its activity page.

Fullscreen and overlay rules must remain beneath Drive Resource roots. This prevents invisible overlays, click interception and UI denial of service in themes or course formats such as Tiles/Mosaico.

Drive Resource must never modify third-party course formats or themes to solve a local compatibility defect.

## Browser deterrents

Disabling context menus, copy shortcuts or visible download controls reduces accidental extraction but is not DRM. It cannot prevent screen capture, developer-tool inspection or an authorised user from receiving bytes required for rendering.

Product documentation and commercial claims must describe these controls accurately.

## Personal data

Progress, active time, completion, page position, points and rewards are personal data. Privacy API coverage must include metadata declaration, context discovery, export and deletion for users and approved user lists.

Drive Resource stores page/progress values but does not store rendered canvases, page images, gestures or viewport dimensions.

## Moodle security validation

Automated validation covers PHP syntax, Moodle Coding Style, metadata, XMLDB savepoints, Mustache, AMD, the PDF.js native-ESM contract, the PDF.js-only production path and PHPUnit.

Production validation additionally requires:

- current supported Moodle maintenance release;
- guest and unenrolled access denial;
- direct protected endpoint denial without login/capability;
- no raw Drive data in HTML;
- safe handling of CR/LF in filenames and headers;
- upstream failures not returned as HTTP 200 media;
- correct valid/invalid range behavior;
- no direct web access to local/cache files;
- PDF.js and worker loaded only from same-origin plugin paths;
- no PageFlip request in learner sessions;
- no JavaScript source displayed as PDF content;
- physical Android and iPhone validation;
- normal navigation in standard and third-party course formats;
- Backup/Restore and Privacy API tests;
- review of current Moodle security advisories before commercial release.

## Range and validator hardening

Drive Resource does not forward browser `If-Range` validators to Google. The proxy emits a stable, URL-derived private ETag, validates `Content-Type`, requires `206` plus `Content-Range` for byte requests and sends `X-Accel-Buffering: no`. These headers improve streaming without exposing file IDs, redirect URLs or Google validators.

## Fullscreen presentation boundary

Fullscreen centring is presentation-only. The protected Moodle endpoint remains the sole document URL, context-menu restrictions remain attached to the viewer and canvas, and the watermark is now bounded to the rendered page. No Google Drive identifier, redirect URL or upstream validator is introduced into the DOM.

## Moodle 4.5 security compatibility

Supporting Moodle 4.5 does not weaken the protected-delivery boundary. The same `require_login()`, course-module lookup, `context_module`, capability checks, protected Moodle URL, MIME validation and byte-range enforcement are required on every supported branch. Compatibility code must never bypass authorisation or expose upstream Google data merely to accommodate an older core API.
