# Moodle 5.x compatibility audit

## Product contract

Drive Resource `1.1.30-beta` supports Moodle 4.5, 5.0, 5.1 and 5.2 from the same package.

```php
$plugin->requires = 2024100700;
$plugin->supported = [405, 502];
```

The supported PHP runtime is 8.2 or 8.3.

## Automated matrix

The GitHub Actions workflow `.github/workflows/moodle-supported-ci.yml` installs the plugin on both `MOODLE_405_STABLE` and `MOODLE_500_STABLE`. Each branch is validated using:

- PHP 8.2 with MariaDB 10.11;
- PHP 8.2 with PostgreSQL 15;
- PHP 8.3 with MariaDB 10.11;
- PHP 8.3 with PostgreSQL 15.

Each environment executes:

1. clean Moodle and plugin installation;
2. PHP syntax validation;
3. Moodle Coding Style;
4. PHPDoc validation;
5. official plugin validation;
6. XMLDB upgrade-savepoint validation;
7. Mustache validation;
8. AMD/JavaScript validation;
9. PHPUnit.

## Compatibility changes completed

- Declared the Moodle 4.5–5.2 supported range in `version.php`.
- Replaced the Moodle 5-only CI definition with a Moodle 4.5 and 5.0 matrix.
- Made `videoplayer.videourl` nullable for Moodle-local protected PDFs.
- Added an idempotent XMLDB upgrade step that preserves existing Drive URLs.
- Normalised Backup/Restore task classes for Moodle 5.0.
- Added PHPUnit tests for Drive URL handling and the shared Moodle 4.5–5.2 API contract.
- Added the standard `videoplayer:addinstance` capability language string.
- Formatted the English and Spanish language packs with the ruleset loaded by an installed Moodle 5.0 environment.
- Kept PHPUnit 11 attributes and added PHPUnit 9-compatible docblock metadata where cross-version test discovery requires it.
- Kept viewer CSS outside the globally compiled module stylesheet to preserve third-party course-format navigation.
- Validated the AMD source with Moodle 5.0 Grunt and regenerated all production bundles and source maps under `amd/build/`.
- Removed the one-time write-enabled AMD rebuild workflow after the generated bundles were committed.

## APIs reviewed

The implementation uses APIs available in Moodle 4.5 and Moodle 5.x:

- Activity module callbacks and `moodleform_mod`;
- File API;
- Completion API;
- Events API;
- External API under `core_external`;
- scheduled and ad-hoc Task API;
- Privacy API;
- Backup and Restore API;
- XMLDB;
- AMD `core/ajax` and `core/notification`.

No Moodle 5-only API is required by the protected-delivery, progress, completion, privacy or backup runtime paths. Moodle 4.5 is the compatibility floor.

## Required staging validation

Automated compatibility does not replace functional QA on the target site. Before production deployment, validate:

- upgrade from the currently installed Drive Resource version;
- activity creation and editing;
- local protected PDF delivery;
- Google Drive PDF cold-cache and cache-hit behaviour;
- video start, pause, seeking and resume;
- physical iPhone Safari playback;
- progress and Completion API state;
- Backup/Restore;
- Privacy API export and deletion;
- Tiles/Mosaico animated navigation without modifying the third-party format;
- Moodle developer debugging with no new warnings.

For Moodle 4.5-specific validation and PHPUnit compatibility details, see `docs/moodle-4.5-compatibility.md`.
