# WPMediaVerse — Release Plan

**Created:** 2026-03-23
**Plugin Version:** 1.0.0 (unreleased)
**Commits:** 96 (no tags, no releases)
**Basecamp Done Cards:** 112
**Target:** WordPress.org submission + Pro launch on wbcomdesigns.com

---

## Roadmap vs Reality — What's Done, What's Not

### Free 1.0.0 (Roadmap: "COMPLETE")

| Item | Roadmap | Codebase | Status |
|------|---------|----------|--------|
| 3 CPTs (media, album, collection) | Yes | Yes | DONE |
| 9 custom DB tables | Yes | 23 tables (grew significantly) | DONE+ |
| 15 capabilities | Yes | 17 capabilities | DONE+ |
| Upload + MIME + EXIF + dedup | Yes | Yes | DONE |
| 6-level privacy | Yes | Yes + template-level gate | DONE |
| Social: reactions, comments, favorites, mentions, sharing | Yes | Yes (6 reaction types) | DONE |
| Albums, playlists, collections, stories | Yes | Yes | DONE |
| AI moderation (OpenAI Vision) | Yes | Yes + approve/reject queue | DONE |
| 8 Gutenberg blocks | Yes | 13 blocks (5 extra) | DONE+ |
| 6 shortcodes | Yes | 8 shortcodes | DONE+ |
| 4 templates | Yes | 15 templates (7 main + 8 partials) | DONE+ |
| BuddyPress integration | Yes | Activity, profiles, groups, notifications | DONE |
| 40+ REST endpoints | Yes | 58 endpoints | DONE+ |
| WP-CLI (8 commands) | Yes | CLI\Commands exists (placeholder) | PARTIAL |
| Webhooks (HMAC-SHA256) | Yes | Yes | DONE |
| rtMedia import | Yes | Moved to Pro | MOVED |
| Access rules + signed URLs | Yes | Yes | DONE |
| 154+ PHPUnit tests | Yes | Tests existed, current count unknown | VERIFY |

### Free 1.1.0 Sprint 1: Media Playback

| ID | Feature | Status | Evidence |
|----|---------|--------|----------|
| F1 | Video/audio metadata extraction | **DONE** | UploadService, MediaController, BPIntegration |
| F2 | Video thumbnails | **DONE** | BuddyPressIntegration `get_media_thumbnail_html()` |
| F3 | Custom media player | **DONE** | `src/blocks/media-player/` (6 files, Interactivity API) |
| F4 | Playlist/sequential playback | **DONE** | `templates/album.php` (sequential, auto-advance) |
| F5 | Play event tracking | **NOT DONE** | No `mvs_play_events` table in free, no play-event REST endpoint. Pro has `AnalyticsService` but free doesn't. |

### Free 1.1.0 Sprint 2: Social Foundation

| ID | Feature | Status | Evidence |
|----|---------|--------|----------|
| F8 | Notification system | **DONE** | `NotificationService`, `NotificationController`, `mvs_notifications` table |
| F9 | User profiles | **DONE** | `UserController`, `ProfileController`, @username pages |
| F10 | Follow system | **DONE** | `FollowService`, `FollowController`, `mvs_follows` table |
| F11 | Report & Block | **DONE** | `ReportService`, `ReportController`, `mvs_reports` + `mvs_blocks` tables |
| F12 | Standalone activity feed | **DONE** | `ActivityService`, `ActivityController`, `mvs_activity` table |
| F13 | User discovery | **DONE** | `UserController` with `/users/search` endpoint |

### Free 1.1.0 Sprint 3: UX Polish

| ID | Feature | Status | Evidence |
|----|---------|--------|----------|
| F14 | Comment editing (15-min window) | **DONE** | `CommentController` |
| F15 | Cursor-based pagination | **NOT DONE** | No cursor/next_cursor in any controller |
| F16 | Draft/scheduled media | **DONE** | `UploadService`, `MediaController` |
| F6 | Settings Pro indicators | **DONE** | `SettingsPage` has Pro upsell/badge |
| F7 | Abilities API (WP 6.9) | **DONE** | `Abilities.php` (17 abilities) |

### Bonus (not in roadmap, built anyway)

| Feature | Status | Evidence |
|---------|--------|----------|
| Standalone DM engine | **DONE** | 5 Messaging classes, 18 REST endpoints, 4 DB tables |
| @username profile pages | **DONE** | `/media/@{username}/` routes |
| Setup wizard | **DONE** | `SetupWizard.php` |
| Demo data importer | **DONE** | 18 stock images, one-click import |
| Stats page + CSV export | **DONE** | `StatsPage.php` with date range |
| Log viewer | **DONE** | `LogViewerPage.php` |
| Plugin-level theme.json | **DONE** | Design tokens via `wp_theme_json_data_default` |
| Rate limiter | **DONE** | 100/min default, per-endpoint |
| Health check service | **DONE** | `HealthCheckService.php` |
| Bulk actions | **DONE** | `BulkController` |
| Signed URL service | **DONE** | HMAC-protected file access |

### Free 1.2.0: Discovery & Intelligence

| ID | Feature | Status |
|----|---------|--------|
| F17 | Trending algorithm | **NOT DONE** |
| F18 | Hashtag pages | **NOT DONE** |
| F19 | Comment likes | **NOT DONE** |
| F20 | Story enhancements | **NOT DONE** |
| F21 | Advanced search | **NOT DONE** |
| F22 | Media carousel block | **NOT DONE** |
| F23 | User preferences table | **NOT DONE** |

### Pro 1.0.0: Monetization + Storage

| ID | Feature | Status | Evidence |
|----|---------|--------|----------|
| P-S1 | Plugin scaffold + license | **DONE** | EDD SL SDK, activation check |
| P-S2 | Settings extension | **DONE** | `ProSettings` extends free settings |
| P-PAY1 | Stripe payment gateway | **NOT DONE** | Removed per user decision (credits via admin/webhook only) |
| P-PAY2 | WooCommerce integration | **NOT DONE** | Removed per user decision |
| P-ST1 | Amazon S3 storage | **DONE** | `S3Driver` (Signature V4, no SDK) |
| P-ST2 | BunnyCDN storage | **DONE** | `BunnyCDNDriver` |
| P-WM1 | Image watermarking | **DONE** | `Watermarker` (GD-based) |
| P-PRV1 | Advanced privacy UI | **DONE** | `PrivacyUIService` + `PrivacyController` |
| P-ACC1 | Access rules frontend | **PARTIAL** | Backend done, no earnings dashboard (removed) |
| P-ADM1 | Pro admin assets | **DONE** | Connection tester, conditional fields |

### Pro 1.1.0: AI + Video Intelligence

| ID | Feature | Status | Evidence |
|----|---------|--------|----------|
| P-AI1 | Google Vision | **DONE** | `GoogleVisionProvider` |
| P-AI2 | AWS Rekognition | **DONE** | `RekognitionProvider` + circuit breaker |
| P-AI3 | AI fallback chain | **PARTIAL** | Two providers exist, no auto-fallback chain |
| P-VID1 | Video transcoding | **DONE** | `TranscodeService` (FFmpeg, HLS) |
| P-VID2 | Chapters + resume | **DONE** | `ChapterService`, `ResumeService` |
| P-VID3 | Video analytics | **DONE** | `AnalyticsService` (heatmaps, sessions) |
| P-VID4 | Auto-captions | **DONE** | `TranscriptionService` (Whisper) |
| P-MSG1 | Direct messaging | **DONE** | Full DM engine (moved to free, Pro adds voice/rich) |
| P-PUSH1 | Push notifications | **NOT DONE** | No Firebase/APNs code |
| P-GATE1 | Email gates | **DONE** | `mvs_email_leads` table exists |

---

## Summary: Roadmap Completion

| Milestone | Total Items | Done | Not Done | % |
|-----------|------------|------|----------|---|
| Free 1.0.0 | 17 | 16 | 1 (WP-CLI placeholder) | 94% |
| Free 1.1.0 Sprint 1 | 5 | 4 | 1 (F5 play events) | 80% |
| Free 1.1.0 Sprint 2 | 6 | 6 | 0 | 100% |
| Free 1.1.0 Sprint 3 | 5 | 4 | 1 (F15 cursor pagination) | 80% |
| Free 1.2.0 | 7 | 0 | 7 | 0% |
| Pro 1.0.0 | 10 | 7 | 3 (Stripe, WooCommerce deliberately removed; ACC1 partial) | 70% |
| Pro 1.1.0 | 10 | 8 | 2 (push notifications, AI fallback chain) | 80% |

**Gaps blocking v1.1.0 release (Free):**
1. F5: Play event tracking in free (REST endpoint + table)
2. F15: Cursor-based pagination across list endpoints

**Gaps blocking v1.0.0 release (Pro):**
1. P-PUSH1: Push notifications (Firebase/APNs) — can defer to Pro 1.1.0
2. P-AI3: AI provider fallback chain — nice-to-have, can defer

**Deliberately removed (per user decision):**
- Stripe payment gateway (P-PAY1) — credits via admin grants/webhook only
- WooCommerce integration (P-PAY2) — same reason
- Earnings dashboard — users don't sell media

---

## What's Built (Free Plugin)

### Core Architecture
- PSR-4 autoloader (`WPMediaVerse\` namespace), Composer vendor
- ServiceContainer (22 lazy-loaded services)
- Version-based DB Migrator (v1–v6, 23 custom tables)
- EDD SL SDK for update delivery from wbcomdesigns.com
- WP 6.9 Abilities API (17 abilities for mobile/AI)

### Post Types & Taxonomies
- `mvs_media` — Core media CPT (image, video, audio, document)
- `mvs_album` — Sequential media collections
- `mvs_collection` — Curated/smart collections with rules
- `mvs_tag`, `mvs_category` — Custom taxonomies

### REST API (58 endpoints under `mvs/v1`)
- MediaController — CRUD + search + duration extraction
- AlbumController — CRUD + reorder + items + cover
- CollectionController — CRUD + rules
- ReactionController — 6-type reactions (like/love/haha/wow/sad/angry)
- CommentController — Comments + 15-min edit window + scheduling
- FavoriteController — Add/remove favorites
- FollowController — Follow/unfollow + follower/following lists
- ReportController — Report content (media, comment, user)
- NotificationController — Get + mark read
- StatsController — View/engagement stats + date range
- TagController — CRUD + tagged media
- UserController — Profile + user media + search
- ProfileController — Bio, avatar, custom avatar upload
- ModerationController — AI queue (approve/reject)
- AccessController — Role/user/group access rules
- SignedUrlController — HMAC-protected file URLs
- ActivityController — Activity feed
- BulkController — Bulk delete, privacy changes
- MessagingController — 18 DM endpoints (conversations, messages, reactions, polling)

### Social Layer
- Reactions (6 types), Comments (editing, scheduling), Favorites
- Follow system (native `mvs_follows` table, no BP dependency)
- @mentions (comments + BP activity), Reports + Blocks
- Notifications (typed, per-actor), Activity feed
- Standalone DM engine (REST polling, adaptive 3–30s)

### Blocks (13 Gutenberg blocks)
- media-grid, media-player, explore-feed, album-viewer
- media-upload, story-viewer, lock-overlay, dashboard-view
- media-social, explore-view, media-stats, shared-ui, blocks
- All compiled to `build/blocks/` via wp-scripts
- 6 use Interactivity API script modules

### Templates (15)
- 7 main: dashboard, explore, media-single, album, collection, profile-edit, messages
- 8 partials: dashboard-content, chat-list, chat-conversation, chat-composer, chat-panel, chat-new, chat-message, chat-media-card

### Shortcodes (8)
- `[mvs_gallery]`, `[mvs_upload]`, `[mvs_album]`, `[mvs_player]`
- `[mvs_stats]`, `[mvs_dashboard]`, `[mvs_collection]`, `[mvs_profile_edit]`

### Admin (8 pages)
- Overview (welcome banner, demo importer)
- Settings (General, Display, Permissions, AI, Webhooks)
- Moderation queue, Stats (date range + CSV), Logs
- Setup Wizard, Collection MetaBox

### Integrations
- BuddyPress: activity stream, notifications, group media, user profiles
- Webhooks: HMAC-SHA256 signed, Action Scheduler delivery
- No hard dependencies — everything works standalone

### Assets
- 3 CSS (admin, frontend, messaging)
- 4 JS (messaging, lightbox, profile-edit, BP activity)
- 18 demo images for one-click import

### Security
- 17 custom capabilities (upload, edit, delete, moderate, manage, view_stats, etc.)
- Rate limiter (100/min default, per-endpoint configurable)
- HMAC signed URLs for private media
- Input sanitization, output escaping, nonce verification throughout
- EXIF stripping (GPS/device data removal)

---

## What's Built (Pro Plugin)

### Core
- 37 PHP classes, 50+ source files
- Hooks into free via `mvs_loaded` action
- Dependency: free ≥ 1.0.0 (`MVS_VERSION` check)
- EDD licensing (wbcomdesigns.com)

### REST API (38+ endpoints under `mvs-pro/v1`)
- Quota: packages, credits, webhook, usage check
- Video: chapters, resume, transcode, analytics, heatmaps
- Captions: Whisper auto-transcription
- Privacy: advanced controls, presets, bulk
- Messaging: 18 DM endpoints (mirrored from free)

### Features
- **Quota system** — packages, per-type limits, credit grants, HMAC webhook
- **Cloud storage** — S3 (Signature V4, no SDK), BunnyCDN
- **AI providers** — Google Vision, AWS Rekognition (+ circuit breaker)
- **Video intelligence** — chapters, resume playback, FFmpeg transcoding, auto-thumbnails
- **Auto-captions** — OpenAI Whisper via Action Scheduler
- **Video analytics** — play events, heatmaps, session tracking
- **Advanced privacy** — album inheritance, presets, bulk controls
- **GD watermarking** — position, opacity, text overlay
- **Instagram feed** — stories bar, feed cards, double-tap like
- **User profiles** — `/media/@{username}/` pages
- **Migration tools** — rtMedia, MediaPress, BuddyBoss importers (WP-CLI + admin UI)

### Database (9 tables)
- mvs_quota_packages, mvs_credit_log, mvs_play_events
- mvs_email_leads, mvs_transactions
- mvs_conversations, mvs_conversation_participants, mvs_messages, mvs_message_reactions

---

## What's in Scope (Basecamp — 11 cards, future app features)

These are scoped but NOT built — mobile app API features:

1. **App: JWT/OAuth2 Authentication** — Token-based auth for mobile
2. **App: Follow/Unfollow System** — Already built in free (mvs_follows), needs cursor pagination
3. **App: Feed Algorithm** — Trending/recommended/following feeds with caching
4. **App: User Discovery + Search** — Already partially built (UserController)
5. **App: Push Notifications** — Firebase + APNs device registration
6. **App: Direct Messaging** — Already built (DM engine), needs WebSocket transport
7. **App: Notification Inbox API** — Already built (NotificationController), needs cursor pagination
8. **App: Report/Block System** — Already built (ReportService + blocks table)
9. **App: Responsive Image Pipeline** — srcset generation, WebP/AVIF, blur hashes
10. **App: Cursor-Based Pagination** — Replace page numbers across all list endpoints
11. **Pro: Standalone DM/Chat V1** — Voice messages, rich composer (V2 spec)

**Status:** Most API foundations already exist. Main gaps are cursor pagination, push notifications, feed algorithm, and image pipeline.

---

## Pre-Release Checklist

### Phase 1: Code Quality (MUST before release)

- [ ] Run WPCS on entire `includes/` directory — fix all errors
- [ ] Run WPCS on `templates/` — fix all errors
- [ ] Run `php -l` on all PHP files — zero syntax errors
- [ ] Run PHPStan level 5+ (create phpstan.neon if missing)
- [ ] Run `npm run build` — verify all 13 blocks compile
- [ ] Check `npm run lint:js` and `npm run lint:css`
- [ ] Verify no `console.log` / debug output in JS
- [ ] Verify no `error_log()` / `var_dump()` in PHP
- [ ] Check all REST endpoints return proper WP_Error on failure
- [ ] Verify all DB queries use `$wpdb->prepare()`
- [ ] Check no direct `$_GET`/`$_POST` without sanitization

### Phase 2: Feature Completeness Verification

- [ ] Upload flow: image, video, audio — all types work
- [ ] Upload: privacy selector (public/members/private)
- [ ] Upload: duplicate detection (warn/skip/allow)
- [ ] Upload: EXIF stripping works
- [ ] Gallery: grid displays, pagination works
- [ ] Gallery: lightbox opens, navigation works
- [ ] Single media: reactions work (all 6 types)
- [ ] Single media: comments work (add, edit within 15 min, delete)
- [ ] Single media: share button + clipboard fallback
- [ ] Single media: privacy gate blocks unauthorized access
- [ ] Single media: delete with confirm dialog
- [ ] Albums: create, add items, reorder, set cover
- [ ] Albums: sequential playback works
- [ ] Collections: create manual + smart, add rules
- [ ] Follow: follow/unfollow, follower/following lists
- [ ] Mentions: @username in comments triggers notification
- [ ] Notifications: all types appear, mark as read
- [ ] DM: send text message, receive, read receipts
- [ ] DM: share media in chat (rich card)
- [ ] DM: message requests for non-followers
- [ ] DM: mute/pin/archive conversations
- [ ] DM: delete for me / unsend for everyone
- [ ] Profile: edit name, bio, avatar
- [ ] Profile: @username public page shows grid
- [ ] Dashboard: my media, stats, quick actions
- [ ] Explore: search, filter by type/tag
- [ ] Stats: admin stats page with date range + CSV
- [ ] Moderation: AI queue shows, approve/reject works
- [ ] Settings: all tabs save correctly
- [ ] Setup wizard: runs on first activation
- [ ] Demo data: imports 18 images successfully
- [ ] Shortcodes: all 8 render correctly
- [ ] BuddyPress: activity shows media uploads (when BP active)
- [ ] BuddyPress: group media works (when BP active)
- [ ] Standalone: everything works without BuddyPress

### Phase 3: Browser Testing

- [ ] Desktop Chrome (1920x1080) — all pages
- [ ] Desktop Firefox — all pages
- [ ] Tablet (768x1024) — responsive layout
- [ ] Mobile (375x812) — responsive layout
- [ ] Dark mode — all pages
- [ ] RTL — all pages
- [ ] Admin pages: Overview, Settings, Moderation, Stats, Logs
- [ ] Frontend: Explore, Upload, Dashboard, Single, Album, Messages, Profile

### Phase 4: Version & Release

- [ ] Bump version to 1.1.0 in:
  - `wpmediaverse.php` header (`Version: 1.1.0`)
  - `MVS_VERSION` constant
  - `readme.txt` (`Stable tag: 1.1.0`)
  - `package.json`
- [ ] Generate changelog from 96 commits
- [ ] Update `readme.txt` description, FAQ, screenshots
- [ ] Run `npm run build` (production)
- [ ] Create `.distignore` for ZIP packaging:
  ```
  .git
  .github
  node_modules
  src/blocks
  tests
  .eslintrc
  .prettierrc
  phpcs.xml
  phpstan.neon
  composer.json
  composer.lock
  package.json
  package-lock.json
  webpack.config.js
  ```
- [ ] Build ZIP: `git archive` or rsync excluding distignore
- [ ] Tag: `git tag v1.1.0`
- [ ] Push: `git push origin main --tags`

### Phase 5: Pro Plugin Release

- [ ] Verify Pro works with free 1.1.0
- [ ] Bump Pro version (1.0.0 → 1.0.0 first release)
- [ ] All Pro REST endpoints return proper errors
- [ ] Quota enforcement works (limits, credits, webhook)
- [ ] S3/BunnyCDN connection tester passes
- [ ] Transcoding works (FFmpeg detected + jobs complete)
- [ ] Captions work (Whisper API key required)
- [ ] Instagram feed template renders
- [ ] License activation/deactivation works
- [ ] Upload to wbcomdesigns.com EDD store

### Phase 6: Documentation

- [ ] Create `docs/website/` structure for wbcom-docs MCP
- [ ] Getting Started guide (install, activate, setup wizard)
- [ ] Settings reference (all tabs documented)
- [ ] Shortcode reference (all 8 with examples)
- [ ] REST API reference (all 58 endpoints)
- [ ] Developer guide (hooks, filters, extending)
- [ ] BuddyPress integration guide
- [ ] Pro features guide
- [ ] Publish via `mcp__wbcom-docs__publish_product_docs`

---

## Recommended Release Version

Given 96 commits of feature work (not a patch), the first public release should be **v1.1.0** (not 1.0.0 which was the dev version).

**Free:** v1.1.0 → WordPress.org
**Pro:** v1.0.0 → wbcomdesigns.com (first Pro release)

---

## Automation with autovap-dev

All phases can be automated via `/autovap-dev`:

| Phase | Mode | Agent |
|-------|------|-------|
| 1. Code Quality | `/autovap-dev qa` | wp-qa-auditor |
| 2. Feature Testing | `/autovap-dev qa` | wp-qa-auditor + wp-verifier |
| 3. Browser Testing | `/autovap-dev qa` | wp-verifier (Playwright) |
| 4. Version & Release | `/autovap-dev release` | wp-releaser |
| 5. Pro Release | `/autovap-dev release` (on Pro dir) | wp-releaser |
| 6. Documentation | Manual / wbcom-docs MCP | — |
