# Elearning Stream for Moodle

Elearning Stream is a protected-video activity for Moodle. The historical Moodle component and folder remain `mod_videoplayer` / `videoplayer` so existing installations can upgrade without a component migration.

## Release

- Product: **Elearning Stream**
- Moodle component: `mod_videoplayer`
- Release: **1.2.0-rc5-m45**
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
