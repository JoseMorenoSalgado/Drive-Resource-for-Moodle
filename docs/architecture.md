# Elearning Stream architecture

## Product identity

The product is **Elearning Stream**. The Moodle component remains `mod_videoplayer` only because Moodle component names, capabilities, database tables, backups and upgrade paths are persistent identifiers.

Elearning Stream has **no Google Drive runtime dependency**. Historical source values are migration data only and are never resolved to Google URLs.

## Trust boundaries

```text
Browser
  |
  v
Moodle
  | login + enrolment + context_module + capability
  | Service ID + installation token + HMAC request
  v
Elearning Stream Gateway / WHMCS
  | account state + installation binding + wallet + ownership
  v
Managed video provider
```

Provider management credentials live only in the gateway. Moodle stores only the branded gateway URL, commercial Service ID and installation-scoped token.

## Commercial account model

Gateway 0.6.0 separates the commercial account from Moodle installations.

```text
WHMCS service / Elearning Stream account
    |
    +-- wallet
    +-- aggregate storage usage
    +-- aggregate monthly transfer usage
    +-- commercial mode: free | payg | legacy
    |
    +-- Moodle installation #1
    |      site_url + site_hash + token_hash
    |
    +-- Moodle installation #2
    |      site_url + site_hash + token_hash
    |
    +-- ...
```

The external `service_id` remains stable for backward compatibility. Authentication first identifies the account, then requires an exact active installation binding by site URL and token hash.

Existing pre-0.6.0 services are backfilled as `legacy` so a gateway upgrade cannot unexpectedly block active customers. The 0.6 migration is idempotent and runs its repair pass on every upgrade invocation; postconditions require one commercial account per service and one primary Moodle installation for every fully provisioned legacy binding. Account/installation backfill values are built by a deterministic migration policy that is also executed in CI against representative active, suspended and incomplete 0.5.9 records. New services enter the FREE/PAYG contract only through WHMCS `CreateAccount` after the paid activation; repair and credential-rotation paths never grant activation credit.

## Default commercial policy

- one-time activation: US$1;
- activation credit: US$1 wallet balance;
- FREE storage: 7 GB;
- FREE monthly transfer: 20 GB;
- FREE Moodle installations: 1;
- minimum PAYG recharge: US$10;
- PAYG storage: US$0.03/GB-month above free allowance;
- PAYG deficits remain in the wallet as debt until covered by a later recharge;
- PAYG transfer: US$0.12/GB above free allowance;
- PAYG installations: unlimited by default.

All wallet value is persisted in micro-USD integers. The wallet ledger is append-only and each financial operation has an idempotency key. Wallet mutation first locks the commercial-account row and then checks the idempotency ledger, serializing concurrent hooks for the same account. Recharge invoices may use the client’s WHMCS currency: the USD wallet amount is converted once at invoice creation, and that exact invoice amount/currency is frozen for `InvoicePaid` verification.

## Recharge lifecycle

```text
Customer -> CreateWalletRecharge
         -> WHMCS CreateInvoice
         -> payment gateway
         -> InvoicePaid hook
         -> wallet credit
         -> FREE -> PAYG when recharge >= minimum

refund / mark unpaid
         -> reversal hook
         -> idempotent wallet debit
         -> if no paid recharge remains: PAYG entitlement removed
```

Creating an invoice never grants balance. Payment confirmation from WHMCS is the authority, and the paid invoice must still match the frozen client-currency amount before wallet credit is permitted.

## Usage accounting

### Storage

Provider-reported `storageSize` is reconciled into the service aggregate. WHMCS cron writes a daily UTC snapshot. PAYG storage above the included allowance is prorated across the number of days in the current UTC month.

### Transfer

Moodle records bytes actually emitted by protected playback. Batches are reported with a stable `report_id`. The gateway deduplicates the report before updating current-month usage and wallet charge.

### Insufficient balance

FREE never receives paid overage. PAYG may use overage only while prepaid credit is available. Exhausted balances move the account to an upload-restricted state; protected playback is also denied after the included transfer allowance is exhausted.

## Moodle installation lifecycle

Each installation has:

- numeric installation id;
- account `service_id`;
- label;
- exact HTTPS `site_url`;
- SHA-256 site hash;
- SHA-256 token hash;
- active/revoked state;
- connection status and timestamps.

Secondary plaintext tokens are shown once to the authenticated WHMCS customer after creation/rotation. They are never persisted in plaintext by the addon.

A secondary Moodle cannot be revoked while it owns active provider references.

## Video upload

```text
Moodle form
  -> create_bunny_upload
  -> gateway authentication
  -> FREE/PAYG storage policy
  -> atomic reservation
  -> provider video allocation
  <- scoped TUS signature
browser
  -> provider TUS endpoint
  -> complete callback
  -> reservation converted to provider-accounted usage
  -> bind task creates activity reference
```

Concurrent uploads are serialized around the aggregate service reservation counters.

## Protected playback

```text
Learner -> protected.php
        -> activity_context
        -> resource_descriptor
        -> protected_resource_service
        -> WHMCS playback authorization
        -> signed MP4 URL kept server-side
        -> http_range_proxy
        -> learner
```

The proxy supports Range/206 behavior and an allow-list limited to the managed provider CDN. Google domains are not accepted.

## Asset lifecycle

Provider ownership is singular at the upload/asset row. Moodle activity references are separate and can be multiple.

Bind, restore and release lock the provider asset before mutating references. Physical deletion occurs only when no active reference remains and the configured retention period permits deletion.

## Local PDF compatibility

Historical Moodle-local PDF activities can still be streamed from Moodle File API using the local protected streamer and PDF.js. Remote Google-backed PDFs are retired. The future protected-document product path will use the independent S3-compatible provider lane.

## Legacy compatibility

The following may remain in code/schema strictly for upgrades:

- component `mod_videoplayer`;
- database table names beginning with `videoplayer`;
- WHMCS internal prefix `driveresource`;
- historical `googledrive` source values in upgrade/restore handling.

Compatibility identifiers must not re-enable Google network access or customer-facing legacy branding.

## Security invariants

1. No arbitrary upstream URL enters the proxy.
2. No provider management credential enters Moodle.
3. Every gateway request is bound to service + installation + timestamp + nonce + HMAC.
4. Asset operations verify service ownership and activity reference.
5. Wallet mutations are transactional and idempotent.
6. Browser-visible playback URLs remain Moodle URLs.
7. Google hosts are rejected by the production upstream policy.

## Persisted resource compatibility

Runtime type/source compatibility is centralized in `classes/local/resource_compatibility.php`. The historical `classes/local/drive.php` class is a deprecated shim only; production controllers, forms, renderers and restore code must not depend on it.
