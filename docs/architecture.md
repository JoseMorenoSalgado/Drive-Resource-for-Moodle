# Drive Resource architecture

## Scope

Drive Resource is a Moodle 4.5 activity module that presents Google Drive learning resources without delegating the learner experience to the Google Drive viewer. The component name remains `mod_videoplayer`; the architecture is resource-oriented rather than video-only.

## Design goals

1. Moodle owns authorization and the browser-visible resource URL.
2. Large files are streamed; they are not loaded completely into PHP memory.
3. Video/audio use native HTML5 media APIs.
4. PDF-like content uses locally bundled PDF.js.
5. Google Drive identifiers and temporary upstream URLs remain server-side.
6. Progress/completion are handled by a service layer and Moodle APIs.
7. Resource-specific JavaScript has one responsibility and one progress writer.

## Request path

```text
view.php
  -> activity_context
  -> resource_descriptor
  -> resource_view
  -> renderer + type-specific Mustache template
  -> AMD viewer/player
  -> protected.php
       -> activity_context
       -> resource_descriptor
       -> protected_resource_service
            -> protected_stream (local/cache)
            -> drive_stream_resolver (video progressive stream)
            -> drive::protected_content_url (server-side fallback/export)
            -> http_range_proxy
```

### Access boundary

`classes/local/access/activity_context.php` centralizes:

- course-module lookup;
- course lookup;
- activity-instance lookup;
- `require_login()`;
- `context_module` resolution;
- capability enforcement.

`protected.php` and the external progress API use this boundary rather than duplicating access checks.

### Resource model

`classes/local/resource/resource_descriptor.php` normalizes source and type. It is the only object the presentation/service layers need to determine whether the resource is video, audio, image, PDF-like or generic.

The descriptor generates only Moodle `protected.php` URLs for templates. It does not provide a browser-facing Google URL.

## Video

`templates/video.mustache` contains an HTML5 `<video>` element without native browser controls. `amd/src/nativevideo.js` owns the Drive Resource control surface and uses the HTML5 Media API.

Playback order:

```text
protected.php?stream=transcoded
  -> drive_stream_resolver
  -> progressive MP4 playback URL
  -> http_range_proxy

if unavailable/error:

protected.php?stream=source
  -> drive::protected_content_url
  -> http_range_proxy
```

The browser sees only the two Moodle protected URLs.

The current progressive resolver uses the public playback endpoint used by the Drive web client. It is intentionally isolated in `drive_stream_resolver` because it is an upstream compatibility integration and may need maintenance when Google changes its web playback service.

## Audio

`templates/audio.mustache` uses native `<audio controls>` with the source pointing to `protected.php`. `amd/src/nativeaudio.js` tracks active time and resume position.

## PDF-like resources

The following types are PDF-like:

- PDF;
- Google Docs;
- Google Sheets;
- Google Slides.

Docs/Sheets/Slides are converted to a server-side Google export URL and proxied as PDF. The browser renders the protected Moodle endpoint using locally bundled PDF.js.

`amd/src/pdfviewer.js` provides page navigation, zoom, fit, fullscreen, search and progress persistence.

### PDF cache

A cold Google Drive PDF is proxied immediately. When caching is enabled, an ad-hoc `precache_pdf` task warms the complete PDF into:

```text
$CFG->localcachedir/mod_videoplayer/pdf/
```

Subsequent requests can be served by `protected_stream` from the local cache. `cleanup_pdf_cache` removes stale cache artifacts on schedule.

## Byte streaming

`http_range_proxy` forwards a single validated byte range and streams received chunks directly to the response. It preserves safe protocol metadata required by media clients and PDF.js.

`protected_stream` implements equivalent range handling for trusted local/cache files.

Neither path uses `file_get_contents()` to buffer an entire learning resource before delivery.

## Progress and completion

```text
video -> nativevideo.js ----+
audio -> nativeaudio.js ----+--> mod_videoplayer_save_progress
PDF   -> pdfviewer.js -------+          |
generic -> progress.js ------+          v
                                 progress_service
                                      |
                        +-------------+--------------+
                        |                            |
                 videoplayer_views          Moodle Completion API
                        |
                 progress_updated
                 resource_completed
```

Video/audio persist `lastposition`, `duration` and active `timespent`. PDF persists `lastpage`, `totalpages` and active time. Generic resources use the generic progress tracker only.

## Database compatibility

Some legacy activity columns are retained in the schema and backup format to make upgrades/restores from older `mod_videoplayer` installations safe. New runtime code does not depend on obsolete player/viewer fields.

## Third-party dependencies

Only PDF.js is required by the learner viewer and is bundled locally. Video/audio have no player-library dependency. No CDN is used.
