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
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=wpmediaverse-settings#general`
- **Expect**: `select[name="mvs_storage_driver"]` contains options `s3` and `bunny` in addition to `local`.

## Pass criteria

Filter returns Pro driver objects for `s3` and `bunny` keys; settings dropdown lists them.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Filter returns null | Pro `register_storage_driver` not hooked | `includes/Core/Plugin.php:211` |
| Dropdown missing options | Free's storage driver dropdown isn't filtered | `../wpmediaverse/includes/Admin/Settings/SettingsRegistrar.php` (storage driver field) |
