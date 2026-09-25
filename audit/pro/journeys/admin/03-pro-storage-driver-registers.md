---
journey: pro-storage-driver-registers
plugin: wpmediaverse-pro
priority: high
roles: [administrator]
covers: [extension-pattern-4, mvs_storage_driver]
prerequisites:
  - "Both plugins active"
estimated_runtime_minutes: 2
---

# Pro registers S3 + BunnyCDN drivers via mvs_storage_driver filter

## Steps

### 1. Inspect available drivers
- **Action**: `wp eval 'print_r( apply_filters("mvs_storage_driver", null, "s3") );'`
- **Expect**: object instance of `WPMediaVersePro\Integrations\AmazonS3\StorageDriver` (or null with credentials warning).

### 2. Same for bunny
- **Action**: `wp eval 'print_r( apply_filters("mvs_storage_driver", null, "bunny") );'`
- **Expect**: object instance of `BunnyCDNDriver`.

### 3. Settings dropdown lists Pro drivers
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=mvs-settings#storage`
- **Expect**: the "Where files are stored" select (`select[name="mvs_storage_driver"]`) contains options `s3`, `bunnycdn`, `r2` and `dospaces` in addition to `local`, labelled in plain words ("This server (WordPress uploads)", "Amazon S3", ...). Picking one shows only that driver's credential card; picking `local` hides all four. After a driver change is saved, the notice names the place ("New uploads now go to Amazon S3."), never the slug.

## Pass criteria

Filter returns Pro driver objects for `s3` and `bunny` keys; settings dropdown lists every driver and shows only the selected driver's credentials.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Filter returns null | Pro `register_storage_driver` not hooked | `includes/Core/Plugin.php:211` |
| Dropdown missing options | Free's storage driver dropdown isn't filtered | `../wpmediaverse/includes/Admin/Settings/SettingsRegistrar.php` (storage driver field) |
