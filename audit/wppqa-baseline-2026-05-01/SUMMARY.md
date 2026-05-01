# WPMediaVerse — wppqa baseline 2026-05-01

**Plugin**: wpmediaverse (FREE)
**Branch**: 1.2.0
**Generated**: 2026-05-01
**Trigger**: refresh after commit `d986525` (DM-access settings duplicate-registration fix)

## Per-check pass/fail

| Check | Passed | Failed | Duration |
|---|---:|---:|---|
| `wppqa_check_plugin_dev_rules` | 2 | 7 (high) + 34 warnings | 63ms |
| `wppqa_check_rest_js_contract` | 49 | 6 (high) | 19ms |
| `wppqa_check_wiring_completeness` | 19 | 3 (high — all false positives) | 73ms |

## Disposition

**Today's commit `d986525` did NOT introduce any of the findings below.** All findings were present in the 2026-04-29 baseline; this baseline confirms the fix only resolved the reported dm_access save-revert bug without adding new debt.

The dm_access bug itself is **NOT** in this baseline because it was a logic bug (duplicate `register_setting()` overwrote the enum sanitizer with the bool one for `mvs_show_online_status` AND silently re-mapped `mvs_dm_access` enum values). wppqa's wiring check inspects template reads, not registration overlap — so it could not have caught this class. A new check class would be needed to surface it; out of scope for this onboarding refresh. The new regression sentinel journey `customer/05-dm-access-setting-persists.md` covers it end-to-end.

## Plugin-dev-rules — top 5 high findings (all pre-existing)

1. ~~`assets/js/frontend/bp-actions.js:28` — `confirm()` used (admin-ux-rulebook Rule 10 ban).~~ **Resolved 2026-05-01** — single `confirmAction()` choke point now awaits `window.mvsConfirm()` from new `assets/js/frontend/mvs-confirm.js` (declared as dep of `mvs-bp-actions`). Native `confirm()` retained inside the helper as last-resort fallback when `<dialog>` is unsupported. Regression journey: `audit/journeys/admin/07-destructive-action-uses-modal-not-confirm.md`.
2. `includes/Admin/MediaListPage.php:432,437,442` — three nonce checks missing paired `current_user_can()` capability check. Real, but not reachable by unauthenticated users (admin-only screen). Action: add explicit cap check for defense-in-depth.
3. `includes/Admin/ModerationQueue.php:79` — same nonce-no-cap pattern.
4. `includes/Admin/OverviewPage.php:626` — same nonce-no-cap pattern (welcome banner dismiss).
5. `includes/Admin/TagManagementPage.php:537` — same nonce-no-cap pattern.

## REST↔JS contract — 6 high findings (likely-false-positive triage)

1. `assets/js/messaging.js:1128` reads `data.server_time` near `/me/notifications/read` — **likely false positive**: the route proximity heuristic (50-line window) matched the wrong controller. The actual long-poll endpoint that returns `server_time` is `MessagingController::poll`, not the notification mark-read endpoint. Verify by reading `MessagingController.php`.
2. `assets/js/messaging.js:1133` reads `data.messages` — same proximity heuristic; this is the long-poll response.
3. `assets/js/messaging.js:1151` reads `data.typing` — same long-poll response.
4. `assets/js/messaging.js:1170` reads `data.unread` — same long-poll response.
5. `src/blocks/dashboard-view/view.js:1217` reads `data.total` near `/media/.../rules` — likely the AccessController's grant-list endpoint or paginated wrapper. Verify.
6. `src/blocks/dashboard-view/view.js:1373` reads `data.count` near `/me/notifications/read` — possibly real; may want to follow up.

**Classification**: 0 confirmed real → 5–6 likely false positive (proximity-window heuristic limitation per skill notes).

### Triage (2026-04-30)

All 6 findings investigated end-to-end against the actual REST controllers + JS call sites. Result: **6/6 FALSE POSITIVE** (50-line proximity-window heuristic limitation).

| # | JS call site | Actual REST endpoint hit | Field read | Field emitted by controller? | Verdict |
|---|---|---|---|---|---|
| 1 | `messaging.js:1128` (`pollForUpdates`) | `apiFetch('/messages/poll?...')` (line 1125) | `data.server_time` | Yes — `MessagingController.php:654` returns `'server_time' => gmdate('c')` | **triaged_2026-04-30: false positive — emitted by /messages/poll which is 6 lines above the read; wppqa walked into the next function** |
| 2 | `messaging.js:1133` | same `/messages/poll` response | `data.messages` | Yes — `MessagingController.php:651` returns `'messages' => $messages` | **triaged_2026-04-30: false positive — same /messages/poll response object** |
| 3 | `messaging.js:1151` | same `/messages/poll` response | `data.typing` | Yes — `MessagingController.php:652` returns `'typing' => $typing` | **triaged_2026-04-30: false positive — same /messages/poll response object** |
| 4 | `messaging.js:1170` (`refreshUnreadCount`) | `apiFetch('/me/messages/unread-count')` (line 1169) | `data.unread` | Yes — `MessagingController.php:669` returns `'unread' => …` | **triaged_2026-04-30: false positive — emitted by /me/messages/unread-count, the call sits one line above the read** |
| 5 | `view.js:1217` | `apiFetch(... 'collections/' + id + '?per_page=1')` (line 1215) | `data.total` | Yes — `CollectionController.php:412` returns `$data['total'] = $resolved['total']` (paginated wrapper) | **triaged_2026-04-30: false positive — paginated total field in CollectionController, not a `/rules` endpoint** |
| 6 | `view.js:1373` (`loadNotificationCount`) | `apiFetch(... 'me/notifications/count')` (line 1371) | `data.count` | Yes — `NotificationController.php:159` returns `array('count' => …)` | **triaged_2026-04-30: false positive — emitted by /me/notifications/count, two lines above the read; wppqa misattributed to nearby `/read` call** |

**Real-bug count: 0. False positives: 6.** No source changes warranted; no journey added. The wppqa heuristic should be tightened to use a tighter (≤10 line) window AND scope to the same `apiFetch()` call's promise chain instead of file-line proximity. Out of scope for this triage — flagged for the wppqa MCP author.

## Wiring — 3 high findings (ALL false positives)

1. `mvs_permissions_submit` — this is the form submit button name, not a setting key.
2. `doaction` — WP core list-table top bulk-action selector name (`<select name="doaction">`).
3. `doaction2` — WP core list-table bottom bulk-action selector name.

The wiring heuristic counted these as posted "settings" because they appear in `name="..."` attributes. They are not real settings.

**Classification**: 0 real → 3 false positive (heuristic limitation; `wppqa_check_wiring_completeness` only inspects `templates/` reads per skill notes — these don't belong in templates).

## Action items (none blocking; all pre-existing)

- ~~Replace `confirm()` / `alert()` calls with modal/toast (UI hygiene; track in CLAUDE.md debt section).~~ **Done 2026-05-01** — Free's single `confirm()` in `bp-actions.js` migrated to `window.mvsConfirm`. New shared helper at `assets/js/frontend/mvs-confirm.js` + `assets/css/mvs-confirm.css`, auto-paired via `Plugin::auto_enqueue_confirm_style`. Regression journey: `audit/journeys/admin/07-destructive-action-uses-modal-not-confirm.md`.
- Add explicit cap checks alongside existing nonce verifications in 6 admin handlers.
- Verify the 6 messaging.js / dashboard-view.js REST contract findings against the actual route handlers (likely all 6 are 50-line-window proximity false positives).
- Suppress the 3 wiring false positives by tightening the wiring heuristic to skip `submit`/`doaction*` names.
