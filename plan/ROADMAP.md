# WPMediaVerse (Free) — v1.0.0 Roadmap

> Single source of truth. Updated: 2026-03-29 (CPT removal complete)
> Architecture: 100% custom tables. No CPT for media. No wp_postmeta.

---

## DONE: Remove mvs_media CPT ✓

Media items are rows in `mvs_media_index` (AUTO_INCREMENT). WordPress attachment stays ONLY for file storage.

### Completed:

1. ✓ **Remove CPT registration** — `PostTypes/Media.php` deleted, no `register_post_type('mvs_media')` anywhere
2. ✓ **Rewrite UploadService** — inserts directly into `mvs_media_index` via `MediaMeta::insert()`
3. ✓ **Rewrite admin listing** — `Admin/MediaListPage.php` with custom table queries, filters, pagination
4. ✓ **Rewrite admin menu** — `add_menu_page('wpmediaverse')`, all subpages under custom slug
5. ✓ **Rewrite permalinks** — rewrite rules for `/media/{slug}/`, `/media/`, `/media/@{user}/`
6. ✓ **Update all templates** — `media-single.php`, `explore.php`, `album.php` read from custom tables
7. ✓ **Update all blocks** — 7 block `render.php` files rewritten to query `mvs_media_index`
8. ✓ **Update REST API** — MediaController, BulkController, + 8 other controllers
9. ✓ **Update seeder** — `seed-demo-data.php` uses `MediaMeta::insert()`
10. ✓ **Update cleanup** — `cleanup-demo-data.php` deletes from custom tables
11. ✓ **Update TemplateLoader** — `template_redirect` with custom rewrite rules
12. ✓ **Update BuddyPress** — all `get_post()` replaced with `MediaMeta::*` methods
13. ✓ **Update albums/collections** — reference `mvs_media_index.media_id`
14. ✓ **Migration v7** — schema upgraded with all columns + AUTO_INCREMENT
15. ✓ **Update all services** — GDPR, Moderation, Privacy, Webhooks, CLI, Capabilities
16. ✓ **188 PHP files** pass syntax check with 0 errors

### What stays the same:
- `mvs_album` CPT — albums are low volume, CPT is fine
- `mvs_collection` CPT — same
- WordPress attachments — file handling only
- All custom tables (mvs_media_index, mvs_media_meta, reactions, favorites, etc.)
- MediaMeta helper class (expanded with insert/exists/get_author/get_permalink/generate_unique_slug)
- All social features (reactions, comments, follows, favorites)
- All gamification features (battles, challenges, tournaments, boosts)

---

## DONE (this session)

- [x] Gamification engine — battles, challenges, tournaments, boosts (Pro)
- [x] Unified competition schema (5 tables)
- [x] wb-gamification manifest (16 triggers)
- [x] All metadata in custom tables (MediaMeta — zero wp_postmeta)
- [x] 30 custom tables (clean, no dead tables)
- [x] Settings page — Jetonomy card layout with design tokens
- [x] 15 admin pages — all working, titles, menu highlighting
- [x] 7-item clean menu (tool pages hidden via CSS)
- [x] Admin UX audit — 45 issues fixed
- [x] Capabilities fixed (edit/trash row actions)
- [x] Demo seeder — 50 media, 5 users, 5 albums, competitions
- [x] 1-click cleanup
- [x] Settings field descriptions
- [x] All postmeta removed (free: 0, pro: 0)
- [x] Raw SQL migrated from wp_postmeta to custom tables
- [x] WP_Query meta_query migrated to custom table queries
- [x] Free + Pro tested independently

---

## TODO (all v1.0.0 — no deferral)

### Release prep:
- [ ] npm run build (regenerate blocks)
- [ ] Remove console.log / error_log / var_dump
- [ ] Version bump to 1.0.0
- [ ] readme.txt
- [ ] .distignore + .pot
- [ ] QA suite
- [ ] Build ZIP
- [ ] Tag + push

### Features still needed for v1.0:
- [ ] 50 demo images (add more stock to assets/demo-images/)
- [ ] Walkthrough onboarding tooltips (wp-pointer)
- [ ] DM integration (move from Pro to Free)
- [ ] Gamification hooks (ActivityService + NotificationService filters)
- [ ] Admin page pagination (6 pages)
- [ ] Layout modes — at minimum Instagram grid must work without CPT
