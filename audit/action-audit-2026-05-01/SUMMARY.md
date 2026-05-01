# WPMediaVerse — action-audit baseline 2026-05-01

**Plugin**: wpmediaverse (FREE)
**Branch**: 1.2.0
**Generated**: 2026-05-01 (after commit `57369ff`)
**Skill**: `/Users/varundubey/.claude/skills/action-audit/SKILL.md`

## Surfaces audited

| Layer | Count | Notes |
|---|---:|---|
| `add_action('wp_ajax_…')` | 3 | All in OverviewPage.php |
| `add_action('admin_post_…')` | 1 | PermissionsManager.php |
| `register_rest_route()` | 81 | Across 19 controllers in `mvs/v1` namespace |
| `permission_callback => '__return_true'` | 23 | Pre-existing — public read endpoints |
| `wp_nonce_field()` calls | 12 | Admin forms |
| `wp_create_nonce()` for AJAX | 3 | mvs_dismiss_welcome, mvs_import_demo, mvs_cleanup_demo |
| `check_ajax_referer()` calls | 3 | All matched |
| `check_admin_referer()` calls | 6 | All matched (paired wp_nonce_field) |
| `wp_verify_nonce()` calls | 9 | All matched |
| Template buttons with class hooks (mvs-*-btn) | 40+ | Mostly Interactivity API directives, jQuery-free |
| JS files scanned (non-min) | 11 | assets/js/*.js + src/blocks/*/view.js |

## REAL_BUG findings: 0

No new bugs surfaced beyond the pre-existing baselines. Cross-layer wiring
is clean for the audited surfaces:

- Each `wp_ajax_*` registration has a matching JS xhr.send call with the
  same `action=` parameter (OverviewPage's import/cleanup/dismiss).
- The single `admin_post_*` handler (mvs_save_role_caps) has both a
  `wp_nonce_field()` emitter and matching `wp_verify_nonce()` check on the
  same nonce action string.
- Every JS `apiFetch(...)` / `fetch(...)` REST path corresponds to a
  registered REST route (verified end-to-end for messaging.js,
  bp-activity-media.js, profile-edit.js, dashboard-view/view.js,
  load-more.js, media-social/view.js).
- BP integration delete buttons (`mvs-media-delete-btn`,
  `mvs-bp-album-delete`, `mvs-bp-album-edit`) all have matching delegated
  click handlers in `assets/js/frontend/bp-actions.js`.
- All `wp_nonce_field()` action strings match the corresponding
  `wp_verify_nonce()` / `check_admin_referer()` check_action argument
  (verified for: mvs_setup_wizard, mvs_moderation_bulk,
  mvs_moderation_action, mvs_clear_logs, bulk-tags, edit-tag_,
  create-tag, delete-tag_, mvs_save_role_caps, mvs_collection_rules,
  mvs_export_stats_csv, mvs_dismiss_welcome).

## FALSE_POSITIVE / DESIGN_DECISION findings

| # | Type | Source | Finding | Verdict | Reason |
|---|---|---|---|---|---|
| 1 | DESIGN_DECISION | OverviewPage.php:193 + 252 | JS sends `action=mvs_import_demo_data` but nonce action is `mvs_import_demo` (not `…_data`) | NOT a bug | AJAX action hook name (`wp_ajax_mvs_import_demo_data`) and nonce action string (`mvs_import_demo`) are independent identifiers by WP convention. The check_ajax_referer at line 712 verifies against `mvs_import_demo`, matching the wp_create_nonce at line 193. Same pattern for cleanup. |
| 2 | DESIGN_DECISION | bp-actions.js:24 | `confirm()` used for delete confirmation | Pre-existing, baseline noted | Already flagged in `wppqa-baseline-2026-05-01/SUMMARY.md` (admin-ux-rulebook Rule 10). Single choke-point function `confirmAction()` for future modal swap. Not introduced by this audit. |
| 3 | DESIGN_DECISION | bp-activity-media.js:209 | `sendBeacon` POST with `_method=DELETE&_wpnonce=…` (nonce in body, not header) | Works | WP REST API accepts `_wpnonce` from query string OR body OR `X-WP-Nonce` header (see `rest_cookie_check_errors`). Beacon API can't set headers, so body is correct. |
| 4 | FALSE_POSITIVE | media-single.php:160 | `mvs-favorite-btn` / `mvs-share-btn` / `mvs-reaction-btn` not bound in any `.js` file | NOT a bug | These are bound via `data-wp-on--click="actions.toggleFavorite"` / `actions.share` / `actions.toggleReaction` (Interactivity API directives), with corresponding store actions in `src/blocks/media-social/view.js`. Skill phase 3 grep heuristic didn't recognize Interactivity API bindings — same limitation the SKILL.md flags as edge case #1 (dynamic action names). |
| 5 | DESIGN_DECISION | MediaListPage.php:432-442 | `check_admin_referer()` without explicit `current_user_can()` next to it | Already noted in wppqa baseline | The page's `render()` entry point (line 28) gates with `current_user_can('manage_options') \|\| current_user_can('upload_mvs_media')` and is the only call path to `handle_bulk_actions()`. wppqa-baseline-2026-05-01 flagged this as defense-in-depth recommendation; not a reachable bug. |
| 6 | DESIGN_DECISION | OverviewPage.php:626 + ModerationQueue.php:79 + TagManagementPage.php:537 | Same nonce-without-cap pattern | Already noted | Pre-existing wppqa baseline. Each handler is reachable only from a `manage_options`-gated admin page render. No new debt added. |
| 7 | DESIGN_DECISION | 23 routes use `permission_callback => '__return_true'` | Pre-existing | These are public read endpoints (album/media/stats/user/follow/comment listings, signed-url issuance). All write endpoints (POST/PUT/DELETE) carry capability- or ownership-aware permission_callbacks. The `__return_true` choices are deliberate for the public read surface. |

## Action items

None blocking for this audit cycle. All findings are pre-existing
defense-in-depth items already tracked in `wppqa-baseline-2026-05-01`.

For future hardening (separate work, not this commit):

- Add explicit `current_user_can()` next to each `check_admin_referer()` /
  `check_ajax_referer()` even when the page entry point already gates —
  defense-in-depth makes static analysis greener.
- Replace the single `confirm()` choke-point in `bp-actions.js` with the
  shared toast/modal helper used elsewhere.
- The Interactivity API edge case is a SKILL.md-known limitation, not a
  plugin issue. Optional: extend the skill's phase 3 to recognize
  `data-wp-on--*` directives as JS event bindings.
