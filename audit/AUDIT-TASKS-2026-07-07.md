# MediaVerse 1.9.0 — Audit Task List

Source: `AUDIT-VERDICT-2026-07-07.md` (5-agent static audit + Critical code-verification).
Status legend: **CONFIRMED** (verified in code) · **NEEDS-VERIFY** (agent-reported, not yet code-verified) · **DISPROVEN** (checked, not a bug).
No fixes started. This list is the backlog; each item to be re-validated by the re-audit pass before any fix.

---

## Batch A — Data safety (Critical)

| ID | Task | Plugin | File:line | Verify | Acceptance |
|---|---|---|---|---|---|
| A1 | Remove 3 `wp_update_post()` calls in moderation (media_id ≠ wp_posts ID → corrupts unrelated posts); rely on `set_status()` | Free | `Services/ModerationService.php:117,203,226` | **CONFIRMED + LIVE-PROVEN** | Moderating media never touches `wp_posts`; unrelated posts unaffected; status persists in `mvs_media_index` |

> **A1 live DB proof (2026-07-07, mediaverse.local):** `wp_mvs_media_index.media_id` overlaps `wp_posts.ID` for **54 rows** — 34 `bp-email`, 4 `page` (incl. `media_id 20` = the **"Compete" page**), 4 `buddypress` (Activity/Members), 3 `nav_menu_item`, 1 each `wp_navigation`/`wp_global_styles`/`post`, 6 `mvs_album`. Rejecting media #20 runs `wp_update_post(['ID'=>20,'post_status'=>'draft'])` → drafts the Compete page site-wide. Card 10068990139.
| A2 | Guard vote counter on insert result + add DB unique key `(votable_type,votable_id,user_id)` | Pro | `Tournaments/TournamentService.php:407` | **CONFIRMED** | Concurrent double-vote cannot inflate `player_*_votes`; counter == actual vote rows |
| A3 | Same guard — battles | Pro | `Battles/BattleService.php:414` | **CONFIRMED** | as A2 |
| A4 | Same guard — challenges | Pro | `Challenges/ChallengeService.php:375` | **CONFIRMED** | as A2 |
| A5 | Consolidate delete-cascade to one path; 2 of 3 copies skip `mvs_media_deleted` | Free | `Repository/MediaRepository.php` + `Admin/MediaListPage.php` + `REST/.../BulkController.php` | **CONFIRMED** | One cascade path; hook always fires; no orphan rows |

## Batch B — Big-site / stability (High)

| ID | Task | Plugin | File:line | Verify | Acceptance |
|---|---|---|---|---|---|
| B1 | Connector delta-sync: cap + background (Action Scheduler); no all-media fetch + sync HTTP in one request | Pro | `.../ConnectorRESTController.php:759` | NEEDS-VERIFY | No timeout at 2000+ linked media |
| B2 | Transcode in-progress lock/guard (concurrent FFmpeg on same paths) | Pro | `.../TranscodeController.php:187` | NEEDS-VERIFY | Two jobs on same media serialize; no corrupt output |
| B3 | Transcode cleanup cron actually deletes expired outputs | Pro | `.../TranscodeService.php:641` | NEEDS-VERIFY | Disk reclaimed |
| B4 | `/media` feed + gallery expansion N+1 → batch prefetch | Free | `REST/Controller/MediaController.php` | NEEDS-VERIFY | Constant query count vs per-item |
| B5 | Tournament registration TOCTOU (unchecked insert; "registered" but not in bracket) | Pro | `Tournaments/*` | NEEDS-VERIFY | Registration atomic or verified before success |
| B6 | Boost points debited before insert with no rollback | Pro | competitions | NEEDS-VERIFY | Debit only on successful insert |
| B7 | Captions provider no try/catch → status stuck "processing"; duplicate billed jobs | Pro | captions | NEEDS-VERIFY | Failures set error status; no duplicate billing |
| B8 | Unbounded `IN()` on follow/block; Stories cleanup cron unbounded | Pro/Free | follows/stories | NEEDS-VERIFY | Bounded/batched |

## Batch C — Blocks (CODE-VERIFIED 2026-07-07, no guesswork) — LIVE visual sweep still owed

Skeleton extracted deterministically for all 26 blocks (`audit-2026-07-07/block-skeleton.json`); every flag below confirmed by reading `block.json` + `edit.js` + `render.php` + the delegated renderer/layout class.

| ID | Task | Plugin | Block | Verify | Evidence |
|---|---|---|---|---|---|
| C1 | `columns` attribute has a RangeControl in edit.js but render **ignores it** — hardcodes `get_option('mvs_grid_columns',3)`. Either honor the attribute or remove the control (comment claims admin is source-of-truth → misleading control). | Free | media-grid | **CONFIRMED** | `media-grid/render.php:16,18,172` |
| C1b | `userId` attribute read in render (line 29) but has **no editor control** (orphan) — code-only filter, no UI. | Free | media-grid | **CONFIRMED** | `render.php:29`; not in edit.js |
| ~~C1c~~ | ~~`showLightbox`/`showReactions` dead~~ | Free | media-grid | **REFUTED** | render.php:26,27 read both — honored |
| C2 | Interactive compete blocks go **non-interactive on a normal page** — the Interactivity store module is enqueued only when a `mvs_*_page` query var is set; `enqueue_assets()` early-returns off-route. No coding-rule forces these blocks to self-enqueue their store (Rule 6 covers only feed-Layout CSS). | Pro | pro-battle, pro-challenge, pro-tournament, pro-compete-hub (interactive variants) | **CONFIRMED** | `GamificationTemplateLoader.php:283-347` |
| C3 | InspectorControls panel present but **zero attributes** → site owner sees an empty options panel; no count/status/sort/filter. | Pro | pro-battles-active, pro-challenges-list, pro-tournaments-list, pro-compete-hub | **CONFIRMED** | block.json attributes = {} ; render.php has 0 `data-wp-` (display-only) |
| C4 | `scope` SelectControl wired in edit.js but the layout class **never reads `scope`** — only `InstagramLayout` does. Dead control on 3 feed blocks. (`perPage` IS honored by all layouts.) | Pro | pro-dribbble-feed, pro-flickr-feed, pro-pinterest-feed | **CONFIRMED** | scope only in `InstagramLayout.php:141`; absent from Dribbble/Flickr/Pinterest layouts |
| ~~C4b~~ | ~~pro-leaderboard source/perPage/window dead~~ | Pro | pro-leaderboard | **REFUTED** | `LeaderboardRenderer.php:44-46` reads all three |
| C5 | 11 typography attributes injected by `StandardAttributes::inject()` (fontFamily/fontSize/…/textTransform) consumed server-side, but `TypographyControl.js` is **never imported in any edit.js** → no typography UI for the site owner. | Pro | all Pro blocks via inject | **CONFIRMED** | `StandardAttributes.php:86-107`; `TypographyControl.js` 0 importers |
| C6 | LIVE visual sweep — render each block in the editor + frontend, confirm controls *look* premium and the panel renders, across light/dark/viewports. (Functional option-wiring above is already code-verified.) | Both | 26 | **PENDING** | needs browser (main session) |

## Batch D — Structure (staged refactor)

| ID | Task | Plugin | File | Verify | Acceptance |
|---|---|---|---|---|---|
| D1 | Introduce Pro Repository layer; consolidate standings join (3×) | Pro | `includes/**` (~400 raw `$wpdb`, 29 files) | **CONFIRMED** | No raw `$wpdb` outside `Pro\Repository\*` |
| D2 | Route `MediaController` stat-increment + privacy query through `MediaRepository` | Free | `REST/Controller/MediaController.php` | **CONFIRMED** | One privacy-filter implementation |
| D3 | Extract `Core/Plugin.php` God-class (2,333 lines) → Bootstrap/AssetManager/MenuManager | Free | `Core/Plugin.php` | **CONFIRMED** | Methods ≤ rule limit; single responsibility |
| D4 | `StandardAttributes` shared base (dup ~230 lines Free↔Pro) | Both | `Blocks/StandardAttributes.php` | **CONFIRMED** | One source; Pro extends |
| D5 | Single owner for admin submenu ordering (Free/Pro `reorder_submenu` @999 fight) | Both | `Core/Plugin.php` | **CONFIRMED** | Integrations item not dropped |
| D6 | CLI: route `bp_activity` writes through MediaRepository/BP API | Free | `CLI/Commands.php` | **CONFIRMED** | No direct bp_activity mutation |

## Batch E — Cleanup / product decisions (Low/Med)

| ID | Task | Plugin | Notes | Verify |
|---|---|---|---|---|
| E1 | `mvs_autopilot_xp_1st/2nd/3rd/participation` read-never-written → add admin UI or hardcode intentionally | Pro | XP payout stuck at defaults | **CONFIRMED (Medium)** |
| E2 | Decision: Pro video analytics (`mvs_play_events`) — wire player to POST events, or hide heatmap until real | Pro | dashboard+ingest exist, empty table | CONFIRMED |
| E3 | `mvs_transactions` admin ledger view (monetization table, no admin surface) | Free | | CONFIRMED |
| E4 | `StorageRouter` registered in DI but never called; inline dup at 2 sites | Free | | NEEDS-VERIFY |
| E5 | Silent-failure logging: credit-log write, Expo push, FFmpeg stderr | Pro | swallowed errors | NEEDS-VERIFY |
| E6 | Remove dead Story-Viewer CSS (~78 lines) + orphaned 1.9.0 build artifact | Free | | CONFIRMED |
| E7 | Pro CLAUDE.md drift (CODE-VERIFIED): version says **1.8.0** (actual 1.9.0); Module Map still lists deleted **`InstagramFeedService`**; READ-FIRST manifest counts stale (37 REST/10 admin vs 91/31). Plus hyphenated EDD option keys; 1 unprefixed hook | Pro | docs/naming | **CONFIRMED** |
| E8 | Manifest quality: hooks_fired under-counts multi-line `apply_filters(\n 'name'`; dedupe `mvs_media_index` in tables[] | — | in refreshed manifest | CONFIRMED |

---

---

# MediaVerse 2.0.0 — FUNCTIONAL bug cards created in Basecamp (Bugs column 9667036607)

Only "works-as-expected or not" defects were carded. Code-quality / refactor / presentation items are intentionally NOT carded (per owner: don't waste time on code quality). Card body = root cause + files + fix.

| # | Card ID | Finding | Sev |
|---|---|---|---|
| 1 | 10068990139 | Moderation `wp_update_post()` on media_id corrupts unrelated posts | Critical |
| 2 | 10068990807 | Competition vote counters increment without checking insert (tournament/battle/challenge) | High |
| 3 | 10068990882 | Media delete-cascade duplicated 3×; 2 skip `mvs_media_deleted` hook | High |
| 4 | 10068990940 | Connector delta-sync unbounded + sync per-item HTTP in one request | High |
| 5 | 10068991014 | Transcode has no RUNNING lock — concurrent FFmpeg corrupts output | Medium |
| 6 | 10068991076 | Transcode cleanup cron never deletes — disk never reclaimed | Medium |
| 7 | 10068991129 | `/media` feed + gallery expansion N+1 (REST path) | Med-High |
| 8 | 10068991206 | Tournament registration TOCTOU — "registered" but not in bracket | Medium |
| 9 | 10068991265 | Boost points debited before insert, no rollback | Medium |
| 10 | 10068991349 | Captions no timeout/stale-job — stuck "processing", duplicate billed jobs | Medium |
| 11 | 10068992312 | media-grid Columns control does nothing (render ignores attribute) | Medium |
| 12 | 10068992387 | Compete blocks non-interactive off-route (store JS only on plugin routes) | High |
| 13 | 10068992480 | Feed Scope control dead on Dribbble/Flickr/Pinterest | Medium |
| 14 | 10068992601 | 4 Pro cron hooks never cleared on deactivation (ghost crons) | High |
| 15 | 10068992677 | `StoryService::cleanup_expired()` unbounded — times out at scale | Medium |

**Pre-existing QA cards in Bugs (already carded, verified real — see BC-1..7 below):** 10064317124 (watermark single page), 10057408558 + 10057399406 (user_login exposure, same root), 10053174913 (online-status nobody bypass), 10053143680 (DM nobody bypass), 10053068913 (chat panel disabled leaks assets). BC-7 10064279994 (access-rules single-page trigger) = enhancement, not defect.

**NOT carded (code quality / refactor / presentation — held per owner):** Pro repository layer, Core/Plugin.php size, StandardAttributes dedup, submenu owner, CLI-via-API, dead-CSS cleanup, MediaController privacy-dup, typography editor UI, empty-panel block options, RTL wiring, dark-mode tokens, AccessController arg-sanitize.

---

# BC — pre-existing QA bug cards (CODE-VERIFIED, full fix spec)

Source: Basecamp "Bugs" column (`9667036607`). Each verified against 1.9.0 code by an independent agent; **no guesswork**. Card ID tracked for QA. Fix specs below name every file touched and REQUIRE dup/dead-code removal in the same change (no parallel paths, no dead vars left behind).

Status key: OPEN (real, unfixed) · PARTIAL (real, some cited lines stale) · move to **RFT `9637173253`** only when fixed.

---

### BC-1 · card 10064317124 · Watermark preview never shows on single media page — REAL / High / OPEN
- **Root cause:** `includes/Core/TemplateLoader.php` `serve_single_media()` (~316-320) calls `can_view_media()` and, on deny, renders `render_branded_404()` and returns — no watermark-preview branch. `templates/media-single.php` never references `WatermarkService`; passes empty `MediaUrl::file()` to `picture_or_img()` → broken image/404.
- **Files touched:** `includes/Core/TemplateLoader.php` (serve_single_media), `templates/media-single.php`, Pro `WatermarkService` (`has_preview()`/`get_preview_url()`).
- **Fix:** before the 404 branch, if `WatermarkService::has_preview($media_id)` → set a flag and still load the template; in `media-single.php`, when `$mvs_file_url` is empty + flag set, render the preview URL with a "restricted — watermarked preview" notice.
- **Dedup / dead-code:** `src/blocks/lock-overlay/render.php:70-77` already implements this exact resolve. **Extract that logic into one `WatermarkService` method and call it from BOTH lock-overlay and media-single** — do not copy the watermark-resolve into the template.

### BC-2 + BC-3 · cards 10057408558 & 10057399406 · `user_login` publicly exposed (+ settings autofill) — REAL / High / OPEN — SAME ROOT, ONE FIX
> These two cards are the same systemic bug — fix once, don't create two parallel patches.
- **Root cause:** `includes/Core/TemplateHelpers.php:214-215` `get_user_profile_url()` builds every profile URL from `user_login` (~14 Free + ~12 Pro call sites). Public `GET /mvs/v1/users/search` (`includes/REST/Controller/UserController.php:305,354`, `__return_true`) returns those URLs → unauthenticated username enumeration — **defeats the plugin's own policy at `UserController.php:184-206`** that withholds `user_login` from the JSON. Pro `templates/layouts/instagram/profile.php:22,101` echoes `user_login` as the visible handle.
- **Files touched (single-source fix):**
  - `includes/Core/TemplateHelpers.php` → `get_user_profile_url()` use `user_nicename` (URL slug), alias legacy `/media/@{login}/` for compat.
  - Pro `templates/layouts/pinterest/feed-body.php:156` + `pinterest/profile.php:224` → **replace inline `home_url('/media/@'.user_login)` with the helper** (kill duplicated URL-building).
  - Pro `templates/layouts/instagram/profile.php:101` → render `display_name`, not `user_login`.
  - Pro `includes/Admin/ProSettings.php` `render_field()` text case (~2060-2066) → add `autocomplete="off"`; optionally upgrade password case `off`→`new-password`.
- **Dedup / dead-code (mandatory):** remove dead `$mvs_author_login` (`templates/media-single.php:105`) and dead `$mvs_username` assignments in `flickr/profile.php` + `dribbble/profile.php` (assigned, never echoed). Do NOT leave them "for later." The `templates/user-profile.php` the card cited is already gone from source (dist-only) — no action.

### BC-4 · card 10053174913 · Online-status "nobody" bypassed by per-user meta — REAL / High / OPEN
- **Root cause:** `includes/Core/Plugin.php:1666-1673` `mvs_show_online_status` filter reads `_mvs_show_online` user meta FIRST; only empty meta falls back to `get_option('mvs_show_online_status')`. Admin's site-wide `nobody` is never a ceiling.
- **Files touched:** `includes/Core/Plugin.php` (filter callback ~1666-1673).
- **Fix:** check `get_option('mvs_show_online_status')` first; if `nobody` → `return false` before reading user meta. Global = ceiling, meta only narrows.

### BC-5 · card 10053143680 · DM "nobody" bypassed (+ send path never re-checks) — REAL / High / OPEN
- **Root cause:** `includes/Messaging/MessagingService.php:100-113` `can_message()` reads `_mvs_dm_access` meta first, global fallback only. Worse: `send_message()` (1098-1123) **never calls `can_message()`**; REST `MessagingController::send_message` (561-596) has no gate; `templates/partials/profile-actions.php:34-38` same precedence.
- **Files touched:** `includes/Messaging/MessagingService.php` (can_message + send_message), `includes/Messaging/MessagingController.php` (~561-596), `templates/partials/profile-actions.php:34-38`.
- **Fix:** global-first ceiling in `can_message()`; call `can_message()` on every send (service + controller); same ordering in the template.
- **Dedup (shared with BC-4):** BC-4 and BC-5 are the identical "global-nobody-as-ceiling" precedence bug. **Extract ONE helper** (e.g. `Privacy::resolve_ceiling($global_option_value, $user_meta_value, $ceiling='nobody')`) and use it for both online-status and DM (and any future per-user-vs-global setting). One implementation, not two copies — this kills the bug class.

### BC-6 · card 10053068913 · Chat panel "Disabled" still loads messaging assets — REAL / Medium / OPEN
- **Root cause:** `includes/Core/Plugin.php` `enqueue_messaging_assets()` (1701-1733) + `print_messaging_config()` (1738-1766) gate only on `is_user_logged_in()` + `mvs_buddynext_active`; neither checks `mvs_chat_panel_visibility`. Only `render_chat_panel()` (2194-2198) does.
- **Files touched:** `includes/Core/Plugin.php` (both enqueue/print functions).
- **Fix + dedup:** factor the visibility check into one private helper `chat_panel_enabled()` and call it from all THREE sites (`render_chat_panel` + the 2 asset paths) — don't inline the `get_option('mvs_chat_panel_visibility')==='disabled'` test three times.

### BC-7 · card 10064279994 · Access-rules UI trigger missing on single media page — REAL / Medium / OPEN (enhancement)
- **Root cause:** the access-rules feature works via admin (`templates/admin/access-rules.php`, `MediaListPage::render_access`), REST (`POST /media/{id}/rules`, `AccessController.php:53-70`), and the BP grid. The shared edit modal WITH the access-rules panel already loads on the single page (`Plugin.php:2097-2146` prints `shared-ui-frame.php:286-330` on `wp_footer`), but the only trigger wiring `.mvs-media-edit-btn`→`mvsOpenEditModal` is `bp-actions.js`, hard-gated to BuddyPress. Non-BP single-media page has the modal but no button.
- **Files touched:** `templates/media-single.php` (inline edit form ~512-584), `assets/js/frontend/bp-actions.js` (or a small non-BP trigger).
- **Fix:** add an owner-only "Access rules" button (`class="mvs-media-edit-btn" data-media-id`) to the single-media inline edit form and enqueue/localize the opener so the already-present modal opens. Reuse the existing modal — do NOT build a second one.
- **Scope:** enhancement (three-entry-points frontend gap), NOT a defect. Card currently sits in Bugs — should move to Suggestion List unless prioritized for 2.0.0.

---

## Cross-cutting cleanup rule for the 2.0.0 branch
No fix in this batch may leave a duplicate path or dead code. Where two cards share a root (BC-2/BC-3 URL scheme; BC-4/BC-5 ceiling precedence; BC-6 triple visibility check) the fix introduces ONE shared helper and deletes the copies — verified by re-grep after each fix.

---

# RE-AUDIT (pass 2, 2026-07-07) — reconciliation

4 independent agents: RA1 adversarial re-validation · RA2 security · RA3 i18n/a11y/RTL/dark · RA4 cron/REST.
**Result: 0 pass-1 findings refuted. Severities refined. Security clean. 3 new dimensions added findings (cron, RTL/dark, a11y).**

## Severity corrections to pass-1 items (from RA1)
| ID | Was | Now | Note |
|---|---|---|---|
| A1 | Critical | **Critical (raised)** | Re-confirmed: no media CPT, upload never calls `wp_insert_post()` → genuine cross-object corruption of unrelated posts |
| A2/A3/A4 | Critical | **Medium-High** | Unique key blocks same-user dup row; real risk = false-success-on-failed-insert + bracket-overfill race |
| CR-5 (→B1) | Critical | **High** | Unbounded fetch + sync per-item HTTP confirmed |
| CR-6 (→B2) | Critical | **Medium** | PENDING-state dedup guard exists; misses RUNNING-state race |
| CR-7 (→B3) | Critical | **Medium** | Cron never `unlink()`s; only relabels stuck jobs |
| B4 | High | **Medium-High** | Real N+1; 1.7.0 fix touched templates not REST |
| B5 | High | **Medium** | Bracket-overfill + false-success-on-insert real |
| B6 | High | **Medium** | Points debited pre-insert, no refund path |
| B7 | High | **Medium** | Real bug = PHP timeout mid-request + no stale-job cron (not the try/catch) |
| E4 | Med | **Low** | Dead code; duplicated at 4 sites not 2 |

**Net: only A1 is a true Critical. Pass-1's "7 Criticals" was over-graded.**

## Batch F — Cron / background jobs (NEW, from RA4) — Pro
| ID | Task | File | Sev |
|---|---|---|---|
| F1 | Duplicate cron scheduling on 6 hooks (`mvs_expire_boosts` + 5 challenge/tournament state hooks) registered by BOTH WP-Cron/AS tick AND direct AS recurring in activation → runs 2×–13×/hr, no lock | `wpmediaverse-pro.php` activation + schedulers | **High** |
| F2 | 4 ghost crons never unscheduled on deactivation (`mvs_competitions_tick`, `mvs_autopilot_create_weekly_challenge`, `mvs_daily_streak_check`, `mvs_pro_prune_play_events`) | Deactivator | **High** |
| F3 | `StoryService::cleanup_expired()` no LIMIT/batching (dupes B8) | `Stories/StoryService.php` | Med |
| F4 | `ChallengeService` 3× `LIMIT 50 ORDER BY id ASC` no cursor → starvation past 50 concurrent | `Challenges/ChallengeService.php` | Med |

## Batch G — i18n / a11y / RTL / dark-mode (NEW, from RA3)
| ID | Task | File | Sev |
|---|---|---|---|
| G1 | RTL stylesheets built by RTLCSS but never wired via `wp_style_add_data($h,'rtl','replace')` → RTL renders LTR in BOTH plugins | both, enqueue paths | **High** |
| G2 | 4 Pro admin CSS hardcode raw hex, no `--mvs-*` tokens → dark mode broken (`migration.css`, `analytics.css`, `confirm-dialog.css`, `dashboard-connectors.css`) | Pro `assets/css/` | **High** |
| G3 | Icon-only like/mute/favorite/comment controls missing `aria-label` (siblings in same file have it) | Pro instagram feed card partial | High |
| G4 | Inline-edit `<label>`s not associated via `for`/`id`; ~15 fields placeholder-as-label | `media-single.php` + both | Med |
| G5 | Chat/messaging icon buttons 32px (below claimed 44px tap floor); some use `title=` not `aria-label` | Pro chat | Med |
| G6 | Missing RTL builds: Free `load-more.css`, `admin/integrations.css`; missing `@media` on 3 Pro CSS + analytics table | both | Med |

## Batch H — Security defense-in-depth (NEW, from RA2 — otherwise CLEAN)
| ID | Task | File | Sev |
|---|---|---|---|
| H1 | `AccessController` REST args `rule_value` (no sanitize_callback) + `expires_at` (no date validate_callback) — not exploitable (prepared queries), data-quality only | `REST/Controller/AccessController.php:81,146` | **Low** |

> RA2 verdict: **no production-exploitable security issues** — ~635 raw `$wpdb` all `prepare()`d; XSS clean (Interactivity `data-wp-text`); `/serve` HMAC+`realpath()` solid; authz enforced on all writes; nonces 1:1. The Pro "no repository" item (D1) is **maintainability/drift risk, NOT security**.

## Reconciled totals (validated)
**1 Critical (A1)** · ~10 High · ~18 Medium · ~15 Low. Blocks batch (C) still needs the LIVE editor sweep to close.

## Still open (not done this session)
- [ ] LIVE block-editor sweep (C6) — confirm InspectorControls render + option→output parity for 26 blocks (needs browser, main session).
- [ ] Commit `AUDIT-VERDICT` + `AUDIT-TASKS` + section reports into the repo (currently uncommitted working-tree files).
