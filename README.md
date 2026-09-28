# Elearning Stream for Moodle

Elearning Stream is a protected-video activity for Moodle. The historical Moodle component and folder remain `mod_videoplayer` / `videoplayer` so existing installations can upgrade without a component migration.

## Release

- Product: **Elearning Stream**
- Moodle component: `mod_videoplayer`
- Release: **1.2.0-rc12-m45**
- Target: Moodle **4.5 LTS**
- PHP baseline: PHP 8.1+
- Video runtime: native HTML5 Media API
- Provider secrets in Moodle: **none**

## Production activity flow

New activities are video-first. Teachers can:

1. upload a new video directly to the managed video provider; or
2. register an existing Elearning Stream video URL.

The browser uploads video bytes directly to the provider using a short-lived authorization. Provider management credentials never enter Moodle.

The Moodle activity form no longer exposes the legacy Google Drive/local-PDF source selector for new activities.

## Legacy compatibility

Existing activities created with previous releases remain readable/editable. Legacy Google Drive and Moodle-local PDF runtime code is retained only to avoid breaking installed courses and backups.

No existing database rows are rewritten during the RC1 upgrade. The new default source applies only to future activities.

## Connection settings

Moodle administrators see production-facing Elearning Stream labels rather than the billing implementation.

Configure:

- **Elearning Stream connection URL** — normally `https://stream.elearningcloud.io`;
- **Service ID** — generated/provisioned for the customer service;
- **Service token** — 64-character service-scoped token;
- **Gateway timeout** — normally 15 seconds.

The internal configuration keys keep their historical names for upgrade compatibility.


### Provider lanes

The gateway now separates **video** from **protected object/PDF storage**. Video uses Elearning Stream today. S3-compatible storage is configured independently in the gateway and is intentionally gated until the protected-PDF data-plane adapter is production-ready. This separation allows additional video providers to be added later without changing Moodle Service IDs or tokens.

## Security model

Every managed request is bound to the provisioned service. The gateway validates service status, site identity, HMAC signatures, timestamps and replay nonces before authorizing media operations.

The learner receives Moodle-owned protected URLs. Provider API keys and playback signing keys stay in the gateway.

Gateway 0.5.7 performs a one-byte server-side Range probe of each newly signed MP4 playback URL and now requires a real `206 Partial Content` response with `Content-Range`. A CDN that only returns `200 OK` is rejected because playback may start but seeking is not production-safe.

RC11 preserves the learner's requested seek position across signed-URL refresh/recovery, commits on touch `pointerup/change`, and refuses to silently fall back to second 0 after a failed seek. The learner video view is also simplified: resource-type, protection and percentage chips are no longer rendered above the player.

RC12 redesigns the teacher upload experience as a responsive upload card with drag-and-drop, selected-file summary, upload progress in percent and transferred bytes, storage/quota feedback, explicit retry/error/completion states, and mobile-first actions. The secure TUS data plane and WHMCS credential boundary are unchanged.

## Video upload and playback

Upload flow:

```text
Teacher browser
  -> Moodle capability/session validation
  -> Elearning Stream Gateway
  -> quota + service validation
  <- short-lived upload authorization
Teacher browser
  -> provider upload endpoint directly
```

Playback flow:

```text
Learner
  -> Moodle protected.php
  -> Elearning Stream Gateway authorization
  -> short-lived provider playback URL
  -> Moodle range proxy
  -> HTML5 video player
```

Moodle meters protected transfer per service and reports idempotent usage batches to the gateway.

## Provider architecture

Managed-video lifecycle transitions are coordinated by `classes/local/provider/bunny_asset_lifecycle.php`. Moodle callbacks persist local state first, then the lifecycle service queues idempotent WHMCS bind, release or rename tasks. Provider credentials and destructive provider calls remain outside Moodle.

WHMCS Gateway 0.5.7 additionally serializes bind/restore/release transitions on the provider upload row and stores references per activity+video. This prevents a replacement bind from erasing the old reference before its release task runs and prevents deletion from racing a concurrent rebind.

If an activity is deleted before its first bind task executes, Moodle forwards the original upload reservation id with the release task. WHMCS accepts that fallback only when the reservation belongs to the same service/video and has never been bound to another activity.

The WHMCS companion 0.5.0 separates:

- **video provider** — Elearning Stream today, extensible to additional managed-video providers;
- **protected PDF/object provider** — an independent S3-compatible provider lane.

The S3 control-plane/profile configuration is present in 0.5.0, but the protected-PDF S3 data plane remains gated until its upload, signed-delivery and lifecycle adapter is complete. It is not presented to teachers as a production source yet.

## Installation

Install the ZIP so Moodle contains:

```text
<moodle>/mod/videoplayer
```

Then run Moodle's normal plugin upgrade and purge caches.

The WHMCS companion is deployed separately from `integrations/whmcs/`; it is intentionally excluded from the Moodle ZIP.

## CI and hardening

The repository validates Moodle 4.5 across PHP 8.1, 8.2 and 8.3 with MariaDB and PostgreSQL, plus dedicated gateway/security/package gates.

The `mod_videoplayer` component name, database tables and internal compatibility identifiers should not be renamed without a formal Moodle migration.

## License

GNU GPL v3 or later for the Moodle plugin. Companion-module licensing is declared in the WHMCS integration source.

### Gateway upload authorization integrity

Direct TUS upload capabilities returned by the control plane are revalidated inside Moodle before they reach the teacher browser. Drive Resource requires the exact HTTPS Bunny upload host/path, standard TLS port, bounded expiration, strict identifiers and signature format. Refreshed capabilities must preserve the upload reservation and video identity originally requested by Moodle.


Gateway 0.5.8 separates the original upload filename from the customer-facing video title. Moodle renames are reflected immediately in Bunny and in the WHMCS client portal, with daily provider reconciliation for legacy/stale rows.
