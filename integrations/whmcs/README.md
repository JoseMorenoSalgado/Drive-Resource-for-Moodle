# Drive Resource WHMCS companion

## Multi-tenant control plane

The WHMCS companion is installed **once** and manages many customers. Each provisioned WHMCS service is an isolated Drive Resource tenant with its own:

- WHMCS service id and customer ownership;
- exact Moodle site URL;
- service-scoped Moodle gateway token;
- status (active/suspended/terminated);
- included quota, used bytes and reserved bytes;
- overage policy and retention policy;
- storage/media backend key and backend profile.

One customer with multiple independent Moodle installations can use multiple WHMCS services under the same WHMCS client account. This preserves per-site credentials, accounting and suspension boundaries.

Addon version **0.4.2** adds a paginated administration dashboard with customer, Moodle URL, product, backend, quota, storage usage, video count, status and direct access to the WHMCS service.

## Backend model

Existing and new services default to:

```text
backend_key     = elearningstream
backend_profile = default
```

The product provisioning module stores backend identity per service. Backend changes are rejected while a service owns media or reserved/used storage; moving existing assets requires a controlled migration rather than an in-place provider switch.

The backend registry currently exposes **Elearning Stream** as the only provisionable backend. **S3-compatible Object Storage** is reserved in the registry but intentionally non-provisionable until its upload, signed-delivery, metering and lifecycle adapter is implemented.

This means S3 can be added later without replacing WHMCS tenant ids, Moodle service tokens, quotas or billing records.


This directory contains the commercial control-plane components used by the Moodle Drive Resource plugin.

It is **not part of the Moodle plugin runtime package**.

## Deployables

Copy these directories into WHMCS:

```text
modules/addons/driveresource_gateway/
modules/servers/driveresource/
```

The addon owns the Elearning Stream management credentials and Moodle-facing media gateway. The provisioning module owns the WHMCS product/service lifecycle and exposes the storage metric used by Usage Billing.

## Credential boundary

Provider management credentials must be configured only in the WHMCS addon. Moodle receives a service-scoped WHMCS gateway token and short-lived, video-scoped TUS upload authorization. It never receives the provider management `AccessKey`.

## Default commercial policy

The provisioning module defaults to:

- 7 GB included video storage;
- soft overage enabled;
- 30-day retention after the last Moodle reference is released.

Configure `video_storage_gb` for storage billing. WHMCS 0.4.0 also exposes `video_transfer_gb` as a monthly-period metric so transfer can be displayed or priced separately if your commercial plan requires it.

## Required WHMCS setup

1. Deploy and activate the addon module.
2. Configure Elearning Stream Library ID and API key.
3. Deploy the `driveresource` provisioning module.
4. Create a WHMCS product using that module. No WHMCS server assignment is required.
5. Add a product custom field named `Moodle Site URL` when the Moodle installation URL cannot be represented exactly by the standard service domain field.
6. Provision the service.
7. Copy the generated WHMCS service ID and service token into the Drive Resource Moodle administration settings.
8. Ensure WHMCS cron runs normally; the addon hook performs provider storage reconciliation, abandoned-upload cleanup and retention deletion.

Do not copy this `integrations/whmcs` directory beneath `mod/videoplayer` on a production Moodle site.


## Existing video URLs

Moodle can register an existing Elearning Stream video by URL. Moodle sends only the parsed video GUID to `api/asset-import.php`. The gateway verifies the asset in the configured library, rejects a video already owned by another active WHMCS service, accounts its provider storage against the service quota, and returns a normal upload reference for binding.

The full pasted URL is never stored by Moodle or WHMCS.


## Protected playback

Configure **Elearning Stream CDN Hostname** and **Elearning Stream Token Key** in the addon in addition to the library ID/API key. The addon generates short-lived HS256 playback URLs only for assets accounted to the authenticated WHMCS service.

Enable MP4 fallback in the provider video library. Drive Resource uses that progressive MP4 representation so Moodle can preserve native HTML5 seek/Range behavior while keeping provider URLs server-side.

Recommended **Elearning Stream Playback TTL**: 300 seconds. Moodle caches the authorization briefly and requests a fresh one when the player explicitly performs stall recovery.


## Upgrade from gateway 0.2.x

1. Replace both WHMCS module directories with the 0.4.2 package.
2. Open the Drive Resource Media Gateway addon in WHMCS so the native addon upgrade hook runs.
3. Confirm existing services appear in the multi-client dashboard.
4. Existing rows are migrated to `backend_key=elearningstream` and `backend_profile=default`.
5. Do not recreate existing services or rotate their Moodle tokens solely for this upgrade.

The provisioning module refuses new provisioning until the 0.3.0 gateway columns exist, preventing partial upgrades from failing with raw database errors.


## Provisioning and Moodle connection

The provisioning module does **not** require a WHMCS server object. After assigning the **Elearning Stream** module to a product, provision each customer service with **Module Commands → Create**.

Create generates:
- username: `dr-{service_id}`;
- a cryptographically random service token stored in WHMCS's protected service password property;
- a tenant row bound to the exact Moodle Site URL and product quota.

The WHMCS administrator service page exposes **Moodle Gateway URL**, **Moodle Service ID** and **Moodle Service Token** through the module's admin service fields so an operator can copy the three values into Moodle.

If Username/Password are empty, that service has not been provisioned yet.


## Repairing an unprovisioned service

A WHMCS service can exist and even show status **Active** without its provisioning module ever having created the gateway tenant. In that state Username/Password remain empty.

WHMCS companion 0.3.2 adds an admin module action:

**Generar/Reparar conexión Moodle**

Run it from the service's module commands. It creates or repairs the tenant, stores `dr-{service_id}` as Username, generates a service-scoped token when none exists, stores only the token hash in the gateway table, and writes the plaintext token to WHMCS protected service properties.

The action is idempotent: if a valid token already exists, repair preserves it. Use **Rotar token Moodle** only when an intentional credential rotation is required.


## Customer self-service portal

WHMCS 0.4.0 replaces the minimal service summary with a responsive service dashboard. The authenticated owner of each WHMCS service can:

- set/change the authorised Moodle URL;
- generate the initial Moodle connection token or explicitly rotate it;
- copy the Service ID/token;
- run a real signed Moodle connection check;
- see storage used versus included quota;
- see protected-video transfer for the current month;
- see the number of managed videos;
- browse a paginated video list;
- permanently delete videos that have no active Moodle activity references.

Changing the Moodle URL is rejected while active asset references exist. Deleting a referenced video is also rejected.

## Connection validation

WHMCS signs a short-lived request to `/mod/videoplayer/gateway-status.php` on the configured Moodle site. Moodle validates the exact `$CFG->wwwroot`, Service ID, timestamp, nonce and HMAC using the service token. Redirects are not followed.

## Per-service transfer

Because multiple WHMCS tenants can share the same provider Video Library, provider library traffic is not used as a per-customer number. Moodle beta9 counts bytes actually emitted through the protected Elearning Stream proxy and reports them to WHMCS in idempotent batches.

WHMCS stores one logical monthly counter per service and exposes:

- `video_storage_gb` — snapshot;
- `video_transfer_gb` — monthly period.

The idempotency ledger prevents retry double-counting and is pruned after its retention window.


## Branded public video aliases

Set **Elearning Stream Public Aliases** to customer-facing hostnames accepted when teachers paste an existing video URL, for example:

```text
video.elearningcloud.io
```

The setting contains hostnames only. The gateway never fetches the pasted URL. It validates the hostname, extracts the GUID and verifies the video through the configured Video Library API. Moodle persists only the provider GUID and WHMCS accounting reference.

## Localisation

The server module ships module-local `english.php` and `spanish.php` dictionaries. Client dashboard labels, statuses, confirmations and connection-probe messages follow the current WHMCS client/admin language context.

## Redacted audit trail

WHMCS 0.4.1 creates `mod_driveresource_audit` and records successful sensitive actions including Moodle URL changes, connection provisioning/token rotation, connection validation and client video deletion.

Audit metadata is filtered before persistence. Token/password/secret/signature/API-key/credential keys are discarded and are never shown in the administrator audit panel.


## Custom WHMCS client themes

Some third-party WHMCS themes do not render the provisioning module's standard `ClientArea()` output on the product-details page. Companion 0.4.2 registers the official `ClientAreaProductDetailsOutput` hook as a compatibility fallback.

The fallback renders only when:
- the logged-in WHMCS client owns the service;
- the product's provisioning module is exactly `driveresource`.

If the standard module output is already present, the fallback detects the same service-specific dashboard marker and removes itself to prevent duplicate output.
