# WPMediaVerse Architecture Reference

> Single source of truth for the plugin's technical architecture.
> Migrator version: **9** | REST namespace: **mvs/v1** | Custom tables: **21**

---

## 1. Plugin Lifecycle

```
wpmediaverse.php
  define MVS_VERSION ('1.1.0')
  define MVS_PLUGIN_DIR, MVS_PLUGIN_URL, MVS_PLUGIN_FILE, MVS_PLUGIN_BASENAME
  require vendor/autoload.php (PSR-4)
  register EDD SL SDK (auto-update with preset license)
  ─────────────────────────────────────────────────────
  Plugin::init()
    ├── load_plugin_textdomain('wpmediaverse')
    ├── MediaCapabilities::add_caps()          [version-gated via mvs_caps_version]
    ├── Migrator::run()                        [CURRENT_VERSION = 9]
    ├── ServiceContainer setup                 [33 lazy-loaded services]
    │     └── register_services()              [storage → upload → admin → social → integrations]
    │     └── LoggerService::register_hooks()
    │     └── GDPRService::init()
    │     └── HealthCheckService::init()
    ├── add_action('init', register_types)     [Album CPT, Collection CPT, MediaTag, MediaCategory]
    ├── add_action('admin_menu', register_admin_menu, 5)
    ├── BlockRegistrar::init()
    ├── Shortcodes::init()
    ├── TemplateLoader::init()
    ├── add_action('admin_init', maybe_redirect_after_activation)
    ├── [is_admin()] Admin pages boot          [overview, settings, moderation, stats, logs, wizard, metabox]
    ├── add_action('rest_api_init', register_rest_routes)  [18 controllers]
    ├── StoryService init                      [cleanup cron]
    ├── ModerationService                      [deferred load - admin or upload]
    ├── AI hooks                               [mvs_media_uploaded → mvs_ai_process_media]
    ├── AccessRulesService filter              [mvs_privacy_can_view, priority 20]
    ├── mvs_media_response filter              [signed URL replacement]
    ├── WatermarkService::init()               [preview_url filter, priority 30]
    ├── CacheService::init()                   [invalidation hooks]
    ├── ProfileService::init()                 [avatar filter hooks]
    ├── BuddyPressIntegration::init()          [conditional - if BP active]
    ├── WebhookService::init()
    ├── add_action('mvs_deliver_webhook')      [Action Scheduler async delivery]
    ├── wp_enqueue_scripts                     [frontend CSS/JS]
    ├── Abilities::init()                      [WP 6.9+ Abilities API]
    ├── register_theme_json                    [plugin-level design tokens]
    ├── Shared UI shell                        [FAB, upload modal, lightbox]
    ├── init_messaging()                       [MessagingService + MessagingController + NotificationListener]
    └── do_action('mvs_loaded', $container)    ← Pro hooks in here
```

---

## 2. Database Schema

All tables are prefixed with `{$wpdb->prefix}mvs_`. Created/updated in `includes/Core/Migrator.php`.

### 2.1 Core Media

#### `mvs_media_index` (v1, upgraded v7)

Authoritative media record. No CPT dependency.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `media_id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `title` | `varchar(255)` | `''` | |
| `slug` | `varchar(255)` | `''` | UNIQUE |
| `description` | `longtext` | NULL | |
| `post_author` | `bigint(20) unsigned` | `0` | |
| `status` | `varchar(20)` | `'publish'` | |
| `media_type` | `varchar(20)` | `''` | image/video/audio/document |
| `privacy` | `varchar(20)` | `'public'` | public/members/private |
| `moderation_status` | `varchar(20)` | `'approved'` | |
| `file_url` | `text` | NULL | |
| `file_path` | `text` | NULL | |
| `file_type` | `varchar(100)` | `''` | MIME type |
| `file_size` | `bigint(20) unsigned` | `0` | bytes |
| `file_hash` | `varchar(64)` | `''` | SHA-256 |
| `width` | `int(11) unsigned` | NULL | pixels |
| `height` | `int(11) unsigned` | NULL | pixels |
| `duration` | `decimal(10,2)` | NULL | seconds (video/audio) |
| `album_id` | `bigint(20) unsigned` | `0` | |
| `view_count` | `bigint(20) unsigned` | `0` | denormalized |
| `reaction_count` | `bigint(20) unsigned` | `0` | denormalized |
| `comment_count` | `bigint(20) unsigned` | `0` | denormalized |
| `is_featured` | `tinyint(1)` | `0` | |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |
| `updated_at` | `datetime` | NULL | |

**Indexes:** `PRIMARY (media_id)`, `UNIQUE slug`, `moderation_privacy_date (moderation_status, privacy, created_at)`, `author_date (post_author, created_at)`, `type_date (media_type, created_at)`, `status_date (status, created_at)`, `album_id`, `file_hash`

#### `mvs_media_meta` (v9)

Sparse key-value metadata for media items.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `media_id` | `bigint(20) unsigned` | | Composite PK |
| `meta_key` | `varchar(100)` | | Composite PK |
| `meta_value` | `longtext` | NULL | |

**Indexes:** `PRIMARY (media_id, meta_key)`, `meta_key`

#### `mvs_media_views` (v1)

Per-event view/download tracking.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `media_id` | `bigint(20) unsigned` | | |
| `user_id` | `bigint(20) unsigned` | NULL | NULL for anonymous |
| `ip_hash` | `varchar(64)` | `''` | hashed IP |
| `event_type` | `enum('view','download')` | `'view'` | |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `media_user_date (media_id, user_id, created_at)`, `created_at`

#### `mvs_media_stats` (v1)

Aggregate statistics per media item.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `media_id` | `bigint(20) unsigned` | | PK |
| `views` | `bigint(20) unsigned` | `0` | |
| `downloads` | `bigint(20) unsigned` | `0` | |
| `reactions` | `bigint(20) unsigned` | `0` | |
| `comments` | `bigint(20) unsigned` | `0` | |
| `shares` | `bigint(20) unsigned` | `0` | |
| `updated_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (media_id)`

### 2.2 Albums

#### `mvs_album_items` (v1)

Album-to-media mapping with ordering.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `album_id` | `bigint(20) unsigned` | | |
| `media_id` | `bigint(20) unsigned` | | |
| `position` | `int(11) unsigned` | `0` | sort order |
| `added_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `UNIQUE album_media (album_id, media_id)`, `album_position (album_id, position)`

### 2.3 Social

#### `mvs_reactions` (v1, unique constraint v5)

Emoji reactions on media items.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `media_id` | `bigint(20) unsigned` | | |
| `user_id` | `bigint(20) unsigned` | | |
| `reaction_type` | `enum('like','love','haha','wow','sad','angry')` | `'like'` | |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `UNIQUE media_user (media_id, user_id)`, `user_id`, `UNIQUE unique_reaction (media_id, user_id, reaction_type)` (v5)

#### `mvs_favorites` (v1, unique constraint v5)

User favorites/bookmarks with optional collection.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `media_id` | `bigint(20) unsigned` | | |
| `user_id` | `bigint(20) unsigned` | | |
| `collection_id` | `bigint(20) unsigned` | NULL | optional grouping |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `UNIQUE media_user (media_id, user_id)`, `user_id`, `collection_id`, `UNIQUE unique_favorite (media_id, user_id)` (v5)

#### `mvs_follows` (v3, unique constraint v5)

User-to-user follow relationships.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `follower_id` | `bigint(20) unsigned` | | |
| `following_id` | `bigint(20) unsigned` | | |
| `status` | `varchar(20)` | `'active'` | |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `UNIQUE follower_following (follower_id, following_id)`, `following_id`, `status`, `UNIQUE unique_follow (follower_id, following_id)` (v5)

#### `mvs_mentions` (v1)

@mention records linking users to media/comments.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `media_id` | `bigint(20) unsigned` | | |
| `mentioned_user_id` | `bigint(20) unsigned` | | |
| `context` | `varchar(50)` | `'description'` | description/comment |
| `comment_id` | `bigint(20) unsigned` | NULL | if context=comment |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `media_id`, `mentioned_user_id`

#### `mvs_activity` (v4)

Activity feed entries.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `user_id` | `bigint(20) unsigned` | | |
| `type` | `varchar(50)` | | e.g. upload, react, comment |
| `media_id` | `bigint(20) unsigned` | `0` | |
| `album_id` | `bigint(20) unsigned` | `0` | |
| `content` | `text` | NULL | |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `user_date (user_id, created_at)`, `type_date (type, created_at)`, `created_at`

#### `mvs_notifications` (v3)

User notification queue.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `user_id` | `bigint(20) unsigned` | | recipient |
| `type` | `varchar(50)` | | notification type |
| `actor_id` | `bigint(20) unsigned` | `0` | who triggered it |
| `media_id` | `bigint(20) unsigned` | `0` | |
| `comment_id` | `bigint(20) unsigned` | `0` | |
| `read_at` | `datetime` | NULL | NULL = unread |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `user_unread (user_id, read_at)`, `user_date (user_id, created_at)`

### 2.4 Moderation

#### `mvs_reports` (v4, unique constraint v5)

Content/user abuse reports.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `reporter_id` | `bigint(20) unsigned` | | |
| `target_type` | `varchar(20)` | | media/user |
| `target_id` | `bigint(20) unsigned` | | |
| `reason` | `varchar(50)` | | |
| `details` | `text` | NULL | |
| `status` | `varchar(20)` | `'pending'` | |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `reporter_target (reporter_id, target_type, target_id)`, `target (target_type, target_id)`, `status`, `UNIQUE unique_report (reporter_id, target_type, target_id)` (v5)

#### `mvs_blocks` (v4)

User block list.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `blocker_id` | `bigint(20) unsigned` | | |
| `blocked_id` | `bigint(20) unsigned` | | |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `UNIQUE blocker_blocked (blocker_id, blocked_id)`, `blocked_id`

### 2.5 Access / Monetization

#### `mvs_access_rules` (v1)

Per-media access control rules.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `media_id` | `bigint(20) unsigned` | | |
| `rule_type` | `varchar(50)` | | e.g. role, password, purchase |
| `rule_value` | `text` | | |
| `price` | `decimal(10,2)` | NULL | for purchase rules |
| `currency` | `varchar(3)` | NULL | ISO 4217 |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `media_id`, `rule_type`

#### `mvs_access_grants` (v1)

Granted access tokens / purchased access.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `media_id` | `bigint(20) unsigned` | | |
| `user_id` | `bigint(20) unsigned` | | |
| `granted_at` | `datetime` | `CURRENT_TIMESTAMP` | |
| `expires_at` | `datetime` | NULL | NULL = permanent |
| `revoked_at` | `datetime` | NULL | soft revoke |
| `source` | `varchar(100)` | `''` | manual/purchase/promo |

**Indexes:** `PRIMARY (id)`, `media_user (media_id, user_id)`, `user_id`

#### `mvs_transactions` (v9)

Quota usage / credit transactions.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `user_id` | `bigint(20) unsigned` | | |
| `media_type` | `varchar(20)` | | |
| `delta` | `int` | | positive = credit, negative = debit |
| `balance_after` | `int` | `0` | |
| `reason` | `varchar(100)` | `''` | |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `user_type (user_id, media_type)`, `created_at`

### 2.6 Messaging

#### `mvs_conversations` (v6)

DM conversation threads.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `type` | `varchar(20)` | `'direct'` | direct/group |
| `title` | `varchar(255)` | NULL | for group chats |
| `created_by` | `bigint(20) unsigned` | | |
| `last_message_id` | `bigint(20) unsigned` | NULL | denormalized |
| `last_message_preview` | `varchar(255)` | NULL | denormalized |
| `last_activity_at` | `datetime` | `CURRENT_TIMESTAMP` | |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `last_activity (last_activity_at)`, `created_by`

#### `mvs_conversation_participants` (v6)

Conversation membership and per-user settings.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `conversation_id` | `bigint(20) unsigned` | | |
| `user_id` | `bigint(20) unsigned` | | |
| `last_read_at` | `datetime` | NULL | |
| `is_muted` | `tinyint(1)` | `0` | |
| `muted_until` | `datetime` | NULL | |
| `is_pinned` | `tinyint(1)` | `0` | |
| `is_archived` | `tinyint(1)` | `0` | |
| `status` | `varchar(20)` | `'active'` | active/left/blocked |
| `joined_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `UNIQUE conv_user (conversation_id, user_id)`, `user_status (user_id, status)`, `conv_read (conversation_id, last_read_at)`

#### `mvs_messages` (v6)

Individual DM messages.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `conversation_id` | `bigint(20) unsigned` | | |
| `sender_id` | `bigint(20) unsigned` | | |
| `content` | `longtext` | NULL | |
| `message_type` | `varchar(20)` | `'text'` | text/image/file/voice/media_share |
| `attachment_id` | `bigint(20) unsigned` | NULL | WP attachment |
| `media_id` | `bigint(20) unsigned` | NULL | shared MVS media |
| `parent_id` | `bigint(20) unsigned` | NULL | reply-to |
| `metadata` | `text` | NULL | JSON extra data |
| `is_deleted` | `tinyint(1)` | `0` | soft delete (for sender) |
| `deleted_for_all` | `tinyint(1)` | `0` | unsend |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `conv_date (conversation_id, created_at)`, `conv_id (conversation_id)`, `sender (sender_id)`

#### `mvs_message_reactions` (v6)

Emoji reactions on DM messages.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `message_id` | `bigint(20) unsigned` | | |
| `user_id` | `bigint(20) unsigned` | | |
| `emoji` | `varchar(50)` | | |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `UNIQUE msg_user (message_id, user_id)`, `message_id`

### 2.7 System

#### `mvs_error_log` (v5)

Centralized error/debug log.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | `bigint(20) unsigned` | AUTO_INCREMENT | PK |
| `level` | `varchar(10)` | `'info'` | info/warning/error/critical |
| `context` | `varchar(50)` | `''` | module name |
| `message` | `text` | | |
| `metadata` | `text` | NULL | JSON extra data |
| `user_id` | `bigint(20) unsigned` | `0` | |
| `ip_address` | `varchar(45)` | `''` | IPv4/IPv6 |
| `created_at` | `datetime` | `CURRENT_TIMESTAMP` | |

**Indexes:** `PRIMARY (id)`, `level_date (level, created_at)`, `context_date (context, created_at)`

### Migration History

| Version | Scope | Tables/Changes |
|---------|-------|----------------|
| 1 | Core foundation | reactions, favorites, media_views, media_stats, access_rules, access_grants, mentions, album_items, media_index |
| 2 | Capabilities | MediaCapabilities::add_caps() |
| 3 | Social | follows, notifications |
| 4 | Moderation + Activity | reports, blocks, activity |
| 5 | Logging + Constraints | error_log, unique constraints on reports/follows/reactions/favorites |
| 6 | Messaging | conversations, conversation_participants, messages, message_reactions |
| 7 | Media index upgrade | Rebuild mvs_media_index with full CPT-free schema |
| 8 | Cleanup | Drop attachment_id column from mvs_media_index |
| 9 | Meta + Transactions | media_meta, transactions |

---

## 3. REST API Map

All routes are under namespace `mvs/v1`. Base URL: `/wp-json/mvs/v1/`.

### 3.1 Media (MediaController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/media` | Public | List/search media items |
| POST | `/media` | `create_item_permissions_check` | Upload new media |
| GET | `/media/{id}` | `get_item_permissions_check` | Get single media item |
| PUT | `/media/{id}` | `update_item_permissions_check` | Update media metadata |
| DELETE | `/media/{id}` | `delete_item_permissions_check` | Delete media item |
| POST | `/media/{id}/view` | Public | Record a view event |
| GET | `/media/{id}/access` | Public | Check access permissions |
| GET | `/media/{id}/group` | Public | Get gallery group items |
| GET | `/me/media` | Authenticated | Current user's media |

### 3.2 Albums (AlbumController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/albums` | Public | List albums |
| POST | `/albums` | `create_item_permissions_check` | Create album |
| GET | `/albums/{id}` | Public | Get single album |
| PUT | `/albums/{id}` | `update_item_permissions_check` | Update album |
| DELETE | `/albums/{id}` | `delete_item_permissions_check` | Delete album |
| PUT | `/albums/{id}/reorder` | `update_item_permissions_check` | Reorder album items |
| POST | `/albums/{id}/items` | `update_item_permissions_check` | Add media to album |
| DELETE | `/albums/{id}/items/{media_id}` | `update_item_permissions_check` | Remove item from album |
| PUT | `/albums/{id}/cover` | `update_item_permissions_check` | Set album cover image |

### 3.3 Collections (CollectionController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/collections` | Authenticated | List user's collections |
| POST | `/collections` | Authenticated | Create collection |
| GET | `/collections/{id}` | `get_item_permissions_check` | Get single collection |
| PUT | `/collections/{id}` | `owner_permissions_check` | Update collection |
| DELETE | `/collections/{id}` | `owner_permissions_check` | Delete collection |
| PUT | `/collections/{id}/rules` | `owner_permissions_check` | Set smart collection rules |

### 3.4 Bulk Operations (BulkController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| POST | `/media/bulk` | `bulk_permissions_check` | Bulk delete/move/change privacy |

### 3.5 Reactions (ReactionController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/media/{media_id}/reactions` | Public | Get reactions for media |
| POST | `/media/{media_id}/reactions` | `create_item_permissions_check` | Add/toggle reaction |
| DELETE | `/media/{media_id}/reactions` | `create_item_permissions_check` | Remove reaction |

### 3.6 Comments (CommentController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/media/{media_id}/comments` | Public | List comments for media |
| POST | `/media/{media_id}/comments` | `create_item_permissions_check` | Create comment |
| PUT | `/media/{media_id}/comments/{comment_id}` | `create_item_permissions_check` | Edit comment (time-limited) |
| DELETE | `/media/{media_id}/comments/{comment_id}` | `create_item_permissions_check` | Delete comment |

### 3.7 Favorites (FavoriteController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/media/{media_id}/favorite` | Authenticated | Check favorite status |
| POST | `/media/{media_id}/favorite` | Authenticated | Toggle favorite on |
| DELETE | `/media/{media_id}/favorite` | Authenticated | Toggle favorite off |
| GET | `/me/favorites` | Authenticated | List current user's favorites |

### 3.8 Stats (StatsController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/media/{media_id}/stats` | Public | Get media statistics |
| GET | `/me/stats` | Authenticated | Get current user's stats |

### 3.9 Tags (TagController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/tags` | Public | Search/autocomplete tags |
| GET | `/tags/cloud` | Public | Top tags with counts |
| POST | `/tags/merge` | Admin (`admin_check`) | Merge source tag into target |
| PUT | `/tags/{id}` | Admin (`admin_check`) | Rename a tag |

### 3.10 Moderation (ModerationController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/moderation` | `moderate_permissions_check` | Get moderation queue |
| GET | `/moderation/counts` | `moderate_permissions_check` | Get status counts |
| POST | `/moderation/{id}/approve` | `moderate_permissions_check` | Approve media |
| POST | `/moderation/{id}/reject` | `moderate_permissions_check` | Reject media (with reason) |
| POST | `/moderation/{id}/analyze` | `moderate_permissions_check` | Trigger AI analysis |
| GET | `/ai/usage` | `moderate_permissions_check` | Get AI usage stats |

### 3.11 Access Rules (AccessController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/media/{media_id}/rules` | `manage_permissions_check` | Get access rules |
| POST | `/media/{media_id}/rules` | `manage_permissions_check` | Set access rules |
| DELETE | `/media/{media_id}/rules/{rule_id}` | `manage_permissions_check` | Delete single rule |
| POST | `/media/{media_id}/grant` | `manage_permissions_check` | Grant access to user |
| DELETE | `/media/{media_id}/grant/{user_id}` | `manage_permissions_check` | Revoke access |
| GET | `/me/grants` | Authenticated | Current user's access grants |

### 3.12 Signed URLs (SignedUrlController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/media/{media_id}/signed-url` | `get_signed_url_permissions_check` | Generate signed download URL |
| GET | `/serve` | Public (signature validates) | Serve file via signed URL |

### 3.13 Follows (FollowController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| POST | `/users/{id}/follow` | Authenticated | Follow user |
| DELETE | `/users/{id}/follow` | Authenticated | Unfollow user |
| GET | `/users/{id}/followers` | Public | List user's followers |
| GET | `/users/{id}/following` | Public | List who user follows |
| GET | `/me/following` | Authenticated | Current user's following list |
| GET | `/me/followers` | Authenticated | Current user's followers |

### 3.14 Notifications (NotificationController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/me/notifications` | Authenticated | Get notifications |
| POST | `/me/notifications/read` | Authenticated | Mark notifications as read |
| GET | `/me/notifications/count` | Authenticated | Get unread count |

### 3.15 Users (UserController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/users/{id}` | Public | Get public profile |
| GET | `/users/{id}/media` | Public | Get user's public media |
| GET | `/users/search` | Public | Search users by query |

### 3.16 Reports (ReportController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| POST | `/media/{id}/report` | Authenticated | Report media |
| POST | `/users/{id}/report` | Authenticated | Report user |
| POST | `/users/{id}/block` | Authenticated | Block user |
| DELETE | `/users/{id}/block` | Authenticated | Unblock user |
| GET | `/me/blocked` | Authenticated | List blocked users |

### 3.17 Activity (ActivityController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/feed` | Public | Public or following feed |
| GET | `/users/{id}/activity` | Public | User's activity feed |

### 3.18 Profile (ProfileController)

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/me/profile` | Authenticated | Get own profile |
| PUT | `/me/profile` | Authenticated | Update profile fields |
| POST | `/me/avatar` | Authenticated | Upload custom avatar |
| DELETE | `/me/avatar` | Authenticated | Delete custom avatar |

### 3.19 Messaging (MessagingController)

Registered via `init_messaging()` in Plugin.php. All routes require authentication (`check_auth`).

| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| GET | `/me/conversations` | Authenticated | List conversations |
| POST | `/conversations` | Authenticated | Create conversation |
| GET | `/conversations/{id}` | Authenticated | Get conversation details |
| PATCH | `/conversations/{id}` | Authenticated | Update conversation (mute/pin/archive) |
| DELETE | `/conversations/{id}` | Authenticated | Delete/leave conversation |
| GET | `/conversations/{id}/messages` | Authenticated | List messages |
| POST | `/conversations/{id}/messages` | Authenticated | Send message |
| DELETE | `/messages/{id}` | Authenticated | Delete message (for self) |
| DELETE | `/messages/{id}/unsend` | Authenticated | Unsend message (for all) |
| POST | `/conversations/{id}/read` | Authenticated | Mark conversation as read |
| POST | `/conversations/{id}/typing` | Authenticated | Send typing indicator |
| POST | `/messages/{id}/reactions` | Authenticated | Add reaction to message |
| DELETE | `/messages/{id}/reactions` | Authenticated | Remove reaction from message |
| GET | `/messages/poll` | Authenticated | Long-poll for new messages |
| GET | `/me/messages/unread-count` | Authenticated | Get total unread count |
| POST | `/conversations/{id}/accept` | Authenticated | Accept message request |
| POST | `/conversations/{id}/decline` | Authenticated | Decline message request |
| POST | `/messages/upload` | Authenticated | Upload DM attachment |

---

## 4. Hook Reference

### 4.1 Plugin Initialization

| Hook | Type | Location | Description |
|------|------|----------|-------------|
| `mvs_loaded` | action | `Core/Plugin.php:217` | Fires after all services initialized; receives `ServiceContainer`. Pro hooks here. |
| `mvs_ai_providers` | action | `Core/Plugin.php:335` | Register additional AI providers on the `AIService` instance. |
| `mvs_reserved_media_paths` | filter | `Core/TemplateLoader.php:101` | Array of URL path segments excluded from `/media/{slug}/` rewrite rule. |
| `mvs_body_classes` | filter | `Core/TemplateLoader.php:463` | Body classes added to MVS pages (default: `['mvs-page', 'no-sidebar']`). |
| `mvs_buddynext_active` | filter | `Core/Plugin.php:1028,1061,1155,1196` | Whether BuddyNext integration is active (default `false`). |
| `mvs_theme_json` | filter | `Core/Plugin.php:964` | Modify plugin-level theme.json design tokens before registration. |
| `mvs_messaging_transport` | filter | `Core/Plugin.php:980` | Override messaging transport (default: `RestPollingTransport`). |
| `mvs_show_online_status` | filter | `Messaging/MessagingService.php:1257,1273` | Whether to show online status for a given user. |

### 4.2 Media Lifecycle

| Hook | Type | Location | Description |
|------|------|----------|-------------|
| `mvs_before_media_insert` | action | `Services/UploadService.php:231` | Fires before media row is inserted during upload. |
| `mvs_before_thumbnail_generation` | action | `Services/UploadService.php:576` | Fires before thumbnail sizes are generated. |
| `mvs_after_thumbnail_generation` | action | `Services/UploadService.php:600` | Fires after thumbnails generated with results array. |
| `mvs_media_group_assigned` | action | `REST/Controller/MediaController.php:577` | Fires when a media item is assigned to a gallery group. |
| `mvs_media_deleted` | action | `REST/Controller/MediaController.php:688`, `BulkController.php:153` | Fires after a media item is deleted. |
| `mvs_media_flagged` | action | `Services/AIService.php:178` | Fires when AI moderation flags a media item. |
| `mvs_moderation_changed` | action | `Services/ModerationService.php:91` | Fires when moderation status changes. |
| `mvs_media_response` | filter | `REST/Controller/MediaController.php:995` | Filter media data in REST responses (signed URL injection). |
| `mvs_rest_pagination_max` | filter | `REST/Controller/MediaController.php:1016` | Max per_page for media list (default 100). |
| `mvs_feed_sort_options` | filter | `REST/Controller/MediaController.php:1048` | Allowed sort options for feed (default: date, trending, popular). |
| `mvs_max_upload_size` | filter | `Services/UploadService.php:70` | Max upload file size in bytes. |
| `mvs_upload_directory` | filter | `Services/UploadService.php:183` | Upload subdirectory path (default: `Y/m`). |
| `mvs_allowed_file_types` | filter | `Services/UploadService.php:311` | Array of allowed MIME types for upload. |
| `mvs_media_metadata` | filter | `Services/UploadService.php:522` | Filter extracted metadata before saving. |
| `mvs_storage_driver` | filter | `Services/StorageService.php:39` | Override storage driver (default: `LocalDriver`). |
| `mvs_should_ai_analyze` | filter | `Services/AIService.php:209` | Whether AI should analyze a given media item. |
| `mvs_ai_moderation_result` | filter | `Services/AIService.php:167` | Filter AI moderation result before action. |
| `mvs_ai_result` | filter | `Services/AIService.php:240` | Filter AI analysis output. |
| `mvs_privacy_can_view` | filter | `Services/PrivacyService.php:90` | Override privacy check for media viewing. |

### 4.3 Social

| Hook | Type | Location | Description |
|------|------|----------|-------------|
| `mvs_reaction_toggled` | action | `REST/Controller/ReactionController.php:173` | Fires after reaction toggle (add or remove). |
| `mvs_reaction_added` | action | `REST/Controller/ReactionController.php:178` | Fires specifically when a reaction is added. |
| `mvs_favorite_toggled` | action | `REST/Controller/FavoriteController.php:179` | Fires after favorite toggle. |
| `mvs_comment_created` | action | `Social/CommentService.php:80` | Fires after a comment is created. |
| `mvs_comment_edit_window` | filter | `REST/Controller/CommentController.php:283` | Edit window in seconds (default: 15 min). |
| `mvs_user_followed` | action | `Social/FollowService.php:63` | Fires after a user follows another. |
| `mvs_user_unfollowed` | action | `Social/FollowService.php:102` | Fires after a user unfollows another. |
| `mvs_mentions_created` | action | `Social/MentionService.php:58` | Fires after @mentions are parsed and stored. |
| `mvs_media_shared` | action | `Social/ShareService.php:50` | Fires when media is shared to a platform. |
| `mvs_notification_created` | action | `Social/NotificationService.php:130` | Fires after a notification is created. |
| `mvs_should_send_notification` | filter | `Social/NotificationService.php:83` | Whether to send a specific notification. |
| `mvs_activity_types` | filter | `Social/ActivityService.php:63` | Allowed activity feed types. |
| `mvs_report_submitted` | action | `Social/ReportService.php:113` | Fires after an abuse report is submitted. |
| `mvs_user_blocked` | action | `Social/ReportService.php:199` | Fires after a user blocks another. |
| `mvs_user_profile_url` | filter | `Core/TemplateHelpers.php:130` | Override user profile URL. |
| `mvs_user_display_name` | filter | `Core/TemplateHelpers.php:147` | Override user display name. |
| `mvs_activity_max_media` | filter | `Integrations/BuddyPressIntegration.php:2112,2137` | Max media items in activity posts (default: 6). |

### 4.4 Messaging

| Hook | Type | Location | Description |
|------|------|----------|-------------|
| `mvs_conversation_created` | action | `Messaging/MessagingService.php:347` | Fires after a conversation is created. |
| `mvs_message_request_accepted` | action | `Messaging/MessagingService.php:612` | Fires when a message request is accepted. |
| `mvs_message_sent` | action | `Messaging/MessagingService.php:829` | Fires after a message is sent. |
| `mvs_voice_message_sent` | action | `Messaging/MessagingService.php:836` | Fires after a voice message is sent. |
| `mvs_message_deleted` | action | `Messaging/MessagingService.php:993,1055` | Fires after a message is deleted (self or for all). |
| `mvs_conversation_read` | action | `Messaging/MessagingService.php:1089` | Fires when a conversation is marked as read. |
| `mvs_message_reaction_added` | action | `Messaging/MessagingService.php:1172` | Fires when a reaction is added to a message. |
| `mvs_can_send_message` | filter | `Messaging/MessagingService.php:59` | Whether a user can message another. |
| `mvs_dm_access_level` | filter | `Messaging/MessagingService.php:91` | DM access level between two users. |
| `mvs_dm_message_rate_limit` | filter | `Messaging/MessagingService.php:155` | Messages-per-minute rate limit. |
| `mvs_dm_convo_rate_limit` | filter | `Messaging/MessagingService.php:174` | New-conversations-per-hour rate limit. |
| `mvs_message_max_length` | filter | `Messaging/MessagingService.php:697` | Max message character length. |
| `mvs_dm_max_upload_size` | filter | `Messaging/MessagingController.php:749` | Max DM attachment size (default: 10 MB). |

### 4.5 Access / Monetization

| Hook | Type | Location | Description |
|------|------|----------|-------------|
| `mvs_access_rule_created` | action | `Services/AccessRulesService.php:91` | Fires after an access rule is created. |
| `mvs_access_rule_deleted` | action | `Services/AccessRulesService.php:169` | Fires after an access rule is deleted. |
| `mvs_access_granted` | action | `Services/AccessRulesService.php:308` | Fires after access is granted to a user. |
| `mvs_access_revoked` | action | `Services/AccessRulesService.php:344` | Fires after access is revoked. |

### 4.6 Albums / Content

| Hook | Type | Location | Description |
|------|------|----------|-------------|
| `mvs_album_items_added` | action | `Services/AlbumService.php:121` | Fires after media items are added to an album. |
| `mvs_album_response` | filter | `REST/Controller/AlbumController.php:593` | Filter album data in REST response. |
| `mvs_collection_response` | filter | `REST/Controller/CollectionController.php:439` | Filter collection data in REST response. |
| `mvs_tags_merged` | action | `REST/Controller/TagController.php:257` | Fires after two tags are merged. |
| `mvs_story_created` | action | `Services/StoryService.php:64` | Fires after a story is created. |
| `mvs_story_expired` | action | `Services/StoryService.php:204` | Fires when a story expires. |

### 4.7 Watermark

| Hook | Type | Location | Description |
|------|------|----------|-------------|
| `mvs_watermark_enabled` | filter | `Services/WatermarkService.php:83` | Whether watermarking is enabled (default `false`). |
| `mvs_watermark_config` | filter | `Services/WatermarkService.php:107` | Watermark configuration array. |
| `mvs_generate_watermark` | filter | `Services/WatermarkService.php:158` | Generate watermarked preview URL. |
| `mvs_watermark_invalidated` | action | `Services/WatermarkService.php:187` | Fires when a watermark cache is invalidated. |
| `mvs_watermarks_invalidated_all` | action | `Services/WatermarkService.php:200` | Fires when all watermark caches are invalidated. |

### 4.8 Profile

| Hook | Type | Location | Description |
|------|------|----------|-------------|
| `mvs_profile_updated` | action | `Services/ProfileService.php:141` | Fires after profile fields are updated. |
| `mvs_avatar_uploaded` | action | `Services/ProfileService.php:238` | Fires after avatar is uploaded. |
| `mvs_avatar_deleted` | action | `Services/ProfileService.php:268` | Fires after avatar is deleted. |
| `mvs_profile_data` | filter | `Services/ProfileService.php:86` | Filter profile data before return. |
| `mvs_profile_update_fields` | filter | `Services/ProfileService.php:107` | Filter profile fields before update. |
| `mvs_avatar_allowed_types` | filter | `Services/ProfileService.php:167` | Allowed avatar MIME types. |
| `mvs_avatar_max_size` | filter | `Services/ProfileService.php:179` | Max avatar file size (default: 2 MB). |

### 4.9 Admin

| Hook | Type | Location | Description |
|------|------|----------|-------------|
| `mvs_dashboard_widgets` | action | `Admin/OverviewPage.php:494` | Render extra widgets on the admin overview page. |
| `mvs_settings_before_save` | action | `Admin/SettingsPage.php:96` | Fires before settings are saved. |
| `mvs_settings_sidebar_after` | action | `Admin/SettingsPage.php:1365` | Render extra content in settings sidebar. |
| `mvs_settings_render_{renderer}` | action | `Admin/SettingsPage.php:1400` | Dynamic hook to render custom settings sections. |
| `mvs_moderation_tabs` | filter | `Admin/ModerationQueue.php:185` | Register additional moderation tabs. |
| `mvs_stats_tabs` | filter | `Admin/StatsPage.php:158` | Register additional stats tabs. |
| `mvs_settings_sections` | filter | `Admin/SettingsPage.php:1235` | Register additional settings sections. |
| `mvs_settings_group_labels` | filter | `Admin/SettingsPage.php:1270` | Customize settings group labels. |
| `mvs_openai_api_key` | filter | `Services/OpenAIProvider.php:210` | Override OpenAI API key. |
| `mvs_before_upload_form` | action | `Shortcodes/Shortcodes.php:104` | Fires before the upload form renders. |

### 4.10 Template

| Hook | Type | Location | Description |
|------|------|----------|-------------|
| `mvs_locate_template` | filter | `Core/TemplateLoader.php:515` | Filter the resolved template file path. |
| `mvs_template_variables` | filter | `Core/TemplateLoader.php:539` | Filter variables passed to a template. |
| `mvs_before_template_render` | action | `Core/TemplateLoader.php:553` | Fires before a template part is rendered. |
| `mvs_after_template_render` | action | `Core/TemplateLoader.php:565` | Fires after a template part is rendered. |

---

## 5. Template System

### Template Loading (`includes/Core/TemplateLoader.php`)

The template system serves frontend pages through two mechanisms:

1. **Rewrite rules** for media pages (no CPT dependency)
2. **CPT template filters** for albums and collections

#### Rewrite Routes

| URL Pattern | Query Var | Template |
|-------------|-----------|----------|
| `/media/` | `mvs_media_archive=1` | `explore.php` |
| `/media/page/{n}/` | `mvs_media_archive=1&paged=N` | `explore.php` |
| `/media/{slug}/` | `mvs_media_slug={slug}` | `media-single.php` |
| `/media/@{username}/` | `mvs_profile_user={username}` | `user-profile.php` (fallback: `explore.php`) |
| `/media/@{username}/page/{n}/` | `mvs_profile_user={username}&paged=N` | `user-profile.php` |
| `/media/edit-profile/` | `mvs_edit_profile=1` | `profile-edit.php` |

Reserved paths (excluded from slug matching, extensible via `mvs_reserved_media_paths`):
`battles`, `challenges`, `tournaments`, `leaderboard`, `edit-profile`, `page`

#### CPT Template Mapping

| Post Type / Taxonomy | Template |
|----------------------|----------|
| Single `mvs_album` | `album.php` |
| Single `mvs_collection` | `collection.php` |
| Archive `mvs_album` | `explore.php` |
| Taxonomy `mvs_tag` | `explore.php` |
| Taxonomy `mvs_category` | `explore.php` |

#### Theme Override Mechanism

Templates are resolved via `TemplateLoader::locate()` in this priority order:

1. **Theme override:** `{theme}/wpmediaverse/{template_name}` (checked via `locate_template()`)
2. **Plugin default:** `{plugin_dir}/templates/{template_name}`
3. Filtered via `mvs_locate_template`

To override a template, copy it from `wpmediaverse/templates/` to `your-theme/wpmediaverse/`.

#### Programmatic Template Loading

```php
// Load a template with data:
TemplateLoader::get_template( 'partials/grid-item.php', [ 'media_id' => 42 ] );

// Locate without rendering:
$path = TemplateLoader::locate( 'media-single.php' );
```

`get_template()` fires `mvs_template_variables` filter (to modify data) and `mvs_before_template_render` / `mvs_after_template_render` actions.

### Template Helpers (`includes/Core/TemplateHelpers.php`)

Static utility methods for consistent rendering across all templates and BuddyPress integration. All lookups use `mvs_media_index` / `MediaMeta` -- never `get_post()`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `get_thumb_url` | `(int $media_id, string $size = 'large'): string` | Resolve best thumbnail URL. Priority: `thumb_large` > `thumb_medium` > `thumb_thumb` > `file_url` (images only). |
| `get_media_type` | `(int $media_id): string` | Returns `image`, `video`, `audio`, or `document`. Reads `media_type` meta, falls back to MIME detection. |
| `get_user_profile_url` | `(int $user_id): string` | Profile URL with BuddyPress support. Filterable via `mvs_user_profile_url`. |
| `get_display_name` | `(int $user_id): string` | Display name, filterable via `mvs_user_display_name`. May contain HTML from badge filters. |
| `render_grid_thumbnail` | `(int $media_id, string $size, string $alt): void` | Outputs `<img>` or type-specific placeholder (video play icon, audio note, document icon). |
| `render_grid_item` | `(int $media_id, array $stats, array $options): void` | Full grid item with thumbnail, hover overlay, gallery badge, author row, Interactivity API context. |
| `bulk_get_stats` | `(int[] $media_ids): array` | Batch fetch from `mvs_media_stats` keyed by media_id. |

**`render_grid_item` options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `show_author` | bool | `true` | Show author avatar + name below thumbnail |
| `show_overlay` | bool | `true` | Show stats overlay on hover |
| `data_attrs` | array | `[]` | Extra `data-*` attributes on the grid item div |
| `size` | string | Setting `mvs_thumbnail_size` or `'large'` | Image size to render |

---

## 6. Free-to-Pro Boundary

### Connection Point

Pro connects via the `mvs_loaded` action, which fires at the end of `Plugin::init()` and passes the `ServiceContainer`:

```php
// In Pro plugin:
add_action( 'mvs_loaded', function ( ServiceContainer $container ) {
    // Access any Free service:
    $upload  = $container->get( 'upload' );
    $privacy = $container->get( 'privacy' );

    // Register Pro services, hooks, and UI.
}, 10, 1 );
```

### Service Container Access

Pro accesses Free services through the container -- never via direct `use` imports:

```php
// Correct (Pro):
$container->get( 'storage' );
$container->get( 'moderation' );

// Wrong (creates coupling):
use WPMediaVerse\Services\StorageService;
```

### Key Filter Hooks for Pro

| Filter | Location | Purpose |
|--------|----------|---------|
| `mvs_storage_driver` | `StorageService.php:39` | Override default `LocalDriver` with S3, Cloudflare R2, etc. |
| `mvs_watermark_enabled` | `WatermarkService.php:83` | Enable watermark feature (default `false` in free). |
| `mvs_watermark_config` | `WatermarkService.php:107` | Configure watermark position, opacity, image. |
| `mvs_generate_watermark` | `WatermarkService.php:158` | Provide watermarked preview URL. |
| `mvs_ai_providers` | `Plugin.php:335` | Register additional AI providers (Claude, Gemini, etc.). |
| `mvs_should_ai_analyze` | `AIService.php:209` | Control which media gets AI analysis. |
| `mvs_openai_api_key` | `OpenAIProvider.php:210` | Override API key for AI services. |
| `mvs_moderation_tabs` | `ModerationQueue.php:185` | Inject additional admin moderation tabs. |
| `mvs_stats_tabs` | `StatsPage.php:158` | Inject additional admin stats tabs. |
| `mvs_settings_sections` | `SettingsPage.php:1235` | Register Pro settings sections in the admin. |
| `mvs_settings_group_labels` | `SettingsPage.php:1270` | Customize settings group names. |
| `mvs_reserved_media_paths` | `TemplateLoader.php:101` | Reserve URL paths for Pro features (battles, tournaments). |
| `mvs_buddynext_active` | `Plugin.php:1028+` | Signal BuddyNext integration is active. |
| `mvs_messaging_transport` | `Plugin.php:980` | Override REST polling with WebSocket transport. |
| `mvs_can_send_message` | `MessagingService.php:59` | Restrict messaging based on Pro rules. |
| `mvs_dm_access_level` | `MessagingService.php:91` | Set DM access (everyone/followers/nobody). |
| `mvs_privacy_can_view` | `PrivacyService.php:90` | Override privacy checks for Pro access models. |
| `mvs_media_response` | `MediaController.php:995` | Inject Pro data into REST responses. |

### Admin Tab Injection

Pro injects additional tabs into admin pages using these filter hooks:

```php
// Add a Pro moderation tab:
add_filter( 'mvs_moderation_tabs', function ( array $tabs ) {
    $tabs['ai_insights'] = [
        'label' => __( 'AI Insights', 'wpmediaverse-pro' ),
        'slug'  => 'ai-insights',
    ];
    return $tabs;
});

// Add a Pro stats tab:
add_filter( 'mvs_stats_tabs', function ( array $tabs ) {
    $tabs['revenue'] = [
        'label' => __( 'Revenue', 'wpmediaverse-pro' ),
        'slug'  => 'revenue',
    ];
    return $tabs;
});
```

### Cron Hooks

| Hook | Schedule | Description |
|------|----------|-------------|
| `mvs_prune_logs` | Daily | Prune old error log entries via `LoggerService::prune()` |
| Story cleanup | Via `StoryService::init()` | Expire stories past their TTL |
| `mvs_deliver_webhook` | Action Scheduler | Async webhook delivery with payload |
