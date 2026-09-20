# Drive Resource 1.1.33-rc17 manual release gate

This checklist is the execution record for the broader commercial hardening phase defined in `docs/hardening-validation.md`.

Run on a staging Moodle 4.5 site before merging to `main` or tagging a stable release.

## Installation/upgrade

- Fresh install completes without XMLDB errors.
- Upgrade from the currently deployed rc15/rc16 completes without `ddldependencyerror`.
- `videoplayer_views.lastposition` and `duration` exist after upgrade.
- Purging caches produces no plugin errors.
- Moodle developer debugging is clean.

## Authorization

- Logged-out access to `protected.php?id=<cmid>` is denied/redirected to login.
- Unenrolled user without `mod/videoplayer:view` cannot fetch bytes.
- Enrolled learner can fetch only an activity they can access.
- Teacher report requires `mod/videoplayer:viewreport`.
- Page HTML contains no raw Google file ID, direct download URL or temporary playback URL.

## Known-working Google Drive video regression (release blocker)

Use the exact Google Drive video that succeeded in rc15/rc16.

- Custom Drive Resource player appears; Google Drive player/UI does not.
- Video starts promptly.
- Play/pause works.
- Seek forward/backward works repeatedly.
- Volume/mute works.
- 0.5x, 0.75x, 1x, 1.25x, 1.5x and 2x can be selected.
- Fullscreen enters/exits cleanly.
- Portrait/landscape media sizing is correct.
- Reload resumes close to the saved second.
- Progress percentage and active time update.
- Browser network requests use Moodle `protected.php` as the media URL.
- Primary progressive stream can fall back to protected source delivery without showing Google UI.
- A short buffer underrun does not flash the loading overlay immediately.
- A persistent stall triggers automatic recovery near the same playback second.
- Recovery refreshes the protected progressive stream before source fallback and never exposes a Google URL.
- Repeated upstream failures stop after the bounded recovery attempts and show the controlled player error state instead of looping forever.

### Video protocol

When the upstream resource supports ranges, authenticated tests should verify:

- `Range: bytes=0-1` -> `206` with valid `Content-Range`;
- a middle byte range -> `206`;
- `Content-Length` matches the returned range;
- `Accept-Ranges: bytes` is preserved where supported;
- invalid/HTML upstream responses are not returned as successful video bytes.

## Mobile video

On physical iPhone/iPad Safari where possible:

- tap-to-play and `playsinline`;
- pause/resume;
- forward/backward seek;
- fullscreen/native fullscreen;
- portrait/landscape rotation;
- lock/unlock recovery;
- cold load and repeat load.

Repeat core tests on Android Chrome.

## Audio

- Protected audio loads from Moodle URL.
- Play/pause/seek works.
- Reload resumes close to saved position.
- Active time and completion update.

## PDF / Docs / Sheets / Slides

- Local uploaded PDF renders with local PDF.js.
- Google Drive PDF renders through `protected.php`.
- Google Doc, Sheet and Slide export to PDF and render without Google viewer UI.
- Previous/next page works.
- Page number/total pages update.
- Zoom in/out and fit work.
- Search finds text and next match navigates to later matches.
- Fullscreen works.
- Reload resumes on the last page.
- Responsive/mobile layout remains usable.

### PDF range/cache

For local/cache files test:

- `bytes=0-0`;
- `bytes=0-1023`;
- `bytes=1024-`;
- `bytes=-500`;
- unsatisfiable range -> `416`;
- `HEAD` -> headers only.

For a cold Drive PDF:

- first request does not wait for full-file warming;
- cache task is queued when enabled;
- after cron, repeated request can return cache `HIT`;
- document quality is unchanged.

## Image/generic resource

- Image renders from a Moodle protected URL.
- Context-menu deterrent follows activity configuration.
- Generic resource link points to Moodle protected endpoint, not Drive.

## Completion/progress

- Video stores `lastposition`, `duration`, `timespent`, percentage and completed state.
- PDF stores last/total page and active time.
- `progress_updated` appears in Moodle logs.
- `resource_completed` fires only on first incomplete -> complete transition.
- Moodle activity completion is updated at the configured threshold.
- No duplicate progress writes from multiple trackers for the same resource type.

## Teacher report

- Report is paginated.
- Default sorting by last modification works.
- Full name, email, active time, media position, percentage, completion and timestamp render correctly.
- A course with many activity instances does not perform one instance query per row on `index.php`.

## Backup/restore

- Activity settings restore.
- Local PDF File API content restores.
- With user data enabled, progress including media position/duration restores.
- Rewards restore when present.

## Privacy

- User export contains declared progress/reward fields.
- Context deletion removes views/rewards.
- Approved-user deletion removes only requested users.

## Final gate

Do not merge/tag if the known-working Drive video regresses, any protected URL leaks a Drive identifier/upstream URL, range seeking fails on target devices, or upgrade produces database errors.

## Hardening evidence header

Before signing off this checklist, record the commit SHA, Moodle/PHP/database versions, web server/reverse proxy, browser/device versions, tested resource sizes, and any load-test concurrency/duration/error-rate figures. A pass without reproducible environment evidence is not sufficient for stable promotion.
