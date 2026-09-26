# Elearning Stream Gateway for WHMCS

Version **0.5.2** is the multi-tenant control plane for Elearning Stream Moodle services.

It is installed once in WHMCS and manages many customer services. Provider management credentials remain server-side and are never copied into Moodle.

## Deployables

Extract the installable ZIP directly into the WHMCS document root. It installs:

```text
modules/addons/driveresource_gateway/
modules/servers/driveresource/
```

The internal module slugs remain `driveresource_gateway` and `driveresource` for upgrade compatibility. Customer-facing branding is **Elearning Stream Gateway** / **Elearning Stream**.

## Branded customer URL

Set **Public Gateway URL** in the addon configuration. Recommended:

```text
https://stream.elearningcloud.io
```

The client portal shows this clean URL, the Service ID and the service token. It no longer exposes the internal Moodle site binding as an editable customer field.

A simple cPanel deployment can point the document root of `stream.elearningcloud.io` directly at:

```text
<WHMCS-root>/modules/addons/driveresource_gateway
```

The addon includes a branded `index.php` landing page and its authenticated endpoints remain under `/api/`.

If a reverse proxy is used instead, it must preserve HTTPS, POST bodies and the signed request headers and must not convert gateway POST requests into redirects.

## Internal Moodle site binding

Connection validation still binds each service to the exact Moodle `$CFG->wwwroot`. The site URL is an internal service property used for signed validation, not the URL the customer pastes into Moodle.

For provisioning, the module resolves the Moodle site from:

1. the product custom field **Moodle Site URL**, when present; otherwise
2. the WHMCS service domain.

The customer-facing connection panel does not allow changing this binding.

## Service credentials

Each provisioned service gets:

- username `dr-{service_id}`;
- a cryptographically random 64-character hexadecimal service token;
- an internal Moodle site binding;
- quota, status and usage state.

Generic WHMCS passwords are rejected as gateway tokens.

## Independent provider lanes

0.5.x separates video and protected-object storage.

Per service:

```text
video_backend_key
video_backend_profile
object_backend_key
object_backend_profile
```

Legacy `backend_key` / `backend_profile` remain and mirror the video provider for API compatibility.

### Video provider

The production provider is currently:

```text
Elearning Stream
```

The registry is capability-based so more video adapters can be added later without changing Moodle Service IDs or tokens.

### Protected PDF / object storage

Addon configuration includes S3-compatible credentials for:

- Amazon S3;
- Cloudflare R2;
- Wasabi;
- Backblaze B2 S3;
- Hetzner Object Storage;
- custom S3-compatible endpoints.

Credentials include endpoint, region, bucket, access key, secret key and optional path-style addressing.

The product configuration has an independent **Protected PDF Storage** lane and **Object Storage Profile**. In 0.5.x the S3 provider can be configured and assigned in the control plane, but its protected-PDF upload/delivery adapter remains intentionally non-operational until the data-plane implementation is complete. This prevents a partially implemented storage path from being sold as production-ready.

## Product configuration

Use WHMCS Product Type **Other** and Module **Elearning Stream**.

Available module options:

- Included Storage GB
- Allow Storage Overage
- Retention Days
- Video Provider
- Video Provider Profile
- Protected PDF Storage
- Object Storage Profile

No WHMCS Server or Server Group is required.


### Deletion policy

For the standard Elearning Stream product, set **Retention Days = 0**. When Moodle removes the final activity reference, the gateway deletes the provider video and recalculates storage usage. If another Moodle activity still references the same asset, the video is preserved.

Use a value from 1 to 365 only when the plan intentionally provides a recovery grace period. Moodle sends release requests asynchronously through its adhoc-task queue, so Moodle cron must run normally.

## Client portal

The customer can:

- copy the clean Elearning Stream connection URL;
- copy Service ID and service token;
- generate/rotate the service token;
- validate the signed Moodle connection;
- view storage and monthly transfer;
- browse managed videos;
- delete videos only when they have no active Moodle references.

The customer cannot change the internal Moodle site binding from the self-service panel.

## Connection validation

The gateway signs a short-lived request to:

```text
https://customer-moodle.example/mod/videoplayer/gateway-status.php
```

Moodle verifies:

- exact Service ID;
- exact `$CFG->wwwroot`;
- timestamp window;
- replay nonce;
- HMAC signature derived from the service token.

The gateway does not follow redirects and rejects private/reserved network destinations.

## Video security and accounting

Elearning Stream management credentials stay in WHMCS. Moodle receives only a service-scoped token and short-lived upload/playback authorizations.

Storage, reservations and protected transfer are tracked per WHMCS service. Transfer reports are idempotent so retries do not double-count usage.

## Upgrade from 0.4.3

1. Replace both module directories with the 0.5.2 package.
2. Open/activate **Elearning Stream Gateway** so the addon upgrade hook runs.
3. Configure **Public Gateway URL**.
4. Existing `backend_key/backend_profile` values are copied into the new video-provider fields.
5. Existing service IDs, tokens, quotas and media references are preserved.
6. Re-save products if you want to assign the new protected-object lane.

Do not recreate existing services or rotate tokens solely for this upgrade.
