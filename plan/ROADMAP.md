# WPMediaVerse (Free) — Master Roadmap

> Single source of truth. Updated: 2026-03-29
> Process: plan → review with user → implement. No cowboy coding.

---

## Current Version: 1.0.0 → Target: 1.1.0

---

## DONE (this session)

- [x] Settings page redesigned to Jetonomy card layout
- [x] Dead CSS removed (50+ lines)
- [x] Sidebar links: added Quotas, Reports (were orphaned)
- [x] Sidebar links: JS fix — external links navigate instead of being blocked
- [x] Overview "Customize permissions" link fixed
- [x] Permission fail → wp_die (3 pages)
- [x] LogViewerPage cleared check fixed
- [x] All pages registered under WPMediaVerse menu (no hidden pages)
- [x] Settings sidebar links use correct URLs

---

## TODO: Admin Listing Pages (before release)

### All Media listing (edit.php?post_type=mvs_media)
- [ ] Missing Edit row action
- [ ] Missing Delete row action
- [ ] Missing Quick Edit
- [ ] Check: are bulk actions (Trash, etc.) available?
- [ ] Check: column sorting works?
- [ ] Audit: what columns are shown? Do we need custom columns (Author, Views, Reactions)?

### Albums listing (edit.php?post_type=mvs_album)
- [ ] Same audit as above

### Collections listing (edit.php?post_type=mvs_collection)
- [ ] Same audit as above

### WPMediaVerse submenu items — which should be visible?
- [ ] Decide: should Logs, Analytics, Quotas, Reports, Migration, Challenges, Tournaments, Battles all show in the menu? Or should some be collapsed/grouped?
- [ ] Too many submenu items clutters the menu — need a strategy

---

## TODO: DM Integration (move from Pro to Free)

- [ ] Move MessagingService, MessagingController, RestPollingTransport from Pro → Free
- [ ] Move 4 DB tables to Free Activator.php
- [ ] Move templates + CSS + JS
- [ ] Add mvs_buddynext_active filter
- [ ] Add mvs_can_send_message filter

---

## TODO: Gamification Hooks

- [ ] Add apply_filters('mvs_activity_types') to ActivityService
- [ ] Add same filter to NotificationService

---

## Pre-Release Checklist

- [ ] php -l — zero errors
- [ ] npm run build — 13 blocks compile
- [ ] Remove console.log / error_log / var_dump
- [ ] Bump version to 1.1.0
- [ ] Create .distignore
- [ ] Generate .pot file
- [ ] Write readme.txt
- [ ] Run full QA suite
- [ ] Build ZIP
- [ ] Tag + push

---

## v1.2.0+

- Social sharing buttons
- Play event tracking
- Cursor-based pagination
- Admin page pagination (6 pages)
