# MediaVerse Free + Pro — Fresh-Eye Audit Verdict

**Date:** 2026-07-07 · **Version audited:** 1.9.0 (both, `main`) · **Method:** 5 parallel static-analysis agents (org/dups · structural/contract · stability/big-site · product-gaps · blocks-matrix) over the freshly-refreshed manifests, + targeted code verification of every Critical. Live block-editor sweep: PENDING (runs in main session).

## Bottom line

The pair is **architecturally sound and product-honest** — 29/33 tables fully wired across frontend+admin+REST, no dead buttons (122 AJAX/REST call sites traced), no capability breaches, no settings-saved-but-unread, no feature-toggle leaks, no intent violations (no image editor, license gates updates-only, moderation is reactive-by-design). **It is NOT yet "ship-and-forget stable"**: there is one live data-corruption bug, a cluster of unchecked-write concurrency races in Pro competitions, and a Pro-side data-access architecture gap (no repository layer). None block *use today at small scale*; several bite at 500+ users / 2000+ media or under concurrent load.

Severity tally (static): **11 Critical · 23 High · 35 Medium · 30 Low** across all five reports.

---

## CRITICAL (verified) — fix before promoting as stable

| ID | Finding | File | Status | Fix |
|---|---|---|---|---|
| CR-1 | Moderation approve/reject/flag calls `wp_update_post()` on `media_id`, but media lives in `mvs_media_index`, not `wp_posts` — silently republishes/drafts an **unrelated page/post** with the same integer ID. Vestigial from the CPT era; `set_status()` already handles real status. | `wpmediaverse/includes/Services/ModerationService.php:117,203,226` | ✅ CONFIRMED | Delete the 3 `wp_update_post()` calls; moderation only needs `set_status()`. |
| CR-2/3/4 | Vote handlers run `... votes = votes + 1` **without checking the `$wpdb->insert()` return** — check-then-act TOCTOU + no insert-result guard inflates counts under concurrent voting, corrupting winner determination + XP payouts. | `wpmediaverse-pro/includes/Tournaments/TournamentService.php:407`, `Battles/BattleService.php:414`, `Challenges/ChallengeService.php:375` | ✅ CONFIRMED | Add DB unique key on `(votable_type,votable_id,user_id)`; only increment if `insert()` returned truthy. Copy `FollowService::follow()` insert-ignore-and-check. |
| CR-5 | Connector delta-sync fetches ALL linked media (no LIMIT) + synchronous per-item remote HTTP in one REST request → timeout / provider rate-limit lockout. | `wpmediaverse-pro/includes/.../ConnectorRESTController.php:759` | ⏳ plausible | Batch + background via Action Scheduler; cap per request. |
| CR-6 | Transcode has no in-progress guard → concurrent FFmpeg jobs write the same output paths (corrupt MP4/HLS). | `wpmediaverse-pro/includes/.../TranscodeController.php:187` | ⏳ plausible | Status/lock guard before spawn. |
| CR-7 | Transcode "cleanup" cron walks every dir but never deletes → disk never reclaimed. | `wpmediaverse-pro/includes/.../TranscodeService.php:641` | ⏳ plausible | Actually unlink expired outputs. |
| ST-1 | **Pro has zero Repository layer** — ~400 raw `$wpdb` calls across 29 files; competition-standings joins hand-rolled 3× + re-forked in `CompeteSummaryController`. Architecture rule "no raw `$wpdb` outside repositories" is fully unmet on the Pro side. | `wpmediaverse-pro/includes/**` | ✅ CONFIRMED | Introduce `Pro\Repository\*`; consolidate the standings join once. (Large, staged.) |
| ST-2 | Free `MediaController` reimplements `MediaRepository`'s stat-increment + **privacy-filtered query builder** in the REST layer — drift risk on the load-bearing privacy/moderation gate. | `wpmediaverse/includes/REST/Controller/MediaController.php` | ✅ CONFIRMED | Route through `MediaRepository`; delete the REST-layer copy. |

---

## HIGH (top items — full list in section reports)

- **Delete-cascade duplicated 3×** (`MediaRepository::delete_cascade`, `MediaListPage`, `BulkController`); 2 copies skip the `mvs_media_deleted` hook → orphan rows + missed integrations at scale. Consolidate to one path.
- **God-class** `wpmediaverse/includes/Core/Plugin.php` (2,333 lines) mixing bootstrap/DI/REST-reg/admin-menu/nav-filter/enqueue; `enqueue_frontend_assets()` alone ~421 lines. Extract to `Bootstrap`, `AssetManager`, `MenuManager`.
- **Free/Pro `reorder_submenu()` fight** — both hook `admin_menu` @999 rewriting the same `$submenu['wpmediaverse']`; Pro runs last, drops Free's "Integrations" item. Single owner.
- **`StandardAttributes` duplicated ~230 lines** Free↔Pro (violates their own Coding Rule 14; docblock admits prior drift). Shared base.
- **N+1 on the main `/media` feed + gallery expansion** (unbounded, no prefetch). Batch-fetch.
- **Tournament registration TOCTOU** — "registered" reported but unchecked insert may not land in bracket. Boost points debited before insert with no rollback. Captions provider no try/catch (status stuck "processing"; duplicate billed jobs).
- **CLI mutates `bp_activity`/`bp_activity_meta` directly**, bypassing MediaRepository + BuddyPress API.

## MEDIUM (representative)

- `mvs_autopilot_xp_1st/2nd/3rd/participation` **read but never written** (no admin UI) → XP payout stuck at hardcoded defaults.
- Quota counter/enforcement TOCTOU → paid-tier bypass under race. Reaction/favorite check-then-act double-hook fire.
- `StorageRouter` registered in DI but never called; logic duplicated inline at 2 sites.
- Silent-failure swallows: credit-log write, Expo push, FFmpeg stderr discarded (undiagnosable transcode failures).
- Unbounded `IN()` lists on follow/block; Stories cleanup cron unbounded.

## LOW

- Dead Story-Viewer CSS (~78 lines) + orphaned 1.9.0 build artifact; stale `InstagramFeedService` ref in Pro CLAUDE.md; hyphenated EDD license option keys; one unprefixed hook; documented idempotent migrations; best-effort `@unlink`.

---

## BLOCKS — static matrix (26 blocks: 13 Free + 13 Pro); live sweep PENDING

| Block | Issue | Severity |
|---|---|---|
| `media-grid` (Free) | `columns` RangeControl **ignored by render** (uses site-wide `mvs_grid_columns`); `showLightbox`, `showReactions` dead attrs; orphan `userId` (no control) | High |
| `pro-battle`, `pro-challenge`, `pro-compete-hub`, `pro-tournament`, `pro-tournaments-list`, `pro-challenges-list` | **Dead client-side JS** — Interactivity store enqueued only on the plugin's own page routes (gated on `mvs_*_page` query var), never on the block's render path → block placed on any normal page = static/non-interactive | High |
| `pro-challenges-list`, `pro-tournaments-list`, `pro-compete-hub` | Zero/minimal `block.json` attributes — no count/status/sort/columns a site owner expects | Medium |
| `pro-dribbble-feed`, `pro-flickr-feed` | `scope` SelectControl wired in editor but **renderer never reads it**; no columns/sort/fixed-tag options | Medium |
| ~10 Pro blocks | Injected typography attrs (`StandardAttributes::inject()`) consumed server-side but **no editor control** — `TypographyControl.js` exists, never mounted | Medium |

**Live sweep will confirm per block:** (2) InspectorControls panel actually renders in the backend editor, (3) each option changes output (SSR↔editor parity), (4) site-owner-expected options present.

---

## What's in GOOD shape (verified clean — do not re-flag)

- Capability enforcement: 0 breaches; Pro's enforced caps all resolve to Free/core. No dead caps.
- Contract health: 0 dead buttons, 0 orphaned hooks (the `mvs_settings_render_license` "dead UI" lead was **disproven** — it renders via `mvs_settings_sections` + dynamic `mvs_settings_render_{renderer}`).
- AJAX/admin-post nonce+capability coverage clean in both plugins.
- `COUNT(*)` used properly (no count-via-load except one config table).
- 29/33 tables fully wired (frontend+admin+REST). Product intent honored end to end.

## Half-cooked features (product)

- **Pro video analytics** (`mvs_play_events`): REST ingest + admin heatmap exist, but the player never POSTs a play event → table permanently empty. Wire the player, or hide the dashboard until it's real.
- **`mvs_transactions`** (Free): no admin ledger view for a monetization table.

---

## Proposed fix batching

1. **Batch A — data safety (CRITICAL, small diffs):** CR-1 moderation, CR-2/3/4 vote-insert guards (+ unique keys), delete-cascade consolidation. Highest risk, lowest effort.
2. **Batch B — big-site (HIGH):** `/media` feed N+1, connector delta-sync batching, transcode lock+cleanup, unbounded `IN()`/cron lists.
3. **Batch C — block usability (HIGH/MED):** media-grid columns, Pro compete-block JS enqueue, feed `scope`, typography controls, missing attrs — paired with the live editor sweep.
4. **Batch D — structure (staged):** Pro repository layer, `Plugin.php` God-class extraction, `StandardAttributes` shared base, submenu single-owner.
5. **Batch E — cleanup (LOW):** dead CSS/build artifact, stale docs, XP option UI, half-cooked analytics/ledger decision.

Section detail: `audit/` scratch reports `01-org-dups.md`, `02-structural-contract.md`, `03-stability-bigsite.md`, `04-product-gaps.md`, `05-blocks-static.md` + `05-blocks-matrix.json`.
