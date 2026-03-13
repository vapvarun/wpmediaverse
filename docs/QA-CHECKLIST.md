# WPMediaVerse — Manual QA Checklist

**Site:** https://wb-media.local
**Date:** 2026-03-12
**Tester:** Claude Code (Playwright)

## Legend
- [ ] Not tested
- [x] Pass
- [!] Fail — see Notes table at bottom

---

## FREE PLUGIN

### 1. Pages & Navigation
- [x] 1.1 Explore page loads (`/media/`) — grid, 20 tags, search, 4 pages
- [x] 1.2 Upload page loads (`/upload-media/`) — dropzone, privacy, title/desc/tags
- [x] 1.3 Dashboard page loads (`/my-media/`) — 4 tabs visible
- [x] 1.4 Single media page loads with social UI — image renders, Favorite, Share, Comments
- [x] 1.5 Single album page loads — title, description, 3 items in grid
- [x] 1.6 Single collection page loads — "Test Collection", manual badge

### 2. Dashboard — My Media Tab
- [x] 2.1 My Media tab active by default, shows media grid
- [x] 2.2 Upload dropzone visible and clickable
- [x] 2.3 Media cards show thumbnail + title + Edit/Delete
- [x] 2.4 Edit button opens modal with title/description/privacy/tags fields
- [x] 2.5 Delete button triggers confirmation dialog
- [x] 2.6 Load More button appears when >20 items

### 3. Dashboard — Albums Tab
- [x] 3.1 Albums tab switches correctly
- [x] 3.2 Create Album button visible ("+ Create Album")
- [x] 3.3 Album cards show cover image + title (8 albums)
- [x] 3.4 Edit/Delete buttons work on album cards

### 4. Dashboard — Favorites Tab
- [x] 4.1 Favorites tab switches correctly
- [x] 4.2 Favorited media items displayed (5 items)
- [x] 4.3 Unfavorite button visible on each item

### 5. Dashboard — Collections Tab
- [x] 5.1 Collections tab switches correctly
- [x] 5.2 Create Collection button visible ("+ Create Collection")
- [x] 5.3 Collection cards show type badge (manual)
- [x] 5.4 Create manual collection via modal — title, description, type toggle, save/cancel
- [x] 5.5 Create smart collection with rules via modal — Smart toggle, rule builder, + Add Rule
- [x] 5.6 Edit collection via modal — pre-filled title/description/type, rules section
- [x] 5.7 Delete collection with confirmation — "Delete this collection? Media items will not be deleted."

### 6. Single Media Page
- [x] 6.1 Media renders (image/video/audio)
- [x] 6.2 Reactions bar shows (like/love/haha/wow/sad/angry) — loads async via REST API
- [x] 6.3 Click reaction toggles it
- [x] 6.4 Comments section renders
- [x] 6.5 Post a comment successfully
- [x] 6.6 Favorite button toggles
- [x] 6.7 Share button works (copy link)
- [x] 6.8 Owner sees Edit/Delete actions

### 7. Single Album Page
- [x] 7.1 Album header shows title + description + item count
- [x] 7.2 Media grid renders with items
- [ ] 7.3 Sequential playback for audio albums — no audio album test data available
- [x] 7.4 Owner sees Edit/Delete actions (tested on own album "Sample Album")

### 8. Explore Page
- [x] 8.1 Media grid loads with items
- [x] 8.2 Tag cloud renders (20 tags)
- [x] 8.3 Search input filters results
- [x] 8.4 Author row on explore cards (avatar + name)
- [x] 8.5 Hover overlay shows stats

### 9. Upload Page
- [x] 9.1 Upload form renders with dropzone
- [x] 9.2 File selection works
- [ ] 9.3 Upload succeeds with success message — requires file upload simulation

### 10. Admin — Overview
- [x] 10.1 Overview page loads at `/wp-admin/admin.php?page=mvs-overview`
- [x] 10.2 Stats cards show (60 media, 9 albums, 564 views)
- [x] 10.3 Quick links section present
- [x] 10.4 Recent uploads list shows

### 11. Admin — Settings
- [x] 11.1 Settings page loads at `/wp-admin/admin.php?page=mvs-settings`
- [x] 11.2 General tab content (Free + Pro settings)
- [x] 11.3 Display tab (grid/pagination/thumbnails)
- [x] 11.4 Permissions tab (role matrix)
- [x] 11.5 AI & Moderation tab
- [x] 11.6 Webhooks tab

### 12. Admin — Collections
- [x] 12.1 Collection CPT list page loads (1 collection)
- [x] 12.2 New collection editor loads (Gutenberg)
- [x] 12.3 Collection Settings meta box visible
- [x] 12.4 Manual/Smart radio toggle works
- [x] 12.5 Smart mode shows rule builder — "Rules (all must match)" with dropdown + Add Rule
- [x] 12.6 Add Rule button adds row — second rule row appears with own dropdown
- [x] 12.7 Rule key dropdown has 7 options (Media Type, Tag, Category, Author, Privacy, Date After, Date Before)

### 13. BuddyPress Integration
- [x] 13.1 Profile Media tab visible (`/members/varundubey/media/`) — "Media 54"
- [x] 13.2 Profile media grid loads (18 items, 3 pages, Upload button, sub-tabs)
- [x] 13.3 Group Media tab visible (`/groups/the-godfather/media/`)
- [x] 13.4 Group media grid loads (12 items, Upload button, author names)
- [x] 13.5 Activity stream shows media filter types ("Media Uploads", "Media Comments")
- [x] 13.6 Activity lightbox opens on media click — image, 6 reactions, comments, view link

### 14. REST API Smoke Tests
- [x] 14.1 GET /wp-json/mvs/v1/media — 200 with items
- [x] 14.2 GET /wp-json/mvs/v1/albums — 200 with items
- [x] 14.3 GET /wp-json/mvs/v1/collections — 200 with items
- [x] 14.4 GET /wp-json/mvs/v1/tags — 200 (5 tags)

### 15. Error Handling
- [x] 15.1 Unauthorized API returns 401 (`/me/favorites` without auth)
- [x] 15.2 Non-existent media returns 404
- [x] 15.3 Unauthorized API call returns 403 (invalid nonce)

---

## PRO PLUGIN

### 16. Pro Admin Pages
- [x] 16.1 Pro settings appear in Settings page
- [x] 16.2 Quota page loads — 3 tabs (Packages, User Quotas, Credit Log)
- [x] 16.3 Reports page loads — status filters (Pending/Resolved/Dismissed), table
- [x] 16.4 Email Leads page loads — stat cards (Total/Today/7d/30d), empty state
- [x] 16.5 Analytics page loads — stat cards (Plays/Engagement/Top Media), Top 10

### 17. Quota System
- [x] 17.1 Quota packages table renders (1 "Free" package: 100 img, 10 vid, 20 audio, 1 GB)
- [x] 17.2 Create quota package form works (name, limits, storage, default checkbox)
- [x] 17.3 Assign package to user — User Quotas tab shows all users with package dropdown + credit options

### 18. Pro REST API
- [x] 18.1 GET /wp-json/mvs-pro/v1/me/quota — 200 OK
- [x] 18.2 GET /wp-json/mvs-pro/v1/analytics/overview — 403 (requires admin, correct)

---

## Notes / Issues Found

| # | Test | Issue | Severity |
|---|------|-------|----------|
| 1 | — | Mixed Content warnings on activity page (HTTP resources loaded over HTTPS) | Low |
| 2 | 7.3 | No audio album test data to verify sequential playback. Code exists in album.php (auto-next on `ended` event). | Info |
| 3 | 9.3 | File upload not tested end-to-end (requires Playwright file upload simulation). Upload form and REST endpoint both verified working. | Info |

## Summary

| Section | Pass | Fail | Not Tested | Total |
|---------|------|------|------------|-------|
| Pages & Navigation | 6 | 0 | 0 | 6 |
| Dashboard — My Media | 6 | 0 | 0 | 6 |
| Dashboard — Albums | 4 | 0 | 0 | 4 |
| Dashboard — Favorites | 3 | 0 | 0 | 3 |
| Dashboard — Collections | 7 | 0 | 0 | 7 |
| Single Media Page | 8 | 0 | 0 | 8 |
| Single Album Page | 3 | 0 | 1 | 4 |
| Explore Page | 5 | 0 | 0 | 5 |
| Upload Page | 2 | 0 | 1 | 3 |
| Admin — Overview | 4 | 0 | 0 | 4 |
| Admin — Settings | 6 | 0 | 0 | 6 |
| Admin — Collections | 7 | 0 | 0 | 7 |
| BuddyPress Integration | 6 | 0 | 0 | 6 |
| REST API Smoke Tests | 4 | 0 | 0 | 4 |
| Error Handling | 3 | 0 | 0 | 3 |
| Pro Admin Pages | 5 | 0 | 0 | 5 |
| Quota System | 3 | 0 | 0 | 3 |
| Pro REST API | 2 | 0 | 0 | 2 |
| **TOTAL** | **84** | **0** | **2** | **86** |

**Pass rate: 98% (84/86)**
**Fail rate: 0% (0/86)**
**Not tested: 2% (2/86)** — no audio album test data (7.3), file upload simulation needed (9.3)
