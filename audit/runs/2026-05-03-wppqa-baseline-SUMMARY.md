# wppqa baseline — Free 1.2.0 RC

**Date:** 2026-05-03
**Plugin version:** 1.2.0
**Tools run:** `wppqa_check_plugin_dev_rules`, `wppqa_check_rest_js_contract`, `wppqa_check_wiring_completeness`

## Headline

**Real bugs surfaced: 0.** All 14 reported errors are false positives — heuristic mismatches against established Wbcom plugin conventions or the linters' known proximity-window failure mode.

| Check | Passed | Failed | Real bugs |
|---|---|---|---|
| plugin-dev-rules | 4 | 5 | 0 |
| rest-js-contract | 49 | 6 | 0 |
| wiring-completeness | 19 | 3 | 0 |
| **Total** | **72** | **14** | **0** |

## Findings — full triage

### plugin-dev-rules (5 errors → 0 real)

#### `confirm-banned` (2)

| File:line | Linter says | Actual code | Verdict |
|---|---|---|---|
| `assets/js/frontend/bp-actions.js:42` | "Browser confirm() used" | Modal-first-with-fallback: `window.mvsConfirm` called first; `window.confirm` only as last-resort fallback for browsers without `<dialog>`. ESLint suppression already in place. | **False positive** — established Wbcom pattern. |
| `assets/js/frontend/mvs-confirm.js:136` | "Browser confirm() used" | This file IS the modal helper. Line 136 is the legacy-browser fallback inside the polyfill. ESLint suppression in place. | **False positive** — fallback is correct. |

#### `nonce-without-cap` (3)

The linter's heuristic looks for nonce-check function calls within N lines without a preceding `current_user_can()`. In all 3 cases, the cap check IS present at the top of the same handler function, but on a different code path that the linter missed.

| File:line | Capability check | Verdict |
|---|---|---|
| `includes/Admin/ModerationQueue.php:78` | `current_user_can( 'manage_options' ) \|\| current_user_can( 'moderate_mvs_media' )` at line 76 | **False positive** |
| `includes/Admin/TagManagementPage.php:400` | Same pattern at line 396 | **False positive** |
| `includes/Admin/TagManagementPage.php:560` | Same pattern at line 555 | **False positive** |

### rest-js-contract (6 errors → 0 real)

The check uses a 50-line proximity window — when multiple route URLs appear in the same JS file, the wrong one gets matched against a property access. All 6 hits were caused by a route URL near the access site that wasn't the route being called.

| File:line | Property | Linter matched route | Actual route | Verdict |
|---|---|---|---|---|
| `assets/js/messaging.js:1128` | `data.server_time` | `/me/notifications/read` | Long-poll endpoint (returns `server_time`) | **False positive** |
| `assets/js/messaging.js:1133` | `data.messages` | `/me/notifications/read` | Same long-poll endpoint | **False positive** |
| `assets/js/messaging.js:1151` | `data.typing` | `/me/notifications/read` | Same long-poll endpoint | **False positive** |
| `assets/js/messaging.js:1170` | `data.unread` | `/me/notifications/read` | Same long-poll endpoint | **False positive** |
| `src/blocks/dashboard-view/view.js:1217` | `data.total` | `/media/{id}/rules` | `collections/{id}?per_page=1` (returns `{total, …}`) | **False positive** |
| `src/blocks/dashboard-view/view.js:1373` | `data.count` | `/me/notifications/read` | `me/notifications/count` (returns `{count}`) — different endpoint | **False positive** |

### wiring-completeness (3 errors → 0 real)

| Setting | Source | Verdict |
|---|---|---|
| `mvs_permissions_submit` | Form submit-button name on Permissions tab | **False positive** — submit-button names aren't stored settings. |
| `doaction` | WP core bulk-action form name | **False positive** — WP convention. |
| `doaction2` | WP core bulk-action form name (footer toolbar) | **False positive** — WP convention. |

## Warnings (34 total) — informational

- 11 distinct CSS breakpoints across the plugin (frontend Rule 1 prefers 3). Most are component-local tweaks; can be normalized in 1.2.1.
- Tap-target warnings on small admin buttons (≤32px, several at 1–18px). Need to be re-checked — some "1px" hits are likely SVG `height: 1px` for icon containers, not real touch targets.
- 2 inline `onclick` attributes (`MediaListPage.php:420`, `TagManagementPage.php:312`). Used in admin paths only; could migrate to event delegation but not blocking.

## Methodology note

The wppqa MCP linters are deliberately liberal — they prefer false positives over false negatives, on the principle that a missed real bug costs more than a triaged false positive. Re-running them after every release as a baseline (and noting which findings are heuristic mismatches) keeps the cost low: re-running the checks a year from now, anyone reading this file knows which lines stay false positive vs which are new and need triage.

## Compared to 2026-05-01 baseline

Baseline file `audit/wppqa-baseline-2026-05-01/SUMMARY.md` reported the same false-positive shape from the same heuristics. No regressions introduced by 1.2.0; no new real bugs surfaced.
