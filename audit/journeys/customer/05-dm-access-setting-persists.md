---
journey: dm-access-setting-persists
plugin: wpmediaverse
priority: critical
roles: [administrator]
covers: [d986525, dm-access-bug, settings-duplicate-registration]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "WPMediaVerse v1.2.0+ active"
estimated_runtime_minutes: 3
---

# DM access setting persists across save (regression sentinel for `d986525`)

**Why this journey exists**: On 2026-05-01 (commit `d986525`) a duplicate `register_setting()` in `SettingsRegistrar.php` silently rewrote the `mvs_dm_access` enum sanitizer with a bool sanitizer, plus duplicate-registered `mvs_show_online_status`. Result: choosing "Nobody" or "Mutual followers only" in the Social tab UI silently saved as "Everyone" — a privacy regression. The regression is invisible in the admin (the Settings-saved notice still appears), only visible by reloading the screen or querying the option directly. This journey saves "Nobody", reloads, and asserts the dropdown still reads "Nobody" AND that `wp_options.option_value` for `mvs_dm_access` equals `nobody`.

## Setup

- Site: `$SITE_URL`
- User: `admin` (autologin via `?autologin=1`)
- DB pre-condition: any current value of `mvs_dm_access` is fine; we'll overwrite.

## Steps

### 1. Auto-login as admin
- **Action**: `playwright_navigate $SITE_URL/wp-admin/?autologin=1`
- **Expect**: redirected to wp-admin dashboard, top-bar shows "Howdy, admin".

### 2. Open Settings → Social tab
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=wpmediaverse-settings#social`
- **Expect**: `select[name="mvs_dm_access"]` is present in DOM with options `everyone`, `followers`, `mutual`, `nobody`.

### 3. Save "Nobody" for DM access
- **Action**: `playwright_select_option select[name="mvs_dm_access"] "nobody"` then click `input[type="submit"]`
- **Expect**: HTTP 302 → reload → admin notice contains "Settings saved."

### 4. Reload the Settings page
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=wpmediaverse-settings#social`
- **Expect**: `select[name="mvs_dm_access"]` selected option is `nobody` (NOT `everyone`).

### 5. Confirm DB persistence
- **Action**: `mysql_query "SELECT option_value FROM wp_options WHERE option_name='mvs_dm_access'"`
- **Expect**: `option_value` == `'nobody'`.

### 6. Repeat for "Mutual"
- **Action**: select `mutual`, save, reload, query DB.
- **Expect**: dropdown shows `mutual`, DB stores `mutual`.

### 7. Repeat for online-status with "Followers only"
- **Action**: select `followers` in `mvs_show_online_status`, save, reload, query DB.
- **Expect**: dropdown shows `followers`, DB stores `followers`.

## Pass criteria

ALL of the following hold:
1. After saving `nobody` and reloading, the dropdown shows `nobody`.
2. After saving `mutual` and reloading, the dropdown shows `mutual`.
3. The corresponding `wp_options` row matches the saved value byte-for-byte.
4. `mvs_show_online_status` saves `followers` and persists.
5. Page-reload after save shows the admin notice "Settings saved." (no fatal/error).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Dropdown reverts to `everyone` after reload | Duplicate `register_setting()` re-introduced — sanitizer overwritten | `includes/Admin/Settings/SettingsRegistrar.php` (search `mvs_dm_access`, `mvs_show_online_status`) |
| Save returns 500 | Sanitizer threw or returned non-string | `includes/Admin/Settings/Sanitizers.php::sanitize_dm_access` |
| DB stores empty string | Sanitizer rejected unknown value AND fallback wrong | `includes/Admin/Settings/Sanitizers.php` |
| Settings-saved notice missing | Settings API not registered for this group | `includes/Admin/Settings/SettingsRegistrar.php` `OPTION_GROUP . '_social'` |
