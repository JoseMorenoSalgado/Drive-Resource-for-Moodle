# Moodle Marketplace readiness

## Component identity

Elearning Stream is distributed as the Moodle activity module `mod_videoplayer`.
The component name is intentionally historical so installed sites can upgrade
without a component rename or database migration.

Current release candidate:

- product: Elearning Stream;
- component: `mod_videoplayer`;
- release: `1.3.0-rc4-m45`;
- build: `2026100203`;
- supported Moodle line: 4.5 LTS;
- PHP: 8.1+.

Do not widen the declared Moodle support range until the complete CI and
functional matrix has been run for that Moodle major version.

## Moodle package boundary

The Moodle ZIP contains only the plugin installed at:

```text
mod/videoplayer
```

The WHMCS commercial control plane under `integrations/whmcs/` is excluded
from the Moodle package. Provider management secrets are never part of the
Moodle ZIP.

## Required Moodle APIs

The production plugin uses Moodle APIs for:

- authentication and course/module access;
- capabilities;
- Completion API;
- Privacy API;
- Backup & Restore;
- File API for historical local protected PDFs;
- Events API;
- external functions/AJAX;
- scheduled and adhoc tasks;
- cache definitions;
- Moodle forms and output/rendering.

Direct database access is limited to this plugin's own Moodle tables.

## Third-party code

PDF.js is bundled locally and declared in `thirdpartylibs.xml`.
`thirdpartylibs/pdfjs/readme_moodle.txt` records version, upstream source,
license and reproduction information. Runtime CDN dependencies are forbidden.

## External service disclosure

Managed video requires an Elearning Stream service. The Moodle plugin stores
only:

- public gateway URL;
- commercial Service ID;
- installation-scoped token.

The external service owns video provider credentials, wallet/quota policy and
provider asset management. The Moodle browser never receives provider
management credentials.

## Release checks

A Marketplace candidate must pass:

1. PHP syntax on all supported PHP versions.
2. Moodle Code Checker with zero warnings.
3. PHPDoc validation.
4. Moodle plugin validation.
5. XMLDB/savepoint validation.
6. Mustache lint.
7. AMD lint/build parity.
8. PHPUnit.
9. MariaDB and PostgreSQL.
10. Privacy API review.
11. Backup/Restore review.
12. install.xml vs upgrade.php parity.
13. no retired Google runtime path.
14. no provider secret or signed provider URL in learner HTML/JavaScript.
15. no dead audio/image/generic/gamification runtime assets.
16. local PDF.js third-party metadata present.

## Submission material

Before submitting a stable release, provide in Moodle Marketplace:

- concise description and screenshots;
- installation/configuration instructions;
- clear external-service/commercial dependency disclosure;
- source repository and issue tracker;
- privacy/data-flow explanation;
- supported Moodle versions;
- release notes;
- any reviewer test account or staging credentials through the Marketplace
  review channel, never committed to the repository.

The GitHub release and Moodle ZIP must be generated from the same green commit.
