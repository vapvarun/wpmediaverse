---
journey: outbound-and-display-toggles
plugin: wpmediaverse
priority: high
roles: [administrator]
covers: [webhooks-toggle, telemetry-opt-in, chat-panel-visibility]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "WPMediaVerse 1.8.0+ active"
estimated_runtime_minutes: 4
---

# Outbound and display toggles persist and gate their surface

**Why this journey exists**: `mvs_webhooks`, `mvs_telemetry_enabled`, and `mvs_chat_panel_visibility` are owner-controlled toggles with no in-band gated GET route the cert can flip-and-dispatch (webhooks fire outbound, telemetry pings on a schedule, the chat panel is a render-time gate). This journey is their enforcement proof: each saves, persists across reload, and visibly changes its surface. Telemetry is opt-IN and MUST default off; webhooks fire only when enabled; the chat panel renders only when visible.

## Setup

- Site: `$SITE_URL`
- User: `admin` (autologin via `?autologin=1`)
- Settings screens: `#webhooks`, `#general`, `#social` tabs of `admin.php?page=mvs-settings`.

## Steps

### 1. Auto-login as admin
- **Action**: `playwright_navigate $SITE_URL/wp-admin/?autologin=1`
- **Expect**: wp-admin dashboard.

### 2. Telemetry defaults to OFF (opt-in invariant)
- **Action**: on a site that never set it, `mysql_query "SELECT option_value FROM wp_options WHERE option_name='mvs_telemetry_enabled'"`.
- **Expect**: row absent OR `0`. Telemetry must never be on without an explicit opt-in.

### 3. Enable webhooks + telemetry, persist
- **Action**: check `mvs_webhooks` and `mvs_telemetry_enabled`; submit; reload.
- **Expect**: 302 -> "Settings saved."; DB rows both `1`; reloaded checkboxes checked.

### 4. Webhook fires only when enabled
- **Action**: trigger an event that emits a webhook (e.g. a media publish) and observe the outbound request log (mock endpoint or `mysql_query` on the delivery log table).
- **Expect**: one delivery attempt recorded. Then set `mvs_webhooks=0`, repeat the event.
- **Expect**: NO new delivery attempt — the toggle gates emission.

### 5. Chat panel visibility gates the front-end panel
- **Action**: set `mvs_chat_panel_visibility` to "hidden"/off; save; visit a front-end page that hosts the chat panel as a logged-in member.
- **Expect**: the chat panel container is absent from the DOM. Set it back to visible; reload.
- **Expect**: the chat panel renders.

### 6. Confirm all three persisted
- **Action**: `mysql_query "SELECT option_name, option_value FROM wp_options WHERE option_name IN ('mvs_webhooks','mvs_telemetry_enabled','mvs_chat_panel_visibility')"`.
- **Expect**: values match the last saved state byte-for-byte.

## Pass criteria

ALL hold:
1. `mvs_telemetry_enabled` is absent or `0` until explicitly enabled (opt-in).
2. Each toggle persists to `wp_options` across save + reload.
3. A webhook delivery is attempted only while `mvs_webhooks` is on.
4. The chat panel renders only while `mvs_chat_panel_visibility` is on.
5. No 500 on any save.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Telemetry on by default | Default arg wrong / opt-in inverted | `includes/Admin/Settings/SettingsRegistrar.php`; the telemetry sender's `get_option('mvs_telemetry_enabled', '0')` |
| Webhook fires while disabled | Emitter does not check the option | the webhook dispatcher service |
| Chat panel shows while hidden | Render gate reads the wrong option / cached | `includes/Shortcodes/Shortcodes.php` (chat panel render), template gate |
| Toggle reverts after reload | Duplicate `register_setting()` overwrote the sanitizer | `includes/Admin/Settings/SettingsRegistrar.php` |
