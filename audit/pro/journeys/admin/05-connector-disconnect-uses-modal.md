---
journey: connector-disconnect-uses-modal
plugin: wpmediaverse-pro
priority: high
roles: [administrator, member]
covers: [admin-ux-rule-10, mvs-confirm, dashboard-connectors, connector-settings]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "WPMediaVerse Pro v1.2.0+ active"
  - "Connectors feature enabled (mvs_connectors_enabled = 1)"
  - "At least one connector (e.g. Flickr) registered"
estimated_runtime_minutes: 4
---

# Connector disconnect shows the styled modal, not browser confirm()

**Why this journey exists**: Pro's `dashboard-connectors.js` (frontend dashboard
panel, "Connected Accounts" tab) and `connector-settings.js` (admin Settings →
Connectors tab) both used `window.confirm()` to gate the disconnect flow.
1.2.0 swaps both for `window.mvsConfirm()` — promise-based, matches the rest
of the plugin's modal UI.

The frontend panel gets `window.mvsConfirm` from Free's `mvs-confirm` script
(declared as a dep of `mvs-dashboard-connectors`). The admin tab gets it from
Pro's existing `Admin\ConfirmDialog` class.

This journey asserts the dashboard panel — the most user-visible disconnect
flow — opens the styled modal instead of a browser dialog.

## Setup

- Site: `$SITE_URL`
- User: any non-admin member who has a Flickr account connected, OR admin
  with a connected account (the path is identical).
- Pre-condition: at least one connector card with `.mvs-fe-disconnect-btn`
  visible on the user dashboard "Connected Accounts" tab.

## Steps

### 1. Auto-login
- **Action**: `playwright_navigate $SITE_URL/?autologin=1`
- **Expect**: redirected to home, top-bar shows "Howdy, …".

### 2. Open the user dashboard "Connected Accounts" tab
- **Action**: `playwright_navigate $SITE_URL/dashboard/?tab=connectors`
  (or whatever URL the dashboard uses; locate via the dashboard nav).
- **Expect**: at least one `.mvs-fe-disconnect-btn` is present in DOM.
  `window.mvsConfirm` is a function. `mvs-confirm.js` is loaded (verify
  with `document.querySelector('script[src*="mvs-confirm.js"]')`).

### 3. Click "Disconnect"
- **Action**: `playwright_click '.mvs-fe-disconnect-btn:first-of-type'`
- **Expect**: a `<dialog>` element with id `mvs-frontend-confirm-dialog`
  opens. Message text reads
  `"Disconnect this account? You can reconnect at any time."`. Confirm
  button text reads `"Disconnect"`, Cancel reads `"Keep connected"`. The
  confirm button has the destructive (red) styling. NO browser
  `window.confirm()` prompt appears.

### 4. Cancel
- **Action**: `playwright_click '.mvs-frontend-confirm-cancel'`
- **Expect**: dialog closes. No `POST /wp-json/mvs-pro/v1/connectors/{id}/disconnect`
  request fires (verify via `playwright_browser_network_requests`).

### 5. Click Disconnect again, confirm
- **Action**: `playwright_click '.mvs-fe-disconnect-btn:first-of-type'`,
  then `playwright_click '.mvs-frontend-confirm-ok'`.
- **Expect**: `POST /wp-json/mvs-pro/v1/connectors/{connector_id}/disconnect`
  fires. Page reloads on `data.disconnected === true` response.

### 6. (Optional) Repeat on the admin Settings → Connectors tab
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=mvs-settings#connectors`
- **Expect**: `window.mvsConfirm` is a function (provided by Pro's
  `Admin\ConfirmDialog` class on Pro admin screens). Clicking
  `.mvs-disconnect-btn` opens the admin `<dialog id="mvs-confirm-dialog">`
  (note: different ID — admin uses Pro's dialog, frontend uses Free's).

## Pass criteria

ALL of the following hold:

1. `window.mvsConfirm` is a function on the dashboard "Connected Accounts" tab.
2. Clicking `.mvs-fe-disconnect-btn` opens the styled `<dialog>`, never a
   browser `window.confirm()` prompt.
3. Confirm button is labeled "Disconnect" and tinted destructive.
4. Cancel aborts with zero outbound API requests.
5. Confirm fires the disconnect endpoint.
6. (If step 6 included) Pro admin Settings → Connectors tab also shows the
   styled `<dialog id="mvs-confirm-dialog">` on disconnect.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Browser confirm dialog appears | `mvs-confirm` not loaded as dep | `includes/Core/Plugin.php::render_dashboard_connectors_panel` — verify `array( 'mvs-confirm' )` dep on `mvs-dashboard-connectors` enqueue |
| Cancel still fires the disconnect | `confirmAction()` not awaited in handler | `assets/js/dashboard-connectors.js::handleDisconnect` |
| Dialog opens but no styling | `mvs-confirm` stylesheet not enqueued | Free's `Plugin::auto_enqueue_confirm_style` should pair the style on `wp_enqueue_scripts@100` |
| Inline notice (red banner) doesn't appear when API fails | `showNotice()` not called in catch handler | `assets/js/dashboard-connectors.js` — search `showNotice(` |

## Coverage scope

This journey covers the swap of native `confirm()` and `alert()` to
`mvsConfirm` + inline-notice across BOTH connector entry points:

- `assets/js/dashboard-connectors.js` (frontend, member dashboard) — 1 confirm + 6 alerts replaced.
- `assets/js/connector-settings.js` (admin, Pro Settings → Connectors tab) — 1 confirm replaced.

Pre-existing baseline (audit/wppqa-baseline-2026-05-01/SUMMARY.md): "All 8
plugin-dev-rules failures are pre-existing UI hygiene debt (browser alert() /
confirm() in connector dashboards)." This journey is the regression sentinel
that prevents them from coming back.
