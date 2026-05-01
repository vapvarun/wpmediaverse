---
journey: destructive-action-uses-modal-not-confirm
plugin: wpmediaverse
priority: high
roles: [administrator, member]
covers: [admin-ux-rule-10, mvs-confirm, bp-actions]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "WPMediaVerse v1.2.0+ active"
  - "BuddyPress active (so the profile media tab is reachable)"
  - "At least one media item owned by the test user"
estimated_runtime_minutes: 3
---

# Destructive action shows the styled modal, not browser confirm()

**Why this journey exists**: admin-ux-rulebook Rule 10 bans `window.confirm()` /
`window.alert()` in any plugin code path because:

1. They're unstyled — the dialog ignores the plugin's design system and looks
   different in every browser.
2. They block the JS event loop — pending fetches/timers hang until the user
   clicks.
3. They can be suppressed by browsers ("don't allow this site to show further
   dialogs") after a few fires in a row, after which any code that gates a
   destructive action behind `if (! confirm(...)) return;` proceeds without
   consent.

Free's `bp-actions.js` (BP profile/group media tab delete buttons) used to call
`window.confirm()`. 1.2.0 swaps it for `window.mvsConfirm()` — promise-based,
matches the rest of the plugin's modal UI, no event-loop block.

This journey asserts: clicking a destructive button on the BP profile media
tab opens the styled modal (not a browser dialog), AND that the modal's
Cancel button aborts the destructive action without making an API call.

## Setup

- Site: `$SITE_URL`
- User: `admin` (autologin via `?autologin=1`)
- Pre-condition: at least one media item visible on the user's profile
  media tab.

## Steps

### 1. Auto-login
- **Action**: `playwright_navigate $SITE_URL/wp-admin/?autologin=1`
- **Expect**: redirected to wp-admin dashboard.

### 2. Open BP profile media tab
- **Action**: `playwright_navigate $SITE_URL/members/admin/media/`
- **Expect**: at least one `.mvs-media-delete-btn` present in DOM.
  `window.mvsConfirm` is a function (truthy `typeof`).
  `document.getElementById('mvs-frontend-confirm-dialog')` is null
  (lazy-created on first call).

### 3. Click the first delete button
- **Action**: `playwright_click '.mvs-media-delete-btn:first-of-type'`
- **Expect**: a `<dialog>` element with id `mvs-frontend-confirm-dialog`
  appears in the DOM. The `.open` attribute is true. The
  `.mvs-frontend-confirm-dialog__message` text reads
  `"Delete this media? This cannot be undone."`. The
  `.mvs-frontend-confirm-ok` button text reads `"Delete"` and the dialog
  carries the `mvs-frontend-confirm-dialog--destructive` class (red OK
  button). NO `window.confirm()` browser dialog appears.

### 4. Cancel the dialog
- **Action**: `playwright_click '.mvs-frontend-confirm-cancel'`
- **Expect**: `<dialog>` `.open` becomes false. No DELETE request fires
  (verify via `playwright_browser_network_requests` that no `/wp-json/mvs/v1/media/`
  DELETE is recorded after the click).

### 5. Click delete again, this time confirm
- **Action**: `playwright_click '.mvs-media-delete-btn:first-of-type'`,
  then `playwright_click '.mvs-frontend-confirm-ok'`.
- **Expect**: a `DELETE /wp-json/mvs/v1/media/{id}` request is fired
  (status 204 on success). The corresponding `.mvs-grid-item` fades to
  opacity 0 and is removed from the DOM within 250 ms.

## Pass criteria

ALL of the following hold:

1. `window.mvsConfirm` is a function on the BP profile media tab.
2. Clicking any `.mvs-media-delete-btn` opens the styled `<dialog>`,
   never a browser `window.confirm()` prompt.
3. The dialog's confirm button is labeled "Delete" and tinted in the
   destructive (red) color.
4. Clicking Cancel aborts the action with zero outbound API requests.
5. Clicking Confirm fires the DELETE request and removes the card.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Browser confirm dialog appears instead of styled modal | `mvs-confirm` script not enqueued | `includes/Core/Plugin.php` (search `mvs-confirm` registration), confirm `mvs-bp-actions` declares `mvs-confirm` as dep |
| Modal opens but Cancel still fires DELETE | `confirmAction()` no longer awaited in handler | `assets/js/frontend/bp-actions.js` (search `confirmAction(` callsites) |
| `window.mvsConfirm` is undefined | Script load failure or ordering issue | Browser DevTools network tab — verify `mvs-confirm.js` returns 200 before bp-actions runs |
| Dialog appears but is unstyled | `mvs-confirm` style not enqueued | `includes/Core/Plugin.php::auto_enqueue_confirm_style` should run on `wp_enqueue_scripts@100`; verify with `wp_style_is('mvs-confirm', 'enqueued')` |
| Modal has no destructive (red) styling | `tone` opt not passed | `assets/js/frontend/bp-actions.js::confirmAction` should pass `tone: 'destructive'` |

## Coverage scope

Free's bp-actions destructive buttons:
- `.mvs-media-delete-btn` — single media delete from BP profile / group tab.
- `.mvs-bp-album-delete` — album delete from BP profile / group tab.

Both use the same `confirmAction()` choke point — verifying one verifies both.
