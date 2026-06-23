# Pre-Release Flow-Gap Audit — WPMediaVerse Free + Pro (1.8.0)

Date: 2026-06-22 · Method: 4 cross-verified passes — admin flow-gaps, member flow-gaps,
three-entry-points completeness (Rule #18), and the full `wppqa_audit_plugin` automated audit
(1965 checks; live API/DB on http://wb-media.local). Every item below was opened in source and
verified; agent false-positives were dropped (noted at bottom).

**Verdict:** No critical security/fatal defect. Maturity 79/100, 97% feature-complete. But there
is a **dominant, systemic flow gap** plus several dead/unmanageable features that should be fixed
before release.

---

## THE dominant theme (fix this pattern first — highest leverage)

**Optimistic UI with no failure path.** Across nearly every member journey, the JS updates/clears
local state *before* the REST result and never rolls back or surfaces an error. Failed sends,
comments, votes, follows, and loads all present as success — or as misleading "end/empty" states.
This single pattern produces most of the member HIGHs below. A shared `restFetch` error/rollback +
toast convention applied across messaging.js, the social/explore/compete stores, and load-more.js
closes the bulk of them at once.

---

## P0 — RELEASE BLOCKERS (data-loss-shaped, broken core flow, dead paid feature, authz)

### Data integrity / silent failure
1. **DM: failed sends render as delivered.** `messaging.js:679` sets `_failed`, but `chat-message.php`
   has no `_failed`/`_sending` binding — no error icon, no retry, no pending state. Message looks sent. (HIGH, data-loss-shaped)
2. **Profile bio bound with `data-wp-text` not `data-wp-bind--value`.** `profile-edit.php:154`,
   `dashboard-content.php:220` — textarea won't two-way bind; an edited bio can save empty/stale. (HIGH, data-loss-shaped)
3. **Comment submit clears the field regardless of REST result** and has **no logged-out guard.**
   `media-social/view.js:241-252` — on 401/429/fail the field empties = false "posted" signal. (HIGH)
4. **"Load More" failure shows "You're all caught up!"** instead of an error. `load-more.js:154,194`
   and `explore-feed/view.js:226` ("Ignore fetch errors"). Member stops scrolling believing they saw everything. (HIGH ×2)

### Dead / unmanageable features (three-entry-point gaps)
5. **`mvs_mentions` is write-only dead weight.** Comment pipeline stores mentions, but there is **no
   API, no template, no admin** to read them — a mentioned user never sees "who mentioned me."
   Fix: `GET /me/mentions` + surface in the existing notification bell. (CRITICAL)
6. **`mvs_blocks` has no unblock UI.** REST-only (`POST/DELETE /users/{id}/block`, `GET /me/blocked`).
   A member can block but has no screen to unblock; owner can't audit. Fix: blocklist section in dashboard. (HIGH)
7. **Auto-captions point at an OpenAI key field that doesn't exist** on the Pro screen
   (`ProSettings.php:1106` says "set above" — key lives on Free AI settings). Owner enables it, it silently no-ops. (HIGH)

### Authorization / contract
8. **3× nonce check without capability check** (`plugin-dev-rules`) — handlers verify nonce but never
   `current_user_can()`. The only authz-flavored finding; verify + add cap checks. (HIGH)
9. **4× REST↔JS contract mismatches** — JS reads keys PHP never returns: `unread`, `total`, `count`,
   `message_type` (messaging/notification surfaces → `undefined` at runtime). Probable live bugs. (HIGH)
10. **Demo-data AJAX returns -1** — `mvs_import_demo_data` / `mvs_cleanup_demo_data` fail nonce/permission. (HIGH — onboarding)

---

## P1 — SHOULD FIX BEFORE RELEASE

### Admin (owner expectations)
- **ThemeLibrary "Edit" dead-ends** — `action=edit` link, no handler (`ThemeLibrary.php:324`).
- **Tournament/Battle admin actions show no success notice** — create/cancel/start/resolve/delete complete silently (`TournamentManager`, `BattleMonitor`). ChallengeManager does it right.
- **`mvs_reports` unmanageable on Free-only** — report moderation is entirely in Pro's ReportManager; a Free owner can't action reports.
- **`mvs_transactions` ghost table** (Free, created-never-used) — wire or drop before release.
- **`mvs_pro_collection_items` has no admin page** — owner can't manage curated collections.
- **AI providers (Vision/Rekognition) have no "Test connection"** — owner can't verify creds until an upload runs.
- **`mvs_telemetry_enabled` half-wired** — toggle exists, `capture()` called from nowhere. Hide until instrumented, or wire it.
- **3× direct `$_POST` → `update_option`** bypassing Settings API sanitization.
- **Weak/CTA-less admin empty states** — the shared `render_admin_empty_state()` helper exists but **no page calls it**.

### Member
- **Compete list fetch failures leave spinner + error simultaneously** (`challenges/battles/tournaments-store.js` never clear loading). (×3)
- **Pro IG feed Like/Comment clickable but dead for logged-out** (no `isLoggedIn` guard). 
- **Upload block renders blank for logged-out** — no "Log in to upload" CTA.
- **Optimistic actions never roll back** — DM reactions, mute/pin/archive, battle votes, boost balance.
- **Inconsistent logged-out CTA** — reactions/favorites show a login *link*; comments/IG-like show nothing or a late toast.
- **Compete XP invisible without wb-gamification** — gate behind a clear "requires wb-gamification" notice or surface XP natively.

### Quality (whole-product)
- **49 a11y errors** — 69 inputs without labels, 27 images without alt, 46 `outline:none` without `:focus-visible`. (WCAG 2.1 AA is the stated bar.)
- **8 enum-drift fields** — `action, privacy, size, media_type, status, tab, scope, type` defined with divergent value lists across layers. Extract to one Enums helper.
- **3× modal/popup missing close button** (frontend-eval).
- **1.4 MB frontend bundle** — performance; review code-split/lazy.

---

## P2 — POLISH
- Upload: no privacy-applied confirmation / no "view it" link; partial-batch error obscured; privacy not reset on cancel.
- Dashboard profile view shows no stats. Leaderboard doesn't show "you are #42". Tournament self-vote not blocked. Avatar removal can leave broken img. Message context-menu is `contextmenu`-only (no mobile long-press). Voice-playback errors only `console.log`. Share label sticks on error. Edit-window expiry surfaced only as generic 403.
- Marketing assets missing (wp.org banner/icon/screenshots) — pre-publish, not pre-code.

---

## Verified NOT a gap (don't re-investigate)
- Onboarding solid: activation creates pages, first-run setup wizard, Overview welcome + 3-step checklist + demo import + "pages active/missing" widget. 0/17 admin screens blank on fresh install.
- All 7 Pro feature toggles render a control AND gate behavior.
- 37/38 Free settings fully wired (only telemetry half-wired).
- Tournament registration IS wired (`POST /tournaments/{id}/register` + `tournaments-store.js:236`) — agent false positive, dropped.
- `mvs_quota_packages`/`mvs_credit_log` surfaced via UsageWidget + QuotaPage; notification bell exists — agent misses, corrected.
- PHPCS "1112 errors" headline is inflated by `tools/wp-stubs.php` + `tests/` (non-shipping). Real-code PHPCS is recommended-tier only.
- The MCP's 6 "fix before release" GAP items (no activation hook, CPT no show_in_rest, REST no permission_callback) are likely detector false-negatives — spot-check, but memory/code show these exist.

---

## Counts
Admin: 4 HIGH / 7 MED / 2 LOW · Member: 13 HIGH / 11 MED / 5 LOW · Entry-points: 5 half-cooked
(1 critical) · Automated: 6 critical / systemic FAIL on wiring, enum, rest-js-contract, ux, dev-rules,
qa-coverage + 49 a11y. **Net release-blocker set: ~10 P0 items, dominated by the optimistic-UI-no-failure-path pattern.**
