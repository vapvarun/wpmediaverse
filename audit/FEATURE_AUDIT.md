# WPMediaVerse — Feature Audit

**Generated:** 2026-05-03 · **Plugin version:** 1.2.0 · **Branch:** `1.2.0`

**Counts at a glance:** 53 REST endpoints · 3 AJAX handlers · 7 admin pages · 13 Gutenberg blocks · 12 shortcodes · 21 custom tables · 10 capabilities · 7 BP integration sub-classes · 2 cron events · 9 frontend JS modules · 5 CSS modules · 33 settings · 19 hooks fired (7 with Pro consumers).

This document is the canonical inventory of every user-facing surface the plugin exposes. Companion docs:

- [`CODE_FLOWS.md`](CODE_FLOWS.md) — request lifecycle for the major features.
- [`ROLE_MATRIX.md`](ROLE_MATRIX.md) — capability vs. feature grid.
- [`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md) — module-level architecture (separate, hand-curated).

---

## 1. REST API (`mvs/v1`)

19 controllers under `includes/REST/Controller/`. Every write endpoint passes through the `RateLimiter` middleware (`includes/REST/RateLimiter.php`).

### 1.1 Media

| Route | Method | Handler | Permission | Purpose |
|---|---|---|---|---|
| `/media` | GET | `MediaController::get_items` | public | List/filter media (paged, faceted) |
| `/media` | POST | `MediaController::create_item` | `create_item_permissions_check` | Create record (post-upload) |
| `/media/{id}` | GET | `MediaController::get_item` | `get_item_permissions_check` | Get single media |
| `/media/{id}` | PUT | `MediaController::update_item` | `update_item_permissions_check` | Update title/description/tags/privacy |
| `/media/{id}` | DELETE | `MediaController::delete_item` | `delete_item_permissions_check` | Delete media |
| `/media/{id}/download` | POST | `MediaController::record_download` | public (privacy-gated) | **1.2.0:** Record download event + increment `mvs_media_stats.downloads`. Rate-limited 30/min. 403 if global `mvs_allow_downloads` off OR per-media `allow_download='0'`. |
| `/media/{id}/share` | POST | `MediaController::record_share` | public (privacy-gated) | **1.2.0:** Record share event + increment `mvs_media_stats.shares`. Rate-limited 30/min. |
| `/media/bulk` | POST | `BulkController::handle_bulk` | `bulk_permissions_check` | Bulk delete/move/privacy |
| `/media/{id}/signed-url` | GET | `SignedUrlController::get_signed_url` | `get_signed_url_permissions_check` | Generate signed URL |
| `/serve` | GET | `SignedUrlController::serve_file` | public (token-validated) | Stream file via signed URL |
| `/media/{id}/report` | POST | `ReportController::report_media` | logged-in | Flag media |

**1.2.0 PUT change:** `PUT /media/{id}` now accepts an `allow_download` boolean param (stored as `mvs_media_meta` value `'1'`/`'0'`, default `'1'` when absent). The response from `prepare_item_for_response()` emits `allow_download` (defaults `true` when meta absent or `!= '0'`).

### 1.2 Social

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/media/{id}/reactions` | GET / POST / DELETE | `ReactionController::*` | public read; logged-in write |
| `/media/{id}/comments` | GET / POST | `CommentController::*` | public read; logged-in write |
| `/media/{id}/comments/{comment_id}` | PUT / DELETE | `CommentController::*` | author or moderator |
| `/media/{id}/favorite` | GET / POST / DELETE | `FavoriteController::*` | logged-in |
| `/me/favorites` | GET | `FavoriteController::get_my_favorites` | logged-in |

### 1.3 Albums + Collections

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/albums` | GET / POST | `AlbumController::*` | public read; logged-in create |
| `/albums/{id}` | GET / PUT / DELETE | `AlbumController::*` | public read; owner/moderator write |
| `/albums/{id}/items` | POST | `AlbumController::add_items` | owner |
| `/collections` | GET / POST | `CollectionController::*` | logged-in |
| `/collections/{id}` | GET / PUT / DELETE | `CollectionController::*` | owner |

### 1.4 Tags + Taxonomy

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/tags` | GET | `TagController::get_tags` | public |
| `/tags` | POST | `TagController::create_tag` | logged-in |
| `/tags/cloud` | GET | `TagController::get_cloud` | public |
| `/tags/{id}` | PUT / DELETE | `TagController::*` | admin |
| `/tags/merge` | POST | `TagController::merge_tags` | admin |

### 1.5 Users + Profile + Follows

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/users/{id}` | GET | `UserController::get_profile` | public |
| `/users/{id}/media` | GET | `UserController::get_user_media` | public (privacy-filtered) |
| `/users/search` | GET | `UserController::search_users` | public |
| `/users/{id}/follow` | POST / DELETE | `FollowController::*` | logged-in |
| `/users/{id}/followers` | GET | `FollowController::get_followers` | public |
| `/users/{id}/following` | GET | `FollowController::get_following` | public |
| `/me/profile` | GET / PUT / PATCH | `ProfileController::*` | logged-in |
| `/me/avatar` | POST / DELETE | `ProfileController::*` | logged-in |
| `/users/{id}/report` | POST | `ReportController::report_user` | logged-in |
| `/users/{id}/block` | POST | `ReportController::block_user` | logged-in |
| `/me/blocked` | GET | `ReportController::get_blocked` | logged-in |

### 1.6 Activity + Stats + Feed

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/feed` | GET | `ActivityController::get_feed` | public (privacy-filtered) |
| `/users/{id}/activity` | GET | `ActivityController::get_user_activity` | public |
| `/media/{id}/stats` | GET | `StatsController::get_media_stats` | public |
| `/me/stats` | GET | `StatsController::get_my_stats` | logged-in |

### 1.7 Access Control

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/media/{id}/rules` | GET / POST | `AccessController::*` | `manage_permissions_check` |
| `/media/{id}/rules/{rule_id}` | DELETE | `AccessController::delete_rule` | `manage_permissions_check` |
| `/media/{id}/grant` | POST | `AccessController::grant_access` | `manage_permissions_check` |

### 1.8 Moderation + Notifications

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/moderation` | GET | `ModerationController::get_queue` | `moderate_permissions_check` |
| `/moderation/counts` | GET | `ModerationController::get_counts` | moderator |
| `/moderation/{id}/approve` | POST | `ModerationController::approve_item` | moderator |
| `/moderation/{id}/reject` | POST | `ModerationController::reject_item` | moderator |
| `/me/notifications` | GET | `NotificationController::get_notifications` | logged-in |
| `/me/notifications/read` | POST | `NotificationController::mark_read` | logged-in |
| `/me/notifications/count` | GET | `NotificationController::get_unread_count` | logged-in |

### 1.9 Direct Messaging

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/me/conversations` | GET | `MessagingController::list_conversations` | logged-in |
| `/conversations` | POST | `MessagingController::create_conversation` | logged-in |
| `/conversations/{id}` | GET / PATCH / DELETE | `MessagingController::*` | participant |
| `/conversations/{id}/messages` | GET / POST | `MessagingController::*` | participant |
| `/conversations/{id}/messages/{msg_id}` | PUT / DELETE | `MessagingController::*` | sender |
| `/conversations/{id}/messages/{msg_id}/reactions` | POST | `MessagingController::add_reaction` | participant |

---

## 2. AJAX Handlers (`wp_ajax_*`)

Only three (admin-only — the public surface lives entirely on REST):

| Action | Handler | File:Line | Nonce | Capability |
|---|---|---|---|---|
| `mvs_import_demo_data` | `OverviewPage::ajax_import_demo_data` | `Admin/OverviewPage.php:711` | `mvs_import_demo` | `manage_options` or `manage_mvs_settings` |
| `mvs_cleanup_demo_data` | `OverviewPage::handle_cleanup_demo` | `Admin/OverviewPage.php:738` | `mvs_cleanup_demo` | same |
| `mvs_dismiss_welcome` | `OverviewPage::ajax_dismiss_welcome` | `Admin/OverviewPage.php:625` | `mvs_dismiss_welcome` | logged-in |

---

## 3. Admin Pages

Top-level menu `wpmediaverse` (slug `wpmediaverse`) registered in `Core/Plugin.php:620`. All sub-pages require `manage_options` or `manage_mvs_settings`.

| Page | Slug | Parent | Source |
|---|---|---|---|
| WPMediaVerse (Overview) | `wpmediaverse` | — | `Core/Plugin.php:620` → `Admin/OverviewPage.php` |
| All Media | `wpmediaverse-media` | `wpmediaverse` | `Admin/MediaListPage.php` |
| Settings | `wpmediaverse-settings` | `wpmediaverse` | `Admin/Settings/SettingsPage.php` |
| Stats | `wpmediaverse-stats` | `wpmediaverse` | `Admin/StatsPage.php` |
| Moderation | `wpmediaverse-moderation` | `wpmediaverse` | `Admin/ModerationQueue.php` |
| Logs | `wpmediaverse-logs` | `wpmediaverse` | `Admin/LogViewerPage.php` |
| Setup Wizard | `wpmediaverse-setup` | `wpmediaverse` | `Admin/SetupWizard.php` |
| Collection meta-box | (post.php) | n/a | `Admin/CollectionMetaBox.php` |

### 3.1 Bulk Actions on All Media (1.2.0)

`Admin/MediaListPage.php` gained a multi-select bulk-actions toolbar (`handle_bulk_action_apply()`):

- **Trash filter active:** offers Restore + Delete-permanently.
- **Default view:** offers Move-to-Trash.
- Each form posts a `wp_nonce_field('mvs_bulk_media')` nonce + a checkbox-array of media IDs.
- Capability gate: `manage_options` OR `moderate_mvs_media`.
- Success notice shows row count + action.
- New helper `permanently_delete_media()` extracts the file-system + DB delete sequence (was inlined into single-row delete previously).

---

## 4. Settings (option keys)

Registered in `Admin/Settings/SettingsRegistrar.php`. Sanitizers in `Admin/Settings/Sanitizers.php`.

### 4.1 General

| Key | Type | Default | Controls |
|---|---|---|---|
| `mvs_max_upload_size` | int (MB) | 50 | Upload size cap |
| `mvs_allowed_file_types` | csv | jpg,png,webp,mp4,mp3,pdf | Whitelist |
| `mvs_default_privacy` | enum | `public` | New-upload default privacy |
| `mvs_allow_user_privacy` | bool | on | Per-user privacy override |
| `mvs_duplicate_action` | enum | `skip` | dedupe / overwrite / suffix |
| `mvs_strip_exif` | bool | on | EXIF removal on upload |
| `mvs_signed_url_ttl` | int (s) | 600 | Signed URL lifetime |

### 4.2 Display

| Key | Type | Default | Controls |
|---|---|---|---|
| `mvs_grid_columns` | int | 4 | Grid column count |
| `mvs_items_per_page` | int | 20 | Page size |
| `mvs_thumbnail_style` | enum | `square` | Card style |
| `mvs_thumbnail_size` | enum | `medium` | Generated size |
| `mvs_lightbox_image_source` | enum | `original` | Lightbox source size |

### 4.3 AI + Moderation

| Key | Type | Controls |
|---|---|---|
| `mvs_ai_provider` | enum | Active provider (`openai`, etc.) |
| `mvs_openai_api_key` | secret | OpenAI key |
| `mvs_openai_model` | enum | Model (`gpt-4o`, `gpt-4o-mini`) |
| `mvs_ai_auto_analyze` | bool | Auto-run image analysis on upload |
| `mvs_ai_auto_apply_tags` | bool | Apply AI tags to taxonomy |
| `mvs_ai_auto_moderate` | bool | Run moderation on upload |
| `mvs_ai_monthly_budget` | float | Monthly $ cap |
| `mvs_ai_cost_per_call` | float | Per-call cost estimate |
| `mvs_moderation_auto_action` | enum | `hide` / `flag` / `remove` |
| `mvs_report_auto_hide_threshold` | int | Reports → auto-hide |

### 4.4 Messaging + Pages

| Key | Type | Controls |
|---|---|---|
| `mvs_dm_access` | enum | `everyone` / `followers` / `mutual` |
| `mvs_dm_min_age` | int (days) | Account-age gate |
| `mvs_show_online_status` | bool | Online presence |
| `mvs_chat_panel_visibility` | enum | **1.2.0:** `everywhere` / `mvs_pages` / `bp_pages` / `disabled`. Where the floating slide-out chat icon appears for logged-in users. Filterable per-page via `mvs_should_render_chat_panel`. |
| `mvs_page_dashboard` | int | Page ID for `[mvs_dashboard]` |
| `mvs_page_explore` | int | Page ID for explore |
| `mvs_page_upload` | int | Page ID for upload |
| `mvs_webhooks` | array | Registered outbound webhooks |
| `mvs_storage_driver` | enum | Active storage driver (local / S3 / BunnyCDN — Pro-extensible via `mvs_storage_driver` filter) |
| `mvs_comment_edit_window` | int (s) | Comment edit window. 0 disables editing. Default 900. |

### 4.5 Display additions

| Key | Type | Default | Controls |
|---|---|---|---|
| `mvs_allow_downloads` | bool | `true` | **1.2.0:** Global Allow Downloads toggle. Hides the lightbox Download button + gates the `POST /mvs/v1/media/{id}/download` REST endpoint. Per-media `allow_download` meta still applies on top of this. |

---

## 5. Shortcodes (12)

| Tag | Handler (`Shortcodes/Shortcodes.php`) | Key attributes |
|---|---|---|
| `[mvs_gallery]` | `render_gallery` | `type`, `category`, `tag`, `orderby`, `order` (asc/desc — 1.2.0), `user_id` (1.2.0; falls back to `bp_displayed_user_id()` inside BP templates) — `per_page` and `columns` always come from backend settings (`mvs_items_per_page` / `mvs_grid_columns`); intentionally not overridable via attrs |
| `[mvs_upload]` | `render_upload` | `max_files`, `show_privacy` |
| `[mvs_album]` | `render_album` | `id`, `columns`, `show_title` |
| `[mvs_player]` | `render_player` | `id`, `autoplay`, `loop`, `download` |
| `[mvs_stats]` | `render_stats` | `views`, `downloads`, `top_count` |
| `[mvs_dashboard]` | `render_dashboard` | (auth-gated) |
| `[mvs_collection]` | `render_collection` | `id`, `columns`, `per_page` |
| `[mvs_profile_edit]` | `render_profile_edit` | (auth-gated) |
| `[mvs_explore_feed]` | `render_explore_feed` | **1.2.0:** classic-editor wrapper around the `mvs/explore-feed` block. Attrs: `layout`, `columns`, `per_page`, `filters`, `search`. Delegates to `render_block_template()`. |
| `[mvs_lock_overlay]` | `render_lock_overlay` | **1.2.0:** classic-editor wrapper around the `mvs/lock-overlay` block. Attrs: `id`, `blur`, `overlay_opacity`, `unlock_label`. |
| `[mvs_member_photos]` | `render_member_photos` | **1.2.0:** classic-editor wrapper around the `mvs/member-photos` block. Attrs: `user_id`, `columns`, `per_page`, `type`, `show_header`, `actions`. Same auto-resolve order (explicit user_id → BP displayed user → post author → current user). |
| `[mvs_pdf_viewer]` | `render_pdf_viewer` | **1.2.0:** classic-editor wrapper around the `mvs/pdf-viewer` block. Attrs: `id`, `height` (clamped 200-1400), `toolbar`. |

---

## 6. Gutenberg Blocks (13 registered)

Registered via `Blocks/BlockRegistrar.php`. All use the WP Interactivity API; render PHP lives in each block's `render.php`.

| Block | Render | Notes |
|---|---|---|
| `wpmediaverse/media-grid` | `src/blocks/media-grid/render.php` | Responsive grid, lightbox-enabled |
| `wpmediaverse/media-player` | `src/blocks/media-player/render.php` | Single audio/video player |
| `wpmediaverse/media-upload` | `src/blocks/media-upload/render.php` | Inline upload form |
| `wpmediaverse/media-stats` | `src/blocks/media-stats/render.php` | Stat counters |
| `wpmediaverse/media-social` | `src/blocks/media-social/render.php` | Reactions/comments toolbar |
| `wpmediaverse/album-viewer` | `src/blocks/album-viewer/render.php` | Album page |
| `wpmediaverse/explore-feed` | `src/blocks/explore-feed/render.php` | Public feed |
| `wpmediaverse/explore-view` | `src/blocks/explore-view/render.php` | Explore landing |
| `wpmediaverse/dashboard-view` | `src/blocks/dashboard-view/render.php` | User dashboard tabs |
| `mvs/member-photos` | `src/blocks/member-photos/render.php` | **1.2.0 NEW.** Auto-resolves user (explicit `userId` attr → BP displayed user → post author → current user). Delegates grid render to media-grid via `do_blocks()`. Attrs: `userId` (int), `columns` (int, default 3), `perPage` (int, default 12), `mediaType` (string), `showHeader` (bool), `showActions` (bool). |
| `mvs/pdf-viewer` | `src/blocks/pdf-viewer/render.php` | **1.2.0 NEW.** Iframe with `#view=FitH&toolbar={0\|1}` URL fragment. Attrs: `mediaId` (int), `height` (int, range 200-1400, default 600), `showToolbar` (bool). 5 server-side empty states: no `mediaId` / not found / wrong type (not PDF) / privacy gate fail / asset missing. |
| `wpmediaverse/lock-overlay` | `src/blocks/lock-overlay/render.php` | Paywall/access overlay |
| `wpmediaverse/shared-ui` | `src/blocks/shared-ui/render.php` | Shared UI primitives (lightbox, modals, toast) |

**Source-kept-but-not-registered (1.2.0):** `src/blocks/story-viewer/` source remains in tree but is intentionally NOT in the `BlockRegistrar::BLOCKS` array — feature paused for 1.2.0 and will return in 1.3 once the 24h-story flow is redesigned.

---

## 7. Custom Post Types & Taxonomies

| Type | Slug | Source |
|---|---|---|
| CPT | `mvs_album` | `PostTypes/Album.php` |
| CPT | `mvs_collection` | `PostTypes/Collection.php` |
| Taxonomy | `mvs_tag` | `Taxonomies/MediaTag.php` |
| Taxonomy | `mvs_category` | `Taxonomies/MediaCategory.php` |

---

## 8. Custom Database Tables (21)

All prefixed `{$wpdb->prefix}mvs_`. Schema in `Core/Migrator.php`.

| Table | Purpose |
|---|---|
| `mvs_media_index` | Authoritative media record (CPT-free) |
| `mvs_media_meta` | Sparse key/value media metadata |
| `mvs_media_views` | Per-user view tracking |
| `mvs_media_stats` | Aggregated stats |
| `mvs_reactions` | Emoji reactions |
| `mvs_favorites` | Bookmarks |
| `mvs_follows` | Follow graph |
| `mvs_mentions` | @-mentions |
| `mvs_activity` | Activity feed entries |
| `mvs_notifications` | In-app notifications |
| `mvs_reports` | Abuse reports |
| `mvs_blocks` | User blocklist |
| `mvs_access_rules` | Per-media access rules |
| `mvs_access_grants` | Granted tokens |
| `mvs_album_items` | Album↔media join |
| `mvs_error_log` | Internal log table |
| `mvs_conversations` | DM threads |
| `mvs_conversation_participants` | DM membership |
| `mvs_messages` | DM messages |
| `mvs_message_reactions` | DM message reactions |
| `mvs_transactions` | Credit/payment ledger (Pro consumes) |

---

## 9. JavaScript Modules

| File | Purpose | Calls | Key selectors |
|---|---|---|---|
| `assets/js/frontend/bp-actions.js` | BP-context like/comment | REST `/reactions`, `/comments` | `.mvs-reaction-btn`, `.mvs-comment-form` |
| `assets/js/frontend/load-more.js` | Pagination/infinite scroll | REST `/media` | `.load-more-btn`, `.media-grid` |
| `assets/js/frontend/card-builders.js` | Card render helpers | (pure) | `.media-card`, `.card-image` |
| `assets/js/messaging.js` | DM UI | REST `/conversations`, `/messages` | `.message-thread`, `.conversation-list` |
| `assets/js/profile-edit.js` | Profile editor | REST `/me/profile`, `/me/avatar` | `.profile-form` |
| `assets/js/bp-activity-media.js` | Legacy-import activity | REST `/media` | `.activity-media-container` |
| `assets/js/admin/icons.js` | Lucide icons | (asset) | `[data-icon]` |
| `assets/js/admin/toast.js` | Admin notifications | (event) | `.mvs-toast` |
| `assets/js/settings-nav.js` | Settings sidebar | (DOM) | `.settings-nav` |

Block-scoped JS lives in each `src/blocks/*/view.js` (loaded via Interactivity).

---

## 10. CSS Modules (Coding Rule #12 — "CSS file ownership")

| File | Scope | Notes |
|---|---|---|
| `assets/css/frontend.css` | Generic frontend | `.mvs-*` classes |
| `assets/css/admin.css` | wp-admin only | `.mvs-admin-*` |
| `assets/css/bp-integration.css` | BP-only | Scoped under `#buddypress` |
| `assets/css/messaging.css` | DM UI | `.mvs-messaging-*` |
| `assets/css/shared-ui-shell.css` | Layout primitives | `.mvs-shell-*` |
| `*-rtl.css` | RTL variants for each | Auto-generated |

Block-specific CSS lives in `src/blocks/*/style.css`.

---

## 11. Capabilities

Defined in `Capabilities/MediaCapabilities.php`. Mapped onto WP roles by `Capabilities/MediaCapabilities::map_to_roles()`.

| Capability | Granted to (default) |
|---|---|
| `upload_mvs_media` | admin, editor, author, contributor, subscriber |
| `edit_mvs_media` | admin, editor, author, contributor, subscriber |
| `edit_others_mvs_media` | admin, editor |
| `delete_mvs_media` | admin, editor, author, contributor, subscriber |
| `delete_others_mvs_media` | admin, editor |
| `publish_mvs_media` | admin, editor, author, subscriber |
| `read_mvs_media` | all roles |
| `moderate_mvs_media` | admin, editor |
| `manage_mvs_settings` | admin |
| `manage_mvs_access` | admin, editor |

See [`ROLE_MATRIX.md`](ROLE_MATRIX.md) for the cross-feature view.

---

## 12. Hooks fired by the plugin

Top 30 most-significant; full list grep `do_action.*mvs_\|apply_filters.*mvs_` in `includes/`.

| Hook | Type | Args | Fired in |
|---|---|---|---|
| `mvs_loaded` | action | `(ServiceContainer $c)` | `Core/Plugin.php:init()` — Pro extension entry point |
| `mvs_media_uploaded` | action | `($media_id, $file_path, $user_id)` | `UploadService::upload()` |
| `mvs_media_deleted` | action | `($media_id, $user_id)` | `UploadService::delete()` |
| `mvs_media_response` | filter | `($data, $media_id)` | REST controllers (always-sign URL hook lives here) |
| `mvs_reaction_added` | action | `($media_id, $user_id, $type)` | `ReactionService::add()` |
| `mvs_comment_created` | action | `($comment_id, $media_id, $user_id)` | `CommentService::create()` |
| `mvs_album_created` | action | `($album_id, $owner_id)` | `AlbumService::create()` |
| `mvs_message_sent` | action | `($message_id, $conversation_id, $sender_id)` | `MessagingService::send()` |
| `mvs_notification_created` | action | `($notification_id, $user_id, $type, $actor_id, $media_id)` | `NotificationService::create()` |
| `mvs_storage_driver` | filter | `($driver_class)` | `StorageService::resolve_driver()` — Pro hooks S3/Bunny here |
| `mvs_ai_providers` | action | `($ai_service)` | `AIService::register_providers()` — Pro hooks Vision/Rekognition here |
| `mvs_thumbnail_sizes` | filter | `($sizes)` | `UploadService::get_thumbnail_sizes()` |
| `mvs_after_thumbnail_generation` | action | `($media_id, $generated, $file_path)` | `UploadService::generate_thumbs()` |
| `mvs_generate_watermark` | filter | `($preview_url, $media_id, $file_path, $file_url, $config)` | `WatermarkService::get_preview()` — Pro hooks |
| `mvs_watermark_invalidated` | action | `($media_id)` | `WatermarkService::invalidate()` |
| `mvs_capability_check` | filter | `($allowed, $cap, $user_id)` | `MediaCapabilities` |
| `mvs_media_privacy_check` | filter | `($allowed, $media_id, $user_id)` | `MediaRepository::can_view()` |
| `mvs_album_query_args` | filter | `($args, $user_id)` | `AlbumRepository::list()` |
| `mvs_activity_content_transform` | filter | `($html, $activity_id)` | `BuddyPress\ActivityContentIntegration` |
| `mvs_profile_tab_label` | filter | `($label)` | `BuddyPress\ProfileTabIntegration` |
| `mvs_group_tab_label` | filter | `($label)` | `BuddyPress\GroupTabIntegration` |
| `mvs_stats_tabs` | filter | `($tabs)` | `Admin/StatsPage::render_tabs()` — Pro injects |
| `mvs_moderation_tabs` | filter | `($tabs)` | `Admin/ModerationQueue::render_tabs()` — Pro injects |
| `mvs_export_started` | action | `($export_id, $user_id)` | `GDPRService::export()` |
| `mvs_import_completed` | action | `($import_id, $media_count)` | `GDPRService::import()` |
| `mvs_cron_cleanup` | action | `()` | Daily cron, `LoggerService::prune()` |
| `mvs_story_expired` | action | `($story_id)` | `StoryService::cleanup()` (hourly cron) |
| `mvs_pro_loaded` | action | (none) | `WPMediaVersePro\Core\Plugin::init()` (Pro signals ready) |
| `mvs_signed_url_lifetime` | filter | `($seconds, $media_id, $user_id)` | `SignedUrlService::generate()` |
| `mvs_serve_file_headers` | filter | `($headers, $media_id)` | `SignedUrlController::serve_file()` |

---

## 13. BuddyPress Integration

7 focused classes in `includes/Integrations/BuddyPress/` (BP manager was split from a 2,811-line monolith into 7 focused classes; further deduped in 1.2.0 by extracting `BaseBPTabIntegration` shared by Profile + Group tab integrations). Top-level `BuddyPressManager` boots only when BP is active.

| Class | Hooks into | What it injects |
|---|---|---|
| `ActivitySyncIntegration` | `bp_activity_after_save`, media events | Mirrors media uploads into BP activity stream |
| `ActivityContentIntegration` | `bp_get_activity_content_body` (priority 0), `bp_activity_entry_content`, `bp_activity_allowed_tags` | Transforms legacy rtMedia/MediaPress/BuddyBoss activity HTML to MVS markup; injects video/audio players. Today's fix: every rebuild path now signs URLs through `MediaUrl`. |
| `ProfileTabIntegration` | `bp_setup_nav`, `bp_template_redirect` | "Media" tab on member profiles (All Media, Albums sub-tabs) |
| `GroupTabIntegration` | `bp_setup_nav` | "Media" tab on group pages, group-scoped via `mvs_media_meta.group_id` |
| `NotificationIntegration` | `bp_notifications_get_registered_components`, `bp_notifications_get_notifications_for_user`, `mvs_notification_created` | Mirrors MVS notifications into BP component (`mvs_new_reaction`, `mvs_new_comment`, `mvs_new_mention`) |
| `ActivityFormIntegration` | `bp_activity_post_form_options` | Adds upload widget to BP post form |
| `MediaDisplayHelper` | (utility, not hooked) | Shared thumbnail/href rendering reused by Activity/Profile/Group sub-classes. Today's fix: signs `href` fallback via `MediaUrl::for_file`. |

---

## 14. Cron Hooks

| Hook | Schedule | Handler | Purpose |
|---|---|---|---|
| `mvs_prune_logs` | daily | `Services/LoggerService::prune()` | Drop log rows > 30 days |
| `mvs_story_cleanup` | hourly | `Services/StoryService::cleanup()` | Expire 24h stories |

---

## 15. WP-CLI

| Command | Class |
|---|---|
| `wp mvs ...` | `WPMediaVerse\CLI\Commands` (router) |

Sub-commands include `regenerate-thumbnails`, `recount-stats`, `export-user-data`. Run `wp mvs help` for the full list.

---

## 16. Storage Drivers

| Driver | Class | URL behavior |
|---|---|---|
| Local | `Services/LocalDriver.php` | `wp-content/uploads/wpmediaverse/<rel>` (direct URL is .htaccess deny-all; reads must flow through `SignedUrlService`) |
| S3 | (Pro) `Storage/S3Driver.php` | Signed S3 / CDN URL |
| BunnyCDN | (Pro) `Storage/BunnyCDNDriver.php` | BunnyCDN edge URL with token signing |

The `mvs_storage_driver` filter (Free) lets Pro register additional drivers. Every URL read passes through `Plugin::maybe_sign_file_url` (always-sign) so the local `.htaccess` deny doesn't break responses.

---

## 17. Security boundaries

- **`.htaccess` deny-all** on `wp-content/uploads/wpmediaverse/`. Every direct URL must flow through `SignedUrlService`.
- **Signing entry points:** `MediaUrl::for_file()` / `MediaUrl::for_thumbnail()` / `MediaUrl::resolve()` (added 1.1.3 patch). Internal storage writes are exempt — annotated `// CI: storage-internal` for the `bin/ci-local.sh` guard.
- **REST emission:** `Plugin::maybe_sign_file_url` filter (priority 10) on `mvs_media_response` — always signs.
- **Capabilities:** every write endpoint runs through `*_permissions_check`. Reads enforce per-media privacy in `MediaRepository::can_view()`.
- **Nonce + capability:** every AJAX handler uses `check_ajax_referer()` + capability check.
- **Rate limiting:** `RateLimiter` middleware on social writes (reactions/favorites/follows/comments/bulk).
