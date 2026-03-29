# WPMediaVerse (Free) — Master Roadmap

> Single source of truth for all free plugin work.
> Last updated: 2026-03-29

---

## Current Version: 1.0.0 → Target: 1.1.0

### What's Built
- 91 PHP classes, 58 REST endpoints, 23 DB tables, 13 blocks, 15 templates, 8 admin pages
- Upload pipeline, privacy model, reactions, comments, follows, favorites, albums, collections
- BuddyPress integration (activity feed, profiles, groups)
- Settings page with Jetonomy-style card layout
- Moderation queue, stats dashboard, log viewer
- 38/38 QA tests passing

---

## TODO: Admin UX Fixes (before release)

### Navigation Fixes
- [ ] Add Quotas + Reports links to Settings sidebar Tools section (`SettingsPage.php:1296-1314`)
- [ ] Fix Overview "Customize permissions" link: `&tab=permissions` → `#permissions` (`OverviewPage.php:447`)

### Dead CSS Removal (admin.css)
- [ ] Remove `.mvs-getting-started` block (~lines 303-331, 29 lines)
- [ ] Remove `.mvs-page-header` block (~lines 337-355)
- [ ] Remove `.mvs-page-subtitle` (~lines 617-622)
- [ ] Remove duplicate `.mvs-page-header .mvs-version` (~lines 349-355)

### Permission Fail Fix (9 pages)
Change `return;` to `wp_die()` in render_page() for:
- [ ] OverviewPage.php
- [ ] SettingsPage.php
- [ ] LogViewerPage.php

### Minor Fixes
- [ ] LogViewerPage.php:87 — `$cleared` uses `isset()`, should be `=== '1'`

---

## TODO: DM Integration (move from Pro to Free)

### Phase 1: Move DM Engine to Free
- [ ] Move `MessagingService`, `MessagingController`, `RestPollingTransport`, `TransportInterface`, `NotificationListener` from Pro → Free
- [ ] Move 4 DB tables to Free Activator.php
- [ ] Move templates (chat-panel, chat-composer, etc.) + CSS + JS
- [ ] Add `mvs_buddynext_active` filter to chat panel template
- [ ] Add `mvs_can_send_message` filter

### Phase 2: Pro DM Additions (future)
- Group DM (2-49 participants)
- Per-message read receipts
- WebSocket transport

---

## TODO: Gamification Hooks (for wb-gamification integration)

- [ ] Add `apply_filters('mvs_activity_types', self::TYPES)` to `ActivityService`
- [ ] Add same filter to `NotificationService`

---

## Pre-Release Checklist

- [ ] `php -l` — zero errors on all PHP files
- [ ] `npm run build` — 13 blocks compile
- [ ] Remove `console.log` / `error_log()` / `var_dump()` from production
- [ ] Bump version to 1.1.0 in: `wpmediaverse.php`, constant, `readme.txt`, `package.json`
- [ ] Create `.distignore`
- [ ] Generate `.pot` file
- [ ] Write `readme.txt` (description, FAQ, screenshots, changelog)
- [ ] Run full QA suite (38 tests)
- [ ] Build distribution ZIP
- [ ] Tag `v1.1.0` + push
- [ ] Publish docs via `mcp__wbcom-docs__publish_product_docs`

---

## v1.2.0 Roadmap

- Social sharing buttons (Facebook/Twitter/Pinterest)
- Play event tracking in free
- Cursor-based pagination for large libraries
- Admin page pagination (Stats top media, Logs)
