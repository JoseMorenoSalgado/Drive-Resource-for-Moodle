# Elearning Stream developer guide

## Product and component identity

Customer-facing product name: **Elearning Stream**.

Compatibility identifiers that must not be casually renamed:

- Moodle component: `mod_videoplayer`;
- Moodle database tables: `videoplayer*`;
- WHMCS internal module/table prefix: `driveresource`.

These identifiers are implementation history, not branding.

## Supported production sources

Production Moodle runtime supports:

- Elearning Stream managed video;
- historical Moodle-local PDF files.

Google-backed remote sources are retired. Do not add Google URL parsing, Google hosts, viewer embeds, export URLs or provider-specific fallback paths back into runtime code. Do not recreate `classes/local/drive.php`; the only allowed historical provider token is the persisted `googledrive` source key inside `resource_compatibility` for migration handling.

The future document/PDF product path must use the independent protected object-storage lane.

## Layer responsibilities

```text
classes/local/access/        Moodle authorization context
classes/local/resource/      normalized resource descriptor
classes/local/resource_compatibility.php  provider-neutral persisted-type compatibility
classes/local/stream/        protected delivery and upstream policy
classes/local/provider/      managed-video lifecycle
classes/local/gateway/       gateway DTO/authorization validation
classes/local/progress/      progress/completion business logic
classes/external/            Moodle AJAX/web-service endpoints
classes/task/                asynchronous bind/release/rename/usage
integrations/whmcs/          commercial control plane
```

## Gateway account model

Do not treat a WHMCS service as one Moodle site.

A service is the commercial account. `mod_driveresource_installations` contains one or more independently authenticated Moodle sites under that account.

All usage and wallet accounting aggregate by `service_id`. Installation ids are attribution/security dimensions, not separate balances.

The 0.6 legacy backfill must use `CommercialMigrationPolicy`; do not duplicate migration row construction inside database orchestration. CI executes this policy with representative 0.5.9 service records so account mode, activation credit, quota, status and primary-installation mapping remain regression-tested.

## Authentication

Every Moodle -> gateway request must validate:

- POST;
- exact Service ID;
- exact canonical HTTPS site URL;
- installation token hash;
- timestamp drift;
- request nonce;
- HMAC over timestamp, nonce and raw body hash;
- active WHMCS service;
- active installation;
- verified commercial activation for non-legacy accounts;
- account state.

Nonce insertion is the replay barrier and must remain transactional enough to reject duplicate requests.

## Money and billing

Never use floating-point values as stored money.

- persisted unit: micro-USD;
- 1 USD = 1,000,000 micro-USD;
- every wallet mutation requires a deterministic idempotency key;
- lock the commercial-account row before consulting the idempotency ledger so concurrent repeats serialize correctly;
- an existing idempotency key must match the same service, entry type and signed amount;
- invoice creation does not credit a wallet;
- `InvoicePaid` is the recharge-credit authority only after the invoice client, currency and frozen converted total are verified;
- commercial activation requires the service order's invoice to be Paid and to contain a `Hosting` line whose related id is the same WHMCS service;
- never treat `CreateAccount` invocation alone as proof that the activation fee was paid;
- wallet accounting is USD-denominated even when the WHMCS client invoice uses another configured currency;
- refund/unpaid hooks reverse the entitlement;
- every recharge settlement uses `settlement_version`; a Paid-after-Unpaid event must create a new versioned credit key rather than reuse a previously reversed ledger key;
- a Refunded wallet order is terminal and must not be silently reopened by `InvoicePaid`;
- FREE/PAYG transition is based on a qualifying paid recharge.

Usage calculations may use byte integers. Convert to charges only at the accounting boundary.

## Upload reservations

Reserve source bytes before issuing TUS authorization. Under a row lock calculate:

```text
projected = used_bytes + reserved_bytes + incoming_bytes
```

FREE rejects projected storage above the included allowance. PAYG allows overage only with available prepaid balance.

On completion, release the reservation and replace provisional source bytes with provider-reported storage when available.

## Protected playback

`protected.php` remains the browser-visible endpoint.

Do not:

- accept arbitrary upstream URLs;
- redirect learners to provider URLs;
- expose signed provider URLs in templates/AMD;
- accept Google hosts in the upstream allow-list.

The provider CDN URL is server-side only and must support reliable HTTP 206 byte ranges.

## Multiple Moodle installations

Secondary installation tokens are never stored plaintext. Show a newly generated token only in the current authenticated WHMCS session.

FREE installation limits are enforced both when creating an installation and again during request authentication. This second check prevents a refunded/downgraded account from continuing to use excess installations.

## Provider extension model

Video providers must implement the gateway capability boundary before becoming operational:

- direct upload;
- asset verification;
- protected playback;
- rename/delete;
- usage/accounting metadata.

Object-storage providers are separate. Do not overload video provider credentials or tables with document storage behavior.

## Progress and completion

Video completion is based on watched ranges, not furthest seek position. Resume position and completion evidence remain separate.

Moodle Completion API changes must be server-authoritative. JavaScript reports telemetry; PHP decides persistence/completion transitions.

## Coding requirements

- Moodle Coding Style for Moodle code;
- PSR-12 style for standalone WHMCS namespaced classes where compatible;
- PHPDoc/JSDoc on public contracts;
- small methods and explicit state transitions;
- no duplicated provider secrets or billing policy;
- no complete large-file buffering in PHP;
- no CDN-loaded runtime libraries.

## Required validation

Before merge:

- PHP syntax;
- Moodle PHPUnit for changed business rules;
- Moodle Plugin CI;
- integration invariant scripts;
- fresh install + upgrade;
- direct upload/resume;
- Range/206 seek;
- rename/replace/delete races;
- FREE storage/transfer limits;
- PAYG recharge and wallet debit;
- refund/unpaid reversal;
- one FREE Moodle limit;
- multiple PAYG Moodle authentication;
- Backup & Restore;
- Privacy API.

## Commercial invariants

Only `driveresource_CreateAccount()` may call the shared provisioning path with activation eligibility. `ProvisionMoodleConnection` and token rotation must pass `false`, so repair operations cannot mint the US$1 activation credit. PAYG provider cost is ledgered in full even when it drives the wallet below zero; future recharges clear debt before overage can resume. `.github/scripts/commercial-wallet-invariants.py` makes these rules release-blocking.
