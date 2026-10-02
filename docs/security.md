# Elearning Stream security model

## Security boundary

Elearning Stream relies on server-side authorization, not hidden controls.

Every learner media request must pass:

- valid course module;
- valid course;
- valid activity instance;
- `require_login()`;
- `context_module`;
- `mod/videoplayer:view`.

Only then may Moodle request gateway playback authorization.

## Provider-secret boundary

Moodle stores only:

- branded gateway HTTPS URL;
- commercial Service ID;
- installation-scoped token.

Provider management API keys, playback signing keys and future object-storage secrets remain in WHMCS.

## Installation isolation

Gateway authentication binds every request to the exact tuple:

```text
service_id + canonical site_url + installation token
```

Tokens are stored as SHA-256 hashes in `mod_driveresource_installations`. Requests also require timestamp, nonce and HMAC validation.

A secondary installation can never authenticate by knowing only the account Service ID.

## Tenant and asset isolation

Provider assets are owned by one account. Activity references additionally include site hash, instance id and provider video id.

Release/delete operations must prove the exact activity/video reference. Physical provider deletion is forbidden while another active reference exists.

## Wallet integrity

Commercial money is represented as integer micro-USD.

Security requirements:

- every credit/debit has an idempotency key;
- wallet mutations lock the account before idempotency lookup, preventing concurrent duplicate hooks from double-applying a balance change;
- an idempotency key collision with a different service/type/amount fails closed;
- recharge invoice creation grants no balance;
- wallet credit occurs only after `InvoicePaid` and only when the paid invoice currency and total equal the values frozen on the recharge order;
- refund/unpaid events reverse the recharge;
- wallet/account rows are locked during balance mutation;
- FREE/PAYG limits are enforced server-side;
- account downgrade is rechecked at authentication time;
- only the WHMCS `CreateAccount` boundary may verify activation and grant the one-time activation credit;
- connection repair and token rotation cannot grant activation credit;
- insufficient PAYG provider cost is preserved as wallet debt rather than discarded.

Do not trust a browser-provided price, balance, plan or charge.

## SSRF and upstream policy

The production Moodle upstream allow-list accepts only approved managed-video CDN hosts. Google hosts are not accepted.

Controllers must never accept a raw upstream URL from a learner. Signed provider playback URLs are obtained server-to-server after ownership validation.

Redirects are resolved hop-by-hop and revalidated against the same allow-list.

## Retired provider isolation

Historical `googledrive` database values may remain for upgrade diagnostics. They are not executable provider configurations. No provider-specific `drive` helper class is shipped in the production runtime; compatibility handling is centralized in `resource_compatibility` and fails closed.

The production runtime must not:

- resolve Google sharing URLs;
- call Google playback/download/export endpoints;
- embed Google viewers;
- allow Google domains in proxy policy.

Legacy remote activities fail closed until migrated to Elearning Stream.

## Response hardening

Protected responses reconstruct safe headers and preserve:

- `Accept-Ranges: bytes`;
- correct `206 Partial Content`;
- validated `Content-Range`;
- `X-Content-Type-Options: nosniff`;
- private caching;
- safe inline filenames.

Unexpected HTML/JSON responses are rejected rather than forwarded as media.

## Browser limitations

UI deterrents such as disabled context menus, watermarking and hidden download buttons are not DRM. An authorized browser receives media bytes and can potentially capture them.

## Logging

Never log:

- plaintext installation tokens;
- provider API keys;
- provider playback signing keys;
- signed playback URLs;
- payment-gateway secrets.

Audit metadata must remain bounded and non-secret.

## Production checklist

Before release:

- HTTPS everywhere;
- TLS certificate verification enabled;
- WHMCS cron enabled;
- Moodle cron enabled;
- no Google host accepted by upstream policy;
- no provider key in Moodle HTML/JS;
- unauthorized `protected.php` requests rejected;
- cross-service/cross-installation asset tests rejected;
- wallet double-credit retry test passes;
- refund/unpaid reversal test passes;
- multi-Moodle entitlement tests pass;
- Range/206 tested on desktop and mobile.
