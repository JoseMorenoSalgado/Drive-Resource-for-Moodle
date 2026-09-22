# Drive Resource WHMCS companion

This directory contains the commercial control-plane components used by the Moodle Drive Resource plugin.

It is **not part of the Moodle plugin runtime package**.

## Deployables

Copy these directories into WHMCS:

```text
modules/addons/driveresource_gateway/
modules/servers/driveresource/
```

The addon owns the Bunny Stream management credentials and Moodle-facing media gateway. The provisioning module owns the WHMCS product/service lifecycle and exposes the storage metric used by Usage Billing.

## Credential boundary

Bunny management credentials must be configured only in the WHMCS addon. Moodle receives a service-scoped WHMCS gateway token and short-lived, video-scoped TUS upload authorization. It never receives the Bunny management `AccessKey`.

## Default commercial policy

The provisioning module defaults to:

- 7 GB included video storage;
- soft overage enabled;
- 30-day retention after the last Moodle reference is released.

Configure the WHMCS Usage Billing metric `video_storage_gb` with the same included quantity and your desired per-GB overage price.

## Required WHMCS setup

1. Deploy and activate the addon module.
2. Configure Bunny Stream Library ID and API key.
3. Deploy the `driveresource` provisioning module.
4. Create a WHMCS server and product using that module.
5. Add a product custom field named `Moodle Site URL` when the Moodle installation URL cannot be represented exactly by the standard service domain field.
6. Provision the service.
7. Copy the generated WHMCS service ID and service token into the Drive Resource Moodle administration settings.
8. Ensure WHMCS cron runs normally; the addon hook performs provider storage reconciliation, abandoned-upload cleanup and retention deletion.

Do not copy this `integrations/whmcs` directory beneath `mod/videoplayer` on a production Moodle site.
