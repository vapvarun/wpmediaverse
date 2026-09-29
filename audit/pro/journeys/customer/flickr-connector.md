---
journey: flickr-connector
plugin: wpmediaverse-pro
priority: high
roles: [subscriber]
covers: [MV-IMP-007, MV-IMP-008, MV-IMP-009, MV-IMP-010, MV-IMP-011, MV-IMP-012, flickr-connector, connectors-toggle]
prerequisites:
  - "Both plugins active; mvs_connectors_enabled = 1"
  - "A Flickr developer app key/secret (site-default or member-supplied custom)"
  - "A member with a real Flickr account able to complete OAuth 1.0a"
estimated_runtime_minutes: 12
---

# Connect, browse-and-import, export/auto-export, delta sync, and the master toggle that removes all of it cleanly

**Why this journey exists**: `admin/05-connector-disconnect-uses-modal.md` (already in your context) only covers the disconnect-modal UX. This journey is the connect/import/export/sync functional flow underneath it — the OAuth handshake, the import-modal keyboard/dialog behaviour, the async export/sync mechanics, and the registration-gated toggle that must leave nothing behind when off.

## Setup

- Site: `$SITE_URL`; member `?autologin=<member>`.

## Steps

### 1. Connect: start the OAuth flow
- **Action**: on the dashboard "Connected Accounts" tab, click Connect on the Flickr card (or `POST /wp-json/mvs-pro/v1/connectors/flickr/connect`).
- **Expect**: `start_auth()` requests a Flickr request token, stores it in a 300-second transient (`mvs_flickr_req_{user_id}`), and returns the authorization redirect URL.

### 2. Complete OAuth and land back connected
- **Action**: complete Flickr's own authorization page; return to the callback.
- **Expect**: `handle_callback()` completes the exchange; the dashboard card shows the connected username (`mvs_connector_flickr_username`) and the auto-export toggle state.

### 3. Expired OAuth session fails gracefully
- **Action**: start the OAuth flow, wait past 300 seconds, then complete the Flickr authorization page.
- **Expect**: error code `flickr_callback_token_mismatch`, message "Flickr OAuth token mismatch or session expired. Please try connecting again." — not cryptic.

### 4. Import modal: dialog and keyboard behaviour
- **Action**: open "Import from Flickr" from the dashboard; navigate with keyboard only.
- **Expect**: header "Import from Flickr" with a Close button (`aria-label="Close"`); `role="dialog"`, `aria-modal="true"`; focus moves to Close on open; Tab/Shift+Tab stay inside the dialog; Escape closes it (not while an import is running); closing returns focus to the button that opened it.

### 5. Browse, filter, and import selected photos
- **Action**: browse/filter by album (`GET .../photos`, paginated max 30/page — Flickr TOS limit; `GET .../albums`); select photos; `POST .../import` with `photo_ids`.
- **Expect**: imported media gets `mvs_media_imported` action fired and the external-source badge metadata (`external_source`, `external_id`, `external_url`, `external_synced`).

### 6. Imported photo's privacy follows the member's connector preference
- **Action**: set the member's `default_privacy` connector preference (`PUT .../prefs`) to something other than `match`; import a new photo.
- **Expect**: the imported photo lands at that configured privacy level.

### 7. Manual export
- **Action**: `POST /mvs-pro/v1/connectors/flickr/export` with local attachment IDs.
- **Expect**: each uploads to Flickr via `export_item()`; fires `mvs_media_exported`.

### 8. Auto-export runs in the background, not blocking the uploader
- **Action**: enable auto-export in the connected-accounts panel; upload new media as that member.
- **Expect**: appears on Flickr without manual action; queued via Action Scheduler (`mvs_connector_auto_export`, group `mvs-connectors`) with a `wp_schedule_single_event` fallback — the upload UI must NOT block or wait on the Flickr round-trip.

### 9. Auto-export failure is logged for the owner
- **Action**: (if reproducible) force an export failure; check MediaVerse > Logs.
- **Expect**: a log entry (context `connectors`) with the media id and the error, so the owner can see why a photo never reached Flickr.

### 10. Auto-export no-ops cleanly if disconnected before the job runs
- **Action**: enable auto-export, upload, then disconnect Flickr before the AS job executes.
- **Expect**: `is_connected($author_id)` checked at execution time — no-ops cleanly, no error.

### 11. Delta sync: immediate response, background continuation
- **Action**: trigger `POST /mvs-pro/v1/connectors/flickr/sync`.
- **Expect**: an immediate REST response communicating "sync started," not full completion, with the remainder completing via the continuation hook (`mvs_pro_connector_delta_sync_continue`, AS group `mvs-connectors`, or a 1-minute `wp_schedule_single_event` fallback). The continuation resumes from `after_media_id` (cursor-based) on each hop, without duplicating work.

### 12. Connectors master toggle off — registration-gated, not permission-gated
- **Action**: turn `mvs_connectors_enabled` off, Save; as a member, visit the dashboard Connected Accounts tab; `GET /wp-json/mvs-pro/v1/connectors` directly.
- **Expect**: `ConnectorManager`/`ConnectorRESTController`/`Connector` (Flickr) are never registered — the REST route 404s (`rest_no_route` from WordPress's router), not a 403 from a permission callback. The dashboard tab either doesn't appear or shows a clean "not available" state, never a shell with dead buttons. Fields under Flickr import section (app key/secret) hide LIVE via `show_when` when unchecked, not just on next page load.

### 13. Toggling off and back on preserves the connection
- **Action**: with an existing Flickr connection (username, auto_export pref already saved), turn the toggle off then back on.
- **Expect**: the connection state survives the flip — nothing in the toggle logic deletes user meta.

### 14. External-source badge, seen by every viewer identically
- **Action**: view a Flickr-imported item's single page as its owner and as another member.
- **Expect**: badge text "Imported from Flickr" with a bolded platform label; "Last synced: {N ago}" or "Never"; "Sync Now" button ONLY when `$is_connected` — and per 2.6.0 there is no longer a per-photo Sync Now button (removed as a dead control with no handler); syncing is whole-library only via step 11's route. "View original" opens in a new tab with `rel="noopener"`.

## Pass criteria

1. OAuth connect succeeds and fails gracefully on an expired session with the exact documented error.
2. The import modal's dialog/focus-trap/keyboard behaviour matches the accessibility contract exactly.
3. Manual export, auto-export (async, non-blocking, logged on failure, safe if disconnected mid-flight), and delta sync (immediate response + cursor-based background continuation) all behave as documented.
4. The master toggle removes the REST routes entirely (404, not 403) and the dashboard tab cleanly, without touching saved connection state.
5. The external-source badge shows identically to every viewer, with no dead per-photo Sync Now control.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Import modal traps focus incorrectly or Escape closes mid-import | dialog focus-trap JS regression | `connector-import-modal.php` / its JS |
| Auto-export blocks the upload response | not actually queued via Action Scheduler/`wp_schedule_single_event` | `ConnectorManager::maybe_auto_export()` |
| Toggle-off returns 403 instead of 404 | routes registered but permission-gated instead of registration-gated | `Plugin::init()` connectors registration guard |
| Connection state wiped by toggling off/on | toggle logic deleting user meta it shouldn't | connectors toggle handler |
| Per-photo Sync Now button still renders | stale template copy pre-2.6.0 fix | `templates/partials/external-source-badge.php` |
