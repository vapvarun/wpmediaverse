---
journey: pro-bootstraps-after-free
plugin: wpmediaverse-pro
priority: critical
roles: [administrator]
covers: [extension-pattern-1, mvs_loaded, mvs_pro_loaded]
prerequisites:
  - "Both wpmediaverse and wpmediaverse-pro active"
estimated_runtime_minutes: 2
---

# Pro plugin boots only after Free has loaded; mvs_pro_loaded fires once

**Why this journey exists**: Pro extends Free via `do_action('mvs_loaded')`. If Pro tries to instantiate before Free has registered the ServiceContainer, every Pro feature crashes. This journey verifies the boot order via a probe that listens to both hooks and asserts ordering.

## Steps

### 1. Inject probe via mu-plugin
- **Action**: write a temp mu-plugin that records `mvs_loaded` and `mvs_pro_loaded` timestamps to a transient.
- **Expect**: probe loads.

### 2. Trigger a request
- **Action**: `curl -I $SITE_URL/wp-admin/`

### 3. Read transient
- **Action**: `wp transient get mvs_boot_probe`
- **Expect**: JSON with `mvs_loaded_at < mvs_pro_loaded_at` AND both numeric.

### 4. Verify free_service() works
- **Action**: `wp eval 'echo is_object(\WPMediaVersePro\Core\Plugin::free_service("upload")) ? "OK" : "FAIL";'`
- **Expect**: `OK`.

## Pass criteria

`mvs_loaded` fires before `mvs_pro_loaded`; `Plugin::free_service('upload')` returns an object.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `mvs_pro_loaded` fires first | Pro hooked into the wrong action | `wpmediaverse-pro.php` |
| `free_service()` returns null | ServiceContainer key changed in Free | `../wpmediaverse/includes/Core/Plugin.php` `register_services()` |
