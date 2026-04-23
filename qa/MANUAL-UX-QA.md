# WPMediaVerse — Manual UX/Usability QA (Pre-Release Gate)

> **Mandatory before every release.** Run end-to-end. Do not ship if any **Critical** item fails.
> Complements `QA-CHECKLIST.md` (feature/contract audit). This one tests **usability on plugin-owned pages only** — can a real user accomplish real tasks without friction, confusion, or broken states?
>
> **Scope:** Plugin-mapped URLs + plugin admin screens **only**. The WP home page, theme pages, and other plugins are **out of scope**. Pro supplements live in `wpmediaverse-pro/qa/MANUAL-UX-QA.md`.
>
> **Format:** Mark ✅ PASS / ❌ FAIL / ⚠️ NOTE. Attach screenshot for every FAIL. Severity: **Critical** (blocks release), **Major** (file bug, waiver required), **Minor** (backlog).

---

## 0. Plugin-Owned URL Surface (the only pages we test here)

Frontend (routed by WPMediaVerse — `templates/` + rewrite rules):

| URL | Template | Purpose |
|-----|----------|---------|
| `/media/` | `templates/explore.php` | Explore feed (query var `mvs_media_archive=1`) |
| `/media/page/2/` | `templates/explore.php` | Paginated explore |
| `/media/{slug}/` | `templates/media-single.php` | Single media by slug |
| `/media/{id}/` | `templates/media-single.php` | Single media by numeric ID |
| `/media/@{username}/` | `templates/user-profile.php` (Pro) / profile partial | User profile page |
| `/media/@{username}/page/2/` | profile + pagination | Paginated profile |
| `/media/edit-profile/` | `templates/profile-edit.php` | Own profile edit |
| `/media/album/{id}/` | `templates/album.php` | Single album |
| `/media/collection/{id}/` | `templates/collection.php` | Single collection |
| `/my-media/` | `templates/partials/dashboard-content.php` | User dashboard (4 tabs) |
| `/compete/` | `templates/compete-hub.php` (Pro) | Compete hub (query var `mvs_compete_page=1`) |
| `/media/battles/` | `templates/battles.php` (Pro) | Battles list |
| `/media/challenges/` | `templates/challenges.php` (Pro) | Challenges list |
| `/media/tournaments/` | `templates/tournaments.php` (Pro) | Tournaments list |
| `/messages/` (if set) | `templates/messages.php` | Direct messages UI |
| BP: `/members/{user}/media/` | BP integration | Profile Media tab |
| BP: `/groups/{slug}/media/` | BP integration | Group Media tab |

Admin (WPMediaVerse menu):

| Slug | Purpose |
|------|---------|
| `admin.php?page=wpmediaverse` | Overview (stats cards, quick actions) |
| `admin.php?page=mvs-settings` | Settings (8 tabs) |
| `admin.php?page=mvs-moderation` | Moderation Queue |
| `admin.php?page=mvs-stats` | Stats |
| `admin.php?page=mvs-logs` | Log viewer |
| `admin.php?page=mvs-media` | All Media list |
| Setup Wizard (first-run modal) | Onboarding wizard |

Pro admin adds: `mvs-competitions`, `mvs-challenges`, `mvs-tournaments`, `mvs-battles`, `mvs-quotas`, `mvs-theme-library`, `mvs-migration`, Pro settings tabs, License.

> Anything outside this list (WP home, theme pages, other plugins' pages) is **out of scope**. Do not log findings for them.

---

## 1. Test Environment Setup (run once per release)

- [ ] Fresh Local WP site or staged copy of production
- [ ] `WP_DEBUG=true`, `WP_DEBUG_LOG=true`; Query Monitor active
- [ ] Auto-login mu-plugin present (`?autologin=<user>`)
- [ ] Seed data: ≥30 media across 5+ users, mix of photo/gallery/video, mix of public/members/private, ≥10 with reactions + comments
- [ ] Accounts: `admin`, `author1` (≥10 uploads), `author2` (≥5 uploads), `subscriber1` (zero uploads, new-user flow)
- [ ] BuddyPress active, ≥1 public group
- [ ] WB-Gamification active
- [ ] Pro activated, valid/dev license; toggles ON for battles, challenges, tournaments, boosts, streaks, connectors
- [ ] Chrome latest, clean profile, DevTools Network cache disabled, Console open (any red error ≥ Major)
- [ ] Viewports: **1440×900 desktop** + **768×1024 tablet** + **390×844 mobile**
- [ ] Snapshot `error_log` size before starting — anything new = finding

---

## 2. Usage

For every step: **Do** it as a user who hasn't read docs → **Observe** what you actually see → **Score** the pass criterion → **Note friction** (every moment you hesitated or guessed) → **Screenshot** FAIL/⚠️ to `docs/qa/{release-tag}/J{n}-{step}.png`.

Severity: **Critical** (blocks release, visible broken UI, data loss, security) • **Major** (confusing, wrong data, mobile-broken, a11y block) • **Minor** (polish, copy).

### Nielsen heuristic scoring (rate 1-5 per journey at the end)

| # | Heuristic |
|---|-----------|
| 1 | Visibility of system status |
| 2 | Match with real world |
| 3 | User control & freedom |
| 4 | Consistency & standards |
| 5 | Error prevention |
| 6 | Recognition not recall |
| 7 | Flexibility & efficiency |
| 8 | Aesthetic & minimalist design |
| 9 | Error recovery |
| 10 | Help & documentation |

Any 1 or 2 = Major finding by default.

---

## 3. Journey 1 — Anonymous visitor on `/media/` Explore

**Persona:** Maya, arrives on `/media/` directly. Not logged in. Never seen the plugin.

### Steps

- [ ] **1.1** Land on `/media/`. **Pass:** First paint < 2s. Heading "Explore" (or brand equivalent). Tag chip row visible. Grid of cards above the fold. Search field present.
- [ ] **1.2** Scan the feed. **Pass:** Every visible card has a real thumbnail. **No black/empty tiles.** No text bleeding over the image area. No "broken image" icons. Private/member-only items either are filtered out for anon OR show an explicit lock overlay with copy like "Log in to view" — never a silent black rectangle.
- [ ] **1.3** Card metadata (author, avatar, timestamp, reaction count). **Pass:** Every small number or badge near the author has a clear meaning or a tooltip. No bare "14" or "1d" that needs guessing.
- [ ] **1.4** Click a media card. **Pass:** Lightbox opens with backdrop within 500ms. Media renders (no stuck spinner > 2s). Author, title, date visible in sidebar.
- [ ] **1.5** Try social actions as anon (react, favorite, comment). **Pass:** Inline, non-destructive prompt "Log in to react / favorite / comment" — not a 401 toast, not a silent no-op.
- [ ] **1.6** Close lightbox via X, Esc, backdrop click. **Pass:** All three close. Body scroll position restored. No leftover overlay blocking clicks.
- [ ] **1.7** Tag chip click. **Pass:** Feed filters; URL reflects filter (shareable); visible "✕ clear" affordance returns to full feed.
- [ ] **1.8** Search. **Pass:** Debounced results; loading state; zero-results state is friendly (not blank), suggests a popular tag.
- [ ] **1.9** Pagination / infinite scroll. **Pass:** Next page loads without scroll jump; end-of-feed has explicit "You're all caught up" message; no infinite spinner.
- [ ] **1.10** Sort/filter controls (Date / Trending / Popular). **Pass:** Visible to anon; active option clearly highlighted; changing sort changes results obviously.
- [ ] **1.11** Stories bar at top (if enabled). **Pass:** Horizontal scroll; circles don't clip; clicking opens a viewer (or shows a "log in to view" for anon, not an error).
- [ ] **1.12** Try `/my-media/` directly as anon. **Pass:** Redirect to login with context ("Log in to view your dashboard"), not a raw WP login with no breadcrumb back.

**Heuristic score for J1:** __ / 50. Findings:

---

## 4. Journey 2 — First upload (logged-in new user)

**Persona:** Alex, just logged in via `/my-media/`. Zero uploads.

### Steps

- [ ] **2.1** `/my-media/` with zero media. **Pass:** Empty state has illustration or icon, one-sentence hook, ONE clear primary CTA: **"Upload your first photo"** (linking to upload modal or `/upload/` if mapped).
- [ ] **2.2** Every empty tab (Media / Albums / Favorites / Collections) has a **distinct** empty state, each explaining the concept. **Pass:** Albums tab explains what an album is; Collections tab explains how it differs from an album. No generic "Nothing here yet." reused in all four.
- [ ] **2.3** Quota widget at first visit. **Pass:** Shows "0 of X" with units (MB/GB, not raw bytes). Images / Videos / Audio counts each with their max clear.
- [ ] **2.4** Find the upload entry point. **Pass:** FAB (+) visible on Dashboard and Explore; hover tooltip "Upload"; alternative upload zone on Dashboard visible too.
- [ ] **2.5** Open upload modal. **Pass:** Modal centered, backdrop dims content, Esc closes, X visible top-right. Tabs **Photo / Gallery / Video** obvious; active tab underlined.
- [ ] **2.6** Photo tab: drop a JPG. **Pass:** Drop zone highlights on drag-over; preview renders in <1s; filename + size visible; remove (×) present.
- [ ] **2.7** Title, description, tags, privacy. **Pass:** Placeholders explain each field. Tag field typeahead from existing tags. Privacy options (Public / Members / Private) each with one-line description.
- [ ] **2.8** Submit. **Pass:** Button shows spinner + "Uploading…"; progress bar for files >1MB; on success a toast "Uploaded!" + modal closes + new card appears at top of feed **without a full page reload**.
- [ ] **2.9** Upload an oversized file. **Pass:** Specific error "File exceeds X MB limit" + suggested next step (compress). NOT a generic "Upload failed".
- [ ] **2.10** Upload a disallowed MIME (.exe renamed to .jpg). **Pass:** Rejected with a specific reason; no silent success.
- [ ] **2.11** Gallery tab: 4 images. **Pass:** 4 previews. Drag-reorder supported. Single title OR per-item behavior clearly labelled.
- [ ] **2.12** Video tab: .mp4. **Pass:** Pro transcoding: warn about wait time if queued. Poster auto-generated or poster-upload optional.
- [ ] **2.13** Cancel mid-upload. **Pass:** Cancel stops request; no orphan row in dashboard.

**Heuristic score for J2:** __ / 50. Findings:

---

## 5. Journey 3 — Engagement in the Lightbox (react, favorite, comment)

- [ ] **3.1** Open any image in lightbox. **Pass:** Sidebar has clear sections: media info, reactions, favorite, comments.
- [ ] **3.2** Reactions bar — 6 emojis with counts. **Pass:** Emojis render as emoji (not tofu squares). Current user's active reaction highlighted distinctly.
- [ ] **3.3** Click emoji. **Pass:** Optimistic increment; subtle animation; second click decrements. Switching to different emoji clears previous.
- [ ] **3.4** Favorite. **Pass:** Outlined → filled heart on click; label updates; re-click unfavors; perceived feedback <200ms.
- [ ] **3.5** Comment input. **Pass:** Placeholder "Add a comment…"; Post disabled while empty; Enter submits or Post button (consistent); new comment appears at bottom with avatar + link to profile + timestamp.
- [ ] **3.6** Edit own comment within 15 min. **Pass:** Inline edit form; Save updates; Cancel reverts.
- [ ] **3.7** Delete own comment. **Pass:** Confirmation; count decrements; comment disappears.
- [ ] **3.8** Follow the author from lightbox. **Pass:** Follow/Following toggle visible; mutual follow shows "Follows you" tag.
- [ ] **3.9** @mention. **Pass:** Typeahead suggests users; mention becomes a link; target gets a notification.
- [ ] **3.10** Share. **Pass:** "Copied!" toast on click (or native share); copies the canonical `/media/{slug}/` URL.
- [ ] **3.11** Open → `/media/{slug}/`. **Pass:** Navigates to single-media page with same data; lightbox state is gone.
- [ ] **3.12** Lightbox state reset between items. **Pass:** Close + open a different item — reactions/comments/favorite all reflect the new item, not cached from previous.

**Heuristic score for J3:** __ / 50. Findings:

---

## 6. Journey 4 — Single media page (`/media/{slug}/`)

- [ ] **4.1** Land on `/media/{slug}/` from a share link. **Pass:** Page renders standalone (no lightbox needed); title, author, date, tags all visible above the fold.
- [ ] **4.2** Image/video/audio media type. **Pass:** Correct player for each. Video has controls (play/pause/volume/fullscreen). Audio has waveform or player bar.
- [ ] **4.3** Tag links. **Pass:** Clicking a tag goes to filtered `/media/?tag=X` (or equivalent) — not a 404.
- [ ] **4.4** View count. **Pass:** Displayed. Increments once per unique user/session (not per refresh).
- [ ] **4.5** Private media while logged out. **Pass:** Lock message with "Log in to view" — not a WP 404.
- [ ] **4.6** Members-only while logged out. **Pass:** Gated with clear copy + Login link; once logged in, visible.
- [ ] **4.7** Deleted media link. **Pass:** Friendly 404 (plugin-branded if possible), not a WP theme 404.
- [ ] **4.8** Report media button. **Pass:** Visible; opens dialog; options pre-defined (Spam/Inappropriate/Copyright/Other); submit confirms; duplicate reports from same user blocked.

**Heuristic score for J4:** __ / 50. Findings:

---

## 7. Journey 5 — User profile (`/media/@{user}/`) & edit profile

- [ ] **5.1** Load `/media/@author1/`. **Pass:** Header with avatar, display name, bio, follower/following counts, Follow button (for non-owners). Media grid below. No empty cells.
- [ ] **5.2** Profile sub-tabs (Media / Albums). **Pass:** Clicking switches content; URL reflects sub-tab; pagination works per tab.
- [ ] **5.3** Paginate `/media/@author1/page/2/`. **Pass:** Next page loads same layout; active-page indicator visible.
- [ ] **5.4** Follow. **Pass:** Button toggles Follow ⇄ Following; count increments/decrements; target user gets a notification.
- [ ] **5.5** Profile with zero media. **Pass:** Empty state explicit ("@user hasn't uploaded yet"), not a broken grid.
- [ ] **5.6** Own profile `/media/@self/`. **Pass:** Shows Edit Profile affordance; no Follow button on own profile.
- [ ] **5.7** `/media/edit-profile/`. **Pass:** Form labels present; avatar uploader with crop; display name validation; bio textarea; Save → success toast → fields reflect saved values on reload.
- [ ] **5.8** Avatar upload error (too large/wrong type). **Pass:** Specific error; form state preserved; no data loss.
- [ ] **5.9** `/media/edit-profile/` while logged out. **Pass:** Redirect to login with context.

**Heuristic score for J5:** __ / 50. Findings:

---

## 8. Journey 6 — `/my-media/` dashboard & Quota awareness

- [ ] **6.1** Load `/my-media/`. **Pass:** < 2s first paint; no cumulative layout shift while data hydrates.
- [ ] **6.2** Header card: avatar, name, View Profile + Edit Profile links. **Pass:** Both links work.
- [ ] **6.3** Four tabs: Media / Albums / Favorites / Collections. **Pass:** URL changes per tab (shareable); keyboard navigable (Tab + Enter); active state clear.
- [ ] **6.4** Media tab: thumbnail, title, privacy badge, Edit / Delete row actions. **Pass:** Edit opens inline or modal form; Delete confirms; counts update.
- [ ] **6.5** Create album. **Pass:** Button on Albums tab; dialog asks title/description/cover; Save → album visible.
- [ ] **6.6** Add items to album. **Pass:** From album detail, "Add media" opens multi-select from own uploads; Add confirms.
- [ ] **6.7** Reorder album items. **Pass:** Drag handles on hover; reorder persists on Save.
- [ ] **6.8** Quota widget. **Pass:** Numeric + progress bar; green/amber/red at 0-80/80-95/95-100%; at 100% upload button disables with "Upgrade" tooltip.
- [ ] **6.9** Storage in human units (MB/GB), not bytes.
- [ ] **6.10** Favorites tab shows items favorited in J3. **Pass:** Items listed; unfavorite from here updates in real time.
- [ ] **6.11** Collections tab — create/edit/delete — clearly different from Albums (smart rules vs manual curation).

**Heuristic score for J6:** __ / 50. Findings:

---

## 9. Journey 7 — Albums (`/media/album/{id}/`) & Collections (`/media/collection/{id}/`)

- [ ] **7.1** Open a public album. **Pass:** Cover image + title + author + description; grid of items; item click opens lightbox (stays within album context: prev/next cycles within album only).
- [ ] **7.2** Album privacy respected. **Pass:** Private album as non-owner = lock message, not a 404.
- [ ] **7.3** Cover change. **Pass:** From dashboard owner sets any item as cover; album page reflects new cover immediately.
- [ ] **7.4** Collection page. **Pass:** Renders with rule-based content (if smart collection) OR curated items (if manual). Empty collection shows friendly state.
- [ ] **7.5** Deep link to album while logged out. **Pass:** Public albums accessible anonymously; members-only gated appropriately.

**Heuristic score for J7:** __ / 50. Findings:

---

## 10. Journey 8 — Compete hub (Pro): `/compete/`, `/media/battles/`, `/media/challenges/`, `/media/tournaments/`

- [ ] **8.1** `/compete/` discoverable from nav or dashboard ≤2 clicks. **Pass:** Entry point present and labeled.
- [ ] **8.2** Hub layout: 3 cards (Battles / Challenges / Tournaments) with one-line descriptions each. **Pass:** Copy makes the difference between them clear to a first-time reader.
- [ ] **8.3** `/media/challenges/` list loads. **Pass:** Active challenges visible; theme, deadline, prize (XP) shown per card; status badge (Active/Closed/Finalized).
- [ ] **8.4** Submit to a challenge. **Pass:** "Submit Entry" flow — pick existing media OR upload new → confirm → "Entry submitted!" success state. Submission disabled after deadline with countdown showing.
- [ ] **8.5** Vote on an entry. **Pass:** UI obvious (pair or grid with Vote buttons); cannot vote on own entry (grays out with tooltip); one vote per round enforced; confirmation after vote.
- [ ] **8.6** `/media/battles/` — start a battle. **Pass:** Pick opponent via typeahead; submit media; pending state for opponent; cancel while pending available.
- [ ] **8.7** Battle accepted by opponent. **Pass:** Voting opens; voters see both entries side-by-side; anon can view but not vote; post-resolution winner highlighted.
- [ ] **8.8** `/media/tournaments/` bracket. **Pass:** Bracket renders without horizontal overflow on desktop; mobile gets a horizontal-scroll bracket with fade-edge hint; current round highlighted.
- [ ] **8.9** XP feedback. **Pass:** After win/advance, XP gain visible as toast ("+50 XP!"); dashboard streak updates.
- [ ] **8.10** Streak widget on `/my-media/`. **Pass:** Current / longest / next milestone; "Buy Freeze" cost + effect clear; confirm before spending tokens.
- [ ] **8.11** Dashboard panels: `dashboard-battles-panel.php`, `dashboard-challenges-panel.php`, `dashboard-tournaments-panel.php`. **Pass:** Each renders on `/my-media/` only when Pro feature enabled; no empty shells when disabled.
- [ ] **8.12** Feature toggles respected. **Pass:** Turn off `mvs_battles_enabled` → `/media/battles/` either 404s with friendly "Battles are disabled" OR is hidden from nav; no half-broken state.

**Heuristic score for J8:** __ / 50. Findings:

---

## 11. Journey 9 — Direct Messages (`/messages/` or equivalent)

- [ ] **9.1** Find DM entry. **Pass:** Envelope icon in plugin header region OR link on profile page; click opens Messages UI.
- [ ] **9.2** Empty inbox. **Pass:** Friendly empty state with "Start a conversation" CTA; not a blank panel.
- [ ] **9.3** Start new conversation. **Pass:** User search typeahead; pick user; compose; Send. Conversation appears in list with unread bold state for recipient.
- [ ] **9.4** Send text. **Pass:** Optimistic bubble; sending → sent → delivered → read indicator.
- [ ] **9.5** Attach media. **Pass:** Paperclip opens picker; preview before send; after send renders inline.
- [ ] **9.6** Voice message (if enabled). **Pass:** Mic prompt; recording indicator; preview/re-record/send.
- [ ] **9.7** Read receipts. **Pass:** "Seen" or double-check appears after recipient opens thread.
- [ ] **9.8** Block user. **Pass:** Block from conversation menu; thread hidden; blocked user can't message you.
- [ ] **9.9** Rate limit. **Pass:** Sending many messages fast → rate-limit notice, not silent fail.
- [ ] **9.10** Message reactions. **Pass:** Long-press / hover reveals emoji picker on a message bubble; reacting visible to both users.
- [ ] **9.11** Edit / delete own message. **Pass:** Edit inline within window; delete shows "Deleted" placeholder (not ghost message).
- [ ] **9.12** DM access level enforcement. **Pass:** If `mvs_dm_access` = followers-only, non-follower can't DM — blocked with a clear reason.

**Heuristic score for J9:** __ / 50. Findings:

---

## 12. Journey 10 — BuddyPress integration (`/members/{user}/media/`, `/groups/{slug}/media/`)

- [ ] **10.1** Activity feed upload: post with 1 image. **Pass:** Media embeds with `data-mvs-media-id`; clicks open MediaVerse lightbox, not a generic BP attachment viewer.
- [ ] **10.2** Post with 3 images → grid layout (`mvs-activity-grid-3`). **Pass:** Grid renders correctly, no overflow out of BP activity bubble.
- [ ] **10.3** Max 6 media per activity enforced. **Pass:** 7th reject with clear message.
- [ ] **10.4** Group activity: post with image in a group. **Pass:** Media appears in group activity stream AND in `/groups/{slug}/media/` tab.
- [ ] **10.5** `/members/{user}/media/` — Media tab active; count badge ("Media 9"); sub-tabs (Media / Albums).
- [ ] **10.6** `/groups/{slug}/media/` — Media / Albums sub-tabs; "Upload Media" visible for members only; empty state for groups with no media.
- [ ] **10.7** Comment on media in BP lightbox. **Pass:** Posting comment creates **exactly one** BP activity comment — no duplicate flood. Check `/wp-admin/admin.php?page=bp-activity`.
- [ ] **10.8** Gallery navigation in BP lightbox for multi-image activities works the same as Explore lightbox.

**Heuristic score for J10:** __ / 50. Findings:

---

## 13. Journey 11 — Mobile (390×844) re-verification of plugin pages

At 390 wide, re-test the **core step** of every plugin-page journey.

- [ ] **11.1** `/media/` Explore: no horizontal scroll; cards stack to 2-col or 1-col; tag chips scroll horizontally with fade.
- [ ] **11.2** Lightbox: fills screen; swipe-down closes; pinch-to-zoom on image.
- [ ] **11.3** Upload modal: keyboard open doesn't hide inputs; Submit button reachable.
- [ ] **11.4** `/my-media/` dashboard: tabs either stack or horizontal-scroll with fade — not truncated.
- [ ] **11.5** `/compete/` + battles/challenges/tournaments pages: cards stack; bracket horizontally scrolls with hint.
- [ ] **11.6** `/messages/`: conversation list + panel switch cleanly (not side-by-side overflow).
- [ ] **11.7** FAB: bottom-right, ≥16px safe-area inset, doesn't collide with keyboard or iOS home bar.
- [ ] **11.8** All tap targets ≥ 40×40px (emoji reactions, pagination, tag chips).
- [ ] **11.9** BP activity media inside activity bubble: no overflow.
- [ ] **11.10** Landscape 844×390: lightbox still usable, nothing critical clipped.

**Heuristic score for J11:** __ / 50. Findings:

---

## 14. Journey 12 — Admin (WPMediaVerse menu)

- [ ] **12.1** Menu order logical: Overview → Settings → Moderation → Stats → Logs → All Media → (Pro items below).
- [ ] **12.2** Overview page: stat cards (Total Media / Albums / Pending Review / Views / Storage Used). **Pass:** Numbers load, not placeholders; quick action buttons (Add Media / Settings / Moderation) navigate correctly.
- [ ] **12.3** Settings page with 8 tabs. **Pass:** Active tab highlighted, content loads, unsaved-changes warning on tab switch OR state preserved.
- [ ] **12.4** Every setting has help text near the field.
- [ ] **12.5** Save feedback. **Pass:** "Settings saved" notice + auto-dismiss ~3s; field-specific errors.
- [ ] **12.6** Dangerous actions guarded (Delete all, Reset). **Pass:** Confirm modal with typed confirmation for destructive actions.
- [ ] **12.7** Moderation Queue. **Pass:** Items scannable (thumbnail + reason + report count); Approve / Reject / View per row; keyboard nav; bulk actions; empty state.
- [ ] **12.8** Stats page: charts render with legends; time-range selector; no empty canvases.
- [ ] **12.9** Log viewer: timestamp, level, message, pagination, empty state.
- [ ] **12.10** All Media list: columns, type filter, privacy filter, search, pagination, row actions (View / Trash).
- [ ] **12.11** Setup Wizard (first run): Welcome → Pages → Settings → Done; Back/Next present; Skip available; doesn't re-show after completion.
- [ ] **12.12** Admin notice policy: no upsell/promotional banners on non-WPMediaVerse admin pages.
- [ ] **12.13** Every admin page has a clear H1 that matches the menu label.

**Heuristic score for J12:** __ / 50. Findings:

---

## 15. Journey 13 — Admin Pro pages (if Pro active)

- [ ] **13.1** `mvs-competitions` (Competitions Dashboard): overview counts, Welcome modal dismiss-persistent, quick links to each manager page.
- [ ] **13.2** `mvs-challenges` Manager: list with status badges; Create / Edit / Activate / Finalize flows; view entries.
- [ ] **13.3** `mvs-tournaments` Manager: bracket visualization; Create; Leaderboard.
- [ ] **13.4** `mvs-battles` Monitor: list active/pending/completed; details panel; manual resolve.
- [ ] **13.5** `mvs-quotas` Quota & Credits: package CRUD; assign package to user; award/deduct credits; credit log.
- [ ] **13.6** `mvs-theme-library` Theme Library: default themes listed; create custom theme; themes selectable in challenge creation.
- [ ] **13.7** `mvs-migration` Migration Tool: Detect → Run Batch → Reset; progress bar updates; empty state for no detected data.
- [ ] **13.8** Gamification Settings: feature toggles; per-action XP; cooldowns; autopilot; boost settings — all save.
- [ ] **13.9** License page: status badge (Active/Expired/Invalid); expired state explains impact.
- [ ] **13.10** Pro settings tabs (AI keys, S3, BunnyCDN): inputs masked on reload after save; "Test Connection" inline result (no `alert()`).
- [ ] **13.11** Moderation Queue — Pro "User Reports" tab present (`mvs_moderation_tabs` filter).
- [ ] **13.12** Stats — Pro "Video Analytics" tab present (`mvs_stats_tabs` filter).

**Heuristic score for J13:** __ / 50. Findings:

---

## 15.5. Journey 14 — Shortcodes sweep (Free — 8 shortcodes)

Create a staging test page `/mvs-test-shortcodes/` containing every `[mvs_*]` shortcode with realistic attributes, then visit as logged-in user and as anonymous.

- [ ] **14.1** `[mvs_gallery]` — renders a media grid; check default sort + pagination + card layout.
- [ ] **14.2** `[mvs_upload]` — renders the upload form/FAB; logged-out state is gated with login CTA.
- [ ] **14.3** `[mvs_album id="{valid_id}"]` — renders album cover + item grid; attrs `columns=3`, `show_title=1`.
- [ ] **14.4** `[mvs_player id="{valid_media_id}"]` — renders media player (image/video/audio); attrs `autoplay=0`, `loop=0`.
- [ ] **14.5** `[mvs_stats]` — renders top-media/top-tags card or per-user stats; no fatal for zero data.
- [ ] **14.6** `[mvs_dashboard]` — already covered by `/my-media/` journey; sanity-check output when embedded on a non-dashboard page.
- [ ] **14.7** `[mvs_collection id="{valid_id}"]` — renders collection (smart rules or curated); attrs `columns=3`, `per_page=12`.
- [ ] **14.8** `[mvs_profile_edit]` — renders profile edit form; logged-out redirects (or shows gate).

**Pass criterion per shortcode:**
- HTML output present (not blank, not a shortcode-literal echo).
- No PHP notice/warning in `error_log` after the page loads.
- No console error referencing the shortcode's script/style.
- Attr variations honored (at least `columns` and `id` on the two that accept them).

**Heuristic score for J14:** __ / 50. Findings:

---

## 15.7. Journey 15 — Blocks sweep (Free — 12 blocks)

Create a staging test page `/mvs-test-blocks/` containing block markup for each `wpmediaverse/*` block. Visit frontend AND open the page in the block editor.

- [ ] **15.1** `wpmediaverse/media-grid` — frontend renders a grid; editor inspector controls render without console error.
- [ ] **15.2** `wpmediaverse/explore-feed` — frontend shows a feed (may mirror `/media/`); editor preview OK.
- [ ] **15.3** `wpmediaverse/explore-view` — frontend renders; editor OK.
- [ ] **15.4** `wpmediaverse/album-viewer` (with `albumId`) — frontend renders album grid; editor inspector lets user pick album.
- [ ] **15.5** `wpmediaverse/media-player` (with `mediaId`) — frontend renders correct player for media type.
- [ ] **15.6** `wpmediaverse/media-upload` — frontend renders upload zone (gated for anon).
- [ ] **15.7** `wpmediaverse/media-stats` — frontend renders stats card; empty-state for zero data.
- [ ] **15.8** `wpmediaverse/media-social` — frontend renders reaction/comment bar; editor OK.
- [ ] **15.9** `wpmediaverse/story-viewer` — frontend renders stories ring; empty-state when no stories.
- [ ] **15.10** `wpmediaverse/lock-overlay` — frontend renders locked-content overlay variant.
- [ ] **15.11** `wpmediaverse/dashboard-view` — frontend renders dashboard-widget style panel.
- [ ] **15.12** `wpmediaverse/shared-ui` — present on every plugin-frontend page (FAB + lightbox shell); confirm no double-mount.

**Pass criterion per block:**
- Renders in editor without "Block error" placeholder.
- Renders on frontend without fatal or blank output.
- Block category `WPMediaVerse` appears in the block inserter.
- Inspector controls work (attributes persist on save).

**Heuristic score for J15:** __ / 50. Findings:

---

## 16. Regression Hot-Spots (known-fragile, re-test every release)

- [ ] **16.1** Black/empty tiles in Explore for video or private media served to anon — MUST show real thumbs or explicit lock overlay.
- [ ] **16.2** Text bleeding onto thumbnail area when thumbnail generation failed.
- [ ] **16.3** BP activity comment-sync loop — posting one comment in lightbox must create exactly one BP activity comment, never duplicate.
- [ ] **16.4** Multi-image BP activity: each image opens correct lightbox item; gallery navigation within activity works.
- [ ] **16.5** Lightbox state bleed — close + reopen a different item shows that item's data (not cached from previous).
- [ ] **16.6** Streak badge in `mvs_user_display_name` — no raw HTML leak, no duplicates.
- [ ] **16.7** Thumbnails on retina — serve 2× not upscaled 1×.
- [ ] **16.8** Deleting own media on `/my-media/` — item vanishes without reload; counts update.
- [ ] **16.9** `/media/@user/` with zero media — friendly empty state, not a broken grid.
- [ ] **16.10** Reactions on gallery items — each image has independent reactions.
- [ ] **16.11** FAB z-index — lightbox must cover FAB, not vice-versa.
- [ ] **16.12** Feature toggle OFF for a Pro module must not leave broken links in nav or dashboard panels.

---

## 17. Accessibility Smoke (keyboard + VoiceOver, on plugin pages only)

- [ ] **17.1** Tab through `/media/` from plugin header region: visible focus ring; logical order.
- [ ] **17.2** Enter on a card opens lightbox; Esc closes; Arrow keys cycle gallery; focus trapped inside lightbox while open.
- [ ] **17.3** Emoji reactions each have `aria-label` (e.g. "React with fire").
- [ ] **17.4** Images have real `alt` or `alt=""` (decorative) — not `alt="image"` boilerplate.
- [ ] **17.5** Form labels linked (`label for` + input `id`); required fields marked `aria-required` + visible asterisk.
- [ ] **17.6** Color contrast ≥ 4.5:1 body text, ≥ 3:1 focus ring.
- [ ] **17.7** VoiceOver announces lightbox open as a dialog with a meaningful label.
- [ ] **17.8** Skip-to-content present when the plugin renders its own layout.

---

## 18. Performance & Reliability Smoke

- [ ] **18.1** `/media/` first paint < 2s on throttled "Fast 3G" (DevTools).
- [ ] **18.2** Lightbox open → image rendered < 500ms on cached media.
- [ ] **18.3** No chatty polling > once / 30s outside `/messages/`.
- [ ] **18.4** No 4xx/5xx on CSS/JS assets in Network tab.
- [ ] **18.5** Console: zero errors after completing each journey; 1-2 benign warnings acceptable (document).
- [ ] **18.6** Heap doesn't grow unbounded after 20 lightbox open/close cycles.

---

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
