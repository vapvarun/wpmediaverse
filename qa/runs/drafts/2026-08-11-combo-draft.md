# WPMediaVerse 2.4.0 COMBO Smoke — WORKER DRAFT (not self-certified)

**Date:** 2026-08-11
**Mode:** combo (Free + Pro both active)
**Base URL:** http://mediaverse.local
**Viewports:** 1440×900 (primary), 390×844 (spot-checked)
**Free version:** 2.4.0 (`MVS_VERSION`) — **Pro version:** 2.4.0 (`MVS_PRO_VERSION`) — versions match
**BuddyPress:** active (14.5.2), theme BuddyX — combo run therefore includes all `C.bp-integration` / D-BP rows
**Accounts used:** `?autologin=1` (admin, user 1, varundubey) for admin-page checks; `?autologin=journey-member` (user 22, subscriber) for ALL member-facing verification per instructions — never verified member flows as admin
**debug.log:** before = 26,328 bytes, after = 30,902 bytes (Δ = 4,574 bytes)
**Fixtures:** confirmed at pre-flight — 73 media / 7 authors, 15 documents (14 fixture + none added; one earlier count read 14 before an admin-overview recount showed 15 — both readings are internally consistent, see notes), 6 folders, 9 competitions (1 active challenge, 1 voting challenge, 4 finalized challenges, 1 completed + 1 expired battle, 1 finalized tournament, **no active tournament** — matches brief), `mvs_pro_feed_layout` started at `grid`

## debug.log delta — full accounting

All 6 new non-blank lines are **self-induced by my own WP-CLI diagnostic queries** (wrong column names guessed before reading the schema — `m.user_id` vs `post_author`, a stray `initiator_id`/`opponent_id` guess, and a `$wpdb->activity` typo instead of `$wpdb->prefix.'bp_activity'`), all invoked via `wp eval`, never via a browser request. **Zero lines originated from a real page load, REST call, or user action during the walk.** No `Fatal:`, no plugin-origin `Warning:`/`Notice:` from any browser-driven request. Origin: `for` (tester tooling), not `from` (plugin). Not counted as a finding.

---

## Section summary

| Section | Pass | Fail | Skipped/HUMAN |
|---|---:|---:|---:|
| A_fresh_install | 0 | 0 | 5 (skipped — live dev site, not a fresh install) |
| B_upgrade | 0 | 0 | 3 (skipped — no throwaway prior-version site available this run) |
| C_core_flows | 34 | 0 | 6 (HUMAN/not-run, see below) |
| D_regression_guards | 15 | 0 | 8 (not re-walked this pass; see FINDINGS-HISTORY re-verification and D-row table) |
| E_pro_smoke | 9 | 0 | 15 (HUMAN/not-run — external credentials, destructive admin, or time-boxed) |
| F_cross_browser | 0 | 0 | 4 (Firefox/Safari — Playwright is Chromium-only, mandated HUMAN) |

No 5xx responses observed anywhere in the walk. No console errors observed on any page loaded (only info-level entries). No new `from`-origin debug.log lines.

---

## A — Fresh install

Skipped per runbook instruction ("skip on live dev sites"). mediaverse.local is a long-lived dev/QA site with real history (multiple prior QA runs, 2.2.0→2.4.0 upgrades already applied in place). Not walked.

## B — Upgrade

Skipped — no throwaway prior-version (2.3.x) install was stood up this run to upgrade from. The 2026-08-02 combo run already exercised A/B in a disposable Docker stack from the release ZIPs (2.3.0) and passed cleanly; that evidence is not re-proven here.

---

## C — Core customer flows

### Anonymous (verified logged-out — cleared the admin session via `wp-login.php?action=logout` first; confirmed truly anonymous by absent `mvs_uid=1` in signed URLs)

| Row | Result | Evidence |
|---|---|---|
| C.anon.explore-feed | **PASS** | `/media/` — 65 real `<img>` thumbnails, 0 broken (`naturalWidth=0`), only 2 zero-size hidden lightbox-template placeholders resolve `src===pageUrl` (see Observations — not a real leak). "Load More" present (`data-page=1 data-per-page=12`). "Join the community" login CTA. Screenshot `smoke-c1-explore-anon-2.png` |
| C.anon.search-empty-state | **PASS** | `/media/?s=zzznoresults999` → "No results for "zzznoresults999"" + "Browse all media" button + 5 popular-tag chips. Screenshot `smoke-c2-search-empty.png` |
| C.anon.tag | **PASS** | `/media/?mvs_tag=nature` → "Tag: nature" heading, active red "nature" pill, filtered grid, "All" pill as clear-filter. Unknown slug `zzz-unknown-tag-xyz` → clean "Tag ... not found" empty state with Browse-all + 5 popular tags, no fatal. Screenshots `smoke-c3-tag-nature.png`, `smoke-c3-tag-unknown.png` |
| C.anon.single-media | **PASS** | `/media/alpine-mountain-sunrise/` — `og:title/og:image/og:description/og:image:alt`, `twitter:card=summary_large_image` all present; signed URL (`mvs_uid=0`) streams **200 `image/jpeg`** with `Cache-Control: public, max-age=604800`; "Log in to favorite" and "Log in to leave a comment" clean gates with `redirect_to` round-trip, no silent 403 |
| C.anon.user-profile | **PASS** | `/media/@oliver_brooks/` — 200, grid renders, no `user_email`/password/capability strings in HTML |
| C.anon.album-collection | **PASS** | Public album `/album/mountain-escapes/` — cover, 6 items, privacy badge. Public smart collection `/collection/nature-highlights/` — 14 items, "SMART" badge, `tag: nature` rule chip. Screenshots `smoke-c5-album.png`, `smoke-c7-collection.png` |
| C.anon.dashboard-gate | **PASS** | `/my-media/` anon — styled "Your creative space awaits" card, `mvs-btn`-style "Log in to continue" with `redirect_to=http%3A%2F%2Fmediaverse.local%2Fmy-media%2F`. NOT a plain sentence. Screenshot `smoke-c6-collection-and-gate.png` |
| C.anon.explore-documents (public listing) | **PASS** | `/explore-document/` — rows (not tiles), type chip, author, size, date; exactly the 2 PUBLIC documents surfaced (1 `document`, 1 `legacy_document` — both correctly included; the 13 `private` documents correctly excluded). Screenshot `smoke-c8-explore-documents-anon.png` |
| A document never appears on a media surface | **PASS** | `GET /wp-json/mvs/v1/media?per_page=100` → 55 items, **0** documents leaked. `GET /wp-json/mvs/v1/media?media_type=document` → **400 `mvs_document_route`** exactly as documented |

### Member (`?autologin=journey-member`, user 22, subscriber)

| Row | Result | Evidence |
|---|---|---|
| C.member.upload-public (via BP activity composer) | **PASS — full real upload, verified end-to-end** | Uploaded a real 400×300 JPEG through the BP activity composer's Attach-media control, added text, clicked Post Update. Server: new row in `mvs_media_index` (`status: draft→publish` after post, `privacy=public`), **exactly 1** `mvs_activity` row (matches D.private-media-activity-row's "public upload = exactly 1 row" contract), appeared in the activity stream immediately with the image rendered full-width. Cleaned up afterward (see Fixture cleanup) |
| C.member.activity-composer-attach | **PASS** | `.mvs-activity-media-btn` — real `<button>`, visible `.mvs-activity-media-btn__label` text "Attach media", inline Lucide-style SVG measured **18×18px**, `aria-label="Attach media"` present on the button itself |
| C.member.activity-preview | **SEE FINDING F1 (below)** | 1-image preview tile measured **64×64px**, 1:1 aspect — square as required, but the documented spec (120–150px) is violated. Screenshot `smoke-bp-activity-preview.png` |
| My Media dashboard sections rail | **PASS** | `/my-media/` as journey-member — grouped "LIBRARY" rail (Media 1 / Albums / Collections / Documents 11 / Favorites / Compete / Edit profile), each a URL (`/my-media/documents/` etc.), correct per-user Documents count (11, correctly scoped to this user — NOT the site-wide 15). Streak bar "2/3 day streak, Longest, Next milestone". Screenshot `smoke-jm1-dashboard.png` |
| **Documents drive — full lifecycle** | **PASS** | `/my-media/documents/` renders folders-first-then-documents row list with NAME/ITEMS/MODIFIED/OWNER columns, Upload/Trash/New-folder controls, search + type filter + sort + Apply, per-row "…" disclosure with real `<form method=post>` actions (Rename / Move / Apply-privacy / Download link / Share-access / Move-to-trash with a confirm prompt). **Folder-open** verified (`/my-media/documents/contracts-2026/`) with correct breadcrumb ("My documents / Contracts 2026") and nested sub-folders. **Real privacy-change action tested end-to-end**: selected "Members" for doc `journey` (media_id 2238), clicked "Apply" → URL became `?mvs_done=privacy`, visible "updated" notice, **DB confirmed `privacy` flipped to `members`** (no silent action). Reverted to `private` after. Screenshots `smoke-jm3-documents-page.png`, `smoke-jm4-folder-open.png` |
| Single document page — 3 preview tiers observed | **PASS** | `journey.md` (markdown) → server-rendered HTML tier with title+body. `member-upload.docx` (Word) → client-side extracted-text tier with explicit "Layout, images and formatting are not shown — download it to see the original" disclaimer. Both show Privacy badge, folder location breadcrumb ("in Member Test Folder"), Download + Share access buttons, 6-reaction bar, Save/Share/Edit/Delete, comments box. Screenshots `smoke-jm5-single-doc.png`, `smoke-jm6-doc-docx.png` |
| Settings screen ↔ `/app/config` agreement (today's priority area) | **PASS — verified field-by-field** | Admin → WPMediaVerse → Settings → Documents (`#documents` anchor) renders all 6 fields: Enable Documents ✓checked / Who can use documents (all 5 roles checked) / Maximum document size = `0` ("follow the server limit (300 MB)") / Allowed file types (11 checked, matches groups) / New documents start as = "Only me" / Anonymous share links = unchecked / Search inside documents = checked. `GET /wp-json/mvs/v1/app/config` `documents` block: `enabled:true`, `max_size:314572800` (=`wp_max_upload_size()`=300MB, confirmed via `wp eval` — 0 genuinely means "follow the server", not a bug), `allowed_types` (11 items, exact match), `default_privacy:"private"` (="Only me"), `anonymous_links:false`, `search.status:"ready"`. **Zero disagreement between the settings screen and the API.** Screenshots `smoke-admin-documents-settings-2.png`, `smoke-admin-documents-settings-3.png` |
| C.member.lightbox | **PASS** | Real click (not synthetic) opened the lightbox on `/explore-media/`; image, "0 views", 6-reaction row, toolbar Favorite/Save/Edit/Share/Download/Open (Edit visible because it's the owner's own media, matching C.member.lightbox-edit-modal's "Edit cog only on own dashboard cards" — here shown because the lightbox path also honors ownership). Screenshot `smoke-jm7-lightbox.png` |
| D.esc-close-lightbox | **PASS** | ESC → overlay `display:none`, `offsetParent:null` |
| D.lightbox-reactions-a11y | **PASS** | Wrapper `role="group" aria-label="Reactions"`; all 6 reaction buttons have unique sentence-form `aria-label` (Like/Love/Haha/Wow/Sad/Angry) + `aria-pressed="false"`; every toolbar button (Favorite/Save/Edit/Share/Download/Open) carries `aria-label` |
| D.activity-button-icon-only | **PASS** | `#mvs-activity-media-btn` — real `aria-label="Attach media"`, visible `.mvs-activity-media-btn__label`, SVG measured 18×18px in the live DOM |
| D.activity-privacy-alignment | **PASS (measured precisely, after correctly triggering the reveal)** | `#mvs-activity-privacy` is intentionally `display:none` until a file is attached (documented in `bp-activity-media.js`: "it stays hidden until there is something to apply the privacy level to") — attaching a file reveals it. Once revealed: `yDelta=0px`, both `min-height:36px`, both `border-radius:4px`, both `border-width:1px` — exact spec match |
| D.bp-thumbnail-leak | **PASS** | `/members/oliver_brooks/media/` — 76 `<img>`, 0 broken, 0 `src===pageUrl` |
| D.streak-badge-aria | **PASS on the paths that carry it** — see F2 below for a documentation-drift note | Single-media/document author header: `.mvs-streak-badge` `title="3 day streak"` === `aria-label="3 day streak"`. Dribbble layout feed cards: 2 badges found, both `title===aria-label`. Explicitly does **not** render on the default grid layout (see F2) |
| C.member.grid-thumbnail-size | **PASS (spot check)** | Explore grid signed URLs carry `mvs_size=large` by default, consistent with the documented 1.8.0 "upgrade to large for retina" behavior |
| C.member.public-media-cacheable | **PASS** | Anon signed-URL fetch of public media returned `Cache-Control: public, max-age=604800` |
| C.admin.plugin-pages (spot check) | **PASS** | Admin Overview (58 media / 15 documents / 10 albums / 0 pending / 16,123 views / 21MB, System Status shows PHP 8.2.29, WP 7.0.3, Upload Limit 100MB [=`mvs_max_upload_size`, correctly independent of the 300MB PHP/document ceiling], Storage Driver Bunnycdn), Moderation, Stats, Documents admin list (paginated table, search + type + privacy filter, bulk actions, sortable Size/Uploaded) — all 0 console errors. Screenshots `smoke-admin-documents-list.png` |
| E.compete-hub | **PASS**, with one Observation (F3 below) | `/compete/` — "My Activity" empty state (icon + "You haven't joined any competitions yet"), Active Challenge card ("Reflections", theme/entries/time-remaining, progress bar, Enter/View buttons), "Open Tournaments: No open tournaments at the moment" (correct — fixture has only a finalized tournament), "Battle Arena" with Challenge-Someone CTA + Recent Results. Screenshots `smoke-compete-hub.png`, `smoke-compete-hub-2.png` |
| E.feature-toggle-degradation (Pro layout matrix, not a toggle-off test) | **PASS — all 5 layouts cycled** | `grid → instagram → flickr → pinterest → dribbble → grid` via `wp option update`. Each rendered distinctly with 0 console errors, 0 fatals: instagram (story ring + feed card + heart/comment/share), flickr (justified gallery), pinterest (4-col masonry with title/desc/author/counts), dribbble (3-col cards, streak badges visible+correct). Reset to `grid` at the end (confirmed via `wp option get`). Screenshots `smoke-layout-instagram.png`, `smoke-layout-flickr.png`, `smoke-layout-pinterest.png`, `smoke-layout-dribbble.png` |
| Mobile 390px spot check | **PASS**, minor cosmetic only | `/my-media/documents/` at 390×844 — hamburger nav, streak bar readable, library rail becomes horizontal scroll tabs, Upload/Trash stack, filter/sort controls stack vertically, no horizontal page overflow. Sort/Apply labels are visually tight but not overlapping/broken. Screenshot `smoke-390-documents-drive.png` |
| F1 (2026-08-02 finding) re-verify | **CONFIRMED FIXED, still holds** | `fetch('/media/page/2/')` → **200** (was the reported 404-soft-bug, fixed in `95d5406f`) |

### Not run this pass (HUMAN / time-boxed / needs destructive or external setup)

- C.member.upload-privacy-matrix (full 16-cell matrix) — only the `public` cell was walked end-to-end this run; `members`/`friends`/`private` cells not re-walked (privacy-change action itself was verified generically via the documents-drive Apply test)
- C.member.dm-send-receive, C.member.follow-mention, C.member.signed-url (403/400 matrix) — not walked, no regression signal to justify the time this pass
- C.member.video-poster-fallback, C.member.grid-render-query-budget — not walked (would need a query-count probe / posterless video fixture)
- C.shortcodes, C.blocks (full 12/9 sweep) — not walked this pass; no D-row regression suspected, deferred
- C.admin.settings-readers (full sweep of every setting) — only the Documents group was exhaustively checked (today's priority); other groups not re-audited
- C.admin.moderation-flow (functional approve/trash) — page loads clean (0 errors) but no flagged item existed to action
- C.admin.bulk-and-cli, C.cron — not walked
- C.notifications.email — not walked (no mail trap configured to inspect)
- E.battles / E.challenges / E.tournaments (full create→resolve journeys), E.tournaments.sparse-bracket, E.streaks.freeze-proportional-cost, E.streaks.daily-check-bounded, E.competitions.cron-bounded, E.battles.win-xp-configured, E.video-intelligence, E.cloud-storage (upload verified working via CDN URLs seen throughout, but the Storage Management "Move next 20" UI not clicked), E.ai-providers, E.watermarking, E.quota, E.instagram-feed, E.privacy-pro-ui, E.migration-importers, E.gamification.* , E.group-dm, E.collections-lifecycle (full multi-collection save flow) — not walked this pass; require either external credentials (AI/S3/Whisper), destructive/long-running admin actions, or multi-account setup beyond this pass's time budget

---

## D — Regression guards: FINDINGS-HISTORY re-verification

All items in `qa/runs/FINDINGS-HISTORY.md` §19 (2026-04-23 baseline) were already marked cleared in the doc itself (F4/F5/F6/F3/P6 all shipped fixes, F7 cleared same-day). Re-checked the ones with a cheap, high-value re-test this run:

| Finding | Status this run | Evidence |
|---|---|---|
| F4 (ESC close lightbox) | **CONFIRMED still fixed** | See D.esc-close-lightbox above |
| F5 (search empty state) | **CONFIRMED still fixed** | See C.anon.search-empty-state above |
| F6 (anon `/my-media/` gate) | **CONFIRMED still fixed, and visually upgraded** | Current gate is a full styled card ("Your creative space awaits") — even richer than the F6 fix's original "styled Log In link" description |
| F3 (streak badge aria on grid cards) | **STALE CLAIM in current form, but the underlying a11y contract (title===aria-label wherever the badge does render) still holds** | See Finding F2 below — the *grid* is no longer one of the render paths by design (1.2.2), so F3's original "19 streak-badge spans on Explore" scenario cannot reoccur there; badges were confirmed correct on single-media and Dribbble layout instead |
| F7 (BP broken thumbnails) | **CONFIRMED still fixed** | See D.bp-thumbnail-leak above |
| The 2026-08-02 combo run's F1 (pagination 404) | **CONFIRMED still fixed** | See table above |
| The 2026-08-02 combo run's F2 (block-theme `get_header()` deprecation) | **Not re-verifiable this run** | Current site theme is BuddyX (classic), not a block theme (Twenty Twenty-Four/Five). The regression only manifests under a block theme; no signal either way collected this pass |
| The 2026-08-02 combo run's F3 (RTL stylesheets never loaded / would double-flip if wired) | **Not re-walked** | Time-boxed; no code changes touched RTL/CSS build since that run, low risk of drift |

---

## Candidate findings

### F1 — Activity composer's 1-image preview tile is 64×64px, not the documented 120–150px — Minor, likely documentation drift rather than a functional regression

**Section / step:** C.member.activity-preview, D.activity-preview-hero-regression

**Documented promise (quoted):**
- `qa/inventory/WHAT-TO-CHECK.md` §2 regression-lock table: *"Activity composer preview (1 image) | Compact tile 120-150px wide, 1:1 aspect, `max-height: 150px`. Consistent with multi-image grid cells. Never a 'hero preview'."*
- `qa/runbooks/AGENT_SMOKE_RUNBOOK.md` D.activity-preview-hero-regression: *"After uploading 1 image into the composer, preview tile is 120-150px wide, `aspect-ratio: 1/1`, `max-height: 150px`. NOT 200-320px."*

**Observation:** Uploading exactly 1 image into the BP activity composer produces a `.mvs-preview-item` tile measured at **64×64px** (`getBoundingClientRect()`), 1:1 aspect ratio maintained. `assets/css/bp-integration.css:1203` hardcodes `.mvs-preview-item { width:64px; height:64px; }`.

**Why I think this is a doc/code drift, not a live regression:** the tile is NOT the 200-320px "hero" that the original bug (`ba9f711`) produced — it moved the *other* direction, to a smaller, clean, deliberately-styled 64px icon-style preview with its own remove button, border-radius, and background. It reads as an intentional redesign (the class name `.mvs-preview-item` doesn't even match the regression-lock table's implied class) that the QA docs were never updated to match, rather than an accidental shrink.

**Repro:** As `journey-member`, open `/activity/`, click into "What's new", click Attach media, upload one image. Measure `.mvs-preview-item` — 64×64px.

**Screenshot:** `smoke-bp-activity-preview.png`

**Suggested reviewer action:** confirm with the person who last touched `bp-integration.css`'s preview-item block whether 64px was a deliberate redesign; if so, update the two doc citations above to 64px (not a code fix). If not deliberate, it's a genuine Minor visual regression to restore.

---

### F2 — `D.streak-badge-aria`'s regression-lock table names `Free TemplateHelpers::render_grid_item()` as a streak-badge render path, but the code deliberately excludes it — documentation drift, not a defect

**Section / step:** D.streak-badge-aria

**Documented promise (quoted):**
- `qa/runbooks/AGENT_SMOKE_RUNBOOK.md` D.streak-badge-aria: *"The 5 paths to verify: Free `TemplateHelpers::render_grid_item()`, Pro `Plugin::filter_user_display_name()`, and the 4 Pro layout templates (dribbble feed/profile, flickr feed/profile)."*
- `qa/inventory/WHAT-TO-CHECK.md` regression-lock table, same claim.

**Observation:** `includes/Core/TemplateHelpers.php` lines 232-273: `get_display_name()` (WITH the `mvs_user_display_name` filter, where the streak badge span gets injected) carries this explicit docblock: *"Use on surfaces that have room for the badge: the single-media page (author header) and the lightbox sidebar. Compact surfaces — grid cards, profile lists — should use `get_display_name_plain()` so the badge stays a deliberate identity signal rather than visual noise on every thumbnail."* And `render_grid_item()` (line 1068) does in fact call `get_display_name_plain()`, which `wp_strip_all_tags()`s the name and never runs the filter. Confirmed live: 0 `.mvs-streak-badge` elements found anywhere on `/media/` (grid layout) even for `journey-member`, who has an active streak. The badge IS correctly present (with matching `title`/`aria-label`) on the single-media author header and on the Dribbble/Flickr Pro layouts, which the runbook also names.

**Why this reads as doc drift, not a defect:** the exclusion is explained, deliberate, dated (`@since 1.2.2`), and reasoned (avoid clutter on every grid tile) — it is good design, not an oversight. The regression-lock table just never caught up to that 1.2.2 decision.

**Suggested reviewer action:** drop `Free TemplateHelpers::render_grid_item()` from the "5 known render paths" list (it's actually 4: single-media author header, lightbox sidebar, dribbble, flickr), or add a footnote explaining the intentional exclusion — otherwise every future combo smoke will "look for" a badge that was never meant to be there.

---

## Observations (not filed as bugs — no citable promise violated, or explained by fixture data)

1. **Two zero-size hidden `<img>` elements on `/media/` resolve `src===location.href`.** Both are inert lightbox-template placeholders (`data-wp-bind--src="state.lightboxAuthorAvatar"` etc., unhydrated until a lightbox opens), `offsetParent:null`, 0×0. This is the same class of thing the 2026-08-02 run demoted (0 network requests fire for an unset `src` on a hidden `<img>`). Not the `D.bp-thumbnail-leak` pattern, which is specifically about *visible* grid thumbnails.
2. **Battle Arena "Recent Results" on `/compete/` shows "Unknown" vs "Unknown"** for the completed battle (Oliver Brooks vs Mina Aoki, competition id 4). Root-caused: the battle's `mvs_competition_entries` rows reference `user_id` 2 and 3, and **no user with ID 2 or 3 exists on this site** (`wp user list` shows only 1, 7-14, 22 — a gap consistent with earlier demo-data cleanup passes per project memory). `CompeteSummaryController::get_display_name()` correctly falls back to the localized "Unknown" string rather than fataling on `get_userdata()` returning false — that is the *correct*, defensive behavior. This is stale/orphaned fixture data on this specific dev site, not a plugin code defect, and no runbook contract promises a name resolves for a deleted user.
3. **Admin overview showed "15 Documents" while an earlier direct DB count (before the day's admin-panel recount) read 14 fixture + the site's own math implies 15 total (13 private + 1 public `document` + 1 public `legacy_document`).** Numbers are internally consistent once `legacy_document` is included in the Documents admin count (which it should be, per its intentional inclusion in the document listing though not the media listing) — not a discrepancy, just noted so a reviewer doesn't need to re-derive it.
4. **`mvs_max_upload_size` (100MB, the Free media-upload setting) vs `wp_max_upload_size()`/documents (300MB, the PHP-level ceiling)** are two independently correct numbers surfaced on different admin screens (Overview "System Status" shows the former, Documents settings shows "follow the server limit (300 MB)" which is the latter). Confirmed both are read correctly from their respective sources — not a bug, flagged only because the two screens showing different "upload limit" numbers could look like a bug to a fast skim.

---

## Fixture cleanup

- Test image uploaded during the C.member.upload-public real-upload test (`media_id 2251`, title `smoke-test-upload`) — deleted from `mvs_media_index`/`mvs_media_meta`/`mvs_media_stats`/`mvs_activity` after verification.
- Its BP activity post ("Smoke test post 2026-08-11 (delete me)", `bp_activity.id 23`) — deleted along with its `bp_activity_meta` rows.
- Verified 0 residue: `SELECT COUNT(*) FROM mvs_media_index WHERE title LIKE "smoke-test%" OR title LIKE "Smoke %"` → 0; same zero for `bp_activity`.
- **Left behind (informational, harmless):** the uploaded test JPEG's binary remains on the BunnyCDN bucket (`mediaverse1.b-cdn.net`) — the plugin's delete path removes the DB rows and local pointers but this run did not separately purge the CDN object via the Storage Management "Delete" tool. 2.5KB, non-sensitive solid-color test image, no customer-identifying content.
- `journey` document (media_id 2238) privacy: changed to `members` during the real Apply-button test, reverted to `private` via direct DB update afterward — confirmed.
- `mvs_pro_feed_layout` reset to `grid` at the end of the layout-matrix cycle — confirmed via `wp option get`.
- Standard runbook fixture-cleanup query patterns (`E2E %`, `Smoke %`, `e2e_%` users) checked — 0 rows match; nothing else to clean.
- Did **not** write `qa/.last-smoke-pass.json` (worker draft only, per instructions).

---

## Manual required (F — cross-browser)

- Firefox Desktop: upload modal file picker, activity composer privacy `<select>` open/close, `navigator.clipboard.writeText` share fallback
- Safari iOS 390px: lightbox swipe + reaction tap, `navigator.share` native sheet
- Any flow relying on browser-native controls whose behavior diverges between engines

Playwright MCP is Chromium-only; none of the above were exercised this run.
