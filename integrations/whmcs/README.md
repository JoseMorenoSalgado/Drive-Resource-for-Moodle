# Elearning Stream WHMCS companion

This directory contains the Elearning Stream commercial control plane. Internal module/table names retain the historical `driveresource` prefix for compatibility only.

## Deployables

Copy to WHMCS:

```text
modules/addons/driveresource_gateway/
modules/servers/driveresource/
```

Gateway 0.6.0 owns:

- provider credentials;
- account and Moodle-installation authentication;
- upload reservations and provider assets;
- prepaid wallet;
- FREE/PAYG enforcement;
- transfer/storage accounting;
- retention/deletion;
- customer portal.

## Default commercial model

- US$1 one-time activation;
- US$1 activation credit;
- FREE: 7 GB storage;
- FREE: 20 GB transfer/month;
- FREE: 1 Moodle;
- PAYG minimum recharge: US$10;
- PAYG storage: US$0.03/GB-month above free;
- PAYG transfer: US$0.12/GB above free;
- PAYG Moodles: unlimited by default.

A WHMCS service is one commercial account. Multiple Moodle installations share that account's wallet and aggregate usage. Only the WHMCS `CreateAccount` path may verify the paid activation and issue the one-time US$1 wallet credit; connection repair and token rotation cannot issue it.

## Recharge billing

`CreateWalletRecharge` creates a normal WHMCS invoice. The wallet is not credited until the official `InvoicePaid` hook fires.

`InvoiceRefunded` and `InvoiceUnpaid` reverse the recharge with a stable idempotency key. If no paid recharge remains, PAYG entitlement is removed and FREE installation limits are enforced again.

This design works with PayPal and other WHMCS payment gateways without storing gateway credentials in Elearning Stream.

For FREE/PAYG accounts, the module's historical WHMCS Usage Billing metrics deliberately report zero billable usage. The prepaid wallet is the billing authority, preventing an upgraded product with old Usage Billing pricing from charging the same storage/transfer twice. Legacy accounts continue exposing their historical usage metrics until intentionally migrated.

If a paid wallet recharge is later refunded after some credit was consumed, or if PAYG provider usage exceeds the remaining prepaid balance, the wallet may become negative. That debt is carried forward; later recharges must cover it before paid overage becomes available again.

## Credential boundary

Moodle receives:

- public gateway URL;
- Service ID;
- installation token.

Provider management credentials remain in WHMCS.

Primary tokens use the WHMCS protected service password. Secondary installation tokens are shown once after creation/rotation and only their hashes are stored in the gateway database.

## Provider configuration

Configure:

- managed-video Library ID/API key;
- CDN hostname;
- playback token key;
- playback/direct-upload TTLs.

The protected object-storage lane remains independent and is not production-enabled in Moodle yet.

## Existing services

Gateway 0.6.0 backfills existing services into `legacy` commercial mode and creates their primary Moodle installation from the existing site/token binding. Its migration repair pass is idempotent and runs on every upgrade invocation. It does not reset current quota, wallet state or media ownership. Migration postconditions verify all critical commercial columns, installation attribution, one account row per existing service, a primary installation for every fully provisioned legacy binding, and zero fabricated activation credit.

## No Google Drive dependency

The gateway and Moodle production runtime do not use Google Drive. Historical identifiers in upgrade data are compatibility artifacts only.

## Operational requirements

- WHMCS cron enabled;
- HTTPS public gateway;
- reverse proxy preserves signed POST headers/body;
- provider CDN supports HTTP byte ranges;
- addon re-saved after hooks.php deployment when required by WHMCS module discovery;
- integration gate green before deployment.
