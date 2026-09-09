# Moodle 4.5 compatibility audit

## Product contract

Drive Resource `1.1.30-beta` supports Moodle 4.5, 5.0, 5.1 and 5.2 from one plugin package.

```php
$plugin->requires = 2024100700;
$plugin->supported = [405, 502];
```

The commercial runtime baseline remains PHP 8.2 or 8.3. Moodle 4.5 itself can run on older PHP versions, but Drive Resource intentionally standardises on PHP 8.2+ so the same production code and test contract are used across Moodle 4.5–5.2.

## Automated compatibility matrix

The workflow `.github/workflows/moodle-supported-ci.yml` installs the plugin on both:

- `MOODLE_405_STABLE`;
- `MOODLE_500_STABLE`.

Each branch is validated with:

- PHP 8.2 + MariaDB 10.11;
- PHP 8.2 + PostgreSQL 15;
- PHP 8.3 + MariaDB 10.11;
- PHP 8.3 + PostgreSQL 15.

The gate runs clean installation, PHP lint, Moodle Coding Style, PHPDoc, plugin validation, XMLDB savepoints, Mustache validation, AMD/Grunt validation, PDF.js production contracts and PHPUnit.

## Cross-version PHPUnit support

Moodle 4.5 uses PHPUnit 9 while Moodle 5.0 uses PHPUnit 11. Drive Resource keeps PHPUnit 11 attributes and the equivalent PHPUnit 9 docblock metadata for data providers and coverage declarations where required. This allows the same test sources to execute on both generations without maintaining a separate test branch.

## Core APIs reviewed

The production implementation is constrained to APIs available on Moodle 4.5 and newer:

- activity module callbacks and `moodleform_mod`;
- File API;
- Completion API;
- Events API;
- External API under `core_external`;
- scheduled and ad-hoc Task API;
- Privacy API;
- Backup and Restore API;
- XMLDB;
- `core\session\manager::write_close()`;
- AMD `core/ajax` and `core/notification`.

The protected-delivery architecture does not depend on a Moodle 5-only authentication, streaming, completion or privacy API.

## Security contract

Moodle 4.5 support does not create a weaker code path. Every protected request must still resolve the course module, course and activity instance, call `require_login()`, create `context_module`, require `mod/videoplayer:view`, and return only Moodle-owned protected URLs.

Raw Google Drive file IDs, direct download URLs, preview URLs and upstream redirect URLs remain server-side.

## Functional release gate

Automated CI is necessary but not sufficient for a commercial release. Before declaring Moodle 4.5 production-ready, staging must verify:

- clean install on Moodle 4.5;
- upgrade from the currently deployed Drive Resource version;
- teacher activity creation and editing;
- local protected PDF delivery;
- Google Drive PDF cold-cache and cache-hit behavior;
- PDF.js zoom, fit, fullscreen, page navigation and mobile scrolling;
- protected video start, pause, seek and resume;
- valid `206 Partial Content` behavior;
- progress and Completion API state;
- Backup/Restore;
- Privacy API export and deletion;
- course-format isolation;
- developer debugging with no new warnings.

## Unsupported branches

Moodle 4.4 and older are outside the release contract. Supporting those branches would require a separate compatibility review and CI gate rather than lowering the current Moodle 4.5 baseline silently.
