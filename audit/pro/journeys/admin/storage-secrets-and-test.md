---
journey: storage-secrets-and-test
plugin: wpmediaverse-pro
priority: high
roles: [administrator]
covers: [MV-STO-008, MV-STO-010, storage-secrets, storage-test-connection]
prerequisites:
  - "Both plugins active; at least one driver's secret already saved with a real value"
estimated_runtime_minutes: 6
---

# A blank resubmit never wipes a saved secret; Test Connection always reads what's saved, never what's unsaved in the form

**Why this journey exists**: this is the exact historic bug class (Basecamp 10057408558) where masked-but-unguarded credential fields were wiped on every Save. `ProSettings::SECRET_OPTIONS` names masking and preservation as "two halves of ONE contract" — this journey verifies both halves, for all 7 storage secrets, not just one.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`; each of the 7 storage secrets already saved with a real value: `mvs_pro_bunny_api_key`, `mvs_pro_s3_access_key`, `mvs_pro_s3_secret_key`, `mvs_pro_r2_access_key`, `mvs_pro_r2_secret_key`, `mvs_pro_do_access_key`, `mvs_pro_do_secret_key`.

## Steps

### 1. A secret field never prints its real value
- **Action**: open Settings > Storage for each driver with a saved secret.
- **Expect**: the field renders as `value=""` with a masked placeholder — never the real secret in the HTML source.

### 2. Blank resubmit preserves the old value — test all 7, not just one
- **Action**: for each of the 7 secrets in turn: without touching that field, change an unrelated field in the same section (e.g. bucket name), then Save.
- **Expect**: `wp option get <secret_option>` still returns the ORIGINAL value after save — the `pre_update_option_{option}` filter keeps the old value when the posted value is empty and the old value is non-empty. Verify out-of-band via direct option check; the settings screen itself gives no explicit "secret preserved" confirmation toast.
- **On fail**: that secret's entry missing from `ProSettings::SECRET_OPTIONS`, or the filter closure not registered for it in the `foreach` loop.

### 3. "Remove" is the only way to actually clear a secret
- **Action**: use the "Remove" control next to a saved secret (same pattern as Free's password fields); confirm via `wp option get` that it is now empty.
- **Expect**: cleared. Confirm a bare blank-and-Save (without using Remove) does NOT clear it — re-verify step 2's guard holds even on repeated attempts.

### 4. Test Connection reads from saved options, never from an unsaved form field
- **Action**: change a driver field (e.g. S3 access key) WITHOUT saving; click Test Connection.
- **Expect**: the test round-trips against whatever is CURRENTLY SAVED, not the unsaved edit — confirmed across all four drivers, since each pulls `$bucket`/`$key`/`$secret` straight from `get_option()`, never `$_POST`.

### 5. Missing-credential guard fires before any network call
- **Action**: for a driver with blank secret fields (never saved), click Test Connection.
- **Expect**: the driver-specific guard message ("S3 credentials are not configured." / "BunnyCDN credentials are not configured." / "Cloudflare R2 credentials are not configured." / "DigitalOcean Spaces credentials are not configured.") fires immediately, before any network attempt.

### 6. Test Connection is independent of the currently active driver
- **Action**: with S3 active, click Test Connection on the BunnyCDN section.
- **Expect**: the test runs against BunnyCDN's own saved credentials, independent of which driver is currently active.

### 7. Unauthorized caller gets a clean error, not a fatal
- **Action**: attempt the Test Connection AJAX action as a user without `manage_mvs_settings`.
- **Expect**: a clean "Unauthorized." AJAX error response, never a fatal or 500.

### 8. wp-config constants are honored consistently by both the driver and the test
- **Action**: (for S3 and R2, which have documented constant support) define the wp-config constant with a value different from the DB option; run both a real upload and Test Connection.
- **Expect**: both the driver's actual behavior and the Test Connection button use the constant value consistently — confirm BunnyCDN/DOSpaces constant precedence (if any) matches this pattern too, or note if they have none.

## Pass criteria

1. No secret field ever renders its real value in markup.
2. A blank resubmit preserves every one of the 7 secrets — verified via direct option read, not the UI.
3. "Remove" is the only path that actually clears a secret.
4. Test Connection always reads saved options, fires the correct per-driver missing-credential guard before any network call, runs independent of the active driver, and fails cleanly for an unauthorized caller.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A secret is wiped by a blank resubmit | that option missing from `SECRET_OPTIONS`, or its filter closure not registered | `ProSettings::__construct()` / `SECRET_OPTIONS` |
| Test Connection reflects an unsaved form edit | test handler reading `$_POST` instead of `get_option()` | driver's AJAX test handler |
| Unauthorized test call returns a fatal | capability check missing before the AJAX handler body runs | AJAX action registration for `mvs_pro_test_*` |
