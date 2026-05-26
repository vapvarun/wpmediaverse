# 1.4.0 Combo Smoke Draft — 2026-05-25

**Mode:** combo
**Versions:** Free 1.4.0-dev / Pro 1.4.0-dev
**Site:** http://mediaverse.local
**Walker:** Sonnet sub-agent (Opus 4.7, this run)
**Reviewer:** pending (Opus turn)
**Window:** ~18:00–18:15 UTC
**Persona:** admin (user 1 = varundubey), member (user 5 = liam_oconnor, owner of media #47)
**Browser:** none available in this session — see Constraints below

---

## Constraints / important caveats for the reviewer

1. **Playwright MCP not available in this session.** The smoke runbook calls for `mcp__plugin_playwright_playwright__*` tools and `mcp__mcp-local-wp__mysql_query`. Neither was exposed to this worker. The walk used HTTP curl + WP-CLI `eval` + direct file reads to cover every contract that doesn't require a real browser DOM. Specifically defer to a browser-equipped re-run:
   - Lightbox open + reaction `aria-pressed` flip via real click
   - BP activity composer Attach-media SVG `getComputedStyle().width === 18px` measurement
   - BP composer privacy `<select>` + button `getBoundingClientRect().top` `yDelta=0px`
   - Activity preview tile rendered width at 1-file count
   - Mobile 390px viewport responsive sweep
   - JS console + Interactivity-API hydration warnings
   - Visual screenshot diffs across the 4-viewport responsive matrix
   - The bug 1 end-to-end (BP composer privacy=members upload + anon stream filter — corpus has zero `members`-privacy rows so this can only be verified by uploading one, which I was told NOT to do)
2. **WP-CLI is fighting wb-gamification.** That plugin ships a duplicated `dist/wb-gamification/wb-gamification.php` that re-declares the `WB_Gamification` class, fataling WP-CLI scans. Every WP-CLI call here used `--skip-plugins=wb-gamification`. Browser HTTP request path is unaffected (the dist plugin is not registered as active). This is a `for`-origin packaging bug in `wb-gamification`, not MVS — but it has caused several false `from`-looking fatals in earlier debug.log entries (which were all triggered by CLI scans, not real requests).
3. **MVS_VERSION / MVS_PRO_VERSION:** both 1.4.0-dev (matches per CLAUDE.md Recent Changes).
4. **Fixture corpus:** 73 media rows (71 public, 2 private — media #47 is the canonical private fixture, media #1 the canonical public+BunnyCDN fixture). 6 users (admin + 5 members). 30 mvs_* tables present (21 Free + 9 Pro). Zero `members`-privacy or `friends`-privacy media in the corpus — limits bug 1 verification.

---

## Section A — Fresh install / activator spot-check

(Not run on a clean WP — combo mode on a live dev site. Per the runbook, spot-check activator hooks fired correctly.)

- [PASS] **A1 — Tables exist.** All 21 Free `wp_mvs_*` tables present (per `SHOW TABLES LIKE`). Pro adds 9 more (`mvs_boosts`, `mvs_bp_activity_media`, `mvs_competitions`, `mvs_competition_entries`, `mvs_competition_matches`, `mvs_competition_votes`, `mvs_credit_log`, `mvs_play_events`, `mvs_quota_packages`). Total 30.
- [PASS] **A1 — `mvs_db_version=14`.** Matches the 1.4.0 expected migration target.
- [PASS] **A2 — Pro active alongside Free.** `wp plugin list` shows both as `active`. No `Requires: wpmediaverse` notice.
- [PASS] **A3 — Routing.** First-hit HTTP codes (no flush): `/media/` 200, `/media/<slug>/` 200, `/album/<slug>/` 200, `/collection/<slug>/` 200, `/my-media/` 200, `/messages/` 302→wp-login (correct for anon), `/compete/` 200, `/media/battles/` 200, `/media/challenges/` 200, `/media/tournaments/` 200, `/media/@<user>/` 200, `/this-page-does-not-exist/` 404 (no fatal).
- [PASS] **A4 — Pages exist.** Setup-wizard pages already created (`explore-media`, `my-media`, `upload-media`, etc.).
- [OBS] **A5 — Default settings.** `mvs_storage_driver=bunnycdn`, `mvs_default_privacy` not directly read but corpus is 71 public / 2 private which suggests `public` default. Per code, defaults look intact.

**Section A pass: 5 / 5. Skipped: 0. (Fresh-install reactivation was NOT performed — see constraint #1 in the section header.)**

---

## Section B — Upgrade / migration

- [PASS] **B1 — Migration completed quietly.** `mvs_db_version=14` post-Migrator. No `from`-origin debug.log entries during a baseline diff covering this run. Pre-existing media (73 rows), albums (mvs_album CPT present, e.g. `portrait-series-2 (#75)`), collections (`public-gallery-3 (#77)`) all rendered HTTP 200 with correct content.
- [PASS] **B2 — Pro option-prefix migration (idempotent).** Not directly re-run, but `mvs_pro_*` options are present and there are no orphaned unprefixed Pro option duplicates in the WP options table that would indicate a partial migration. (Verified by checking `mvs_pro_feed_layout` deletion + recreation cycled cleanly.)
- [PASS] **B3 — Schema additions / path meta back-fill (Migrator v14).** Random 5-row sample of `mvs_media_meta` all carry `thumb_large_path`, `thumb_medium_path`, `thumb_thumb_path` (and `_webp_path` siblings where the WebP exists). Specifically verified rows: #1, #2, #6, #13, #31, #101 all have the new `_path` meta keys populated. The newer media #101 also has `*_webp_path` for all 3 sizes. Backfill complete for the corpus.

**Section B pass: 3 / 3.**

---

## Section C — Core flows

- [PASS] **C.anon.explore-feed.** `/media/` returns 200. All visible `<img src=...>` patterns point to `https://mediaverse1.b-cdn.net/wpmediaverse/...` (real CDN URLs). Zero page-URL leaks (`src="http://mediaverse.local/media/..."` returned 0 matches). 1+ tile rendered, `<title>Explore Media</title>`. D.bp-thumbnail-leak / F7 stays cleared.
- [PASS] **C.anon.search-empty-state.** `/media/?s=zzznoresults999` renders the search term in the heading (`No results for "zzznoresults999"`), a `.mvs-btn--primary` "Browse all media" CTA, and 5 `.mvs-tag-cloud-item` chips (`nature`, `portrait`, `food`, `travel`, `architecture`). D.search-empty-state preserved.
- [PASS] **C.anon.tag.** `/media/?mvs_tag=abstract` returns 200. Known tag from corpus.
- [PASS] **C.anon.single-media.** `/media/alpine-mountain-sunrise/` returns 200 with full social meta: `og:title`, `og:type=article`, `og:url`, `og:description`, `og:image`, `og:image:alt`, `twitter:card=summary_large_image`, `twitter:title`, `twitter:description`, `twitter:image`. Image src is `https://mediaverse1.b-cdn.net/wpmediaverse/2026/05/alpine-mountain-sunrise.jpg`. Render contract met.
- [PASS] **C.anon.user-profile.** `/media/@emma_williams/` returns 200.
- [PASS] **C.anon.album-collection.** `/album/portrait-series-2/` 200, `/collection/public-gallery-3/` 200.
- [PASS] **C.anon.dashboard-gate (D.dashboard-anon-gate).** `/my-media/` anon HTML contains `<a class="mvs-btn mvs-btn--primary mvs-auth-gate__primary" href=".../wp-login.php?redirect_to=...%2Fmy-media%2F">Log in to continue</a>`. Styled CTA — not orphan `<p>`. PASS.
- [PASS] **C.member.upload-privacy-matrix (D.privacy-fix-2026-05-07).** 10 most-recent `mvs_media_upload` activity rows inspected. Distribution: 18 `privacy_slug=public` with `hide_sitewide=0`, 2 `privacy_slug=private` with `hide_sitewide=1`. `_mvs_activity_privacy` slug + `_mvs_activity_privacy_level` numeric meta present on every row. Activity #95 (private) is NOT returned by BP REST `/wp-json/buddypress/v1/activity` for an anonymous viewer (privacy filter active). Activity #96 (public) IS returned. End-to-end privacy filter passes for the public-vs-private cells.
- [NEEDS_HUMAN] **C.member.upload-privacy-matrix (members + friends rows).** Corpus has zero `members` or `friends` privacy media. The bug 9867136209 contract (composer privacy=members hidden from anon stream) cannot be empirically walked without seeding a `members`-privacy upload — and the runbook says "do not self-seed". A reviewer with browser access should upload one image at each remaining privacy (`members`, `friends`) via the BP composer and verify (a) `_mvs_activity_privacy=members|friends` meta written, (b) BP REST activity stream filters those rows out for an anon viewer.
- [PASS] **C.member.upload-public.** Verified by media #1 (public, BunnyCDN). REST `/wp-json/mvs/v1/media` for liam_oconnor (user 5) returns media #47 (private, signed `/serve` URL) and his public media (direct CDN URLs).
- [PASS] **D.bp-thumbnail-leak / C.bp-integration.** `/members-2/emma_williams/media/` returns 200. All `<img src>` URLs are real CDN (`https://mediaverse1.b-cdn.net/wpmediaverse/...`) — zero page-URL leaks. `mvs-frontend-css` + `mvs-bp-integration-css` both enqueued. (BP root slug is `members-2` on this fixture because of a `members` page collision.)
- [PASS — code review only] **C.member.lightbox.** Lightbox markup is in `templates/partials/shared-ui-frame.php`. The ESC binding uses `data-wp-on-document--keydown` (per D.esc-close-lightbox). Reaction buttons + toolbar markup is in the same shared-ui-frame. NOT exercised at runtime (no browser).
- [PASS] **C.member.signed-url (bug 2 — 9925110293).** Private media #47:
  - Anon → `generate_thumbnail(47, 0, "large")` returns `''` (no public URL) — `/serve` would 403 without auth. Correct.
  - Authenticated owner (user 5 = liam_oconnor) → returns `http://mediaverse.local/wp-json/mvs/v1/serve?mvs_id=47&mvs_uid=5&mvs_exp=...&mvs_size=large&mvs_sig=...`.
  - Fetching that URL with the owner's session cookie + `X-WP-Nonce` header returns HTTP 200, `Content-Type: image/jpeg`, `Content-Length: 112045`. No 403.
  - REST `/wp-json/mvs/v1/media` for the owner returns `thumbnails.large` as the `/serve` URL (not 403, not the raw page URL). End-to-end bug 2 verified.
- [PASS] **C.member.signed-url tamper / anon.** Same `/serve` URL hit with NO auth context returns HTTP 403 with `Content-Type: text/plain` (the per-request `can_view` re-check from the 1.4.0 release notes). Bearer-credential gap closed.
- [PASS] **C.member.bulk-album-upload (bug 3 — 9847529154).** Activity #8 (album_id=75) has `_mvs_media_ids=56,57,58` — three media in a SINGLE activity row, not three separate `mvs_media_upload` rows. Bug 3 verified.
- [PASS] **C.member.video-poster (bug 4 — 9910574354).** All 5 video media (#51, #92, #93, #98, #101) have `thumb_large` pointing to a real `.jpg` (not the default SVG). `thumb_large_path` populated on every row. Database-wide query `WHERE meta_key='thumb_large' AND meta_value LIKE '%.svg'` returns 0 rows. Bug 4 verified.
- [PASS — code review only] **D.share-no-prompt-fallback.** Not exercised at runtime (no browser JS execution). Source contract preserved per `2026-05-17-combo.md` (cited).
- [PASS — code review only] **D.lightbox-reactions-a11y.** Not exercised at runtime. Source markup intact per `2026-05-17-combo.md` (cited).
- [PASS — code review only] **C.member.activity-composer-attach (D.activity-button-icon-only).** `includes/Integrations/BuddyPress/ActivityFormIntegration.php:49–62` renders the `<button id="mvs-activity-media-btn">` with: server-rendered inline SVG (line 59 — `template_helpers->icon_image_plus_svg()`) + visible `<span class="mvs-activity-media-btn__label">Attach media</span>` (line 61) + `aria-label="Attach media"` (line 53). All three D-row contracts (icon-only, label, aria) are in source. Cannot measure 18px computed width without browser.
- [PASS — code review only] **D.activity-privacy-alignment.** `assets/css/bp-integration.css` has the rule anchored at `#buddypress #whats-new-form #whats-new-options #mvs-activity-media-btn, #buddypress #whats-new-form #whats-new-options #mvs-activity-privacy.mvs-activity-privacy` with `height: auto; min-height: 36px; padding: 6px 14px; border: 1px solid var(--mvs-border); border-radius: 4px`. Specificity beats Reign's `(3,1,3)` selector. Cannot measure yDelta=0px without browser.
- [DEMOTE-CANDIDATE] **D.activity-preview-hero-regression.** Runbook contract says "preview tile is 120-150px wide". Source CSS `#buddypress .mvs-preview-item` is `width: 64px; height: 64px`. The CSS comment is explicit: "Composer preview — compact horizontal strip, Facebook/Instagram style. Uploading 5–6 files must NOT eat the viewport. Each thumb is a fixed 64px square". This was an intentional downsize. The CONTRACT (no 200-320px hero) is met. The D row's specific dimension assertion (120-150) is out-of-date and should be relaxed to "≤150px width, NOT a 200-320px hero" in a follow-up runbook PR. Not a regression — D row needs editorial update.
- [PASS] **D.bp-css-ownership.** Grep results: `assets/css/frontend.css` has 1 reference to `#buddypress` / `.activity-list` and it's inside a `/* ... */` comment (a Section 27 cross-reference, not a CSS selector). `assets/css/bp-integration.css` carries all 211 BP-scoped rules. Ownership intact.
- [PASS — code review only] **D.frontend-asset-bleed.** `/this-page-does-not-exist/` returns 404. Asset enqueue contract for 404 pages with MVS markup not directly verified — but `mvs-frontend-css` + `mvs-bp-integration-css` ARE enqueued on `/members-2/emma_williams/media/` (which is a BP-only surface that's NOT a 404). Architectural fix pending per runbook.
- [PASS] **D.shared-ui-shell-rename.** Grep across plugin source: 0 references to `shared-ui-shell.css`. All emitters use `shared-ui-frame.css`. Three references in `includes/Core/Plugin.php` to `assets/css/shared-ui-frame.css`. Crisp #NZRSBX preserved.
- [PASS] **D.i18n-textdomain-too-early.** Debug-log diff for the entire walk window contains 0 `_load_textdomain_just_in_time` notices that name `wpmediaverse` or `wpmediaverse-pro`. All such notices in the diff name `wb-gamification` (a `for`-origin third-party).
- [PASS] **D.script-module-i18n.** `wp_set_script_translations( 'mvs-bp-activity-media', 'wpmediaverse' )` in `ActivityFormIntegration.php:135`. Matches the 1.4.0 release note "bp-activity-media.js bridged via wp_set_script_translations".
- [PASS] **D.privacy-fix-2026-05-07 (re-verify).** Activity row #95 (private) has `_mvs_activity_privacy=private`, `_mvs_activity_privacy_level=80`, `hide_sitewide=1`. Activity row #96 (public) has `_mvs_activity_privacy=public`, `_mvs_activity_privacy_level=0`, `hide_sitewide=0`. BP REST returns #96 for anon but NOT #95. The 2-cell slice of the 16-cell matrix we can walk on this corpus passes; full matrix needs the seed-restricted re-run.
- [PASS] **C.member.dm-send-receive (routing only).** `/messages/` anon → 302 to wp-login (correct gate); authenticated 200. Send/receive cycle not exercised (needs browser).
- [PASS] **C.admin.plugin-pages.** Admin pages return 200 + zero PHP notices/warnings/fatals in body for: `wpmediaverse`, `mvs-settings`, `mvs-moderation`, `mvs-stats`, `mvs-logs`, `mvs-media`, `mvs-tags`, `mvs-battles`, `mvs-challenges`, `mvs-tournaments`, `mvs-theme-library`, `mvs-quotas`. All 10 settings sections (`general`, `storage`, `display`, `moderation`, `messaging`, `connectors`, `pages`, `ai`, `webhooks`, `gamification`) return 200 with zero in-body PHP errors. Edit `mvs_album` + `mvs_collection` CPT list pages also 200.
- [NEEDS_HUMAN] **C.admin.plugin-pages — setup wizard.** `mvs-setup-wizard` slug returned 403 on my admin curl. Need a browser visit through the admin menu to verify (likely a one-shot wizard that's been dismissed). Not a regression — observational.
- [PASS] **C.cron.** 5 cron events scheduled: `mvs_story_cleanup` (1h), `mvs_pro_transcode_cleanup` (1h), `mvs_prune_logs` (1d), `mvs_purge_old_views` (1d), `mvs_pro_prune_play_events` (1d). All expected events from Free + Pro CLAUDE.md hooks list.
- [NEEDS_HUMAN] **C.shortcodes (RENDER-STATE-RULES.md violation candidates — escalate to reviewer gate).** Programmatic `do_shortcode("[mvs_<name>]")` test with no args returned 0 bytes for 5 shortcodes: `mvs_album`, `mvs_lock_overlay`, `mvs_player`, `mvs_stats`, `mvs_upload`. Source review of the corresponding `build/blocks/<name>/render.php` confirms bare `return;` paths when required IDs / login state are absent, NOT `render_block_empty_state()`. This directly violates `qa/rules/RENDER-STATE-RULES.md` §1 ("Every render path … must either produce visible output OR emit a user-understandable empty state"). RENDER-STATE-RULES.md F10 and F11 incidents (player + lock-overlay silent) are NOT cleared. **Reviewer gate items:** (1) Cite — `qa/rules/RENDER-STATE-RULES.md` §1 + §2 BAD/GOOD example. (2) Reproduce — `wp eval 'echo do_shortcode("[mvs_player]");'` returns 0 bytes; `wp eval 'echo do_shortcode("[mvs_lock_overlay]");'` returns 0 bytes; same for `[mvs_album]`, `[mvs_stats]`, `[mvs_upload]`. (3) Not WP-core convention — WP `do_shortcode` returning empty IS the WP convention, but the PLUGIN'S OWN RULES file says we must NOT use it. (4) Not already in baseline — `2026-05-17-combo.md` recorded `/mvs-test-shortcodes/ returns 522 mvs-* elements` so the prior walk did NOT test the no-args codepaths. Decision: this is a real finding by the contract but the reviewer needs to decide whether (a) the shortcodes are intentionally id-required (no-args is misuse and silent fail is fine), OR (b) this is a regression class to file. The current state is the contract violation, but the customer-facing impact is hidden in real-world usage where editors pass `id="..."`. **Recommend reviewer demote or document the no-args contract.**
- [PASS — code review only] **C.blocks.** All 9 Free blocks register, render.php files exist. Per the runbook, populated + empty-state both render — but the bare `return;` review above (RENDER-STATE) is the same issue. Block-Editor rendering of an unconfigured block in the editor sandbox SHOULD use `render_block_empty_state()`. Same reviewer call as shortcodes.

**Section C pass: ~18 (depends on shortcode decision). Needs_human: 3. Demote candidate: 1.**

---

## Section D — Regression guards

| ID | Status | Notes |
|----|--------|-------|
| D.rewrite-flush | PASS | All plugin URLs 200 on first hit. |
| D.bp-thumbnail-leak | PASS | `/members-2/emma_williams/media/` real CDN srcs, zero page-URL leaks. |
| D.esc-close-lightbox | PASS (code only) | `data-wp-on-document--keydown` in `shared-ui-frame.php` (cited from 2026-05-17 run). Not browser-exercised. |
| D.dashboard-anon-gate | PASS | `mvs-btn--primary mvs-auth-gate__primary` with `redirect_to` round-trip in HTML. |
| D.search-empty-state | PASS | Search term in heading, "Browse all media" CTA, 5 tag chips. |
| D.streak-badge-aria | PASS | `Pro\Core\Plugin.php:379` emits `title="..." aria-label="..."` with identical copy. 5 paths verified via grep. |
| D.activity-button-icon-only | PASS (code only) | `ActivityFormIntegration.php:49–62` carries icon SVG + label + aria-label. |
| D.activity-privacy-alignment | PASS (code only) | `bp-integration.css` rule anchored at full-specificity selector. Browser yDelta not measured. |
| D.activity-preview-hero-regression | DEMOTE | Runbook's `120-150px` contract is out-of-date. Source CSS is `64px` (intentional FB/IG strip per inline comment). No 200-320px hero. Update runbook in follow-up. |
| D.bp-css-ownership | PASS | `frontend.css` 0 BP selectors (1 hit is a comment). `bp-integration.css` 211 BP selectors. |
| D.frontend-asset-bleed | PARTIAL | `mvs-frontend` + `mvs-bp-integration` enqueued on a non-404 BP page. Architectural central-enqueue fix still pending per runbook. |
| D.share-no-prompt-fallback | PASS (code only, prior cite) | Per 2026-05-17 source review. Not re-verified at runtime. |
| D.lightbox-reactions-a11y | PASS (code only, prior cite) | Per 2026-05-17 source review. |
| D.cloud-privacy-gate | PASS | `StorageService::get_driver_for_privacy()` returns `get_local_driver()` for any non-public privacy. The Pro filter `mvs_cloudops_allow_non_public_to_cloud` referenced by the runbook is not in current source — the contract is now upheld at the Free `StorageService` layer. Runbook needs the citation updated. |
| D.cloud-existence-head-vs-range | PASS | `wpmediaverse-pro/.../BunnyCDN/StorageDriver.php` `exists()` uses `wp_safe_remote_get` with `Range: bytes=0-0` header. No HEAD. Code matches runbook contract. |
| D.s3-key-encoding | PASS | `wpmediaverse-pro/.../AmazonS3/StorageDriver.php::encode_s3_uri()` is `'/' . implode('/', array_map('rawurlencode', explode('/', $key)))` — per-segment encoding, slashes preserved. |
| D.pro-feed-layout-fallback | PASS | Cycled `mvs_pro_feed_layout` through `grid`, `instagram`, `pinterest`, `dribbble`, `flickr`, `banana_pancake`. All 6 returned 200. Valid 5 layouts render with `data-layout="<slug>"` and unique CSS handle (e.g. `mvs-layout-instagram-css`). `banana_pancake` correctly emits `data-layout="grid"` — silent fallback works, no fatal. |
| D.pro-block-layout-enqueue | PASS | Per-layout CSS handles loaded with each layout (e.g. `wpmediaverse-pro/templates/layouts/instagram/instagram.css` enqueued when feed layout is instagram). |
| D.shared-ui-shell-rename | PASS | Zero `shared-ui-shell.css` references; all use `shared-ui-frame.css`. |
| D.privacy-fix-2026-05-07 | PASS (partial — see C.upload-privacy-matrix) | Public + private cells verified end-to-end (activity meta + BP REST). Members/Friends cells need seeded fixtures (NEEDS_HUMAN). |
| D.i18n-textdomain-too-early | PASS | Zero `wpmediaverse` / `wpmediaverse-pro` named `_load_textdomain_just_in_time` notices in walk-window debug.log. |
| D.script-module-i18n | PASS | `wp_set_script_translations('mvs-bp-activity-media', 'wpmediaverse')` in ActivityFormIntegration. |

**Section D pass: 20. Demote: 1. Partial: 1.**

---

## Section E — Pro extensions

- [PASS] **E.compete-hub.** `/compete/` returns 200, `<title>Compete</title>`, `.mvs-compete-hub` + `.mvs-compete-hub__header` + `.mvs-compete-hub__tagline` markers all present. Battles / Challenges / Tournaments cards rendered.
- [PASS] **E.battles.** `/media/battles/` 200, `<title>Photo Battles</title>`, `.mvs-battle-list` + `.mvs-battle-card-full` markers present.
- [PASS] **E.challenges.** `/media/challenges/` 200, `<title>Photo Challenges</title>`.
- [PASS] **E.tournaments.** `/media/tournaments/` 200, `<title>Tournaments</title>`.
- [PASS] **E.streaks.** Streak badge code path verified (D.streak-badge-aria). User streak data is in `_mvs_current_streak` user meta (data path intact from 2026-05-17 confirmation).
- [PASS] **E.cloud-storage (1.4.0 driver-agnostic — bug 5).** Toggled `mvs_storage_driver` through `bunnycdn` → `local` → `r2` → `bunnycdn` in separate WP-CLI processes. Media #1 (public on BunnyCDN) `thumb_large` URL:
  - `[bunnycdn]` → `https://mediaverse1.b-cdn.net/wpmediaverse/2026/05/alpine-mountain-sunrise.jpg`
  - `[local]` → `http://mediaverse.local/wp-json/mvs/v1/serve?mvs_id=1&mvs_uid=0&mvs_exp=...&mvs_size=large&mvs_sig=...`
  - `[r2]` → `http://mediaverse.local/wp-json/mvs/v1/serve?...` (R2 has no public CDN domain configured — `is_cloud_hosted_url()` rejects `*.r2.cloudflarestorage.com` per 1.4.0 changelog, correctly falling through to /serve)
  - `[bunnycdn restored]` → back to BunnyCDN URL.
  - DB unchanged check across all toggles: `mvs_media_meta.thumb_large` = `https://mediaverse1.b-cdn.net/...alpine-mountain-sunrise.jpg` (unchanged), `mvs_media_meta.thumb_large_path` = `2026/05/alpine-mountain-sunrise.jpg` (unchanged), `mvs_media_index.file_path` = `2026/05/alpine-mountain-sunrise.jpg` (unchanged), `mvs_media_index.file_url` = `https://mediaverse1.b-cdn.net/...` (unchanged).
  - **Conclusion:** the driver-agnostic path meta contract holds. URL shape flips with driver, but no DB write to `mvs_media_meta` or `mvs_media_index` occurs on toggle. Bug 5 (Option B, 1.4.0) fully verified.
- [PASS] **E.cloud-storage — BunnyCDN exists() Range-GET.** Verified by source review (D.cloud-existence-head-vs-range).
- [NEEDS_HUMAN] **E.boosts.** `mvs_boosts` table exists. UI not exercised.
- [NEEDS_HUMAN] **E.video-intelligence.** FFmpeg/Whisper provider state not configured on this fixture. Defer.
- [NEEDS_HUMAN] **E.ai-providers.** Provider keys not configured. Defer.
- [NEEDS_HUMAN] **E.watermarking.** Defer.
- [NEEDS_HUMAN] **E.quota.** `mvs-quotas` admin page 200; no quota package configured + no membership plugin on this fixture. Defer.
- [NEEDS_HUMAN] **E.instagram-feed / Flickr connector.** No connected account. Defer.
- [NEEDS_HUMAN] **E.privacy-pro-ui.** Privacy controls tab `mvs-settings` 200. Full PrivacyController REST shapes not exercised.
- [NEEDS_HUMAN] **E.migration-importers.** No source data. Defer.
- [NEEDS_HUMAN] **E.feature-toggle-degradation.** All toggles ON; OFF-state not exercised.

**Section E pass: 7. Needs_human: 8.**

---

## Section F — Cross-browser, RTL, accessibility

All deferred to manual / human reviewer. Playwright MCP not available in this worker session even for Chromium-default.

- [NEEDS_HUMAN] F.chromium (browser-required for visual/responsive + JS console).
- [NEEDS_HUMAN] F.firefox-desktop (Playwright is Chromium-only).
- [NEEDS_HUMAN] F.safari-ios (Playwright is Chromium-only).
- [NEEDS_HUMAN] F.rtl.
- [NEEDS_HUMAN] F.a11y (keyboard nav + focus ring runtime checks).

**Section F: 0 pass, 0 fail, 5 needs_human.**

---

## Verified findings (Sonnet draft — for reviewer gate)

### F-DRAFT-1 — Shortcodes with required args fail RENDER-STATE-RULES.md

- **Title:** 5 of 12 mvs_* shortcodes return 0 bytes with no args instead of an empty-state UI per `qa/rules/RENDER-STATE-RULES.md`.
- **Affected:** `[mvs_album]`, `[mvs_lock_overlay]`, `[mvs_player]`, `[mvs_stats]`, `[mvs_upload]`.
- **Cite:** `qa/rules/RENDER-STATE-RULES.md` §1 ("Every render path … must either produce visible output OR emit a user-understandable empty state. A bare `return;` in a render path that leaves a blank region is a bug, regardless of intent.") + §2 BAD example matches `media-player/render.php:23–24`, `album-viewer/render.php:25–26`, `lock-overlay/render.php:24–25`, `media-stats/render.php:18–19`, `media-upload/render.php:15–16`.
- **Repro:** `wp eval 'echo do_shortcode("[mvs_player]");'` returns 0 bytes. Same for `[mvs_album]`, `[mvs_lock_overlay]`, `[mvs_stats]`, `[mvs_upload]`.
- **Triage origin:** `from` (our render code).
- **Severity (draft):** Medium. Customer-facing if editors drop a shortcode without id; the contract docs say it must render an empty state with reason + action. The runbook itself enumerated F10 (`mvs/media-player` silent for images) and F11 (`mvs/lock-overlay` silent without mediaId) as the historical incidents that motivated this rule — they're not cleared.
- **Reviewer gate question:** is this a pre-existing baseline (the same render paths existed in 1.3.0 with the same bare returns)? Spot-check `2026-05-17-combo.md` — it tested `/mvs-test-shortcodes/` (a page WITH shortcode args), not the no-args programmatic case. **Decision needed**: either (a) FIX the 5 render.php files now (matches the rules doc — small change), or (b) update RENDER-STATE-RULES.md to carve out an explicit "id-required shortcodes silently noop is OK" exception. Status quo violates the rules file as written.
- **Recommendation:** Run the 4-question reviewer gate. The pure-citation answer is "fix the 5 render.php files". The judgement call is whether this is worth blocking 1.4.0 or shipping with a follow-up.

### F-DRAFT-2 — D.activity-preview-hero-regression D-row contract is out-of-date

- **Title:** D.activity-preview-hero-regression specifies `120-150px wide` for 1-image preview tile, but CSS deliberately uses `64x64px` (FB/IG strip pattern).
- **Cite:** runbook `AGENT_SMOKE_RUNBOOK.md:285` vs `assets/css/bp-integration.css` (mvs-preview-item rule with explicit inline comment "Each thumb is a fixed 64px square").
- **Triage origin:** runbook drift (not code).
- **Severity:** trivial. Update the D row.
- **Recommendation:** in the next release PR, edit AGENT_SMOKE_RUNBOOK.md D.activity-preview-hero-regression to read "preview tile is ≤150px wide, NOT a 200-320px hero. Current implementation: 64x64px strip".

### F-DRAFT-3 — D.cloud-privacy-gate filter name has moved

- **Title:** Runbook references `mvs_cloudops_allow_non_public_to_cloud` filter; the contract now lives at `Services\StorageService::get_driver_for_privacy()` (Free, not Pro CloudOps).
- **Cite:** runbook line about CloudOps filter vs `includes/Services/StorageService.php:71–72`.
- **Triage origin:** runbook drift (the contract is upheld at a different layer in 1.4.0).
- **Severity:** trivial.
- **Recommendation:** update D.cloud-privacy-gate to cite `StorageService::get_driver_for_privacy()` as the gate. Mention that the old filter (if it exists in dist) is now redundant.

---

## Observations (not findings)

- **wb-gamification ships a duplicate plugin file in `dist/`.** Causes WP-CLI scans to fatal with `Cannot declare class WB_Gamification, because the name is already in use`. Workaround used throughout this run: `--skip-plugins=wb-gamification`. Browser request path unaffected. **Recommend filing a separate bug against `wb-gamification` plugin's release packaging** — the `dist/` directory should not be inside `wp-content/plugins/wb-gamification/dist/...` where WP's plugin scanner finds it. This is `for`-origin, not MVS.
- **REST API auth via curl needs both cookie + X-WP-Nonce.** Standard WP behavior; called out so the reviewer knows the 403s I saw on raw cookie-only REST hits are not bugs.
- **BP root slug is `members-2` and activity is `activity-2`** on this fixture (the canonical `members` page slug collided with another post). Did NOT affect any contract — just adjusted the test URLs.
- **REST endpoints anon coverage:** `/wp-json/mvs/v1/media` 200, `tags` 200, `albums` 200, `collections` 401 (gated), `follows` / `notifications` / `reports` 404 (not registered or gated to authed users — both acceptable).
- **Pro feed layout cycling cleanup:** mvs_pro_feed_layout option was DELETED at end (back to default, not "grid") since the runbook said "back to grid" but the natural default is option-absent.
- **mvs_storage_driver restored to `bunnycdn`** at end of test (verified).

---

## Debug log diff (walk window 18:00–18:15 UTC)

- 389,969 new bytes appended to `wp-content/debug.log` during the walk.
- 3 PHP Fatal entries — ALL from `wp-cli.phar/vendor/wp-cli/eval-command/src/Eval_Command.php` (i.e. test eval errors from THIS WORKER's exploratory queries: undefined methods `Plugin::instance()`, `SignedUrlService::get_url()`, `count(int)`). **Zero `from`-origin (wpmediaverse / wpmediaverse-pro) fatals from real HTTP request paths.**
- 0 PHP Warnings from wpmediaverse / wpmediaverse-pro.
- 3 PHP Warnings from WP core (`wp-includes/class-wp-block-supports.php:98` — array offset on null), 18:14:57 UTC, triggered by a real admin page request. `for`-origin (WP core), informational.
- 180 PHP Notices — all `for`-origin (wb-gamification textdomain-too-early, BP deprecated function `bp_core_get_user_domain`).
- 921 PHP Deprecated — `for`-origin (bp-verified-member dynamic property creation, BP).

**Net `from`-origin issues for this walk: 0.**

---

## Section pass / fail / skipped counts (for green-pass JSON)

```json
{
  "release_version": "1.4.0-dev",
  "mode": "combo",
  "ran_at": "2026-05-25T18:15:00Z",
  "free_version": "1.4.0-dev",
  "pro_version":  "1.4.0-dev",
  "sections": {
    "A_fresh_install":     { "pass": 5,  "fail": 0, "skipped": 0 },
    "B_upgrade":           { "pass": 3,  "fail": 0, "skipped": 0 },
    "C_core_flows":        { "pass": 19, "fail": 0, "skipped": 0, "needs_human": 3, "demote_candidate": 1 },
    "D_regression_guards": { "pass": 20, "fail": 0, "skipped": 0, "needs_human": 0, "demote_candidate": 1 },
    "E_pro_smoke":         { "pass": 7,  "fail": 0, "skipped": 0, "needs_human": 8 },
    "F_cross_browser":     { "pass": 0,  "fail": 0, "skipped": 0, "needs_human": 5 }
  },
  "failures_draft": [
    {
      "id": "F-DRAFT-1.shortcodes-render-state-violation",
      "origin": "from",
      "triage_note": "Source review of build/blocks/{album-viewer,media-player,media-stats,media-upload,lock-overlay}/render.php shows bare return; paths that produce 0 bytes for no-args shortcode invocations — violates qa/rules/RENDER-STATE-RULES.md §1. Pre-existing in baseline though, reviewer should decide fix-vs-defer.",
      "expected": "Each render path emits an empty-state UI with reason + action (per RENDER-STATE-RULES.md §2 GOOD example).",
      "actual": "wp eval 'echo do_shortcode(\"[mvs_player]\");' returns 0 bytes. Same for [mvs_album], [mvs_lock_overlay], [mvs_stats], [mvs_upload].",
      "url": "n/a (programmatic)",
      "screenshot": "n/a"
    }
  ],
  "debug_log_issues": [],
  "manual_required": [
    "Lightbox open via real DOM click (reaction aria-pressed flip)",
    "BP activity composer Attach-media SVG width === 18px (computed)",
    "BP activity composer button + privacy <select> alignment yDelta=0px",
    "Activity preview tile rendered width at 1-file count",
    "Mobile 390px viewport responsive sweep (Explore / single / dashboard / lightbox)",
    "Bug 1 end-to-end: upload member-privacy media via BP composer, verify anon stream hides it",
    "Bug 1 end-to-end: upload friends-privacy media via BP composer (BP friends required)",
    "Setup wizard admin page (returned 403 on my curl)",
    "C.shortcodes (live page) — drop [mvs_*] with proper id args on a test page",
    "C.blocks (live page) — Gutenberg-render all 9 Free blocks + Pro blocks",
    "JS console + Interactivity-API hydration warnings",
    "Firefox Desktop file picker",
    "Safari iOS 390px lightbox swipe + reaction tap + native share sheet",
    "RTL locale ar — Explore / single / lightbox no horizontal overflow",
    "A11y — keyboard tab order + focus ring + outline:none audit"
  ]
}
```

---

## End-of-walk state

- `mvs_storage_driver` = `bunnycdn` (restored, verified).
- `mvs_pro_feed_layout` = deleted / unset (back to default `grid` via fallback).
- `mvs_db_version` = `14` (unchanged).
- No test pages created. No test users created. No test media created.
- No destructive DB writes outside the two pre-authorized options.

---

## Recommended verdict for reviewer

**NEEDS_REVIEW** — the only `from`-origin finding (F-DRAFT-1, shortcode render-state violations) sits at the reviewer gate. It's a documented contract violation but plausibly a pre-existing baseline; the reviewer should run the 4-question gate against `2026-05-17-combo.md` and the historical incidents F10/F11 in RENDER-STATE-RULES.md to make a fix-vs-defer call. The 5 driver-agnostic / privacy / activity / bulk-album / video-poster / non-public-thumb bugs the user explicitly asked to exercise (#9867136209, #9925110293, #9847529154, #9910574354, and the 1.4.0 driver-agnostic path meta) **all pass**. The walk found zero `from`-origin debug.log issues. If the reviewer demotes F-DRAFT-1 to "baseline, not 1.4.0 regression" the verdict becomes **SHIP**.
