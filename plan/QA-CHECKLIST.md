# WPMediaVerse (Free) — QA Checklist

> Feature-grouped checklist. Each section is self-contained: settings, admin UI, frontend, REST API, database, hooks, and mobile checks all together.
> Mark PASS/FAIL for each item. Note issues inline.

---

## 1. Platform Foundation

### 1.1 Automated Scans (wp-plugin-qa MCP)

```
Plugin Path: /path/to/wp-content/plugins/wpmediaverse
Site URL:    http://mediaverse.local
```

- [ ] `wppqa_scan_plugin` — feature manifest matches this checklist
- [ ] `wppqa_run_code_checks` — PHPCS, PHPStan, ESLint, Stylelint, PHP compat (7.4-8.4), i18n, bundle size, security, performance, PCP
- [ ] `wppqa_check_a11y` — WCAG 2.1 AA (form labels, alt text, ARIA, focus, contrast, keyboard nav)
- [ ] `wppqa_check_ux` — help text, error messages, empty states, visual feedback, onboarding
- [ ] `wppqa_check_templates` — template files, override paths, lifecycle hooks
- [ ] `wppqa_check_api` — REST endpoints auth/unauth, nonce, malformed input
- [ ] `wppqa_check_database` — custom tables, settings persistence, orphan data
- [ ] `wppqa_check_browser` — generate Playwright E2E specs
- [ ] `wppqa_evaluate_product` — admin UI + frontend + marketing readiness scoring

### 1.2 Setup Wizard

- [ ] Step 1: Welcome → Next
- [ ] Step 2: Pages creation (Explore, Upload, Dashboard)
- [ ] Step 3: Settings configuration
- [ ] Completion → `mvs_setup_complete` option set to true
- [ ] Wizard doesn't show again after completion

### 1.3 Admin Overview Page

- [ ] Stats cards show: Total Media, Albums, Pending Review, Total Views, Storage Used
- [ ] Quick links: Add Media, Settings, Moderation, Stats → navigate correctly
- [ ] Import Demo Data button → creates 50 items, 5 users, 5 albums
- [ ] Admin dashboard widgets render via `mvs_dashboard_widgets` hook

### 1.4 WP-CLI Commands

- [ ] `wp mvs stats` — displays plugin statistics (media count, albums, views, reactions)
- [ ] `wp mvs migrate` — runs database migrations
- [ ] `wp mvs migrate --check` — checks migration status without running

### 1.5 Deactivation & Uninstall

- [ ] Deactivate plugin → rewrite rules flushed
- [ ] Uninstall plugin → all custom tables dropped (if clean uninstall enabled)
- [ ] Uninstall → all `mvs_*` options removed

---

## 2. Settings & Configuration

### 2.1 General Tab

- [ ] `mvs_max_upload_size` — change value, save, reload → persists (default: 104857600)
- [ ] `mvs_allowed_file_types` — edit MIME types, save → persists
- [ ] `mvs_default_privacy` — toggle public/members/private, save → persists
- [ ] `mvs_duplicate_action` — toggle warn/skip/allow, save → persists
- [ ] `mvs_strip_exif` — toggle on/off, save → persists
- [ ] `mvs_storage_driver` — shows "local" (S3/BunnyCDN require Pro)
- [ ] `mvs_signed_url_ttl` — change seconds, save → persists (default: 3600)

### 2.2 Display Tab

- [ ] `mvs_grid_columns` — select 2/3/4, save → explore grid updates
- [ ] `mvs_items_per_page` — select 12/24/48, save → pagination adjusts
- [ ] `mvs_thumbnail_style` — toggle square/original, save → thumbnails render correctly
- [ ] `mvs_thumbnail_size` — change pixel value, save → persists

### 2.3 Pages Tab

- [ ] `mvs_page_explore` — assign page, save → explore shortcode uses that page
- [ ] `mvs_page_upload` — assign page, save → upload form uses that page
- [ ] `mvs_page_dashboard` — assign page, save → dashboard uses that page

### 2.4 Permissions Tab

- [ ] Role capabilities editable per role (admin, editor, author, subscriber)
- [ ] Save role permissions → capabilities persist
- [ ] New user gets correct defaults

### 2.5 Settings Page Chrome

- [ ] All tabs render without errors: General, Display, Permissions, AI & Moderation, Webhooks, Messaging, Watermark, Pages
- [ ] Tab switching preserves unsaved state (or warns)
- [ ] `mvs_settings_before_save` action fires on save
- [ ] `mvs_settings_sidebar_after` action renders sidebar content

### 2.6 Sanitization & Security

- [ ] XSS payload in text fields → stripped on save
- [ ] Invalid MIME types → rejected or sanitized
- [ ] Negative upload size → rejected or set to 0
- [ ] SQL injection in text fields → escaped

---

## 3. Media Upload & Storage

### 3.1 FAB Upload — Photo

- [ ] Click (+) FAB on explore page → modal opens
- [ ] Select "Photo" tab → pick image → preview shows
- [ ] Add title, description, tags → select privacy → click Upload
- [ ] Success toast appears → media visible in explore feed
- [ ] 3 thumbnail sizes generated (check `mvs_media_meta`: thumb_large, thumb_medium, thumb_thumb)

### 3.2 FAB Upload — Gallery

- [ ] Click (+) FAB → select "Gallery" tab
- [ ] Select 2-4 images → previews show
- [ ] Upload → gallery post appears as single card with badge

### 3.3 FAB Upload — Video

- [ ] Click (+) FAB → select "Video" tab
- [ ] Select video file → upload completes
- [ ] Video card appears in feed

### 3.4 Dashboard Upload

- [ ] "Drop files here or click to upload" area works
- [ ] File appears in Media tab after upload

### 3.5 REST API Upload

- [ ] `POST /mvs/v1/media` with file → 201 response with media data
- [ ] Thumbnails generated server-side

### 3.6 Validation

- [ ] Upload disallowed MIME type → error message
- [ ] Upload file exceeding `mvs_max_upload_size` → error message
- [ ] Duplicate file with `mvs_duplicate_action=warn` → warning shown
- [ ] Duplicate file with `mvs_duplicate_action=skip` → silently skipped
- [ ] EXIF stripping: upload with `mvs_strip_exif=true` → EXIF removed from file

### 3.7 REST API — Media CRUD

- [ ] `GET /mvs/v1/media` — list with pagination, filtering
- [ ] `GET /mvs/v1/media/{id}` — read single
- [ ] `PUT /mvs/v1/media/{id}` ��� update (owner/admin only)
- [ ] `DELETE /mvs/v1/media/{id}` — delete (owner/admin only)
- [ ] `POST /mvs/v1/media/{id}/view` — record view (increments count)
- [ ] `GET /mvs/v1/media/{id}/access` — check access
- [ ] `GET /mvs/v1/media/{id}/group` — get gallery group items
- [ ] `GET /mvs/v1/me/media` — current user's media

### 3.8 Admin — All Media List

- [ ] Table columns: Thumbnail, Title, Author, Type, Privacy, Status, Date
- [ ] Type filter dropdown → filters by media type
- [ ] Privacy filter dropdown → filters by privacy level
- [ ] Search box → filters by title/description
- [ ] Pagination → navigates pages correctly
- [ ] View row action → opens single media
- [ ] Trash row action → moves to trash

### 3.9 Database

- [ ] New upload → row in `mvs_media_index` + `mvs_media_meta` (thumb sizes)
- [ ] New upload → NO row in `wp_posts` with `post_type='attachment'`
- [ ] Thumbnail files exist on disk at `uploads/wpmediaverse/YYYY/MM/`
- [ ] Delete media → rows removed from `mvs_media_index`, `mvs_media_meta`, `mvs_media_stats`
- [ ] Delete media → file removed from disk
- [ ] No orphaned meta entries after media deletion

### 3.10 Hooks

- [ ] `mvs_media_uploaded` action fires on successful upload
- [ ] `mvs_media_deleted` action fires on media delete
- [ ] `mvs_max_upload_size` filter enforces upload limit
- [ ] `mvs_allowed_file_types` filter controls MIME types
- [ ] `mvs_media_response` filter modifies REST media response

---

## 4. Media Display

### 4.1 URL Routing

- [ ] `/media/` → explore page loads (query var: `mvs_media_archive=1`)
- [ ] `/media/page/2/` → paginated explore loads
- [ ] `/media/{slug}/` → single media by slug loads
- [ ] `/media/{id}/` → single media by numeric ID loads
- [ ] `/media/@{username}/` → user profile media page loads
- [ ] `/media/@{username}/page/2/` → paginated profile loads
- [ ] `/media/edit-profile/` → profile edit form loads (logged in)

### 4.2 Explore / Feed

- [ ] Instagram grid layout displays correctly
- [ ] Card shows: thumbnail (not original size), author avatar + name, like count
- [ ] Long description has "more" button
- [ ] Comment preview shows (if comments exist)
- [ ] Timestamp shows (e.g. "3 hours ago")
- [ ] Stories bar shows at top with recent uploaders
- [ ] Pagination: scroll past items → more load
- [ ] `mvs_feed_sort_options` filter → custom sort options appear (default: date, trending, popular)
- [ ] `mvs_feed_args` filter → modifies feed query

### 4.3 Single Media Page

- [ ] `/media/{slug}/` loads correctly
- [ ] Image/video/audio renders
- [ ] Title, description, author, date show
- [ ] Tags displayed as clickable links
- [ ] View count shows

### 4.4 Template Files

- [ ] `templates/explore.php` renders explore grid
- [ ] `templates/media-single.php` renders single media
- [ ] `templates/album.php` renders album page
- [ ] `templates/collection.php` renders collection page
- [ ] `templates/profile-edit.php` renders profile edit
- [ ] `templates/messages.php` renders messaging UI
- [ ] `templates/partials/shared-ui-shell.php` renders FAB + lightbox shell
- [ ] `templates/partials/dashboard-content.php` renders dashboard

### 4.5 Theme Override System

- [ ] Copy `templates/explore.php` to `theme/wpmediaverse/explore.php` → plugin uses theme copy
- [ ] Modify theme copy → changes visible on frontend
- [ ] Remove theme copy → falls back to plugin template
- [ ] `mvs_locate_template` filter → can redirect template location via code
- [ ] `mvs_template_variables` filter → injects custom variables into template
- [ ] `mvs_before_template_render` action fires before template output
- [ ] `mvs_after_template_render` action fires after template output
- [ ] `mvs_body_classes` filter → custom CSS classes added to body

---

## 5. Lightbox

### 5.1 Open / Close

- [ ] Click image on explore → lightbox opens with dark overlay
- [ ] Image displays full size, author avatar + name + link in sidebar
- [ ] View count shows
- [ ] Click X → lightbox closes
- [ ] Click dark overlay → closes
- [ ] Press Escape → closes
- [ ] Body scroll restored after close

### 5.2 Gallery Navigation

- [ ] Open gallery post → prev/next arrows visible
- [ ] Click arrows → cycles through gallery images
- [ ] Position indicator shows (e.g. "2 / 4")

### 5.3 Reactions (in Lightbox)

- [ ] Click emoji → count increments, button highlights
- [ ] Click different emoji → switches reaction (previous deactivates)
- [ ] Click same emoji again → removes reaction (count decrements)

### 5.4 Favorites (in Lightbox)

- [ ] Click "Favorite" → changes to "Favorited" with filled heart
- [ ] Click again → unfavorites

### 5.5 Comments (in Lightbox)

- [ ] Type comment → click Post → comment appears in list
- [ ] Comment shows: avatar + author name (clickable) + text
- [ ] "No comments yet" message hides after first comment

### 5.6 Share & Open Link

- [ ] Click Share → "Copied!" feedback (or native share dialog)
- [ ] Click "Open" → navigates to `/media/{slug}/` single page

---

## 6. Albums & Collections

### 6.1 Albums

- [ ] Album page renders at `/media/album/{id}/`
- [ ] Create new album form works
- [ ] Shows user's albums (or empty state) in dashboard

### 6.2 REST API — Albums

- [ ] `GET /mvs/v1/albums` — list
- [ ] `POST /mvs/v1/albums` — create
- [ ] `GET /mvs/v1/albums/{id}` — read
- [ ] `PUT /mvs/v1/albums/{id}` — update
- [ ] `DELETE /mvs/v1/albums/{id}` — delete
- [ ] `POST /mvs/v1/albums/{id}/reorder` — reorder items
- [ ] `GET /mvs/v1/albums/{id}/items` — list items
- [ ] `POST /mvs/v1/albums/{id}/items` — add items
- [ ] `DELETE /mvs/v1/albums/{id}/items/{media_id}` — remove item
- [ ] `PUT /mvs/v1/albums/{id}/cover` — set cover image

### 6.3 Collections

- [ ] Collection page renders at `/media/collection/{id}/`
- [ ] Shows user's collections in dashboard

### 6.4 REST API — Collections

- [ ] `GET /mvs/v1/collections` — list
- [ ] `POST /mvs/v1/collections` — create
- [ ] `GET /mvs/v1/collections/{id}` — read
- [ ] `PUT /mvs/v1/collections/{id}` — update
- [ ] `DELETE /mvs/v1/collections/{id}` — delete
- [ ] `GET /mvs/v1/collections/{id}/rules` — get smart collection rules

---

## 7. Reactions

- [ ] Reactions bar on single media page — 6 emojis with counts
- [ ] Click emoji → count increments, button highlights
- [ ] Click different emoji → switches reaction
- [ ] Click same emoji again → removes reaction (count decrements)
- [ ] `POST /mvs/v1/media/{media_id}/reactions` — toggle reaction (body: type)
- [ ] `mvs_reaction_toggled` action fires

---

## 8. Comments

### 8.1 Frontend

- [ ] Comment form visible on single page (logged in)
- [ ] Post comment → appears with avatar + author link
- [ ] Edit own comment → inline edit form (within `mvs_comment_edit_window` = 15 min)
- [ ] Delete own comment → removed
- [ ] Preview comments (latest 2) show on feed cards

### 8.2 REST API

- [ ] `GET /mvs/v1/media/{media_id}/comments` — list comments for media
- [ ] `POST /mvs/v1/media/{media_id}/comments` — create comment
- [ ] `GET /mvs/v1/media/{media_id}/comments/{comment_id}` — read
- [ ] `PUT /mvs/v1/media/{media_id}/comments/{comment_id}` — update (within edit window)
- [ ] `DELETE /mvs/v1/media/{media_id}/comments/{comment_id}` — delete (owner only)

### 8.3 Hooks

- [ ] `mvs_comment_created` action fires
- [ ] `mvs_comment_edit_window` filter changes edit window (default: 15 min)

---

## 9. Favorites & Bookmarks

- [ ] Click "Favorite" on single page → changes to "Favorited" with filled heart
- [ ] Click again → unfavorites
- [ ] Favorites tab in dashboard shows favorited media
- [ ] `POST /mvs/v1/media/{media_id}/favorite` — toggle favorite
- [ ] `GET /mvs/v1/me/favorites` — list user favorites
- [ ] `mvs_favorite_toggled` action fires

---

## 10. Follow System

- [ ] Follow button shows for non-owner on profile/media
- [ ] Click Follow → changes to "Following"
- [ ] Click again → unfollows
- [ ] Follower/following counts update
- [ ] `POST /mvs/v1/users/{id}/follow` — follow/unfollow
- [ ] `GET /mvs/v1/users/{id}/followers` — followers list
- [ ] `GET /mvs/v1/users/{id}/following` — following list
- [ ] `GET /mvs/v1/me/following` — current user following
- [ ] `GET /mvs/v1/me/followers` — current user followers
- [ ] `mvs_user_followed` action fires
- [ ] `mvs_user_unfollowed` action fires

---

## 11. Notifications

- [ ] Notification created on: follow, reaction, comment, mention, favorite
- [ ] Unread count accurate
- [ ] Mark read works
- [ ] `GET /mvs/v1/me/notifications` — list notifications
- [ ] `POST /mvs/v1/me/notifications/read` — mark read
- [ ] `GET /mvs/v1/me/notifications/count` — unread count
- [ ] `mvs_notification_created` action fires
- [ ] `mvs_should_send_notification` filter can suppress

---

## 12. User Profiles & Dashboard

### 12.1 Profile Page

- [ ] `/media/@{username}/` loads user profile
- [ ] Profile header with avatar, username, stats
- [ ] Media grid displays
- [ ] Follow button visible for non-owner

### 12.2 Dashboard (/my-media/)

- [ ] Profile header with avatar, username, View/Edit Profile links
- [ ] 4 tabs: Media, Albums, Favorites, Collections
- [ ] Media tab: user's uploads with thumbnail, title, privacy badge, Edit/Delete buttons
- [ ] Albums tab: user's albums (or empty state)
- [ ] Favorites tab: media user has favorited
- [ ] Collections tab: user's collections
- [ ] Storage Quota: Images/Videos/Audio counts + limit display

### 12.3 Profile Edit

- [ ] `/media/edit-profile/` loads form
- [ ] Edit display name, bio, avatar → save → persists

### 12.4 REST API

- [ ] `GET /mvs/v1/users/{id}` — user profile
- [ ] `GET /mvs/v1/users/{id}/media` — user's media
- [ ] `GET /mvs/v1/users/search` — search users
- [ ] `GET /mvs/v1/me/profile` — get profile
- [ ] `PUT /mvs/v1/me/profile` — update profile
- [ ] `POST /mvs/v1/me/avatar` — upload avatar

---

## 13. Content Moderation & Reporting

### 13.1 AI Moderation Settings

- [ ] `mvs_ai_provider` — select provider, save → persists
- [ ] `mvs_openai_api_key` — enter key, save → masked on reload
- [ ] `mvs_openai_model` — select model, save → persists
- [ ] `mvs_ai_auto_moderate` — toggle, save → uploads trigger moderation
- [ ] `mvs_ai_auto_analyze` — toggle, save → uploads trigger analysis
- [ ] `mvs_ai_auto_apply_tags` — toggle, save → tags auto-applied
- [ ] `mvs_ai_monthly_budget` — set budget, save → enforced
- [ ] `mvs_ai_cost_per_call` — set cost, save → tracked
- [ ] `mvs_moderation_auto_action` — set flag/hide/delete, save → auto-action works
- [ ] `mvs_report_auto_hide_threshold` — set threshold, save → auto-hide triggers

### 13.2 Admin Moderation Queue

- [ ] Queue shows flagged media items
- [ ] Approve action → media published
- [ ] Reject action → media removed/hidden
- [ ] `mvs_moderation_tabs` filter → custom tabs appear
- [ ] Empty state message when no flagged items

### 13.3 Admin Stats Page

- [ ] Charts/metrics display correctly
- [ ] `mvs_stats_tabs` filter → custom tabs appear

### 13.4 Admin Log Viewer

- [ ] Error log entries display with timestamp, level, message
- [ ] Pagination works
- [ ] Empty state when no logs

### 13.5 Reporting & Blocking

- [ ] Report button visible on media
- [ ] Report media → `mvs_report_submitted` action fires
- [ ] Report user → report created
- [ ] Block user → `mvs_user_blocked` action fires
- [ ] Blocked user's content hidden from blocker

### 13.6 REST API

- [ ] `GET /mvs/v1/moderation` — list flagged
- [ ] `GET /mvs/v1/moderation/counts` — counts
- [ ] `POST /mvs/v1/moderation/{id}/approve` — approve
- [ ] `POST /mvs/v1/moderation/{id}/reject` — reject
- [ ] `POST /mvs/v1/moderation/{id}/analyze` — AI analyze
- [ ] `GET /mvs/v1/ai/usage` — AI usage stats
- [ ] `POST /mvs/v1/media/{id}/report` — report media
- [ ] `POST /mvs/v1/users/{id}/report` — report user
- [ ] `POST /mvs/v1/users/{id}/block` — block user
- [ ] `GET /mvs/v1/me/blocked` — list blocked users

### 13.7 Hooks

- [ ] `mvs_media_flagged` action fires when AI flags media
- [ ] `mvs_ai_moderation_result` filter modifies moderation result

---

## 14. Privacy & Access Control

- [ ] Private media → shows lock message for non-owners
- [ ] Members-only → visible when logged in, hidden when logged out
- [ ] `mvs_privacy_can_view` filter controls access
- [ ] `GET /mvs/v1/media/{media_id}/rules` — list rules
- [ ] `POST /mvs/v1/media/{media_id}/rules` — create rule
- [ ] `DELETE /mvs/v1/media/{media_id}/rules/{rule_id}` — delete rule
- [ ] `POST /mvs/v1/media/{media_id}/grant` — grant access
- [ ] `DELETE /mvs/v1/media/{media_id}/grant/{user_id}` — revoke access
- [ ] `GET /mvs/v1/media/{media_id}/signed-url` — generate signed URL
- [ ] `GET /mvs/v1/serve` — serve file via signed URL

---

## 15. Direct Messaging

### 15.1 Settings

- [ ] `mvs_dm_access` — set access level, save → DM rules enforced
- [ ] `mvs_dm_min_age` — set days, save → new accounts blocked from DM
- [ ] `mvs_show_online_status` — toggle, save → online indicator shows/hides

### 15.2 Conversations

- [ ] Create new conversation → `mvs_conversation_created` action fires
- [ ] List conversations → shows recent with preview
- [ ] Delete/archive conversation works

### 15.3 Messages

- [ ] Send text message → appears in chat
- [ ] Send media attachment → card displays
- [ ] Voice message → plays in chat
- [ ] `mvs_message_sent` action fires
- [ ] Edit message (within window) works
- [ ] Delete message → `mvs_message_deleted` action fires

### 15.4 Real-time

- [ ] Read receipts (conversation marked read → `mvs_conversation_read` fires)
- [ ] Online status indicator (when `mvs_show_online_status` enabled)
- [ ] Message reactions (emoji on message → `mvs_message_reaction_added` fires)

### 15.5 Access Control

- [ ] `mvs_can_send_message` filter → blocks unauthorized DMs
- [ ] `mvs_dm_access_level` filter → respects access level
- [ ] Rate limit: messages per minute (`mvs_dm_message_rate_limit`)
- [ ] Rate limit: conversations per hour (`mvs_dm_convo_rate_limit`)
- [ ] Max message length (`mvs_message_max_length`)
- [ ] Max DM upload size (`mvs_dm_max_upload_size`)
- [ ] Account age check (`mvs_dm_min_age`)

---

## 16. Watermarks

- [ ] `mvs_watermark_type` — toggle text/image, save → watermark type changes
- [ ] `mvs_watermark_text` — enter text, save → visible on media
- [ ] `mvs_watermark_position` — select from 9 positions, save → placement changes
- [ ] `mvs_watermark_opacity` — set 0-100, save → opacity changes
- [ ] `mvs_watermark_font_size` — set size, save → font size changes
- [ ] `mvs_watermark_color` — pick color, save → color changes
- [ ] `mvs_watermark_image` — select image, save → image watermark applied

---

## 17. Webhooks

- [ ] Add webhook (URL + events), save → webhook fires on event
- [ ] Edit webhook, save → changes persist
- [ ] Delete webhook → removed
- [ ] Webhook delivery uses HMAC-SHA256 signature
- [ ] Failed delivery tracked in `mvs_webhook_failures`

---

## 18. BuddyPress Integration

### 18.1 Activity Media Upload

- [ ] Post activity with 1 image → media attached with `data-mvs-media-id`
- [ ] Post activity with 3 images → grid layout (mvs-activity-grid-3)
- [ ] Max 6 media per activity (`mvs_activity_max_media` filter, default: 6)

### 18.2 BP Activity — Group

- [ ] Go to group page → post activity with image
- [ ] Media appears in group activity stream
- [ ] Media appears in `/groups/{slug}/media/` tab

### 18.3 BP Lightbox (Clone Approach)

- [ ] Click media in BP activity → lightbox opens
- [ ] Same sidebar layout as explore lightbox
- [ ] Reactions, favorites, comments all work
- [ ] Gallery navigation works for multi-image activities

### 18.4 Comment Sync

- [ ] Post comment on media via lightbox → appears as BP activity comment
- [ ] One-way sync only (media → activity)
- [ ] No infinite loop — 1 comment = 1 BP activity comment
- [ ] Check `/wp-admin/admin.php?page=bp-activity` → no duplicate flood
- [ ] Multi-image activity: comments on different media all appear on same activity

### 18.5 Profile Media Tab

- [ ] `/members/{user}/media/` → Media tab active in profile nav
- [ ] Shows media count badge (e.g. "Media 9")
- [ ] Grid with stats overlay (views, likes, comments)
- [ ] Click image → lightbox opens
- [ ] Sub-tabs: Media (default), Albums

### 18.6 Group Media Tab

- [ ] `/groups/{slug}/media/` → Media tab active in group nav
- [ ] Sub-tabs: Media | Albums
- [ ] Shows media uploaded via group activity
- [ ] Empty state for groups with no media
- [ ] "Upload Media" button visible for group members

### 18.7 URL Integration

- [ ] `mvs_user_profile_url` filter auto-detects BP → returns BP profile URL
- [ ] Slug-based fallback for old activity posts (no `data-mvs-media-id`)

---

## 19. Shortcodes & Blocks

### 19.1 Shortcodes

- [ ] `[mvs_gallery]` — renders media grid; test attrs: type, category, tag, orderby
- [ ] `[mvs_upload]` — renders upload form; test attrs: max_files, show_privacy
- [ ] `[mvs_album id="X"]` — renders album; test attrs: columns, show_title, show_description
- [ ] `[mvs_player id="X"]` — renders player; test attrs: autoplay, loop, download
- [ ] `[mvs_stats]` — renders stats; test attrs: views, downloads, reactions, top, top_count
- [ ] `[mvs_dashboard]` — renders dashboard (requires login, redirect if logged out)
- [ ] `[mvs_collection id="X"]` — renders collection; test attrs: columns, per_page
- [ ] `[mvs_profile_edit]` — renders profile edit (requires login)

### 19.2 Gutenberg Blocks

- [ ] `wpmediaverse/media-upload` — renders in editor + frontend
- [ ] `wpmediaverse/media-grid` — renders in editor + frontend
- [ ] `wpmediaverse/media-player` — renders in editor + frontend
- [ ] `wpmediaverse/album-viewer` — renders in editor + frontend
- [ ] `wpmediaverse/story-viewer` — renders in editor + frontend
- [ ] `wpmediaverse/media-stats` — renders in editor + frontend
- [ ] `wpmediaverse/explore-feed` — renders in editor + frontend
- [ ] `wpmediaverse/lock-overlay` — renders in editor + frontend
- [ ] Block category `wpmediaverse` appears in inserter
- [ ] Block attributes save/load correctly

---

## 20. Developer Hooks & Interfaces

### 20.1 Action Hooks (spot-check)

- [ ] `mvs_loaded` — fires after init, passes ServiceContainer
- [ ] `mvs_story_created` — fires on story creation
- [ ] `mvs_before_template_render` — fires before template output
- [ ] `mvs_after_template_render` — fires after template output

### 20.2 Filter Hooks (spot-check)

- [ ] `mvs_feed_sort_options` — customizes sort options
- [ ] `mvs_user_profile_url` — customizes profile URL
- [ ] `mvs_user_display_name` — customizes display name
- [ ] `mvs_settings_sections` — adds settings sections
- [ ] `mvs_theme_json` — customizes design tokens
- [ ] `mvs_rest_pagination_max` filter → enforces max page size (default: 100)

### 20.3 Interfaces

- [ ] `StorageDriverInterface` — custom storage driver can be registered via `mvs_storage_drivers` filter
- [ ] `AIProviderInterface` — custom AI provider can be registered via `mvs_ai_providers` hook
- [ ] `TransportInterface` — custom messaging transport can replace REST polling

### 20.4 Stats & Tags REST

- [ ] `GET /mvs/v1/media/{media_id}/stats` — media stats
- [ ] `GET /mvs/v1/me/stats` — user stats
- [ ] `GET /mvs/v1/tags` — list
- [ ] `GET /mvs/v1/tags/{id}` — read
- [ ] `POST /mvs/v1/tags` — create
- [ ] `POST /mvs/v1/tags/merge` — merge tags (body: source_id, target_id)
- [ ] `GET /mvs/v1/tags/cloud` — tag cloud

### 20.5 Bulk Operations & Activity

- [ ] `POST /mvs/v1/media/bulk` — bulk update/delete
- [ ] `GET /mvs/v1/feed` — activity feed
- [ ] `GET /mvs/v1/users/{id}/activity` — user activity
- [ ] `mvs_activity_types` filter customizes activity types

---

## 21. Database Integrity

### 21.1 Custom Tables (21 total)

- [ ] `mvs_media_index` — exists, AUTO_INCREMENT PK
- [ ] `mvs_media_meta` — exists
- [ ] `mvs_media_stats` — exists
- [ ] `mvs_media_views` — exists
- [ ] `mvs_album_items` — exists
- [ ] `mvs_reactions` — exists
- [ ] `mvs_favorites` — exists
- [ ] `mvs_mentions` — exists
- [ ] `mvs_follows` — exists
- [ ] `mvs_notifications` — exists
- [ ] `mvs_activity` — exists
- [ ] `mvs_reports` — exists
- [ ] `mvs_blocks` — exists
- [ ] `mvs_access_rules` — exists
- [ ] `mvs_access_grants` — exists
- [ ] `mvs_conversations` — exists
- [ ] `mvs_conversation_participants` — exists
- [ ] `mvs_messages` — exists
- [ ] `mvs_message_reactions` — exists
- [ ] `mvs_transactions` — exists
- [ ] `mvs_error_log` — exists

### 21.2 Options

- [ ] `mvs_db_version` option matches current migration version
- [ ] `mvs_caps_version` option exists
- [ ] `mvs_rewrite_version` option exists

---

## 22. Mobile & Edge Cases

### 22.1 REST API Security

- [ ] Unauthenticated request to protected endpoint → 401
- [ ] Non-owner request to owner-only endpoint → 403
- [ ] Missing nonce → 403
- [ ] Malformed JSON body → 400 with error details

### 22.2 Logged Out User

- [ ] Explore page → public media visible
- [ ] Click image → lightbox opens (no social actions / login prompt)
- [ ] Single media page → visible, "Log in to comment" message
- [ ] Private media → not visible or shows lock icon
- [ ] Dashboard → redirects to login

### 22.3 Non-Owner User

- [ ] Cannot edit/delete another user's media
- [ ] Can react, comment, favorite
- [ ] Follow button visible on other's profile/media
- [ ] Cannot access other user's draft/private media

### 22.4 Mobile (390px viewport)

- [ ] Explore grid responsive (single column or 2-col)
- [ ] Lightbox fills screen properly
- [ ] Upload modal usable on touch
- [ ] BP activity media sized correctly
- [ ] Dashboard tabs navigable
- [ ] Settings page usable (tabs scroll or stack)
- [ ] FAB button positioned correctly
