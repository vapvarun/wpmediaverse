# WPMediaVerse — Pending Audit & Fixes (2026-03-29)

> Issues found during CPT removal + browser testing session.
> Priority: Must fix ALL before v1.0 release.

---

## 1. LIGHTBOX — Make Uniform Everywhere

**Current state**: Shared-ui Interactivity API lightbox works on explore grid and Instagram expand. BUT it doesn't work on BP activity pages (Interactivity API not accessible from classic JS).

**Decision needed**:
- Option A: Activity media clicks → navigate to `/media/{slug}/` single page (current behavior after fix)
- Option B: Convert `bp-activity-media.js` to ES module so it can access the Interactivity API store
- Option C: Build a lightweight standalone lightbox in `bp-activity-media.js` that doesn't conflict with the shared-ui one

**What works now**:
- Explore grid → shared-ui lightbox (reactions, comments, favorites, share) ✓
- Instagram expand button → shared-ui lightbox ✓
- Album grid → shared-ui lightbox ✓
- BP activity → navigates to single page (no lightbox overlay)
- Dashboard My Media → delegates to shared-ui lightbox (untested)

---

## 2. WP Attachment — Remove Entirely

**Problem**: `UploadService::create_wp_attachment()` creates a wp_post (attachment type) for thumbnail generation. This causes slug collisions even with hash prefix. Violates the "no wp_posts for media" architecture.

**Fix**: Generate thumbnails without `wp_insert_attachment()`:
1. Use `wp_get_image_editor()` to resize images directly
2. Save resized files to upload dir manually
3. Store thumbnail URLs in `mvs_media_meta` (key: `thumbnail_large`, `thumbnail_medium`, etc.)
4. Remove all `wp_get_attachment_image_url()` calls — read from meta instead
5. Delete `create_wp_attachment()` method entirely

**Files affected**:
- `UploadService.php` — remove `create_wp_attachment()`
- `MediaListPage.php` — thumbnail column reads from meta
- `TemplateHelpers.php` — grid thumbnail reads from meta
- `BuddyPressIntegration.php` — activity thumbnails
- `AlbumService.php` — album cover
- `CollectionController.php` — collection cover
- All block `render.php` files that use `wp_get_attachment_image_url()`

---

## 3. BuddyPress Integration — Deep Audit

**Problem**: `BuddyPressIntegration.php` (2000+ lines) was bulk-updated by AI during CPT removal. Many methods may be broken.

**Must test & fix**:
- [ ] Activity media upload (single image)
- [ ] Activity media upload (multiple images)
- [ ] Activity video upload
- [ ] Activity audio upload
- [ ] Group media uploads
- [ ] BP profile media tab
- [ ] BP notifications for media actions
- [ ] Activity comments with media references
- [ ] Activity media thumbnail generation
- [ ] Media count on profile header

---

## 4. MediaRenderer — Unified Media HTML

**Problem**: Media is rendered differently in 6+ contexts (explore grid, Instagram card, album grid, activity, dashboard, single page). Different HTML, different CSS classes, different click handlers.

**Fix**: Create `MediaRenderer` class:
```php
MediaRenderer::grid_item( $media_id, $context = 'explore' );
MediaRenderer::card( $media_id, $context = 'instagram' );
MediaRenderer::thumbnail( $media_id, $size = 'large' );
MediaRenderer::activity_attachment( $media_id );
```

All output consistent HTML with `data-wp-interactive="mvs/shared-ui"` + `data-mvs-media-id` + proper CSS classes.

---

## 5. CSS Cleanup — Phase 2

**Done**: Removed 566 lines of dead CSS (old lightbox, unused utility classes).

**Still needed**:
- Remove remaining `var(--mvs-*)` references that have no `:root` definition
- Consolidate duplicate rules for `.mvs-activity-media`
- Verify all CSS on mobile viewport (390px)

---

## 6. Single Media Page — Missing Features

**Current**: Shows image, title, author, description, tags, favorite, share, comments.

**Missing**:
- Reactions bar (6 emoji) — only in lightbox, not on single page
- View count display
- Follow button for non-owner
- Related media section
- Download button (for allowed media)
- EXIF data display (if available)

---

## 7. npm run build

Block JS bundles need regeneration. `media-player/view.js` returns 404.

---

## 8. Release Prep

- [ ] Remove console.log / error_log / var_dump
- [ ] Version bump to 1.0.0
- [ ] readme.txt
- [ ] .distignore + .pot
- [ ] WPCS check
- [ ] Build ZIP
