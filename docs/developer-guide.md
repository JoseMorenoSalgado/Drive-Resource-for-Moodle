# Drive Resource developer guide

## Component identity

- Product: Drive Resource
- Moodle component: `mod_videoplayer`
- Target branch for this package: Moodle 4.5 LTS
- PHP: 8.1+

Do not rename the Moodle component without a separate migration project; existing database tables, capabilities, backups and installed-site upgrade paths depend on it.

## Directory responsibilities

```text
classes/local/access/        authorization/request context
classes/local/resource/      normalized resource model
classes/local/stream/        protected delivery orchestration/policy
classes/local/progress/      progress/completion business logic
classes/output/              renderer/template models/report tables
classes/external/            AJAX/web-service API
classes/event/               Moodle events
classes/privacy/             Privacy API
classes/task/                PDF cache tasks
amd/src/                     JavaScript source
amd/build/                   production AMD modules
templates/                   Mustache presentation
db/                          schema, services, cache, tasks, capabilities
backup/moodle2/              Backup & Restore API
thirdpartylibs/pdfjs/         local PDF.js distribution
```

## Adding or changing a resource type

1. Add the canonical type to `resource_descriptor::SUPPORTED_TYPES`.
2. Add detection/resolution logic to `drive` only if needed.
3. Add a descriptor predicate when the behavior warrants it.
4. Add a dedicated Mustache template/AMD module if the browser interaction differs materially.
5. Keep upstream URLs out of `export_for_template()`.
6. Add language strings, backup/privacy implications and tests.
7. Update documentation and the manual regression checklist.

## Protected endpoint rules

`protected.php` must remain a controller, not a business-logic container. It must:

- load Moodle;
- validate the course module through `activity_context`;
- construct the resource descriptor;
- release the PHP session lock before long streaming;
- delegate to `protected_resource_service`.

Do not add a direct URL parameter that accepts an arbitrary upstream URL.

## Streaming rules

When changing `http_range_proxy` or `protected_stream`:

- never read a complete large file into memory;
- preserve `Range`/`206` behavior;
- handle `HEAD` without a body;
- do not relay unsafe upstream headers;
- do not expose upstream errors/pages as successful media;
- validate server-side upstream hosts;
- test Safari/iOS seeking as well as Chromium;
- preserve cancellation/low-speed handling so abandoned or stalled upstream cURL transfers do not occupy PHP workers indefinitely;
- preserve the `refresh=1` recovery contract as a boolean server-side cache bypass only; never convert it into an arbitrary upstream URL parameter.

The progressive Drive stream resolver is upstream-dependent. Keep it isolated and preserve source fallback. Any resolver change must be regression-tested with the exact Google Drive video that previously worked on rc15. Persistent `waiting`/`stalled` recovery must retain the current playback position and must not loop indefinitely; the AMD player caps recovery attempts and resets the counter only after stable playback.

## JavaScript

Video is implemented by `amd/src/nativevideo.js`; audio by `nativeaudio.js`; PDF by `pdfviewer.js`.

There is no Plyr, Video.js or StPageFlip dependency.

After source changes, rebuild the Moodle AMD bundles in a Moodle development environment:

```bash
npx grunt amd
```

Commit both `amd/src/` and generated `amd/build/` artifacts expected by Moodle production deployments.

### Progress ownership

Do not attach multiple trackers to one resource:

- video -> `nativevideo.js`;
- audio -> `nativeaudio.js`;
- PDF-like -> `pdfviewer.js`;
- image/generic -> `progress.js` when tracking is enabled.

This avoids double-counted time and duplicate AJAX writes.

## Progress API

`mod_videoplayer_save_progress` is AJAX-enabled and delegates all persistence to `progress_service`.

The server, not the UI, decides the persisted completion transition. Do not directly update Moodle completion from JavaScript.

When adding progress fields:

1. update `db/install.xml`;
2. add a monotonic version/savepoint to `db/upgrade.php`;
3. update external parameters/returns;
4. update Privacy API;
5. update Backup & Restore;
6. update reports/tests/docs.

## Database upgrades

Never edit a historical savepoint to represent a new schema change. Add a new `$plugin->version` and a new guarded block in `db/upgrade.php`.

When changing an indexed field, explicitly account for XMLDB index/key dependencies before calling type/default change methods. This plugin previously encountered `ddldependencyerror`; regression-test upgrades from older installations.

## Moodle coding conventions

Use Moodle Coding Style and PHPDoc. Keep classes final unless extension is a deliberate API. Prefer small single-purpose services and avoid accessing globals outside Moodle-facing infrastructure where practical.

## Testing

Static checks before packaging:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check amd/src/nativevideo.js
node --check amd/src/nativeaudio.js
node --check amd/src/pdfviewer.js
```

CI additionally runs Moodle Plugin CI checks and PHPUnit where a full Moodle environment is available.

Manual release testing is mandatory because Google Drive playback is an external integration. Follow `docs/manual-test-checklist.md`.

## Commercial hardening workflow

Changes intended for a stable commercial release must pass both the standard Moodle 4.5 CI matrix and the dedicated hardening gate. Run the invariant guard locally from the plugin root with:

```bash
bash .github/scripts/release-invariants.sh
```

Do not weaken an invariant simply to make the gate pass. If the architecture legitimately changes, update the implementation, security model, tests and `docs/hardening-validation.md` together, then document why the invariant changed.
