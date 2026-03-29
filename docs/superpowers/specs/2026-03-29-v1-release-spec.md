# WPMediaVerse v1.0.0 Release Spec

> Approved: 2026-03-29. Follow exactly.

## Goal

Ship v1.0.0 with a polished standalone media platform AND working BuddyPress integration. Target audience: 10k existing rtMedia/MediaPress/BuddyBoss users who will only switch if BP integration works and the standalone experience is better than what they have.

## Approach

Follow BP-INTEGRATION-PLAN.md execution order. Each step builds on the previous. Removing wp_insert_attachment is the foundation — everything else depends on correct thumbnail/URL resolution.

---

## Phase 1: Remove wp_insert_attachment

### What

Eliminate all WordPress attachment creation for media. Media lives exclusively in `mvs_media_index` + `mvs_media_meta`. Thumbnails generated via `wp_get_image_editor()`, URLs stored in mvs_media_meta.

### Current State

- `UploadService::create_wp_attachment()` still exists and is called on upload
- 16 files reference `wp_get_attachment_image_url()` for thumbnail display
- Causes slug collisions between WP attachments and custom media slugs

### Changes

**UploadService.php:**
- Delete `create_wp_attachment()` method entirely
- Add `generate_thumbnails()` method:
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
      $base_dir = dirname($file_path);
      $base_url = $upload_dir['baseurl'] . '/wpmediaverse/' . str_replace($upload_dir['basedir'] . '/wpmediaverse/', '', $base_dir);

      foreach ($generated as $size_name => $data) {
          MediaMeta::set($media_id, 'thumb_' . $size_name, $base_url . '/' . $data['file']);
      }
  }
  ```
- Call `generate_thumbnails()` where `create_wp_attachment()` was called

**16 files — replace wp_get_attachment_image_url():**

All instances replaced with a helper pattern:
```php
MediaMeta::get($media_id, 'thumb_medium') ?: MediaMeta::get($media_id, 'file_url')
```

Size mapping by context:
- Grid cards, activity thumbnails → `thumb_medium` (300px)
- Lightbox, single page → `thumb_large` (1024px) or `file_url` (original)
- Tiny thumbnails, avatars → `thumb_thumb` (150px)

Files to update:
1. `Admin/MediaListPage.php`
2. `Services/UploadService.php`
3. `Integrations/BuddyPressIntegration.php`
4. `Services/AlbumService.php`
5. `REST/Controller/CollectionController.php`
6. `REST/Controller/MediaController.php`
7. `templates/media-single.php`
8. `templates/explore.php`
9. `templates/album.php`
10. `templates/partials/dashboard-content.php`
11. All block render.php files that reference attachment URLs
12. Pro: `templates/layouts/instagram/partials/feed-card.php`
13. Pro: `templates/layouts/instagram/feed.php`
14. Pro: `templates/layouts/instagram/profile.php`
15. Pro: Any other Pro templates referencing attachment URLs
16. `TemplateHelpers.php` (if exists)

**Migration v8 (Migrator.php):**
- Drop `attachment_id` column from `mvs_media_index`
- No backfill needed — fresh demo data import after changes

**No existing data migration** — demo site will be wiped and re-seeded.

### Thumbnail Storage Schema

| Meta Key | Size | Crop | Use Case |
|----------|------|------|----------|
| `thumb_large` | 1024x1024 | proportional | Lightbox, single page |
| `thumb_medium` | 300x300 | proportional | Grid cards, activity |
| `thumb_thumb` | 150x150 | center crop | Tiny thumbnails |

### Video/Audio

No thumbnails generated for video/audio. These use `file_url` directly. Video poster frames are a future enhancement.

---

## Phase 2: Uniform Lightbox on BP Pages

### What

Make the shared-ui lightbox work on BP activity pages via vanilla JS DOM manipulation. Same HTML skeleton, same CSS, same features.

### Current State

- `shared-ui-shell.php` renders lightbox HTML in `wp_footer` on ALL pages (already working)
- Explore/Instagram grids open lightbox via Interactivity API (`data-wp-on--click`)
- BP activity pages use classic jQuery — can't access Interactivity API stores
- Currently BP activity media clicks navigate to single page (no overlay)

### Changes

**bp-activity-media.js — add lightbox driver:**

On click of `[data-mvs-media-id]` in BP activity content:
1. `e.preventDefault()`
2. Get `media_id` from `data-mvs-media-id` attribute
3. Fetch `GET /mvs/v1/media/{id}` with nonce header
4. Fetch reactions, comments, favorites, stats in parallel (same REST endpoints)
5. Populate `.mvs-lightbox-overlay` DOM:
   - Image/video/audio src
   - Title, author avatar + name
   - Reaction counts per type
   - User's current reaction (if any)
   - Favorite state
   - Comments list
   - View count
   - Permalink for "Open link"
6. Remove `hidden` attribute from `.mvs-lightbox-overlay`
7. Wire up button click handlers:
   - Reaction buttons → `POST /mvs/v1/media/{id}/reactions`
   - Favorite toggle → `POST /mvs/v1/media/{id}/favorites`
   - Comment submit → `POST /mvs/v1/media/{id}/comments`
   - Share → clipboard copy of permalink
   - Open link → navigate to `/media/{slug}/`
   - Close → add `hidden` back, reset state

**Gallery navigation:** For multi-image activity posts, prev/next arrows cycle through the media IDs from that activity.

**No HTML duplication.** The lightbox skeleton already exists on every page. This is purely JS logic to drive it.

### Conflict Prevention

- Remove any remaining vanilla lightbox code from `mvs-lightbox.js` if still enqueued
- Ensure `bp-activity-media.js` does NOT re-create lightbox HTML
- Only one click handler per `[data-mvs-media-id]` element

---

## Phase 3: BP Activity Media Upload Flow

### What

Ensure activity media uploads work end-to-end: upload files, attach to activity, display with lightbox-compatible markup.

### Flow

1. User types text + attaches 1-6 files via activity form
2. JS uploads each file to `POST /mvs/v1/media` → creates `mvs_media_index` row (status: `draft`)
3. On "Post Update", JS sends `mvs_activity_media_ids` with activity form
4. PHP `attach_media_to_activity()`:
   - Sets each media status to `publish`
   - Stores `bp_activity_id` on each media: `MediaMeta::set($media_id, 'bp_activity_id', $activity_id)`
   - Stores `group_id` if group post: `MediaMeta::set($media_id, 'group_id', $group_id)`
   - Builds thumbnail grid HTML with `data-mvs-media-id` on each `<img>`
   - Saves HTML to activity content

### Activity Content HTML Structure

```html
<div class="mvs-activity-media" data-mvs-media-count="3">
  <div class="mvs-activity-media-grid mvs-grid-3">
    <img src="{thumb_medium_url}"
         data-mvs-media-id="{media_id}"
         alt="{title}"
         class="mvs-activity-media-img" />
    <!-- repeat for each media -->
  </div>
</div>
```

Grid class varies: `mvs-grid-1` through `mvs-grid-6` for layout.

### Limits

- Max 6 files per activity post (images, video, audio mixed)
- Video/audio: rendered as `<video>` / `<audio>` elements with poster/controls
- Enforced in JS (file picker limit) and PHP (server-side validation)

### CSS

Activity media images constrained: `max-height: 600px; object-fit: cover` (already in frontend.css).

---

## Phase 4: BP Profile Media Tab

### Route

`/members/{username}/media/`

### Registration

Register as BP member navigation tab via `bp_setup_nav()`:
```php
bp_core_new_nav_item(array(
    'name'                => __('Media', 'wpmediaverse'),
    'slug'                => 'media',
    'screen_function'     => 'mvs_member_media_screen',
    'position'            => 80,
    'default_subnav_slug' => 'my-media',
));
```

### Query

```sql
SELECT * FROM {prefix}mvs_media_index
WHERE post_author = {displayed_user_id}
AND status = 'publish'
ORDER BY created_at DESC
LIMIT {per_page} OFFSET {offset}
```

### Display

- Grid layout matching explore page (responsive, same card HTML)
- Each image has `data-mvs-media-id` for lightbox clicks
- Pagination
- Empty state message for users with no media

### Privacy

- Own profile: show all own media
- Other user's profile: respect media privacy settings (public, members, friends, private)

---

## Phase 5: BP Group Media Tab

### Route

`/groups/{group-slug}/media/`

### Registration

Register as BP group extension via `bp_register_group_extension()` or `bp_setup_nav()` within group context.

### Data Model

Media linked to groups via `mvs_media_meta`:
- Key: `group_id`, Value: `{group_id}`
- Set during `attach_media_to_activity()` when activity is a group post

### Query

```sql
SELECT mi.* FROM {prefix}mvs_media_index mi
INNER JOIN {prefix}mvs_media_meta mm
  ON mi.media_id = mm.media_id AND mm.meta_key = 'group_id'
WHERE mm.meta_value = '{group_id}'
AND mi.status = 'publish'
ORDER BY mi.created_at DESC
LIMIT {per_page} OFFSET {offset}
```

### Visibility

- Group members: see all group media
- Non-members: based on group status
  - Public group: see media
  - Private group: no access
  - Hidden group: no access (group itself not visible)

### Display

Same grid + lightbox as profile tab and explore page.

---

## Phase 6: Comments Bridge

### What

Bidirectional sync between media comments (mvs_comments) and BP activity comments when BP is active.

### Media Comment → Activity Comment

Hook: `mvs_comment_created`

```php
add_action('mvs_comment_created', function($media_id, $user_id, $comment_id, $content) {
    $activity_id = MediaMeta::get($media_id, 'bp_activity_id');
    if (!$activity_id || !function_exists('bp_activity_new_comment')) return;

    bp_activity_new_comment(array(
        'activity_id' => $activity_id,
        'content'     => $content,
        'user_id'     => $user_id,
    ));
}, 10, 4);
```

### Activity Comment → Media Comment

Hook: `bp_activity_comment_posted`

```php
add_action('bp_activity_comment_posted', function($comment_id, $params) {
    $activity = new BP_Activity_Activity($params['activity_id']);
    // Check if this activity has associated media
    // Parse data-mvs-media-id from activity content or check activity meta
    $media_ids = mvs_get_activity_media_ids($params['activity_id']);
    if (empty($media_ids)) return;

    // Create media comment on the first/primary media
    $comment_service = new CommentService();
    $comment_service->create(array(
        'media_id' => $media_ids[0],
        'user_id'  => $params['user_id'],
        'content'  => $params['content'],
        'source'   => 'bp_activity', // prevent infinite loop
    ));
}, 10, 2);
```

### Loop Prevention

Both hooks check a `source` field. If comment originated from BP sync, don't sync back to BP. If originated from media, don't sync back to media.

---

## Phase 7: QA + Release Prep

### Build

1. `npm run build` in free plugin root — regenerate all block JS bundles
2. Verify all 12 blocks have fresh build output

### Demo Data

1. Delete existing demo data: `wp mvs cleanup`
2. Re-seed with fresh data: `wp mvs seed`
3. Verify seeder creates thumbnails via new `generate_thumbnails()` (not wp_insert_attachment)

### Test Matrix

| Flow | Standalone | BP Activity | BP Group | BP Profile |
|------|-----------|-------------|----------|------------|
| Upload single image | Test | Test | Test | Verify appears |
| Upload multi image (2-6) | Test | Test | Test | Verify appears |
| Upload video | Test | Test | Test | Verify appears |
| Upload audio | Test | Test | Test | Verify appears |
| Grid display | Test | Test | Test | Test |
| Lightbox open | Test | Test | Test | Test |
| Lightbox reactions | Test | Test | Test | Test |
| Lightbox comments | Test | Test | Test | Test |
| Lightbox favorites | Test | Test | Test | Test |
| Lightbox share | Test | Test | Test | Test |
| Comments bridge | N/A | Test | Test | N/A |
| Privacy gates | Test | N/A | Test | Test |

### Release Checklist

- [ ] `npm run build` passes
- [ ] All PHP files pass `php -l` syntax check
- [ ] WPCS check (major violations only)
- [ ] Version 1.0.0 in all headers (wpmediaverse.php, readme.txt, package.json)
- [ ] .pot file generated
- [ ] .distignore excludes dev files
- [ ] No console.log, error_log, var_dump in production code
- [ ] Build ZIP for distribution
- [ ] Git tag v1.0.0

---

## Out of Scope for v1.0.0

These are explicitly deferred:

- Flickr/Pinterest/Dribbble layout modes (v1.1+)
- MediaRenderer unified class (nice-to-have, not blocking)
- Challenge/Battle/Tournament frontend submission UIs (Pro v1.1)
- Competition notifications
- Single media page enhancements (related media, download, EXIF)
- S3/BunnyCDN storage (Pro settings exist, not critical for launch)
- Video poster frame generation
- Advanced analytics dashboard
- Mobile app / PWA
