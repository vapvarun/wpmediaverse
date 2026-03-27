# WPMediaVerse — Release Plan v1.1.0

**Last Updated:** 2026-03-24
**Free Version:** 1.1.0 (target: WordPress.org)
**Pro Version:** 1.0.0 (target: wbcomdesigns.com)
**Commits:** 96+ (no tags, no prior releases)

---

## What's Built

### Free Plugin — 91 PHP classes, 58 REST endpoints, 23 DB tables

| Category | Count | Details |
|----------|-------|---------|
| Post Types | 3 | mvs_media, mvs_album, mvs_collection |
| Taxonomies | 2 | mvs_tag, mvs_category |
| REST Endpoints | 58 | Media CRUD, social, follow, DM, notifications, moderation, stats |
| Database Tables | 23 | 19 core + 4 messaging |
| Blocks | 13 | All compiled via wp-scripts, 6 use Interactivity API |
| Templates | 15 | 7 main + 8 partials (dashboard, explore, single, album, collection, profile, messages) |
| Shortcodes | 8 | gallery, upload, album, player, stats, dashboard, collection, profile_edit |
| Admin Pages | 8 | Overview, Settings (5 tabs), Moderation, Stats, Logs, Setup Wizard |
| Capabilities | 17 | Role-based (admin 12, editor 11, author 7, subscriber 4) |
| Integrations | 2 | BuddyPress (activity, profiles, groups, notifications), Webhooks (HMAC-SHA256) |

### Pro Plugin — 37 PHP classes, 38+ REST endpoints, 9 DB tables

| Feature | Details |
|---------|---------|
| Quota System | Packages, credits, per-type limits, HMAC webhook |
| Cloud Storage | S3 (Signature V4), BunnyCDN |
| AI Providers | Google Vision, AWS Rekognition + circuit breaker |
| Video Intelligence | Chapters, resume, FFmpeg transcoding, analytics, heatmaps |
| Auto-Captions | OpenAI Whisper via Action Scheduler |
| Advanced Privacy | Album inheritance, presets, bulk controls |
| Instagram Feed | Stories bar, feed cards, double-tap like, dark mode |
| User Profiles | `/media/@{username}/` pages |
| Migration Tools | rtMedia, MediaPress, BuddyBoss WP-CLI importers |
| EDD Licensing | wbcomdesigns.com, modal license UI |

---

## Compatibility Verified

| Platform | Version | Status |
|----------|---------|--------|
| WordPress | 6.9.4 | Tested |
| BuddyPress | 14.4.0 | Tested |
| BuddyBoss Platform | 2.21.0 | Tested |
| Standalone (no BP) | — | Tested |
| PHP | 8.x | Tested |

---

## Migration — All 3 Source Plugins Verified

| Source | Types Tested | Privacy Mapping | Albums | Activities |
|--------|-------------|-----------------|--------|-----------|
| rtMedia | photo, video, audio, document, profile, activity, group, album | public, members, friends, private, group | Created from rtMedia album IDs | rtMedia HTML transformed to MVS rendering |
| MediaPress | photo, video, audio, member gallery, group gallery, multi-user | public, members, friends, private, group | Gallery → album with items | MediaPress activity meta → MVS thumbnail |
| BuddyBoss | photo, profile, group, private, friends, loggedin, album | public, members, friends, private, group | album_id → MVS album | bp_media_ids meta → MVS thumbnail |

**Post-migration:** `wp mvs backfill-activity-thumbnails` persists thumbnails into BP activity (requires DB backup, confirms before write).

---

## QA Results (2026-03-24)

| Category | Tests | Pass |
|----------|-------|------|
| Database tables (24) | 2 | 2 |
| Capabilities (4 roles) | 4 | 4 |
| Data flow (reactions, comments, follows, privacy, blocks, reports) | 7 | 7 |
| DM (create, send, read, mark read, block) | 5 | 5 |
| BuddyPress integration | 3 | 3 |
| Pro endpoints (quota, packages, analytics, transcode, privacy) | 5 | 5 |
| Member perspective (browse, react, favorite, follow, security) | 12 | 12 |
| WPCS (with project phpcs.xml) | — | 369 remaining (non-auto-fixable) |
| PHP lint | — | Zero errors |
| **Total** | **38** | **38 pass** |

Full reusable test suite: `docs/QA-SUITE.md`

---

## What's Done vs Roadmap

### Free 1.0.0 Core — 94% complete

| Feature | Status |
|---------|--------|
| 3 CPTs, 23 tables, 17 capabilities | Done |
| Upload + MIME + EXIF + duplicate detection | Done |
| 6-level privacy (public/members/friends/group/private/custom) | Done |
| Social: 6 reaction types, comments (15-min edit), favorites, @mentions | Done |
| Albums, playlists, smart collections, stories (24h) | Done |
| AI moderation (OpenAI Vision) | Done |
| 13 Gutenberg blocks (Interactivity API) | Done |
| 8 shortcodes | Done |
| BuddyPress integration (activity, profiles, groups, notifications) | Done |
| 58 REST endpoints | Done |
| Webhooks (HMAC-SHA256) + rate limiter | Done |
| WP-CLI commands | Done (stats, migrate, reindex, prune, backfill) |

### Free 1.1.0 Features — 90% complete

| Feature | Status |
|---------|--------|
| Video/audio metadata extraction | Done |
| Custom media player (Interactivity API) | Done |
| Sequential album playback | Done |
| Notification system (standalone) | Done |
| Follow system (native mvs_follows) | Done |
| Report & block (hidden from feed + DM) | Done |
| Activity feed (standalone) | Done |
| User discovery (search) | Done |
| Comment editing (15-min window) | Done |
| Draft/scheduled media | Done |
| Pro upsell indicators in settings | Done |
| WP 6.9 Abilities API (17 abilities) | Done |
| DM engine (REST polling, read receipts, media sharing) | Done |
| @username profile pages | Done |
| Trending + popular sort (`?orderby=trending\|popular`) | Done |
| Hashtag pages (`/media-tag/{slug}/`) | Done (taxonomy archive) |
| Report button on single media page | Done |
| Activity transforms (rtMedia, MediaPress, BuddyBoss) | Done |
| Admin custom columns (thumb, type, privacy, status) | Done |
| Play event tracking in free | Not done (Pro only) |
| Cursor-based pagination | Not done |

### Competitive Advantages

| vs rtMedia | vs BuddyBoss | vs MediaPress |
|-----------|-------------|--------------|
| Video + audio + docs (not just images) | Standalone (no BP required) | 58 REST endpoints (no API) |
| AI moderation (free tier) | Modern stack (Interactivity API) | Social features (reactions, DM, follow) |
| Native DM engine | Migration from BuddyBoss | Albums, collections, stories |
| 6 reaction types (not just like) | Open pricing (no $228/yr lock) | Active development |

---

## Pre-Release Checklist

### Code Quality
- [ ] `php -l` on all PHP files — zero errors
- [ ] `npm run build` — all 13 blocks compile
- [ ] WPCS: 369 remaining errors (short ternary, docblocks — non-blocking)
- [ ] Verify no `console.log` / `error_log()` / `var_dump()` in production code

### Version Bump
- [ ] Free: `wpmediaverse.php` header → `Version: 1.1.0`
- [ ] Free: `MVS_VERSION` constant → `1.1.0`
- [ ] Free: `readme.txt` → `Stable tag: 1.1.0`
- [ ] Free: `package.json` → `1.1.0`
- [ ] Pro: version stays 1.0.0 (first release)

### Packaging
- [ ] Create `.distignore` (exclude .git, node_modules, src/blocks, tests, phpcs.xml, etc.)
- [ ] Build ZIP via `git archive` or rsync
- [ ] Tag: `git tag v1.1.0` + push
- [ ] Pro: upload to wbcomdesigns.com EDD store

### Documentation
- [ ] `readme.txt` — description, FAQ, screenshots, changelog
- [ ] Generate changelog from git history
- [ ] Publish docs via `mcp__wbcom-docs__publish_product_docs`

---

## Post-Release Roadmap (v1.2.0)

| Feature | Priority | Effort |
|---------|----------|--------|
| Social sharing buttons (Facebook/Twitter/Pinterest) | High | Low |
| Play event tracking in free plugin | High | Low |
| Cursor-based pagination | High | Low |
| Advanced search (date, duration, file size) | Medium | Medium |
| Story enhancements (seen-by, reactions) | Medium | Medium |
| Comment likes/upvotes | Medium | Low |
| User preferences table | Low | Medium |
| Media carousel block | Low | Medium |

---

## File Structure (Final)

```
docs/
├── RELEASE-PLAN.md      ← This file (roadmap + status + checklist)
├── ARCHITECTURE.md       ← Code architecture, bootstrap, services
├── CODING-STANDARDS.md   ← PHP/JS/CSS conventions, naming
├── QA-SUITE.md           ← 50 reusable WP-CLI + browser tests
```

## Security Checklist (Every Release)

- [ ] All user input sanitized (`sanitize_text_field`, `absint`, `esc_url`)
- [ ] All output escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`)
- [ ] All DB queries use `$wpdb->prepare()`
- [ ] All REST endpoints have `permission_callback`
- [ ] All file uploads validate MIME server-side
- [ ] EXIF GPS stripped from images
- [ ] No direct file access (ABSPATH check)
- [ ] CSRF protection via nonces
- [ ] IDOR checks (user owns resource before modify/delete)
- [ ] Rate limiting on write endpoints
- [ ] API keys never exposed in responses
- [ ] Signed URLs use HMAC-SHA256 with timing-safe comparison
- [ ] Blocked users excluded from all queries

## Explicitly Out of Scope

- Social login (other plugins handle this)
- User registration/profiles (WordPress handles this)
- Email marketing (integrate via hooks, not build)
- SEO (Yoast/RankMath handle this)
- Page builders (blocks + shortcodes cover all builders)
- Form builders (not our domain)
- Stripe/WooCommerce payments (removed — credits via admin/webhook)
- LearnDash/PeepSo/WooCommerce integrations (shortcodes sufficient)
