# RC17 refactor report

## Objective

Refactor the Moodle 4.5 compatibility build without regressing the Google Drive video path confirmed working in rc15/rc16.

## Completed

- Centralized access control in `activity_context`.
- Centralized resource normalization in `resource_descriptor`.
- Reduced `protected.php` to an authenticated controller.
- Separated protected delivery orchestration, upstream policy, local/cache streaming and HTTP Range proxy responsibilities.
- Kept native HTML5 as the only video/audio player runtime.
- Removed residual Plyr, Video.js, StPageFlip/book/ebook paths and obsolete Google viewer presentation code.
- Kept PDF.js local and CDN-free.
- Added exact media resume position/duration persistence.
- Removed duplicate per-resource progress writers and corrected visible-time accounting.
- Added audio tracking and generic-resource tracking separation.
- Fixed PDF search listener duplication, bounded search memory behavior and serialized PDF progress writes.
- Added paginated teacher reporting and removed index-page N+1 instance queries.
- Updated Privacy API, Backup & Restore and Moodle completion/event integration.
- Added upstream host policy and cache-warm validation.
- Made protected delivery mandatory in active runtime/settings.
- Added Moodle 4.5 CI and focused unit tests.
- Rewrote release documentation and manual regression gate.

## Static validation executed

- PHP syntax validation across 42 PHP files.
- XML parsing for plugin XML files.
- JavaScript syntax validation for all AMD source/build modules.
- English/Spanish language-key parity and duplicate-key checks.
- Verification that all plugin-local strings referenced by PHP/Mustache exist.
- Runtime-source grep confirms no Plyr, Video.js, StPageFlip, Google preview or Drive iframe references.
- Browser-facing output/template scan confirms no Google file id/upstream host reference.

## Runtime validation still required

Static validation cannot reproduce Moodle session/enrolment rules, Google Drive network behavior, real browser media stacks or database upgrades. Before merge/tag, run `manual-test-checklist.md` on a Moodle 4.5 staging site.

The release blocker is the exact Drive video previously confirmed working in rc15/rc16. RC17 must reproduce play, seek, fallback, fullscreen and resume before it is merged.

## Known external dependency risk

Google Drive progressive playback is an upstream compatibility integration rather than a stable contracted media API. The resolver is isolated and the protected source fallback is retained so future upstream changes can be handled without replacing the player architecture.

## GitHub validation

RC17 was applied to the refactor branch from the verified release payload. This commit triggers the final Moodle 4.5 CI matrix against the completed source tree.

- Moodle PHPCBF automatic style corrections were applied before the final CI pass.
- Direct Moodle PHPCBF formatting was applied to the PHP sources before the final validation pass.
