# WPMediaVerse (Free) — Master Roadmap

> Updated: 2026-03-29
> Architecture: Custom tables. CPT for permalinks/admin only (v1.0). Full custom in v1.1.

---

## v1.0 — Ship with current architecture

CPT + custom tables is the pragmatic v1.0 approach. Everything works, tested, demo data seeds.

### What's done:
- [x] All metadata in custom tables (MediaMeta — zero wp_postmeta for media)
- [x] 30 custom tables (clean, no dead tables)
- [x] 15 admin pages — all working, menu highlighting, titles, descriptions
- [x] Settings page — Jetonomy card layout
- [x] 7-item clean menu
- [x] Gamification engine (battles, challenges, tournaments, boosts)
- [x] Demo seeder (50 media, 5 users, 5 albums, competitions)
- [x] 1-click cleanup
- [x] Settings field descriptions
- [x] Capabilities fixed (edit/trash actions)
- [x] Free + Pro tested independently

### What's left for v1.0:
- [ ] npm run build (regenerate stale block renders)
- [ ] php -l all files
- [ ] Remove console.log / error_log
- [ ] Version bump
- [ ] readme.txt
- [ ] .distignore + .pot
- [ ] QA suite
- [ ] Build ZIP

---

## v1.1 — Remove CPT dependency

**Goal:** mvs_media is no longer a CPT. Media = row in mvs_media_index.

### Why:
- At 1M media, CPT creates 2M wp_posts rows (media + attachment)
- wp_postmeta bloat from attachments
- Custom tables are faster for feed queries
- Clean separation from WordPress core

### Plan:
1. Upload → `wp_handle_upload()` only (no `wp_insert_attachment`)
2. Store file path/URL directly in mvs_media_index
3. Generate thumbnails ourselves (GD/Imagick)
4. Permalinks via rewrite rules (already pattern exists for /media/battles/)
5. Admin listing via custom page (not edit.php?post_type=)
6. REST API already reads from custom tables
7. Remove mvs_media CPT registration

### Risk:
- Lose WordPress SEO plugin integration
- Lose Gutenberg block editor for media
- Lose wp_get_attachment_image_srcset() responsive images
- Need custom thumbnail generation

---

## v1.2+ — Feature roadmap
- Layout modes (Instagram/Flickr/Pinterest masonry)
- Social sharing buttons
- Cursor-based pagination
- Layout mode walkthrough in setup wizard
