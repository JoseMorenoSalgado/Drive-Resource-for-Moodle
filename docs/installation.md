# Elearning Stream installation and upgrade

## Supported platform for 1.2.0-rc6-m45

- Moodle 4.5 LTS
- PHP 8.1+
- PHP cURL extension
- MariaDB/MySQL or PostgreSQL supported by Moodle 4.5
- HTTPS for production
- Moodle cron enabled
- writable `$CFG->localcachedir`

This compatibility package declares Moodle 4.5 only. Do not install it on Moodle 5.x without a separately tested release.


## Production connection model

Install the matching **Elearning Stream Gateway 0.5.1** companion in WHMCS. In the addon configure **Public Gateway URL** to the branded HTTPS endpoint that customers will paste into Moodle; the recommended production value is:

```text
https://stream.elearningcloud.io
```

That hostname must terminate HTTPS and reverse-proxy or serve the addon root without changing signed request methods, bodies, paths or headers. Do not use an HTTP 301/302 redirect for the gateway API.

In Moodle configure only:

- **Elearning Stream connection URL**;
- **Service ID**;
- **Service token**;
- **Gateway timeout** (15 seconds recommended).

The customer portal now displays these connection values.

For video products, set **Retention Days = 0** when deleting the final Moodle activity should remove the provider video on the next Moodle adhoc-task execution. Use a positive value only when a recovery grace period is intentionally required. After changing an existing WHMCS product, run **Module Commands → Change Package** for existing services so the per-service retention policy is refreshed.
 The Moodle site's own URL remains an internal service binding and is not the connection URL pasted into Moodle.

### Provider configuration

The WHMCS product has independent provider selectors:

- **Video Provider**: Elearning Stream in 0.5.0;
- **Protected PDF Storage**: Disabled or S3-compatible object storage;
- independent provider profiles for each lane.

The addon can store S3-compatible settings for Amazon S3, Cloudflare R2, Wasabi, Backblaze B2, Hetzner Object Storage and custom S3 endpoints. This release does **not** yet enable the protected-PDF S3 data plane; configuration is staged for the dedicated adapter and must not be presented as working PDF storage until that adapter passes production validation.

New Moodle activities are video-only. Existing Google Drive/local-PDF activities remain available only for backward compatibility.


## Filesystem installation

Place the plugin at:

```text
<moodle-root>/mod/videoplayer
```

Verify the local PDF.js files exist:

```text
mod/videoplayer/thirdpartylibs/pdfjs/pdf.min.mjs
mod/videoplayer/thirdpartylibs/pdfjs/pdf.worker.min.mjs
```

There is no Plyr, Video.js, StPageFlip or CDN requirement.

## Upgrade commands

On staging first:

```bash
php admin/cli/maintenance.php --enable
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
php admin/cli/maintenance.php --disable
```

If the site already runs rc16, rc17 applies database version `2026092013` and adds `lastposition` and `duration` to `videoplayer_views`.

Do not manually edit Moodle database columns for this release; use the upgrade script.

## Web-server/PHP considerations

Large video/PDF delivery is streamed by PHP. Ensure:

- reverse proxy and web-server timeouts are compatible with long media requests;
- PHP cURL is enabled;
- output compression/buffering rules do not rewrite range responses;
- HTTPS certificate validation works from the Moodle server;
- Moodle/PHP can write `$CFG->localcachedir`;
- reverse proxies do not buffer or rewrite byte-range video responses in a way that prevents timely delivery;
- infrastructure timeouts are longer than normal range requests but do not mask a broken upstream indefinitely; the plugin itself aborts effectively stalled upstream transfers.

The plugin does not require increasing PHP memory to the size of a video because the proxy streams chunks rather than buffering the full object.

## Google Drive sharing

The Moodle server must be able to access the linked Drive resource using the sharing permissions configured for that resource. A link that only works for a different authenticated Google account cannot be made accessible by the plugin without an authenticated Drive API integration.

Paste a normal supported Drive/Docs sharing URL into the activity. The learner must not be given the direct source URL separately.

## Post-upgrade validation

After installing rc19:

1. purge Moodle caches;
2. hard-refresh the browser;
3. open the exact video known to work in rc15/rc16;
4. verify play, seek, fullscreen, speed and resume;
5. while playing, throttle/interrupt the network long enough to trigger buffering and confirm playback recovers near the same second without exposing Google UI;
6. verify the page source/network requests show Moodle `protected.php`, not a Google viewer;
7. test a PDF and a Google Doc/Sheet/Slide export;
8. run cron so PDF cache tasks can execute;
9. verify teacher progress report;
10. test backup/restore and Privacy API on staging.

Use `docs/manual-test-checklist.md` for the complete gate.

## PDF cache

With caching enabled, complete PDFs are stored beneath:

```text
$CFG->localcachedir/mod_videoplayer/pdf/
```

This directory must be writable by the PHP/cron user and must remain outside the public web root.

Cache diagnostics may include `X-Drive-Resource-Cache` values such as `HIT`, `MISS`, `MISS_QUEUED`, `LOCAL` or `BYPASS`.

## Rollback

Before upgrading a commercial production site, take a database backup and code backup. If a regression is found, restore both database and code from the same pre-upgrade point; do not simply downgrade plugin code after a schema upgrade and assume the database is compatible.

## Hardening validation before production promotion

For a commercial production rollout, installation success is necessary but not sufficient. Execute the complete gate in `docs/hardening-validation.md` on staging, including physical mobile-device playback, network interruption/recovery, byte-range protocol checks, long-play soak, PDF/document export paths, Privacy/Backup/Completion verification and production-like concurrency measurements.

Record the tested commit SHA and environment. Do not promote an RC build while any release-blocking hardening criterion remains unresolved.

## RC18 deep-cleanup validation

When upgrading from RC17, no schema change is required for the canonical type-resolution cleanup. Purge Moodle caches after deploying the updated code and verify at least one existing activity stored as `type=auto` with a normal `drive.google.com/file/d/.../view` URL. It must resolve consistently in the course index and learner view.

Legacy `displaymode` and `disabledownload` columns remain in the database for restore compatibility; administrators should not manually remove them.


## RC19 progress schema upgrade

Upgrading from RC18 or earlier automatically adds the nullable `videoplayer_views.watchedranges` field through Moodle XMLDB. No manual SQL migration is required. Complete the normal Moodle upgrade before learners resume video activities.


## RC19 completion-form hotfix validation

RC19 is a code/API compatibility hotfix with no schema change. After deployment and cache purge, create a new Drive Resource activity and edit an existing one in a course with completion tracking enabled. The settings form must open normally, automatic completion must expose the progress-percentage rule, and saving the activity must not raise `get_suffixed_name()` errors.

## Bunny Stream + WHMCS beta installation

The Bunny provider requires two separately deployed components.

### WHMCS

1. Copy `integrations/whmcs/modules/addons/driveresource_gateway/` to the WHMCS `modules/addons/` directory.
2. Activate **Drive Resource Media Gateway** in WHMCS.
3. Configure the Bunny Stream Library ID and Bunny Stream API key in the addon. These credentials stay in WHMCS.
4. Copy `integrations/whmcs/modules/servers/driveresource/` to WHMCS `modules/servers/`.
5. Create a WHMCS server/product using the **Drive Resource Video** provisioning module.
6. Set **Included Storage GB** to 7 (or the commercial allowance), enable/disable overage, and set the retention period.
7. For Usage Billing, enable the `video_storage_gb` metric on the WHMCS product and configure its included amount and per-GB overage price. Keep this included amount aligned with the provisioning-module quota.
8. Set the service's **Moodle Site URL** to the exact HTTPS `$CFG->wwwroot` value, including a subdirectory if Moodle is installed in one.
9. Provision the WHMCS service and obtain its generated service ID/token for the Moodle administrator.

### Moodle

Under Drive Resource administration settings configure:

- WHMCS gateway URL: the HTTPS base of the deployed addon, for example `https://billing.example.com/modules/addons/driveresource_gateway`;
- WHMCS service ID;
- WHMCS service token;
- gateway timeout.

Do not enter a Bunny API key in Moodle.

After upgrading to database version `2026092103`, purge Moodle caches. Verify that the activity form offers **Bunny Stream (direct upload)** and that a teacher with `mod/videoplayer:uploadvideo` can select a video, receive quota information, upload it directly and save the activity.

### Beta validation boundary

For `1.2.0-beta1-m45`, verify ingestion and accounting only. Bunny learner playback is deliberately gated until the secure HLS playback phase is implemented. Google Drive and local PDF behavior must continue to pass their existing regression checks.

## Beta3 DDL recovery

If an upgrade from an earlier RC/beta stops with:

```text
Unknown column 'duration' in 'videoplayer_views'
ALTER TABLE ... ADD watchedranges ... AFTER duration
```

deploy `1.2.0-beta8-m45` or newer and run the normal Moodle upgrade again:

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

The `2026092202` repair step verifies and creates missing `lastposition`, `duration` and `watchedranges` columns without relying on physical column order. Do **not** run a manual `ALTER TABLE`; the migration is designed to recover the interrupted upgrade while preserving existing progress records.


## Beta5 partial-schema recovery

If Moodle reports `Unknown column 'completionprogressenabled'` after an earlier RC/beta installation, deploy `1.2.0-beta8-m45` or newer and open the normal Moodle upgrade page or run:

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

Build `2026092204` detects and recreates missing completion, Bunny provider metadata and watched-progress fields. Do not issue manual `ALTER TABLE` statements before attempting the beta5 repair.


## Elearning Stream URL workflow

After configuring the WHMCS companion, teachers can choose **Elearning Stream** in the activity form and select either **Upload a new video** or **Use an existing video URL**.

For an existing video, paste a supported HTTPS playback/embed URL. Moodle extracts only the video GUID and sends that identifier to the authenticated WHMCS gateway. WHMCS verifies the video in the configured provider library, rejects assets assigned to another service, and accounts storage before the Moodle activity is saved. The original pasted URL is not stored in the Moodle activity table.


## Elearning Stream protected playback requirements

In the WHMCS Drive Resource Media Gateway configure the fields shown as:

- **Elearning Stream Library ID**
- **Elearning Stream API Key**
- **Elearning Stream CDN Hostname**
- **Elearning Stream Token Key**
- **Elearning Stream Playback TTL** (300 seconds recommended)

The provider video library must have MP4 fallback enabled. Videos that were encoded without an MP4 fallback cannot be delivered through the native HTML5 protected playback path until the provider generates that fallback.

Learners never receive the upstream CDN URL. Their browser requests `mod/videoplayer/protected.php`, which validates Moodle access and then proxies the authorized MP4 byte ranges.


## Beta8 Elearning Stream connection check

Before a teacher can upload or paste an Elearning Stream video URL, Moodle must have these three settings under **Site administration > Plugins > Activity modules > Drive Resource**:

- **WHMCS gateway URL** — use the addon URL itself, for example `https://billing.example.com/modules/addons/driveresource_gateway`;
- **WHMCS service ID** — the provisioned WHMCS service id bound to this Moodle site;
- **WHMCS service token** — the service-scoped token generated by the Elearning Stream provisioning module.

Beta8 validates these settings in the activity form before save. If any setting is missing, the teacher receives a field-level validation message instead of a `whmcsgatewaynotconfigured` exception from `lib.php`.


## WHMCS 0.3 multi-client upgrade

WHMCS gateway 0.3.0 is installed once for all customers. Do not install a separate addon copy for each Moodle.

After replacing the WHMCS files, open **Drive Resource Media Gateway** in the WHMCS administrator area once so WHMCS executes the addon upgrade hook. Existing service rows receive:

```text
backend_key     = elearningstream
backend_profile = default
```

Existing Moodle service ids and tokens remain valid.

The provisioning product retains its existing first three module options (included storage, overage, retention) and adds backend/profile after them. Existing products therefore keep their current quota semantics.

The 0.3 dashboard lists multiple customer services and their Moodle site, plan, backend, storage usage, quota, video count and state. Create one WHMCS service per independently authenticated Moodle installation; several services may belong to the same WHMCS client.

### Future S3

The database and provisioning contract already carry a provider-neutral backend key/profile. `s3compatible` exists only as a reserved architecture entry and cannot be provisioned in 0.3.0. Do not manually set a service to S3: the S3 adapter must first implement multipart upload, signed delivery, usage reconciliation and lifecycle/retention.


## WHMCS 0.3.1 service provisioning

The Elearning Stream provisioning module does not require a WHMCS server object. In **Product/Services → Module Settings**, select the Elearning Stream module and save the product. Existing services may continue to show **Server: None/Ninguno**; this is valid.

For a service that existed before provisioning, open the service and execute **Module Commands → Create**. Successful provisioning writes the tenant row and stores:
- Username: `dr-{WHMCS service id}`;
- Password: service-scoped Moodle gateway token.

The module also exposes Moodle Gateway URL, Moodle Service ID and Moodle Service Token on the administrator service page. Copy those three values into Moodle Drive Resource settings.

If Create returns an error, do not type an arbitrary password into the WHMCS service. Correct the module/configuration error and run Create again so WHMCS and the gateway token hash remain synchronized.


## Recover an unprovisioned WHMCS service

If a service is visible in WHMCS but its Username and Password are empty, do not type a password manually.

With WHMCS companion 0.3.2, open the service and run **Generar/Reparar conexión Moodle** under Module Commands. The action provisions the tenant even when the WHMCS service is already Active.

After success, the administrator service fields show:
- Estado conexión Moodle: Provisionada;
- Moodle Gateway URL;
- Moodle Service ID;
- Moodle Service Token.

Copy those values to the matching Moodle Drive Resource settings. Use **Rotar token Moodle** only when the previous token must intentionally be invalidated.


## WHMCS 0.4.0 client portal upgrade

Install the WHMCS 0.4.0 companion by replacing both module directories, then open **Drive Resource Media Gateway** in the WHMCS administrator area so the addon upgrade hook adds connection and transfer columns plus the idempotent usage-report table.

Upgrade the matching Moodle site to **Drive Resource 1.2.0-beta10-m45** and run Moodle upgrade/cron. Beta9 creates `videoplayer_transfer_events`, adds the signed `gateway-status.php` endpoint and schedules transfer synchronization every five minutes.

After both sides are upgraded:

1. provision or repair the WHMCS service if it has no token;
2. enter Gateway URL, Service ID and Service Token in Moodle;
3. open the customer's WHMCS service dashboard;
4. click **Validar conexión**;
5. confirm the connection card shows **Conectado**;
6. play an Elearning Stream video long enough to deliver bytes;
7. run Moodle cron or wait for the five-minute task;
8. confirm the transfer card increases.

The client can edit the Moodle URL only when no active asset references remain. If a production Moodle with active content changes domain, use a controlled migration workflow rather than forcing a URL reassignment.

The client video list permits permanent deletion only for videos with zero active Moodle references.


## WHMCS 0.4.1 branded video hostname

After upgrading the WHMCS companion to 0.4.1, open **System Settings → Addon Modules → Drive Resource Media Gateway** and set:

```text
Elearning Stream CDN Hostname:
vz-xxxxxxxx-xxx.b-cdn.net

Elearning Stream Public Aliases:
video.elearningcloud.io
```

Enter hostnames only: no `https://`, paths or wildcards. Multiple aliases can be separated with commas.

Upgrade the Moodle plugin to **Drive Resource 1.2.0-beta10-m45**. A teacher can then paste a video URL using the configured branded hostname. Moodle sends the URL transiently to WHMCS, and WHMCS verifies both the hostname and the underlying video against the configured Video Library.

WHMCS 0.4.1 also installs/updates the redacted audit table automatically. The multi-client addon dashboard shows recent audit entries. The customer service portal selects English or Spanish from the active WHMCS language context.


## WHMCS 0.4.2 client-area verification

After replacing the WHMCS module files with companion 0.4.2:

1. open **Products/Services → Stream Pro → Module Settings**;
2. confirm the provisioning module is **Elearning Stream / driveresource**;
3. save the product;
4. open the customer's service in the WHMCS administrator area;
5. run **Generate/Repair Moodle connection** if Username/Password were not provisioned by Drive Resource;
6. the resulting Username should be `dr-{service_id}`;
7. open the same service from the customer Client Area.

The customer page should show Drive Resource cards for Moodle connection, storage, current-month transfer and videos. Companion 0.4.2 also supplies a product-details output fallback for compatible custom WHMCS themes that omit standard provisioning-module output.

If the Client Area still shows only the generic WHMCS domain/username cards and the service Username is not `dr-{service_id}`, verify the product is actually assigned to the `driveresource` module and reprovision that service.


## WHMCS 0.4.3 direct installation

WHMCS 0.4.3 removes the previous `whmcs-root/` packaging wrapper. Extract the ZIP **directly into the WHMCS document root**. The archive places files at:

```text
modules/addons/driveresource_gateway/
modules/servers/driveresource/
```

After extraction, open **Addons → Drive Resource Media Gateway** and review **Drive Resource installation health**.

A healthy commercial configuration should report:

```text
Loaded companion: 0.4.3
Server module: OK
Products using driveresource: >= 1
Pending provisioning: 0
Generic usernames: 0
Wrong product type: 0
```

For **Stream Pro** use Product Type **Other** and provisioning module **Elearning Stream / driveresource**. WHMCS documents Product Type Other for non-hosting products; using Shared Hosting causes generic domain/account UI that is not appropriate for Elearning Stream.

If an existing service shows a username such as one derived from the domain instead of `dr-{service_id}`, run **Generate/Repair Moodle connection** from the administrator service page. Do not copy the generic WHMCS password into Moodle: 0.4.3 recognizes only the module-generated 64-character hexadecimal service token as a valid Moodle credential.


### Organize existing videos by virtual classroom

Gateway 0.5.3 creates one Bunny collection per WHMCS service automatically when a new video is uploaded or imported. The collection name is similar to `S288 - campus.aspeten.org`.

For services that already owned videos before 0.5.3:

1. update the WHMCS companion to Gateway 0.5.3 and open the addon once so the schema upgrade runs;
2. open the customer's WHMCS service;
3. run **Module Commands → Organize virtual classroom**;
4. verify the service now shows its virtual classroom name and the existing videos appear in that Bunny collection.

No Moodle token rotation or video re-upload is required.
