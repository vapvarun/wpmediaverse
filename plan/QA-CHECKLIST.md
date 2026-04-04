# WPMediaVerse (Free) — QA Checklist

> Comprehensive audit checklist covering backend, frontend, REST API, developer hooks, template overrides, and data integrity.
> Mark PASS/FAIL for each item. Note issues inline.

---

## 1. Automated Scans (wp-plugin-qa MCP)

Run these first — they catch code quality, accessibility, and structural issues automatically.

```
Plugin Path: /path/to/wp-content/plugins/wpmediaverse
Site URL:    http://mediaverse.local (for live checks)
```

- [ ] `wppqa_scan_plugin` — feature manifest matches this checklist
- [ ] `wppqa_run_code_checks` — PHPCS, PHPStan, ESLint, Stylelint, PHP compat (7.4-8.4), i18n, bundle size, security, performance, PCP
- [ ] `wppqa_check_a11y` — WCAG 2.1 AA (form labels, alt text, ARIA, focus, contrast, keyboard nav)
- [ ] `wppqa_check_ux` — help text, error messages, empty states, visual feedback, onboarding
- [ ] `wppqa_check_templates` — template files, override paths, lifecycle hooks
- [ ] `wppqa_check_api` — REST endpoints auth/unauth, nonce, malformed input (requires site_url)
- [ ] `wppqa_check_database` — custom tables, settings persistence, orphan data (requires site_url)
- [ ] `wppqa_check_browser` — generate Playwright E2E specs (requires site_url)
- [ ] `wppqa_evaluate_product` — admin UI + frontend + marketing readiness scoring

---

## 2. Admin Settings

### 2.1 General Tab

- [ ] `mvs_max_upload_size` — change value, save, reload → persists (default: 104857600)
- [ ] `mvs_allowed_file_types` — edit MIME types, save → persists (default: image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg)
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

### 2.5 AI & Moderation Tab

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

### 2.6 Webhooks Tab

- [ ] Add webhook (URL + events), save → webhook fires on event
- [ ] Edit webhook, save → changes persist
- [ ] Delete webhook → removed
- [ ] Webhook delivery uses HMAC-SHA256 signature
- [ ] Failed delivery tracked in `mvs_webhook_failures`

### 2.7 Messaging Tab

- [ ] `mvs_dm_access` — set access level, save → DM rules enforced
- [ ] `mvs_dm_min_age` — set days, save → new accounts blocked from DM
- [ ] `mvs_show_online_status` — toggle, save → online indicator shows/hides

### 2.8 Watermark Tab

- [ ] `mvs_watermark_type` — toggle text/image, save → watermark type changes
- [ ] `mvs_watermark_text` — enter text, save → visible on media
- [ ] `mvs_watermark_position` — select from 9 positions, save → placement changes
- [ ] `mvs_watermark_opacity` — set 0-100, save → opacity changes
- [ ] `mvs_watermark_font_size` — set size, save → font size changes
- [ ] `mvs_watermark_color` — pick color, save → color changes
- [ ] `mvs_watermark_image` — select image, save → image watermark applied

### 2.9 Settings Sanitization

- [ ] XSS payload in text fields → stripped on save
- [ ] Invalid MIME types → rejected or sanitized
- [ ] Negative upload size → rejected or set to 0
- [ ] SQL injection in text fields → escaped

---

## 3. Admin Pages

### 3.1 Overview Page

- [ ] Stats cards show: Total Media, Albums, Pending Review, Total Views, Storage Used
- [ ] Quick links: Add Media, Settings, Moderation, Stats → navigate correctly
- [ ] Import Demo Data button → creates 50 items, 5 users, 5 albums
- [ ] Admin dashboard widgets render via `mvs_dashboard_widgets` hook

### 3.2 All Media List

- [ ] Table columns: Thumbnail, Title, Author, Type, Privacy, Status, Date
- [ ] Type filter dropdown → filters by media type
- [ ] Privacy filter dropdown → filters by privacy level
- [ ] Search box → filters by title/description
- [ ] Pagination → navigates pages correctly
- [ ] View row action → opens single media
- [ ] Trash row action → moves to trash

### 3.3 Settings Page

- [ ] All tabs render without errors: General, Display, Permissions, AI & Moderation, Webhooks, Messaging, Watermark, Pages
- [ ] Tab switching preserves unsaved state (or warns)
- [ ] `mvs_settings_before_save` action fires on save
- [ ] `mvs_settings_sidebar_after` action renders sidebar content

### 3.4 Moderation Queue

- [ ] Queue shows flagged media items
- [ ] Approve action → media published
- [ ] Reject action → media removed/hidden
- [ ] `mvs_moderation_tabs` filter → custom tabs appear
- [ ] Empty state message when no flagged items

### 3.5 Stats Page

- [ ] Charts/metrics display correctly
- [ ] `mvs_stats_tabs` filter → custom tabs appear
- [ ] Date range filtering (if applicable)

### 3.6 Log Viewer

- [ ] Error log entries display with timestamp, level, message
- [ ] Pagination works
- [ ] Empty state when no logs

### 3.7 Setup Wizard

- [ ] Step 1: Welcome → Next
- [ ] Step 2: Pages creation (Explore, Upload, Dashboard)
- [ ] Step 3: Settings configuration
- [ ] Completion → `mvs_setup_complete` option set to true
- [ ] Wizard doesn't show again after completion

---

## 4. Frontend Pages & Templates

### 4.1 URL Routing

- [ ] `/media/` → explore page loads (query var: `mvs_media_archive=1`)
- [ ] `/media/page/2/` → paginated explore loads
- [ ] `/media/{slug}/` → single media by slug loads
- [ ] `/media/{id}/` → single media by numeric ID loads
- [ ] `/media/@{username}/` → user profile media page loads
- [ ] `/media/@{username}/page/2/` → paginated profile loads
- [ ] `/media/edit-profile/` → profile edit form loads (logged in)

### 4.2 Template Files

- [ ] `templates/explore.php` renders explore grid
- [ ] `templates/media-single.php` renders single media
- [ ] `templates/album.php` renders album page
- [ ] `templates/collection.php` renders collection page
- [ ] `templates/profile-edit.php` renders profile edit
- [ ] `templates/messages.php` renders messaging UI
- [ ] `templates/partials/shared-ui-shell.php` renders FAB + lightbox shell
- [ ] `templates/partials/dashboard-content.php` renders dashboard

### 4.3 Theme Override System

- [ ] Copy `templates/explore.php` to `theme/wpmediaverse/explore.php` → plugin uses theme copy
- [ ] Modify theme copy → changes visible on frontend
- [ ] Remove theme copy → falls back to plugin template
- [ ] `mvs_locate_template` filter → can redirect template location via code
- [ ] `mvs_template_variables` filter → injects custom variables into template
- [ ] `mvs_before_template_render` action fires before template output
- [ ] `mvs_after_template_render` action fires after template output
- [ ] `mvs_body_classes` filter → custom CSS classes added to body

---

## 5. Upload Flows

### 5.1 FAB Button — Photo

- [ ] Click (+) FAB on explore page → modal opens
- [ ] Select "Photo" tab → pick image → preview shows
- [ ] Add title, description, tags → select privacy → click Upload
- [ ] Success toast appears → media visible in explore feed
- [ ] 3 thumbnail sizes generated (check `mvs_media_meta`: thumb_large, thumb_medium, thumb_thumb)

### 5.2 FAB Button — Gallery

- [ ] Click (+) FAB → select "Gallery" tab
- [ ] Select 2-4 images → previews show
- [ ] Upload → gallery post appears as single card with badge

### 5.3 FAB Button — Video

- [ ] Click (+) FAB → select "Video" tab
- [ ] Select video file → upload completes
- [ ] Video card appears in feed

### 5.4 BP Activity — Single Image

- [ ] Go to /activity/ → click media attachment button
- [ ] Select 1 image → preview shows in form
- [ ] Post update → activity appears with text + media
- [ ] Image has `data-mvs-media-id` attribute

### 5.5 BP Activity — Multi Image

- [ ] Post activity with 3 images → grid layout displays (mvs-activity-grid-3)
- [ ] Each image clickable for lightbox

### 5.6 BP Activity — Group

- [ ] Go to group page → post activity with image
- [ ] Media appears in group activity stream
- [ ] Media appears in `/groups/{slug}/media/` tab

### 5.7 Dashboard Upload

- [ ] "Drop files here or click to upload" area works
- [ ] File appears in Media tab after upload

### 5.8 REST API Upload

- [ ] `POST /mvs/v1/media` with file → 201 response with media data
- [ ] Thumbnails generated server-side

### 5.9 Validation

- [ ] Upload disallowed MIME type → error message
- [ ] Upload file exceeding `mvs_max_upload_size` → error message
- [ ] Duplicate file with `mvs_duplicate_action=warn` → warning shown
- [ ] Duplicate file with `mvs_duplicate_action=skip` → silently skipped
- [ ] EXIF stripping: upload with `mvs_strip_exif=true` → EXIF removed from file

---

## 6. Explore / Feed

- [ ] Instagram grid layout displays correctly
- [ ] Card shows: thumbnail (not original size), author avatar + name, like count
- [ ] Long description has "more" button
- [ ] Comment preview shows (if comments exist)
- [ ] Timestamp shows (e.g. "3 hours ago")
- [ ] Stories bar shows at top with recent uploaders
- [ ] Pagination: scroll past items → more load
- [ ] `mvs_feed_sort_options` filter → custom sort options appear (default: date, trending, popular)
- [ ] `mvs_feed_args` filter → modifies feed query

---

## 7. Lightbox (Interactivity API)

### 7.1 Open / Close

- [ ] Click image on explore → lightbox opens with dark overlay
- [ ] Image displays full size, author avatar + name + link in sidebar
- [ ] View count shows
- [ ] Click X → lightbox closes
- [ ] Click dark overlay → closes
- [ ] Press Escape → closes
- [ ] Body scroll restored after close

### 7.2 Reactions

- [ ] Click emoji → count increments, button highlights
- [ ] Click different emoji → switches reaction (previous deactivates)
- [ ] Click same emoji again → removes reaction (count decrements)
- [ ] `mvs_reaction_toggled` action fires (verify via debug log or hook test)

### 7.3 Favorites

- [ ] Click "Favorite" → changes to "Favorited" with filled heart
- [ ] Click again → unfavorites
- [ ] `mvs_favorite_toggled` action fires

### 7.4 Comments

- [ ] Type comment → click Post → comment appears in list
- [ ] Comment shows: avatar + author name (clickable) + text
- [ ] "No comments yet" message hides after first comment
- [ ] `mvs_comment_created` action fires

### 7.5 Share

- [ ] Click Share → "Copied!" feedback (or native share dialog)

### 7.6 Open Link

- [ ] Click "Open" → navigates to `/media/{slug}/` single page

### 7.7 Gallery Navigation

- [ ] Open gallery post → prev/next arrows visible
- [ ] Click arrows → cycles through gallery images
- [ ] Position indicator shows (e.g. "2 / 4")

---

## 8. Single Media Page

### 8.1 Display

- [ ] `/media/{slug}/` loads correctly
- [ ] Image/video/audio renders
- [ ] Title, description, author, date show
- [ ] Tags displayed as clickable links

### 8.2 Social Actions

- [ ] Reactions bar — 6 emojis with counts
- [ ] Favorite button toggles
- [ ] Share button copies link
- [ ] Report button visible

### 8.3 Comments

- [ ] Comment form visible (logged in)
- [ ] Post comment → appears with avatar + author link
- [ ] Edit own comment → inline edit form (within `mvs_comment_edit_window` = 15 min)
- [ ] Delete own comment → removed

### 8.4 Follow

- [ ] Follow button shows for non-owner
- [ ] Click Follow → changes to "Following"
- [ ] Click again → unfollows
- [ ] `mvs_user_followed` / `mvs_user_unfollowed` actions fire

### 8.5 Privacy

- [ ] Private media → shows lock message for non-owners
- [ ] Members-only → visible when logged in, hidden when logged out
- [ ] `mvs_privacy_can_view` filter controls access

---

## 9. Dashboard (/my-media/)

### 9.1 Layout

- [ ] Profile header with avatar, username, View/Edit Profile links
- [ ] 4 tabs: Media, Albums, Favorites, Collections

### 9.2 Media Tab

- [ ] Shows user's uploaded media
- [ ] Each item: thumbnail, title, privacy badge, Edit/Delete buttons
- [ ] Upload area ("Drop files here or click to upload")

### 9.3 Albums Tab

- [ ] Shows user's albums (or empty state)
- [ ] Create new album form works

### 9.4 Favorites Tab

- [ ] Shows media user has favorited

### 9.5 Collections Tab

- [ ] Shows user's collections

### 9.6 Storage Quota

- [ ] Shows Images/Videos/Audio counts
- [ ] Shows quota limit (Unlimited or specific)

---

## 10. Social Features

### 10.1 Follow System

- [ ] Follow user → appears in following list
- [ ] Unfollow user → removed from following list
- [ ] Follower/following counts update
- [ ] `GET /mvs/v1/users/{id}/followers` returns correct list
- [ ] `GET /mvs/v1/users/{id}/following` returns correct list

### 10.2 Notifications

- [ ] Notification created on: follow, reaction, comment, mention, favorite
- [ ] `mvs_notification_created` action fires
- [ ] `mvs_should_send_notification` filter can suppress
- [ ] Unread count (`GET /me/notifications/count`) accurate
- [ ] Mark read (`POST /me/notifications/read`) works

### 10.3 Mentions

- [ ] Type @username in comment → mention created
- [ ] `mvs_mentions_created` action fires
- [ ] Mentioned user gets notification

### 10.4 Blocking & Reporting

- [ ] Report media → `mvs_report_submitted` action fires
- [ ] Report user → report created
- [ ] Block user → `mvs_user_blocked` action fires
- [ ] Blocked user's content hidden from blocker

### 10.5 Activity Feed

- [ ] `GET /mvs/v1/feed` returns recent activity
- [ ] `GET /mvs/v1/users/{id}/activity` returns user activity
- [ ] `mvs_activity_types` filter customizes activity types

---

## 11. BuddyPress Integration

### 11.1 Activity Media Upload

- [ ] Post activity with 1 image → media attached with `data-mvs-media-id`
- [ ] Post activity with 3 images → grid layout (mvs-activity-grid-3)
- [ ] Max 6 media per activity (`mvs_activity_max_media` filter, default: 6)

### 11.2 BP Lightbox (Clone Approach)

- [ ] Click media in BP activity → lightbox opens
- [ ] Same sidebar layout as explore lightbox
- [ ] Reactions, favorites, comments all work
- [ ] Gallery navigation works for multi-image activities

### 11.3 Comment Sync

- [ ] Post comment on media via lightbox → appears as BP activity comment
- [ ] One-way sync only (media → activity)
- [ ] No infinite loop — 1 comment = 1 BP activity comment
- [ ] Check `/wp-admin/admin.php?page=bp-activity` → no duplicate flood
- [ ] Multi-image activity: comments on different media all appear on same activity

### 11.4 Profile Media Tab

- [ ] `/members/{user}/media/` → Media tab active in profile nav
- [ ] Shows media count badge (e.g. "Media 9")
- [ ] Grid with stats overlay (views, likes, comments)
- [ ] Click image → lightbox opens
- [ ] Sub-tabs: Media (default), Albums

### 11.5 Group Media Tab

- [ ] `/groups/{slug}/media/` → Media tab active in group nav
- [ ] Sub-tabs: Media | Albums
- [ ] Shows media uploaded via group activity
- [ ] Empty state for groups with no media
- [ ] "Upload Media" button visible for group members

### 11.6 URL Integration

- [ ] `mvs_user_profile_url` filter auto-detects BP → returns BP profile URL
- [ ] Slug-based fallback for old activity posts (no `data-mvs-media-id`)

---

## 12. Direct Messaging

### 12.1 Conversations

- [ ] Create new conversation → `mvs_conversation_created` action fires
- [ ] List conversations → shows recent with preview
- [ ] Delete/archive conversation works

### 12.2 Messages

- [ ] Send text message → appears in chat
- [ ] Send media attachment → card displays
- [ ] Voice message → plays in chat
- [ ] `mvs_message_sent` action fires
- [ ] Edit message (within window) works
- [ ] Delete message → `mvs_message_deleted` action fires

### 12.3 Real-time

- [ ] Read receipts (conversation marked read → `mvs_conversation_read` fires)
- [ ] Online status indicator (when `mvs_show_online_status` enabled)
- [ ] Message reactions (emoji on message → `mvs_message_reaction_added` fires)

### 12.4 Access Control

- [ ] `mvs_can_send_message` filter → blocks unauthorized DMs
- [ ] `mvs_dm_access_level` filter → respects access level
- [ ] Rate limit: messages per minute (`mvs_dm_message_rate_limit`)
- [ ] Rate limit: conversations per hour (`mvs_dm_convo_rate_limit`)
- [ ] Max message length (`mvs_message_max_length`)
- [ ] Max DM upload size (`mvs_dm_max_upload_size`)
- [ ] Account age check (`mvs_dm_min_age`)

---

## 13. REST API Endpoints

### 13.1 Media (`/mvs/v1/media`)

- [ ] `GET /media` — list with pagination, filtering
- [ ] `POST /media` — create (auth required)
- [ ] `GET /media/{id}` — read single
- [ ] `PUT /media/{id}` — update (owner/admin only)
- [ ] `DELETE /media/{id}` — delete (owner/admin only)
- [ ] `POST /media/{id}/view` — record view (increments count)
- [ ] `GET /media/{id}/access` — check access
- [ ] `GET /media/{id}/group` — get gallery group items
- [ ] `GET /me/media` — current user's media

### 13.2 Albums (`/mvs/v1/albums`)

- [ ] `GET /albums` — list
- [ ] `POST /albums` — create
- [ ] `GET /albums/{id}` — read
- [ ] `PUT /albums/{id}` — update
- [ ] `DELETE /albums/{id}` — delete
- [ ] `POST /albums/{id}/reorder` — reorder items
- [ ] `GET /albums/{id}/items` — list items
- [ ] `POST /albums/{id}/items` — add items
- [ ] `DELETE /albums/{id}/items/{media_id}` — remove item
- [ ] `PUT /albums/{id}/cover` — set cover image

### 13.3 Collections (`/mvs/v1/collections`)

- [ ] `GET /collections` — list
- [ ] `POST /collections` — create
- [ ] `GET /collections/{id}` — read
- [ ] `PUT /collections/{id}` — update
- [ ] `DELETE /collections/{id}` — delete
- [ ] `GET /collections/{id}/rules` — get smart collection rules

### 13.4 Reactions (`/mvs/v1/media/{id}/reactions`)

- [ ] `POST /media/{id}/reactions` — toggle reaction (body: type)

### 13.5 Comments (`/mvs/v1/comments`)

- [ ] `GET /comments` — list (with media_id filter)
- [ ] `POST /comments` — create
- [ ] `GET /comments/{id}` — read
- [ ] `PUT /comments/{id}` — update (within edit window)
- [ ] `DELETE /comments/{id}` — delete (owner only)

### 13.6 Favorites (`/mvs/v1`)

- [ ] `POST /media/{id}/favorite` — toggle favorite
- [ ] `GET /me/favorites` — list user favorites

### 13.7 Stats (`/mvs/v1`)

- [ ] `GET /media/{id}/stats` — media stats
- [ ] `GET /me/stats` — user stats

### 13.8 Tags (`/mvs/v1/tags`)

- [ ] `GET /tags` — list
- [ ] `GET /tags/{id}` — read
- [ ] `POST /tags` — create
- [ ] `POST /tags/{source_id}/merge/{target_id}` — merge tags

### 13.9 Moderation (`/mvs/v1/moderation`)

- [ ] `GET /moderation` — list flagged
- [ ] `GET /moderation/counts` — counts
- [ ] `POST /moderation/{id}/approve` — approve
- [ ] `POST /moderation/{id}/reject` — reject
- [ ] `POST /moderation/{id}/analyze` — AI analyze
- [ ] `GET /ai/usage` — AI usage stats

### 13.10 Access Control (`/mvs/v1/media/{id}`)

- [ ] `GET /media/{id}/rules` — list rules
- [ ] `POST /media/{id}/rules` — create rule
- [ ] `DELETE /media/{id}/rules/{rule_id}` — delete rule
- [ ] `POST /media/{id}/grant` — grant access
- [ ] `DELETE /media/{id}/access/{user_id}` — revoke access

### 13.11 Signed URLs (`/mvs/v1`)

- [ ] `GET /media/{id}/signed-url` — generate signed URL
- [ ] `GET /serve` — serve file via signed URL

### 13.12 Bulk Operations (`/mvs/v1/bulk`)

- [ ] `POST /bulk` — bulk update/delete

### 13.13 Reports (`/mvs/v1`)

- [ ] `POST /media/{id}/report` — report media
- [ ] `POST /users/{id}/report` — report user
- [ ] `POST /users/{id}/block` — block user
- [ ] `GET /me/blocked` — list blocked users

### 13.14 Activity (`/mvs/v1`)

- [ ] `GET /feed` — activity feed
- [ ] `GET /users/{id}/activity` — user activity

### 13.15 Users (`/mvs/v1/users`)

- [ ] `GET /users/{id}` — user profile
- [ ] `GET /users/{id}/media` — user's media
- [ ] `GET /users/search` — search users

### 13.16 Follow (`/mvs/v1/users`)

- [ ] `POST /users/{id}/follow` — follow/unfollow
- [ ] `GET /users/{id}/followers` — followers list
- [ ] `GET /users/{id}/following` — following list
- [ ] `GET /me/following` — current user following
- [ ] `GET /me/followers` — current user followers

### 13.17 Notifications (`/mvs/v1/me`)

- [ ] `GET /me/notifications` — list notifications
- [ ] `POST /me/notifications/read` — mark read
- [ ] `GET /me/notifications/count` — unread count

### 13.18 Profile (`/mvs/v1/me`)

- [ ] `GET /me/profile` — get profile
- [ ] `PUT /me/profile` — update profile
- [ ] `POST /me/avatar` — upload avatar

### 13.19 API Security

- [ ] Unauthenticated request to protected endpoint → 401
- [ ] Non-owner request to owner-only endpoint → 403
- [ ] Missing nonce → 403
- [ ] Malformed JSON body → 400 with error details
- [ ] `mvs_rest_pagination_max` filter → enforces max page size (default: 100)

---

## 14. Shortcodes

- [ ] `[mvs_gallery]` — renders media grid; test attrs: type, category, tag, orderby
- [ ] `[mvs_upload]` — renders upload form; test attrs: max_files, show_privacy
- [ ] `[mvs_album id="X"]` — renders album; test attrs: columns, show_title, show_description
- [ ] `[mvs_player id="X"]` — renders player; test attrs: autoplay, loop, download
- [ ] `[mvs_stats]` — renders stats; test attrs: views, downloads, reactions, top, top_count
- [ ] `[mvs_dashboard]` — renders dashboard (requires login, redirect if logged out)
- [ ] `[mvs_collection id="X"]` — renders collection; test attrs: columns, per_page
- [ ] `[mvs_profile_edit]` — renders profile edit (requires login)

---

## 15. Gutenberg Blocks

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

## 16. Developer Hooks & Filters

### 16.1 Action Hooks (spot-check critical ones)

- [ ] `mvs_loaded` — fires after init, passes ServiceContainer
- [ ] `mvs_media_uploaded` — fires on successful upload
- [ ] `mvs_comment_created` — fires on comment post
- [ ] `mvs_reaction_toggled` — fires on reaction toggle
- [ ] `mvs_favorite_toggled` — fires on favorite toggle
- [ ] `mvs_user_followed` — fires on follow
- [ ] `mvs_user_unfollowed` — fires on unfollow
- [ ] `mvs_media_deleted` — fires on media delete
- [ ] `mvs_notification_created` — fires on notification create
- [ ] `mvs_before_template_render` — fires before template output
- [ ] `mvs_after_template_render` — fires after template output
- [ ] `mvs_media_flagged` — fires when AI flags media
- [ ] `mvs_story_created` — fires on story creation
- [ ] `mvs_report_submitted` — fires on report
- [ ] `mvs_user_blocked` — fires on block

### 16.2 Filter Hooks (spot-check critical ones)

- [ ] `mvs_media_response` — modifies REST media response
- [ ] `mvs_feed_args` — modifies feed query args
- [ ] `mvs_feed_sort_options` — customizes sort options
- [ ] `mvs_privacy_can_view` — controls media access
- [ ] `mvs_user_profile_url` — customizes profile URL
- [ ] `mvs_user_display_name` — customizes display name
- [ ] `mvs_locate_template` — overrides template location
- [ ] `mvs_template_variables` — injects template data
- [ ] `mvs_body_classes` — adds CSS body classes
- [ ] `mvs_max_upload_size` — enforces upload limit
- [ ] `mvs_allowed_file_types` — filters MIME types
- [ ] `mvs_should_send_notification` — suppresses notifications
- [ ] `mvs_comment_edit_window` — changes edit window (default: 15 min)
- [ ] `mvs_activity_max_media` — changes max media per activity (default: 6)
- [ ] `mvs_can_send_message` — controls DM sending
- [ ] `mvs_settings_sections` — adds settings sections
- [ ] `mvs_moderation_tabs` — adds moderation tabs
- [ ] `mvs_stats_tabs` — adds stats tabs
- [ ] `mvs_ai_moderation_result` — modifies moderation result
- [ ] `mvs_theme_json` — customizes design tokens

### 16.3 Interfaces (extensibility)

- [ ] `StorageDriverInterface` — custom storage driver can be registered via `mvs_storage_drivers` filter
- [ ] `AIProviderInterface` — custom AI provider can be registered via `mvs_ai_providers` hook
- [ ] `TransportInterface` — custom messaging transport can replace REST polling

---

## 17. WP-CLI Commands

- [ ] `wp mvs stats` — displays plugin statistics (media count, albums, views, reactions)
- [ ] `wp mvs migrate` — runs database migrations
- [ ] `wp mvs migrate --check` — checks migration status without running

---

## 18. Edge Cases & Mobile

### 18.1 Logged Out User

- [ ] Explore page → public media visible
- [ ] Click image → lightbox opens (no social actions / login prompt)
- [ ] Single media page → visible, "Log in to comment" message
- [ ] Private media → not visible or shows lock icon
- [ ] Dashboard → redirects to login

### 18.2 Non-Owner User

- [ ] Cannot edit/delete another user's media
- [ ] Can react, comment, favorite
- [ ] Follow button visible on other's profile/media
- [ ] Cannot access other user's draft/private media

### 18.3 Mobile (390px viewport)

- [ ] Explore grid responsive (single column or 2-col)
- [ ] Lightbox fills screen properly
- [ ] Upload modal usable on touch
- [ ] BP activity media sized correctly
- [ ] Dashboard tabs navigable
- [ ] Settings page usable (tabs scroll or stack)
- [ ] FAB button positioned correctly

---

## 19. Database Integrity

### 19.1 Custom Tables

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

### 19.2 Data Integrity

- [ ] New upload → row in `mvs_media_index` + `mvs_media_meta` (thumb sizes)
- [ ] New upload → NO row in `wp_posts` with `post_type='attachment'`
- [ ] Thumbnail files exist on disk at `uploads/wpmediaverse/YYYY/MM/`
- [ ] Delete media → rows removed from `mvs_media_index`, `mvs_media_meta`, `mvs_media_stats`
- [ ] Delete media → file removed from disk
- [ ] `mvs_db_version` option matches current migration version
- [ ] `mvs_caps_version` option exists
- [ ] `mvs_rewrite_version` option exists

### 19.3 Cleanup

- [ ] Deactivate plugin → rewrite rules flushed
- [ ] Uninstall plugin → all custom tables dropped (if clean uninstall enabled)
- [ ] Uninstall → all `mvs_*` options removed
- [ ] No orphaned meta entries after media deletion
