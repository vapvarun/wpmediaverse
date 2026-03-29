# WPMediaVerse (Free) — v1.0.0 Roadmap

> Single source of truth. Updated: 2026-03-29
> Architecture: 100% custom tables. No CPT for media. No wp_postmeta.

---

## BLOCKER: Remove mvs_media CPT

Media items must be rows in `mvs_media_index`, NOT wp_posts. WordPress attachment stays ONLY for file storage (thumbnails, disk management).

### What needs to change:

1. **Remove CPT registration** — delete `PostTypes/Media.php` register call
2. **Rewrite UploadService** — no `wp_insert_post()`, create row in `mvs_media_index` directly, use `wp_handle_upload()` + `wp_insert_attachment()` for the file only
3. **Rewrite admin listing** — custom page (not `edit.php?post_type=mvs_media`), query `mvs_media_index` directly
4. **Rewrite permalinks** — rewrite rules for `/media/{id}/` or `/media/{slug}/` (like we already do for `/media/battles/`)
5. **Update all templates** — replace `get_post()`, `get_the_ID()`, `the_title()`, `get_permalink()` with custom table reads via MediaMeta
6. **Update all blocks** — `render.php` files read from MediaMeta, not WP_Query
7. **Update REST API** — `MediaController` already reads from custom tables, but response shaping may use `get_post()`
8. **Update seeder** — no `wp_insert_post()` for media, insert directly into `mvs_media_index`
9. **Update cleanup** — delete from custom tables, not `wp_delete_post()`
10. **Update TemplateLoader** — `pre_get_posts` and `archive_template` hooks won't work without CPT, use `template_redirect` instead
11. **Update BuddyPress integration** — activity recording, profile media queries
12. **Update albums/collections** — these reference `mvs_media` posts, need to reference `mvs_media_index.media_id` instead

### What stays the same:
- `mvs_album` CPT — albums are low volume, CPT is fine
- `mvs_collection` CPT — same
- WordPress attachments — file handling only
- All custom tables (mvs_media_index, mvs_media_meta, reactions, favorites, etc.)
- MediaMeta helper class
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

### After CPT removal:
- [ ] npm run build (regenerate blocks)
- [ ] php -l all files
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
