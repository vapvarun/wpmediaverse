## 19. Current-Session Baseline Findings (2026-04-23, Free plugin, anon on `/media/`)

Re-verify next release; any that are still there at release = blocker.

**Run: 2026-04-23** | Tester: Claude Sonnet 4.6 (automated) | Build: v1.1.3 | Viewport: 1440×900 + 390×844

| # | Journey | Severity | Finding | Status | Screenshot |
|---|---------|----------|---------|--------|------------|
| F1 | J1.2 | **Critical** | Black tiles in full-page screenshot of Explore — root cause confirmed as **lazy-loading** (images below fold; `naturalWidth=0` only for off-screen `loading="lazy"` elements). Scroll-to reveals all images correctly. NOT a true bug for logged-in users. For anon: same lazy behavior — not a data/privacy issue. Recommend adding `loading="eager"` to first 6 images above fold only. | Open — downgraded from Critical to Minor | `ux-sonnet-j1-01-explore-anon-desktop.png` |
| F2 | J1.2 | Major | One card (Developer Desk Setup thumbnail) shows text content rendered as a graphic — appears to be a product listing / text-heavy image, not CSS text overflow. Not a plugin rendering bug. | Closed — false positive | `ux-sonnet-j1-01-explore-anon-desktop.png` |
| F3 | J1.3 | Minor | Streak badge "1d" shown next to author names in the explore grid. Badge has `title="1 day streak"` tooltip (desktop hover only). On mobile (390px), tooltip is inaccessible — no aria-label. Cryptic to first-time users on mobile. | Open — Minor (file ticket for aria-label) | `ux-sonnet-p5-streak-badge-mobile.png` |
| F4 | J1.6 | Major | ESC key does NOT close the lightbox. Only the X button closes it. Violates Nielsen H3 (User control & freedom). | Open — Major | `ux-sonnet-j1-06-esc-close-test.png` |
| F5 | J1.8 | Minor | Zero-results search shows generic message ("No media found") with no suggestion to try a popular tag or broaden search. | Open — Minor | `ux-sonnet-j1-08-search-empty.png` |
| F6 | J1.12 | Major | `/my-media/` as anonymous user does NOT redirect to login. Page renders with no content and no "Log in" CTA. Users get a confusing empty page instead of a login prompt. | Open — Major | `ux-sonnet-j1-12-mymedia-anon.png` |
| F7 | J10.5 | **Critical** | BuddyPress member media tab (`/members/oliver_brooks/media/`) shows 4 broken images with `src` set to the page URL (`http://mediaverse.local/members/oliver_brooks/media/`) instead of actual image files. JavaScript confirmed: `naturalWidth=0`, src = page URL. Real broken images (not lazy-loading). | ✅ **CLEARED 2026-04-23 17:18** — fix committed in `caf4671` (`UploadService::generate_thumbnails()` now backfills any size `multi_resize()` skips with `file_url`; seeder rewritten to go through `UploadService::handle()`). Re-tested at `/members/oliver_brooks/media/`: all 13 grid items load real upload URLs (`wp-content/uploads/wpmediaverse/2026/04/*.jpg`), 0 `naturalWidth=0`, 0 `src===pageUrl`. | `ux-verify-F7-bp-member-media-after-scroll.png` |

> ~~F7 is release-blocking.~~ **F7 cleared.** F4 and F6 are Major — require tickets before shipping. F1 downgraded after root-cause investigation.

### 2026-04-23 17:20 — reproductions (Opus verify-per-item)

Each finding re-reproduced in browser with evidence captured.

| # | Reproduction | Screenshot |
|---|-------------|------------|
| F4 | Opened lightbox on "Digital Texture Art" → pressed Escape → JS-checked overlay: `display:flex, opacity:1, visibility:visible` — **still open**. `data-wp-on--keydown` binds to non-focusable `.mvs-app-shell` div (shared-ui-shell.php:48) so keydown never reaches handler. | `ux-verify-F4-after-esc.png` |
| F5 | Loaded `/media/?s=zzzznoresults999` → empty state text: "No media has been shared yet — Be the first to share something with the community!". No tag chips, no "Browse all" link, no mention of search term. Explore template has single generic `else` branch at explore.php:470. | `ux-verify-F5-empty-search-state.png` |
| F6 | Logged out → loaded `/my-media/` → rendered "My Media" heading + plain orphan sentence "Please log in to access your media dashboard." No link, no redirect, no `redirect_to` round-trip. Shortcodes.php:211 check exists but only returns `<p>…</p>`. | `ux-verify-F6-anon-mymedia.png` |
| F3 | Explore page DOM has **19 streak-badge spans**, all with `title="1 day streak"` and **zero with `aria-label`**. Pro Plugin.php:349 constructs badge inline without `aria-label`. | `ux-verify-F4-before-click.png` (badges visible in feed) |
| P6 | DB `SELECT option_value FROM wp_options WHERE option_name = 'mvs_pro_feed_layout'` → `'default'` — not a valid layout slug. `LayoutManager::get_active_slug()` only short-circuits on `'grid'`; `'default'` falls through `MODES` lookup → no layout activates. Fix is a DB `UPDATE` (or optional defensive fallback in code). | — |
| ✅ P6 CLEARED 2026-04-23 17:24 | `UPDATE wp_options SET option_value='grid' WHERE option_name='mvs_pro_feed_layout'` (1 row changed). Reloaded `/media/`: 24 cards render, 0 console errors, default grid layout active. User can switch to `instagram`/`pinterest`/`flickr`/`dribbble` via WP Admin → WPMediaVerse Pro → Settings → Display. Optional follow-up: add `array_key_exists( $slug, self::MODES + array( 'grid' => true ) )` defensive check in `LayoutManager::get_active_slug()` so stale option values default to `grid` instead of silent fallthrough. | `ux-verify-P6-grid-after-fix.png` |

### 2026-04-23 17:32 — fixes applied + per-item browser verification (Opus)

| # | Fix | Files touched | Browser verified |
|---|-----|---------------|------------------|
| ✅ F4 | Change `data-wp-on--keydown` → `data-wp-on-document--keydown` so ESC fires from document, not from the unfocusable `.mvs-app-shell` div | `wpmediaverse/templates/partials/shared-ui-shell.php:48` | ESC now closes lightbox (`display: none, offsetParent: null`). `ux-verify-F4-after-esc-fix.png` |
| ✅ F3 | Add `aria-label` to streak badge. Root-cause discovered during verify: Free's `TemplateHelpers::render_grid_item()` called `wp_kses()` with allowlist permitting only `class` + `title` — it stripped `aria-label` even when Pro added it. Extended allowlist in **5 locations** (Free helper + 4 Pro layout templates: dribbble/feed, dribbble/profile, flickr/feed, flickr/profile) | `wpmediaverse-pro/includes/Core/Plugin.php:349-350` + `wpmediaverse/includes/Core/TemplateHelpers.php:456-464` + 4 Pro layout templates | All 19 badges now `aria-label="1 day streak"`. Verified `all_have_aria: true`. |
| ✅ F5 | Branch empty state in `explore.php` on `$mvs_search` — search-specific copy + "Browse all media" + 5 popular tag chips from `get_terms('mvs_tag')`. Reuses existing `.mvs-empty-state-frontend` + `.mvs-tag-cloud-item` styles; no new REST/JS surface | `wpmediaverse/templates/explore.php:470-525` | `/media/?s=zzzznoresults999` → "No results for 'zzzznoresults999'" heading + Browse-all button + 5 tag chips. `ux-verify-F5-empty-search-fix.png` |
| ✅ F6 | Upgrade `Shortcodes::render_dashboard()` anonymous fallback from plain `<p>` text to a styled `mvs-btn--primary` "Log in" link with `redirect_to=<current_url>`. Respects original design intent (render the page, show gate) — no `template_redirect` hook added | `wpmediaverse/includes/Shortcodes/Shortcodes.php:211-219` | Anon `/my-media/` shows **Log in** button + "to access your media dashboard."; link `redirect_to=http%3A%2F%2Fmediaverse.local%2Fmy-media%2F` brings user back after auth. `ux-verify-F6-anon-mymedia-fix.png` |

**Result:** All 6 findings cleared. Release ready on usability gate. Next release should re-run `/mediaverse-qa combo` as fresh baseline.

### 2026-04-23 17:45 — J14 / J15 / P21 extension runs (Opus)

**J14 — 8 shortcodes sweep:** ✅ All 8 rendered on test page `/mvs-test-shortcodes/` (post ID 55). Full grid with pagination, upload zone with quota widget, album viewer with items, stats block, collection embed, profile edit form. Zero console errors. Screenshot `ux-j14-shortcodes-sweep.png`.

**J15 — 12 blocks sweep (corrected namespace `mvs/`):** Mixed results. Findings recorded:

| # | Severity | Finding | Screenshot |
|---|---|---|---|
| F8 | Minor | Block namespace is `mvs/*` not `wpmediaverse/*` — doc mentions both, causes confusion for theme devs adding blocks to templates. Doc reference was corrected in the QA journey section itself. | — |
| F9 | **Major** | Three blocks present on disk (`src/blocks/dashboard-view`, `explore-view`, `media-social`) but **NOT registered** by `BlockRegistrar::BLOCKS`. They ship as dead code in the plugin build. Either register them or remove the source. | — |
| F10 | Minor | `mvs/media-player` block silently returns empty output for image media — handler guards with `! $is_video && ! $is_audio` return. Shortcode `[mvs_player id="X"]` renders the same image fine. Behavior divergence between shortcode and block. | `ux-j15-blocks-frontend-fixed.png` |
| F11 | Minor | `mvs/lock-overlay` requires `mediaId` attribute — with none provided it silently renders nothing. Needs inspector-panel empty state in editor pointing the user to set a media ID. | `ux-j15-blocks-frontend-fixed.png` |
| F12 | Minor | `mvs/story-viewer` renders nothing when no active stories exist — expected behavior, but missing friendly empty state ("No active stories"). | `ux-j15-blocks-frontend-fixed.png` |

**Confirmed working blocks (5 of 8 registered):** `mvs/media-grid`, `mvs/explore-feed`, `mvs/album-viewer`, `mvs/media-upload`, `mvs/media-stats`. Test page at post ID 56 `/mvs-test-blocks/`.

**P21 — Pro layout matrix:** ✅ All 4 Pro layouts rendered correctly once DB option was fixed from invalid `'default'` to `'grid'`.

| Layout | Desktop verified | Notes |
|---|---|---|
| `grid` | ✅ | Free default explore template |
| `instagram` | ✅ | Stories ring + feed card + heart/comment/share/save buttons. Also verified mobile 390px — stories bar scrolls, feed card full-width | `ux-p21-instagram-desktop.png`, `ux-p21-instagram-390.png` |
| `flickr` | ✅ | Justified gallery, rows fill cleanly, no orphan tail | `ux-p21-flickr-desktop.png` |
| `pinterest` | ✅ | 4-col masonry with title + description + author + like + comment count | `ux-p21-pinterest-desktop.png` |
| `dribbble` | ✅ | 3-col showcase cards with streak badge and meta overlay | `ux-p21-dribbble-desktop.png` |

DB reset to `'grid'` after matrix run.

**Net:** 3 new Major/Minor findings (F9 Major, F10/F11/F12 Minor). F9 ship decision needed — either register the 3 orphan blocks or delete them from `src/`.

---

## 20. Sign-off

| Role | Name | Date | Build / Tag |
|------|------|------|-------------|
| QA | | | |
| Engineering lead | | | |
| Product | | | |

**Release may proceed only when:**
- All Critical items PASS
- Major items have filed tickets + targeted release
- Heuristic score per journey averages ≥ 3.5 / 5
- Console clean on all plugin pages
- Zero new PHP notices added to `error_log` after a full pass

---

## 21. Change log

| Date | Version | Author | Change |
|------|---------|--------|--------|
| 2026-04-23 | 1.0 | Varun | Initial — 13 journeys on plugin-mapped pages, regression hot-spots, a11y + perf smoke, baseline findings from 2026-04-23 pass |
| 2026-04-23 | 1.1 | Claude Sonnet 4.6 | Updated Section 19 baseline: 7 findings logged (F1 downgraded, F2 closed as false positive, F4/F6 Major, F7 Critical — BP broken thumbnails); run covers J1–J12 + mobile J11 at 390px |
