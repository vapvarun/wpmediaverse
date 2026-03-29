# BuddyPress Integration — Definitive Plan

> Decided: 2026-03-29. No ambiguity. Follow exactly.

---

## Core Principles

1. **ALL media goes to `mvs_media_index`** — whether uploaded via activity form, media page, FAB, REST API, or admin
2. **NO `wp_insert_attachment()`** — generate thumbnails using `wp_get_image_editor()` directly, store thumbnail URLs in `mvs_media_meta`
3. **ONE lightbox everywhere** — same skeleton, same CSS, same REST API calls for reactions/comments/favorites
4. **Activity ↔ Media link** — `mvs_media_index` stores `activity_id`, activity content stores `media_id`. Bidirectional.
5. **Comments on media appear as activity comments** when BP is enabled

---

## 1. Activity Media Upload Flow

**User posts activity with media:**
1. User types text + attaches 1-6 files via activity form
2. JS uploads each file to `POST /mvs/v1/media` → creates `mvs_media_index` row (status: draft)
3. On "Post Update", JS sends `mvs_activity_media_ids` with the activity form
4. PHP `attach_media_to_activity()`:
   - Sets each media status to `publish`
   - Stores `activity_id` on each media: `MediaMeta::set($media_id, 'bp_activity_id', $activity_id)`
   - Stores `group_id` if group post: `MediaMeta::set($media_id, 'group_id', $group_id)`
   - Builds thumbnail grid HTML with `data-mvs-media-id` on each image
   - Saves to activity content

**Limits:**
- Max 6 images per activity post
- Video and audio: 1 per post (or mixed with images up to 6 total)

---

## 2. Lightbox — Uniform Everywhere

**Same lightbox for ALL contexts:**
- Explore grid (Interactivity API `data-wp-on--click="actions.openLightbox"`)
- Instagram feed expand button
- BP activity media images
- Album grid
- Dashboard My Media
- BP profile media tab
- BP group media tab

**Lightbox features (same everywhere):**
- Image/video/audio display
- 6 emoji reactions with counts
- Favorite toggle
- Comments list + post
- Share (clipboard copy)
- Open link → `/media/{slug}/` single page
- View count
- Author avatar + name
- Gallery prev/next (for multi-image posts)

**How it works on BP pages:**
- The `.mvs-lightbox-overlay` HTML is rendered by `shared-ui-shell.php` in `wp_footer` (already on ALL pages)
- On BP pages, `bp-activity-media.js` intercepts clicks on `[data-mvs-media-id]` images
- Opens the lightbox by manipulating the DOM directly (no Interactivity API needed)
- Fetches media data + reactions + comments + stats via REST API
- Same CSS, same HTML skeleton, same visual appearance

---

## 3. Group Media Tab

**Route:** `/groups/{group-slug}/media/`

**What it shows:**
- All media from `mvs_media_index` where `group_id = {group_id}`
- Grid view with lightbox on click
- Only media uploaded via group activity posts

**Data model:**
- `mvs_media_index.group_id` — NULL for personal media, group ID for group media (add column if not exists)
- OR use `mvs_media_meta` key `group_id`

**Who can see:**
- Group members see all group media
- Non-members see based on group visibility (public/private/hidden)

---

## 4. BP Profile Media Tab

**Route:** `/members/{username}/media/`

**What it shows:**
- All media from `mvs_media_index WHERE post_author = {user_id}`
- Includes: activity uploads, direct uploads, any source
- Grid view with lightbox on click

---

## 5. Remove wp_insert_attachment Entirely

**Current flow:**
```
Upload → mvs_media_index (custom table) → wp_insert_attachment (wp_posts) → wp_generate_attachment_metadata (thumbnails)
```

**New flow:**
```
Upload → mvs_media_index (custom table) → wp_get_image_editor (thumbnails) → mvs_media_meta (thumbnail URLs)
```

**Implementation:**
1. Replace `create_wp_attachment()` with `generate_thumbnails()`:
   ```php
   private function generate_thumbnails(int $media_id, string $file_path, string $mime): void {
       if (!str_starts_with($mime, 'image/')) return;

       $editor = wp_get_image_editor($file_path);
       if (is_wp_error($editor)) return;

       $sizes = array(
           'large'  => array('width' => 1024, 'height' => 1024, 'crop' => false),
           'medium' => array('width' => 300,  'height' => 300,  'crop' => false),
           'thumb'  => array('width' => 150,  'height' => 150,  'crop' => true),
       );

       $generated = $editor->multi_resize($sizes);
       $upload_dir = wp_upload_dir();
       $base_url = $upload_dir['baseurl'] . '/wpmediaverse/' . dirname(MediaMeta::get($media_id, 'file_path'));

       foreach ($generated as $size_name => $data) {
           MediaMeta::set($media_id, 'thumb_' . $size_name, $base_url . '/' . $data['file']);
       }
   }
   ```

2. Replace ALL `wp_get_attachment_image_url($attachment_id, $size)` calls with:
   ```php
   MediaMeta::get($media_id, 'thumb_large') ?: MediaMeta::get($media_id, 'file_url')
   ```

3. Delete `create_wp_attachment()` method

4. Remove `attachment_id` column from `mvs_media_index` (migration v8)

---

## 6. Comments ↔ Activity Comments Bridge

**When BP is active:**
- Comment on media (via lightbox or single page) → ALSO creates a BP activity comment on the media's activity
- BP activity comment on a media activity → ALSO creates a media comment in `mvs_comments` custom table

**Implementation:**
- Hook into `mvs_comment_created` → if media has `bp_activity_id`, create `bp_activity_new_comment()`
- Hook into `bp_activity_comment_posted` → if activity has `_mvs_media_ids` meta, create media comment

---

## Execution Order

1. **Remove `wp_insert_attachment()`** — eliminates slug collision, simplifies architecture
2. **Fix BP activity lightbox** — use DOM manipulation on shared-ui-shell, not Interactivity API
3. **Profile media tab** — query `mvs_media_index WHERE post_author = user_id`
4. **Group media tab** — add `group_id` to media, register BP group extension
5. **Comments bridge** — bidirectional sync between media comments and activity comments
6. **Test all flows** — single image, multi image, video, audio, group, profile
