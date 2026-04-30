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

1. `assets/js/frontend/bp-actions.js:28` — `confirm()` used (admin-ux-rulebook Rule 10 ban). Action: replace with modal.
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

## Wiring — 3 high findings (ALL false positives)

1. `mvs_permissions_submit` — this is the form submit button name, not a setting key.
2. `doaction` — WP core list-table top bulk-action selector name (`<select name="doaction">`).
3. `doaction2` — WP core list-table bottom bulk-action selector name.

The wiring heuristic counted these as posted "settings" because they appear in `name="..."` attributes. They are not real settings.

**Classification**: 0 real → 3 false positive (heuristic limitation; `wppqa_check_wiring_completeness` only inspects `templates/` reads per skill notes — these don't belong in templates).

## Action items (none blocking; all pre-existing)

- Replace `confirm()` / `alert()` calls with modal/toast (UI hygiene; track in CLAUDE.md debt section).
- Add explicit cap checks alongside existing nonce verifications in 6 admin handlers.
- Verify the 6 messaging.js / dashboard-view.js REST contract findings against the actual route handlers (likely all 6 are 50-line-window proximity false positives).
- Suppress the 3 wiring false positives by tightening the wiring heuristic to skip `submit`/`doaction*` names.
