# Elearning Stream for Moodle

Elearning Stream is a protected managed-video activity for Moodle. The historical Moodle component and folder remain `mod_videoplayer` / `videoplayer` so installed sites can upgrade without a component migration.

## Current release line

- Product: **Elearning Stream**
- Moodle component: `mod_videoplayer` (compatibility identifier)
- Moodle release: **1.3.0-rc2-m45**
- Gateway release: **0.6.0**
- Target: Moodle **4.5 LTS**
- PHP baseline: PHP 8.1+
- Player: native HTML5 Media API
- Provider management secrets in Moodle: **none**
- Retired external viewer/provider dependency: **none**

## Production architecture

```text
Teacher / learner
      |
      v
Moodle mod_videoplayer
      |
      | Service ID + installation token + signed request
      v
Elearning Stream Gateway (WHMCS)
      |
      +--> commercial account / wallet / usage
      +--> Moodle installations
      +--> managed video provider
      +--> future protected object-storage provider
```

Moodle never receives the provider management API key. Direct uploads use short-lived, video-scoped TUS authorization. Learner playback remains Moodle-owned: the browser requests `protected.php`, Moodle verifies course/module access, WHMCS authorizes the owned asset, and Moodle proxies byte ranges from the signed provider URL.

## Commercial model

A WHMCS service represents one **Elearning Stream commercial account**, not one Moodle site.

Default 0.6.0 policy:

- activation: **US$1 once**, provisioned only after the WHMCS service is activated;
- the activation dollar becomes **US$1 usable Elearning Stream wallet credit**;
- FREE: **7 GB** video storage;
- FREE: **20 GB/month** protected video transfer;
- FREE: **1 Moodle installation**;
- PAYG transition: a wallet recharge of at least **US$10**;
- PAYG storage: **US$0.03/GB-month** above the free allowance;
- PAYG transfer: **US$0.12/GB** above the monthly free allowance;
- PAYG Moodle installations: **unlimited by default**;
- all installations under the account share the same wallet and aggregate usage.

Money is stored as integer micro-USD. Only the WHMCS `CreateAccount` provisioning path may verify the paid activation and grant the one-time US$1 credit; repair and token-rotation paths cannot mint activation credit. Recharge invoices credit the wallet only after WHMCS reports `InvoicePaid`. Refund/unpaid transitions reverse the wallet entitlement idempotently. If provider usage exceeds the remaining prepaid balance, the wallet carries the deficit as debt so a later recharge must cover it before paid overage resumes.

## Retired provider compatibility

The former Google Drive provider is fully retired from the Elearning Stream runtime. Elearning Stream does not resolve, download, preview or proxy that provider.

Historical database values such as `googledrive`, the Moodle component name `mod_videoplayer`, WHMCS table prefixes such as `mod_driveresource_*`, and some upgrade migrations remain only for compatibility with previously installed builds. They are not production provider paths.

When an activity backed by the retired remote provider is edited, Moodle requires migration to Elearning Stream before it can be saved. Runtime access to that retired remote source fails closed.

## Upload and playback

Upload:

```text
Teacher browser
  -> Moodle login/capability validation
  -> Elearning Stream Gateway
  -> account + installation + wallet/quota checks
  -> provider asset reservation
  <- short-lived upload authorization
Teacher browser
  -> provider TUS upload directly
```

Playback:

```text
Learner
  -> Moodle protected.php
  -> course/module/capability validation
  -> Elearning Stream Gateway
  -> account + installation + asset-reference validation
  <- short-lived provider playback URL (server-side only)
  -> Moodle Range proxy
  -> learner HTML5 player
```

## Multiple Moodle installations

Each Moodle installation has its own exact HTTPS site binding and token. A secondary token is displayed once when it is created or rotated and is stored only as a hash in the gateway database.

FREE permits one active Moodle installation by default. PAYG permits unlimited installations by default while maintaining one consolidated balance and usage ledger.

## Provider lanes

The control plane separates:

- **managed video** — Elearning Stream today;
- **protected objects/documents** — independent S3-compatible lane reserved for the future document/PDF implementation.

The S3 control plane must remain disabled in Moodle until its upload, delivery, lifecycle, accounting and security adapters pass production validation.

## Installation

Install the Moodle package at:

```text
<moodle>/mod/videoplayer
```

Deploy the WHMCS companion from:

```text
integrations/whmcs/modules/addons/driveresource_gateway/
integrations/whmcs/modules/servers/driveresource/
```

The internal directory/module identifiers are compatibility names and are not customer-facing branding.

## Release gate

Before production:

- Moodle CI must pass on the supported PHP/database matrix;
- the WHMCS integration/security gate must pass;
- direct upload, seek, Range/206, rename, replace and delete must be tested end-to-end;
- wallet recharge must be tested with paid, refunded and unpaid invoices;
- FREE/PAYG limits must be tested with multiple Moodle installations;
- no provider management key or signed provider URL may appear in browser HTML or JavaScript;
- no Google host may be accepted by the Moodle upstream proxy.

## License

GNU GPL v3 or later for the Moodle plugin. Companion-module licensing is declared in the WHMCS integration source.
