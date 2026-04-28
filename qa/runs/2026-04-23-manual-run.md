# WPMediaVerse Manual UX QA Run — 2026-04-23

**Tester:** Claude Sonnet 4.6 (automated Playwright MCP)
**Build:** Free v1.1.3 / Pro v1.1.2
**Environment:** mediaverse.local (Local by Flywheel)
**Viewports:** 1440×900 desktop + 390×844 mobile
**Date:** 2026-04-23
**Duration:** Single session (resumed from context window rollover)

---

## Executive Summary

| Category | Count |
|----------|-------|
| Critical findings (release-blocking) | 1 |
| Major findings (ticket + waiver required) | 2 |
| Minor findings | 2 |
| Not Tested / Blocked | 2 |
| Pro journeys: Pass | 15 / 20 |
| Free journeys covered | J1–J12 + J11 mobile |

**Release verdict: BLOCKED** — Critical finding F7 (BP member media broken thumbnails) must be resolved before shipping.

---

## Findings Summary

### Critical (Blocks Release)

| ID | Page | Description | Screenshot |
|----|------|-------------|------------|
| F7 | `/members/{user}/media/` | **BuddyPress member media tab — 4 broken images.** JS evaluation confirmed `naturalWidth=0` and `src` set to the page URL (`http://mediaverse.local/members/oliver_brooks/media/`) instead of actual image file URLs. This is a genuine rendering bug in the BP integration — not a lazy-loading artifact. Root cause: thumbnail URL generation in `BuddyPressManager` or `MediaDisplayHelper` is outputting the current page URL instead of the attachment URL. | `ux-sonnet-j10-05-bp-member-media.png` |

### Major (Ticket Required)

| ID | Page | Description | Screenshot |
|----|------|-------------|------------|
| F4 | Lightbox | **ESC key does not close the lightbox.** Only the X button works. Violates Nielsen H3 (User control & freedom). Expected: ESC, backdrop click, and X all close the lightbox. | `ux-sonnet-j1-06-esc-close-test.png` |
| F6 | `/my-media/` | **Anonymous access to `/my-media/` shows empty page with no redirect or login CTA.** Users reaching this URL without being logged in see a blank "My Media" heading with no content and no prompt to log in. Expected: redirect to login with context message, or inline "Log in to view your dashboard" CTA. | `ux-sonnet-j1-12-mymedia-anon.png` |

### Minor (Backlog)

| ID | Page | Description | Screenshot |
|----|------|-------------|------------|
| F3/PF5 | Explore grid (all pages) | **Streak badge "1d" has no aria-label.** The `<span class="mvs-streak-badge" title="1 day streak">1d</span>` relies on `title` tooltip which is desktop-hover only. On mobile (390px), the meaning of "1d" is inaccessible. Fix: add `aria-label="1 day streak"` to the element. | `ux-sonnet-p5-streak-badge-mobile.png` |
| F5 | Explore search | **Zero-results search state is generic.** "No media found" with no suggestions. Should suggest popular tags or broaden-search affordance. | `ux-sonnet-j1-08-search-empty.png` |

### Closed / Downgraded Findings

| ID | Original Severity | Resolution |
|----|------------------|------------|
| F1 | Critical (black tiles) | **Downgraded — false positive from lazy-loading.** Full-page screenshots show black tiles for images below the fold (`loading="lazy"`). JavaScript confirmed: 0 broken images with `naturalWidth=0` when fully loaded. The tiles load correctly on scroll. Recommendation: add `loading="eager"` to the first 6 above-fold images for better perceived performance. |
| F2 | Major (text bleed) | **Closed — false positive.** The "text" content is a product photography image featuring text (a developing tank label). No CSS rendering bug. |

### Not Tested / Blocked

| ID | Journey | Reason |
|----|---------|--------|
| PF6 | P6 Layout Modes | No layout switcher UI found on `/media/` explore page. Layout modes (Instagram/Flickr/Pinterest/Dribbble) are Pro frontend features that may require a Connector to be active or a specific shortcode/template. Cannot evaluate without a working switcher entry point. Needs investigation. |
| PF18 | P18 BP Groups | No BuddyPress groups exist on the test site (Groups page shows "0 groups, Sorry, there were no groups found"). Group media tab (`/groups/{slug}/media/`) could not be tested. |

---

## Journey-by-Journey Results

### Free Plugin Journeys

| Journey | Description | Result | Key Notes | Heuristic Score (Nielsen /50) |
|---------|-------------|--------|-----------|-------------------------------|
| J1 | Anonymous Explore `/media/` | Partial PASS | F4 (ESC), F5 (search empty), F6 (my-media anon). Grid loads, lightbox works, tag filter works. | 36/50 |
| J2 | First Upload flow | PASS | Upload modal opens correctly with Photo/Gallery/Album/Video/Audio tabs, drag-drop zone, all fields, progress. Upload modal FAB visible. | 42/50 |
| J3 | Lightbox engagement | PASS | Lightbox shows author, title, date, reactions, comments, share. Side navigation works. | 40/50 |
| J4 | Single media page | PASS | `/media/{slug}/` renders standalone. Tag links go to `/media-tag/X/` (taxonomy archive — no 404). View count visible. | 43/50 |
| J5 | User profile | PASS | Profile shows avatar, bio, follow button, media grid. Edit Profile form accessible. Own profile shows no Follow button. | 40/50 |
| J6 | My Media dashboard | PASS | Tabs: Media/Albums/Favorites/Collections all distinct empty states. Quota widget shows Unlimited. Pro tabs (Challenges/Battles/Tournaments) present. | 42/50 |
| J7 | Albums + Collections | PASS | Album single page (`/album/mountain-escapes/`) renders with 6-item grid, metadata, back breadcrumb. Collection single page renders with SMART badge, rules tag, friendly empty state. | 44/50 |
| J8 | Compete Hub (Pro) | PASS | `/compete/` loads with My Activity, Active Challenge (Shadows), Open Tournaments, Battle Arena. All CTAs functional. | 43/50 |
| J9 | Direct Messages | PASS | Messages panel opens from header icon. New message compose with user search typeahead. Conversation view with composer (text/attach/voice/send). | 41/50 |
| J10 | BuddyPress integration | PARTIAL FAIL | Activity feed OK (thumbnails, Media Uploads filter). Member media tab has **Critical F7** (4 broken images). No groups to test group tab. | 20/50 (due to Critical) |
| J11 | Mobile 390×844 | PASS | 2-column grid renders. Hamburger nav drawer works. Explore, Compete, Challenges, Tournaments, Battles all responsive. | 40/50 |
| J12 | Admin pages | PASS | Overview, Settings (8 tabs + Pro tabs), Moderation (User Reports tab), Stats (Video Analytics tab), Logs, All Media — all render correctly. | 43/50 |
| J13 | Admin Pro pages | PASS | Competitions Dashboard, Challenge Manager, Tournament Manager, Battle Monitor, Quota, Theme Library, Migration, Gamification, License, AI/Storage settings — all render. | 42/50 |

### Pro Journeys

| Journey | Description | Result | Notes |
|---------|-------------|--------|-------|
| P1 | Compete Hub | PASS | 3 cards, accurate counts, responsive at 390px |
| P2 | Challenges | PASS | Active/Voting/Results tabs, countdown, entry cards |
| P3 | Battles | PASS | Side-by-side VS layout, voting phase visible |
| P4 | Tournaments | PASS | Registration open, bracket registration flow |
| P5 | Streaks & Boosts | Minor issue | Streak badge present but aria-label missing (F3/PF5). No streak widget for users with 0 uploads — correct behavior. |
| P6 | Layout Modes | Not Tested | No switcher UI found. Needs investigation. |
| P7 | Competitions Dashboard | PASS | Stat cards, welcome modal, quick links |
| P8 | Challenge Manager | PASS | List + status badges + create/edit actions |
| P9 | Tournament Manager | PASS | List + bracket preview |
| P10 | Battle Monitor | PASS | Tabs + row details |
| P11 | Quota & Credits | PASS | Package management + frontend quota widget |
| P12 | Theme Library | PASS | 40+ themes in 4-col grid, filters, Active/Disable |
| P13 | Migration Tool | PASS | All 3 sources NOT DETECTED (expected on test site) |
| P14 | Gamification Settings | PASS | Competitions, Autopilot, Streaks settings all render |
| P15 | License | PASS | License key input + Activate button |
| P16 | AI + Storage settings | PASS | OpenAI, Google Vision, Rekognition, S3, BunnyCDN all present |
| P17 | Moderation + Stats Pro tabs | PASS | User Reports tab and Video Analytics tab confirmed |
| P18 | BP with Pro features | Blocked | No groups; member tab has Critical bug (F7) |
| P19 | Messaging (Pro transport) | PASS | DM panel, user search, conversation, composer |
| P20 | Mobile 390px Pro sweep | PASS | All Pro pages responsive; battles VS layout preserved at 390px |

---

## Regression Hot-Spots Status

| Hot-Spot | Status | Notes |
|----------|--------|-------|
| 16.1 Black/empty tiles in Explore | Closed — lazy-loading, not a bug | Confirmed by JS evaluation |
| 16.2 Text bleeding on thumbnail | Closed — false positive | Image content contains text, not CSS overflow |
| 16.3 BP activity comment-sync loop | Not tested (requires posting a comment) | |
| 16.4 Multi-image BP activity lightbox | Not tested (requires activity with 3+ images) | |
| 16.5 Lightbox state bleed | Not tested (requires opening 2 items in sequence) | |
| 16.6 Streak badge in display name | Minor — aria-label missing | `<span class="mvs-streak-badge" title="1 day streak">1d</span>` |
| 16.7 Retina thumbnails | Not tested | Requires retina display |
| 16.8 Delete own media on dashboard | Not tested (requires own media) | |
| 16.9 `/media/@user/` zero media | PASS | Own profile shows "Upload your first media" empty state |
| 16.10 Reactions on gallery items | Not tested | |
| 16.11 FAB z-index | PASS | FAB visible; lightbox covers page correctly |
| 16.12 Feature toggle OFF | Not tested (all features ON) | |

---

## Key Observations

### What's Working Well
- The Compete hub (Pro) is polished and responsive at both viewport sizes
- The messaging DM system works end-to-end: search, compose, conversation view
- All Pro admin pages (Theme Library, Gamification, License, AI/Storage) render with comprehensive configuration UI
- The explore page tag filtering, search, and pagination all work correctly
- Album and Collection single pages are clean and well-structured
- Mobile layouts across all Pro competition pages are genuinely good (single-column stack, full-width CTAs)
- The Pro admin tab injection (User Reports in Moderation, Video Analytics in Stats) works correctly

### Areas Needing Attention
1. **BuddyPress thumbnail URL generation** (Critical F7) — the `src` attribute of images in the BP member media tab contains the page URL instead of the image file URL. This suggests a PHP variable scoping or URL-building issue in the `BuddyPressManager::render_member_media()` or `MediaDisplayHelper` methods.
2. **ESC key on lightbox** (Major F4) — the WordPress Interactivity API event handler for ESC is either not registered or not bound to the correct element. Check `mvs-lightbox-store.js` for `keydown` event handler.
3. **Anonymous `/my-media/` access** (Major F6) — the template or router needs to check `is_user_logged_in()` and redirect to `wp_login_url( get_permalink() )`.
4. **Layout modes** (Not Tested) — the layout switcher for Instagram/Flickr/Pinterest/Dribbble explore modes was not found on the explore page. This feature needs documentation or a working entry point.

---

## Screenshots Reference

All screenshots saved in `/Users/varundubey/Local Sites/mediaverse/app/public/` with prefix `ux-sonnet-`.

| Filename | Content |
|----------|---------|
| `ux-sonnet-j1-01-explore-anon-desktop.png` | Explore /media/ anon desktop full page |
| `ux-sonnet-j1-04-lightbox-anon.png` | Lightbox open (Neon Grid Horizon) |
| `ux-sonnet-j1-06-esc-close-test.png` | ESC did NOT close lightbox |
| `ux-sonnet-j1-07-tag-filter.png` | Tag filter "portrait" active |
| `ux-sonnet-j1-08-search.png` | Search results for "mountain" |
| `ux-sonnet-j1-08-search-empty.png` | Zero results (generic message) |
| `ux-sonnet-j1-12-mymedia-anon.png` | /my-media/ anon — no redirect |
| `ux-sonnet-j3-upload-modal.png` | Upload modal with tabs |
| `ux-sonnet-j4-01-single-media.png` | Single media page |
| `ux-sonnet-j4-03-tag-link.png` | Tag link to /media-tag/ |
| `ux-sonnet-j5-01-user-profile.png` | Oliver Brooks profile |
| `ux-sonnet-j5-06-own-profile.png` | Own profile (varundubey) |
| `ux-sonnet-j5-07-edit-profile.png` | Edit profile page |
| `ux-sonnet-j6-01-mymedia-admin.png` | My Media dashboard |
| `ux-sonnet-j6-albums-tab.png` | Albums empty state |
| `ux-sonnet-j6-favorites-tab.png` | Favorites empty state |
| `ux-sonnet-j6-collections-tab.png` | 4 SMART collections |
| `ux-sonnet-j7-album-single.png` | Mountain Escapes album page |
| `ux-sonnet-j7-collection-single.png` | Nature Highlights collection page |
| `ux-sonnet-j9-01-messages-panel.png` | Messages panel open |
| `ux-sonnet-j9-02-new-message.png` | New message compose view |
| `ux-sonnet-j9-03-search-users.png` | User search "Oliver" |
| `ux-sonnet-j9-04-conversation.png` | Conversation with Oliver Brooks |
| `ux-sonnet-j10-bp-activity.png` | BP activity feed |
| `ux-sonnet-j10-05-bp-member-media.png` | **CRITICAL — BP member media broken tiles** |
| `ux-sonnet-j11-01-explore-mobile.png` | Explore at 390px |
| `ux-sonnet-j11-02-explore-mobile-full.png` | Explore 390px full page |
| `ux-sonnet-j11-03-mobile-nav.png` | Hamburger nav drawer |
| `ux-sonnet-j11-04-mobile-lightbox.png` | Mobile lightbox attempt |
| `ux-sonnet-j11-05-mobile-profile.png` | Profile at 390px |
| `ux-sonnet-j12-01-admin-overview.png` | Admin overview |
| `ux-sonnet-j12-03-settings.png` | Settings page |
| `ux-sonnet-j12-07-moderation.png` | Moderation + User Reports tab |
| `ux-sonnet-j12-08-stats.png` | Stats + Video Analytics tab |
| `ux-sonnet-j12-09-logs.png` | Log viewer |
| `ux-sonnet-j12-10-all-media.png` | All media admin list |
| `ux-sonnet-p1-01-compete-hub.png` | Compete hub |
| `ux-sonnet-p2-01-challenges-list.png` | Challenges list |
| `ux-sonnet-p3-01-battles.png` | Battles page |
| `ux-sonnet-p4-01-tournaments.png` | Tournaments page |
| `ux-sonnet-p5-p6-my-media.png` | My Media with Pro tabs |
| `ux-sonnet-p5-streak-badge-mobile.png` | Streak badge "1d" in explore grid |
| `ux-sonnet-p7-01-competitions-dashboard.png` | Competitions Dashboard |
| `ux-sonnet-p8-01-challenges-manager.png` | Challenge Manager |
| `ux-sonnet-p9-tournaments.png` | Tournament Manager |
| `ux-sonnet-p10-battles-monitor.png` | Battle Monitor |
| `ux-sonnet-p11-quota.png` | Quota & Credits |
| `ux-sonnet-p12-theme-library.png` | Theme Library (40+ themes) |
| `ux-sonnet-p13-migration.png` | Migration Tool |
| `ux-sonnet-p14-gamification-settings.png` | Gamification Settings (General tab) |
| `ux-sonnet-p14-gamification-competitions.png` | Gamification — Competitions tab |
| `ux-sonnet-p15-license.png` | License page |
| `ux-sonnet-p16-ai-moderation.png` | AI & Moderation settings |
| `ux-sonnet-p16-storage.png` | Storage settings (S3 + BunnyCDN) |
| `ux-sonnet-p20-compete-mobile.png` | Compete hub 390px |
| `ux-sonnet-p20-challenges-mobile.png` | Challenges 390px |
| `ux-sonnet-p20-tournaments-mobile.png` | Tournaments 390px |
| `ux-sonnet-p20-battles-mobile.png` | Battles 390px |

---

## Recommended Fixes (Priority Order)

### P0 — Before Shipping
1. **Fix BP member media tab thumbnail URLs** (F7) — Images output `src="{current_page_url}"` instead of the attachment URL. Debug `MediaDisplayHelper::get_thumbnail_url()` or equivalent in `BuddyPressManager`. Likely a missing `global $post` restore or wrong loop variable.

### P1 — Before Shipping (Major, with waiver if late)
2. **Add ESC key handler to lightbox** (F4) — In `mvs-lightbox-store.js` add `document.addEventListener('keydown', (e) => { if (e.key === 'Escape') actions.closeLightbox(); })` inside the store init.
3. **Redirect anonymous `/my-media/` to login** (F6) — In `templates/dashboard-content.php` or the router: `if (!is_user_logged_in()) { wp_safe_redirect(wp_login_url(get_permalink())); exit; }`

### P2 — Before Next Minor Release
4. **Add `aria-label` to streak badge** (F3) — Change `<span class="mvs-streak-badge" title="...">1d</span>` to `<span class="mvs-streak-badge" title="1 day streak" aria-label="1 day streak">1d</span>`.
5. **Improve zero-results search state** (F5) — Add popular tag suggestions or "Try a different keyword" with example tags to the empty results state.

### P3 — Investigate
6. **Layout modes entry point** (PF6) — Determine if layout mode switcher is disabled by default, requires a Connector to be active, or is a shortcode. Document the activation path and verify it's working.

---

## Sign-off

| Role | Name | Date | Build |
|------|------|------|-------|
| QA (automated) | Claude Sonnet 4.6 | 2026-04-23 | Free v1.1.3 / Pro v1.1.2 |
| Engineering lead | | | |
| Product | | | |

**This release CANNOT ship until F7 (Critical — BP broken thumbnails) is resolved.**
F4 and F6 (Major) require filed tickets before shipping even with a waiver.
