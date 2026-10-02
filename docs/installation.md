# Elearning Stream installation and upgrade

## Supported platform

For **Elearning Stream 1.3.0-rc1-m45**:

- Moodle 4.5 LTS;
- PHP 8.1+;
- PHP cURL;
- supported MariaDB/MySQL or PostgreSQL;
- HTTPS;
- Moodle cron.

The Moodle component directory remains:

```text
<moodle-root>/mod/videoplayer
```

This is a compatibility identifier, not the product name.

## WHMCS companion

Deploy Gateway **0.6.0**:

```text
modules/addons/driveresource_gateway/
modules/servers/driveresource/
```

Activate/re-save the addon after deployment so WHMCS detects its hooks and runs the 0.6.0 schema upgrade.

Configure the public gateway URL, normally:

```text
https://stream.elearningcloud.io
```

The hostname must terminate HTTPS and route the addon/API without redirecting signed POST requests.

## Provider settings

Configure in the gateway:

- Elearning Stream Library ID;
- provider API key;
- CDN hostname;
- playback token key;
- playback TTL;
- direct-upload TTL.

Provider secrets never go into Moodle.

## Commercial settings

Gateway 0.6.0 defaults:

- Free Storage GB: 7;
- Free Monthly Transfer GB: 20;
- Activation Credit USD: 1;
- Minimum PAYG Recharge USD: 10;
- PAYG Storage USD/GB-month: 0.03;
- PAYG Transfer USD/GB: 0.12;
- FREE Moodle Installations: 1;
- PAYG Moodle Installations: 0 (unlimited).

Configure the WHMCS product as the **US$1 one-time activation** product. Provisioning occurs after WHMCS activates the paid service; the gateway then creates the FREE account and grants the US$1 wallet credit once.

## Moodle connection

Primary installation:

1. provision the WHMCS service;
2. copy the public gateway URL;
3. copy Service ID;
4. copy the primary service token;
5. enter them in Moodle Elearning Stream settings;
6. validate the connection.

Secondary installations are created in the WHMCS customer portal after PAYG is active. Each receives its own token, shown once.

## Recharge flow

The customer chooses a recharge amount in the Elearning Stream portal. WHMCS creates a normal invoice using the service's payment method.

No credit is granted at invoice creation. The wallet is credited only when WHMCS marks the invoice Paid. PayPal or any other configured WHMCS gateway therefore remains outside plugin billing logic.

A refund or marking the invoice Unpaid reverses the associated wallet recharge idempotently.

## Cron

WHMCS daily cron performs:

- provider storage reconciliation;
- PAYG daily storage settlement;
- abandoned upload cleanup;
- retention/deletion;
- nonce/report/audit cleanup.

Moodle cron performs provider lifecycle and transfer-report tasks.

Both crons are mandatory in production.

## Upgrade from Gateway < 0.6.0

The upgrade creates:

- `mod_driveresource_accounts`;
- `mod_driveresource_installations`;
- `mod_driveresource_wallet_ledger`;
- `mod_driveresource_wallet_orders`;
- `mod_driveresource_usage_daily`;
- installation attribution columns on uploads/usage reports.

Existing services are backfilled as `legacy`, preserving their previous quota/overage behavior and primary site/token. They are **not** silently converted to FREE or PAYG.

New services created after 0.6.0 use the new commercial model.

## Google Drive retirement

No Google configuration is required or supported.

Existing database records with historical remote-source values remain recognizable only for migration. Moodle does not resolve or proxy them. Editing such an activity requires replacing the source with Elearning Stream.

## Post-upgrade validation

On staging:

1. run Moodle upgrade and purge caches;
2. activate/re-save the WHMCS addon;
3. verify an existing legacy service still authenticates;
4. create a new US$1 activation service;
5. verify FREE shows 7 GB / 20 GB / one Moodle;
6. create a US$10 recharge invoice and confirm the wallet is unchanged while unpaid;
7. pay the invoice and confirm PAYG + wallet credit;
8. add a second Moodle and validate its independent token;
9. upload and play video from both Moodle sites;
10. verify shared aggregate transfer/storage;
11. test refund/unpaid reversal;
12. verify Google URLs are not accepted or proxied;
13. test Range/206 seek, rename, replace and final-reference deletion.
