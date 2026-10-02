PDF.js bundled for Elearning Stream
=================================

Component: mod_videoplayer
Library: PDF.js
Version: 6.0.227
Upstream: https://github.com/mozilla/pdf.js
License: Apache License 2.0

Purpose
-------
Elearning Stream uses PDF.js locally to render Moodle-local protected PDF
resources. No CDN copy of PDF.js is loaded by the plugin.

Bundled production files
------------------------
- pdf.min.mjs
- pdf.worker.min.mjs

Reproducible source
-------------------
The corresponding upstream source is the PDF.js v6.0.227 release/tag in the
Mozilla pdf.js repository. To reproduce the distribution, use a supported
Node.js environment for that release, install the upstream dependencies from
the lockfile, and build the generic/minified distribution according to the
PDF.js build documentation. Only the browser runtime and worker ESM artifacts
listed above are copied into this directory.

Moodle packaging notes
----------------------
- thirdpartylibs.xml declares this dependency and its license.
- The library is distributed inside the Moodle plugin ZIP.
- Elearning Stream does not modify PDF.js source semantics.
- Provider credentials and remote document URLs are not embedded in these files.
