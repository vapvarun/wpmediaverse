# WPMediaVerse — Architecture Specification

## Overview

WPMediaVerse is a general-purpose WordPress media platform plugin that provides media uploading, organization, social interactions, AI-powered features, monetization, and deep integration with BuddyPress, WooCommerce, LearnDash, and PeepSo.

---

## Core Architecture

### Bootstrap Flow
1. `wpmediaverse.php` — Plugin header, constants, Composer autoloader, hooks
2. `Core\Plugin::init()` — Called on `plugins_loaded`
3. Loads textdomain, creates `ServiceContainer`, registers CPTs/taxonomies
4. Fires `mvs_loaded` action with container reference

### Service Container
- `Core\ServiceContainer` — Simple lazy-load container
- `register($key, $factory)` / `get($key)` / `has($key)`
- Factory receives container reference for dependency resolution
- Instances cached after first creation

### Database Migrations
- `Core\Migrator` — Version-based migrations via `mvs_db_version` option
- `CURRENT_VERSION` incremented per migration
- All tables created via `dbDelta()` for safe upgrades

### Storage Layer
- `StorageDriverInterface` — `store()`, `delete()`, `url()`, `exists()`
- `LocalDriver` — Default, stores in `wp-content/uploads/wpmediaverse/`
- S3/BunnyCDN drivers via `mvs_storage_driver` filter (Pro)

---

## Data Model

### Custom Post Types

| CPT | Slug | Purpose |
|---|---|---|
| `mvs_media` | media | Individual media items |
| `mvs_album` | album | Ordered media collections |
| `mvs_collection` | collection | User favorites/bookmarks |

### Taxonomies

| Taxonomy | Hierarchical | Attached To |
|---|---|---|
| `mvs_tag` | No | `mvs_media` |
| `mvs_category` | Yes | `mvs_media` |

### Custom Tables

1. **mvs_reactions** — Per-user reactions on media (like/love/haha/wow/sad/angry)
2. **mvs_favorites** — User favorites with optional collection grouping
3. **mvs_media_views** — Individual view/download events
4. **mvs_media_stats** — Aggregate stats (views, downloads, reactions, comments, shares)
5. **mvs_access_rules** — Monetization rules per media item
6. **mvs_access_grants** — Purchased/granted access records
7. **mvs_mentions** — @mention tracking in descriptions and comments
8. **mvs_album_items** — Album-to-media ordered relationships
9. **mvs_media_index** — Flat denormalized index for fast explore/feed queries

### Post Meta (mvs_media)
- `_mvs_file_url` — Public URL of stored file
- `_mvs_file_path` — Relative storage path
- `_mvs_file_size` — File size in bytes
- `_mvs_file_type` — MIME type
- `_mvs_file_hash` — SHA-256 hash
- `_mvs_media_type` — image/video/audio/document
- `_mvs_privacy` — public/members/friends/group/private/custom
- `_mvs_moderation_status` — approved/pending/rejected/flagged
- `_mvs_exif_raw` — Raw EXIF data (preserved before stripping)

---

## Privacy Model

| Level | Access |
|---|---|
| public | Anyone |
| members | Logged-in users |
| friends | BuddyPress friends (falls back to private if BP inactive) |
| group | BuddyPress group members (falls back to private if BP inactive) |
| private | Owner + admins only |
| custom | Explicit user ID list in `_mvs_custom_access` |

---

## Capabilities

| Capability | Subscriber | Author | Editor | Administrator |
|---|---|---|---|---|
| upload_mvs_media | Yes | Yes | Yes | Yes |
| edit_mvs_media | — | Yes | Yes | Yes |
| edit_others_mvs_media | — | — | Yes | Yes |
| delete_mvs_media | — | Yes | Yes | Yes |
| delete_others_mvs_media | — | — | Yes | Yes |
| moderate_mvs_media | — | — | Yes | Yes |
| manage_mvs_settings | — | — | — | Yes |

---

## Upload Pipeline

1. Validate MIME type against whitelist
2. Check file size against admin setting
3. Compute SHA-256 hash, check for duplicates
4. Strip EXIF GPS data (preserve raw in meta)
5. Store via configured driver
6. Create `mvs_media` CPT post with all meta
7. Insert row into `mvs_media_index`
8. Initialize `mvs_media_stats` row
9. Fire `mvs_media_uploaded` action

---

## REST API (Phase 1b+)

Namespace: `mvs/v1`

| Endpoint | Methods | Purpose |
|---|---|---|
| `/media` | GET, POST | List/create media |
| `/media/{id}` | GET, PUT, DELETE | Single media CRUD |
| `/media/{id}/view` | POST | Record a view |
| `/media/{id}/access` | GET | Check access |
| `/me/media` | GET | Current user's media |
| `/albums` | GET, POST | List/create albums |
| `/albums/{id}` | GET, PUT, DELETE | Album CRUD |
| `/albums/{id}/reorder` | PUT | Reorder album items |
| `/albums/{id}/items` | POST | Add items to album |
| `/collections` | GET, POST | List/create collections |
| `/media/bulk` | POST | Bulk operations |

---

## Phase Roadmap

- **Phase 1a**: Core foundation (scaffold, CPTs, taxonomies, caps, upload, settings, stubs)
- **Phase 1b**: REST API + Privacy service
- **Phase 2**: Social layer (reactions, comments, favorites, mentions, shares, stats)
- **Phase 3**: Organization (albums, playlists, smart collections, stories, tags)
- **Phase 4**: AI features (auto-tagging, moderation, Action Scheduler)
- **Phase 5**: Blocks & frontend (7 blocks, 5 interactivity stores, shortcodes, templates)
- **Phase 6**: Integrations (BuddyPress, LearnDash, PeepSo, WooCommerce, webhooks)
- **Phase 7**: Monetization (access rules, signed URLs, lock overlay, watermarking)
- **Phase 8**: Hardening & launch (security audit, performance, WP-CLI, import tool, docs)

---

## Key Decisions

1. **Container over Singleton** — ServiceContainer for testability, no global state
2. **Action Scheduler over WP Cron** — Reliable async processing for AI jobs
3. **Flat index table** — mvs_media_index for fast explore/feed queries without JOIN on postmeta
4. **Privacy fallback** — friends/group → private when BuddyPress deactivated
5. **Access grants survive** — Purchases honored even if payment plugin deactivated
6. **EXIF stripped** — GPS/device data removed from served files, raw preserved in meta
7. **Local storage first** — S3/BunnyCDN as Pro features via driver pattern
## Hook Registry

### Actions (50+)

#### Media Lifecycle
| Hook | Params | Since |
|------|--------|-------|
| `mvs_media_uploaded` | `$media_id, $user_id, $args` | 1.0.0 |
| `mvs_media_updated` | `$media_id, $changes` | 1.0.0 |
| `mvs_media_deleted` | `$media_id, $user_id` | 1.0.0 |
| `mvs_media_viewed` | `$media_id, $user_id, $event_type` | 1.0.0 |
| `mvs_media_group_assigned` | `$media_id, $group_id` | 1.0.0 |
| `mvs_media_privacy_changed` | `$media_id, $old, $new` | 1.1.0 |
| `mvs_media_play_event` | `$media_id, $user_id, $event, $position` | 1.1.0 |

#### Social
| Hook | Params | Since |
|------|--------|-------|
| `mvs_reaction_added` | `$media_id, $user_id, $type` | 1.0.0 |
| `mvs_reaction_removed` | `$media_id, $user_id, $type` | 1.0.0 |
| `mvs_comment_created` | `$comment_id, $media_id, $user_id` | 1.0.0 |
| `mvs_comment_deleted` | `$comment_id, $media_id` | 1.0.0 |
| `mvs_favorite_added` | `$media_id, $user_id` | 1.0.0 |
| `mvs_favorite_removed` | `$media_id, $user_id` | 1.0.0 |
| `mvs_mentions_created` | `$media_id, $user_ids, $context, $comment_id` | 1.0.0 |
| `mvs_share_recorded` | `$media_id, $user_id, $platform` | 1.0.0 |
| `mvs_user_followed` | `$user_id, $target_id` | 1.1.0 |
| `mvs_user_unfollowed` | `$user_id, $target_id` | 1.1.0 |
| `mvs_user_blocked` | `$blocker_id, $blocked_id` | 1.1.0 |
| `mvs_media_reported` | `$media_id, $reporter_id, $reason` | 1.1.0 |
| `mvs_notification_created` | `$notification_id, $user_id, $type` | 1.1.0 |

#### Albums
| Hook | Params | Since |
|------|--------|-------|
| `mvs_album_created` | `$album_id, $user_id, $args` | 1.0.0 |
| `mvs_album_updated` | `$album_id, $changes` | 1.0.0 |
| `mvs_album_deleted` | `$album_id` | 1.0.0 |
| `mvs_album_item_added` | `$album_id, $media_id, $position` | 1.0.0 |
| `mvs_album_item_removed` | `$album_id, $media_id` | 1.0.0 |

#### Monetization
| Hook | Params | Since |
|------|--------|-------|
| `mvs_access_granted` | `$media_id, $user_id, $rule_type, $expires` | 1.0.0 |
| `mvs_access_revoked` | `$media_id, $user_id` | 1.0.0 |
| `mvs_payment_completed` | `$media_id, $user_id, $amount, $gateway` | 1.0.0 |
| `mvs_subscription_cancelled` | `$user_id, $subscription_id` | 1.0.0 |
| `mvs_code_redeemed` | `$code, $media_id, $user_id` | 1.0.0 |

#### AI & Moderation
| Hook | Params | Since |
|------|--------|-------|
| `mvs_ai_analysis_complete` | `$media_id, $results, $provider` | 1.0.0 |
| `mvs_moderation_approved` | `$media_id, $moderator_id` | 1.0.0 |
| `mvs_moderation_rejected` | `$media_id, $moderator_id, $reason` | 1.0.0 |
| `mvs_ai_providers` | `$ai_service` | 1.0.0 |

### Filters (30+)

| Filter | Returns | Since | Description |
|--------|---------|-------|-------------|
| `mvs_allowed_file_types` | `array` | 1.0.0 | Allowed MIME types for upload |
| `mvs_max_upload_size` | `int` | 1.0.0 | Max upload size in bytes |
| `mvs_upload_args` | `array` | 1.0.0 | Pre-upload arguments |
| `mvs_media_metadata` | `array` | 1.1.0 | Extracted file metadata |
| `mvs_storage_driver` | `StorageDriverInterface` | 1.0.0 | Active storage driver |
| `mvs_storage_path` | `string` | 1.0.0 | Storage subdirectory path |
| `mvs_privacy_can_view` | `bool` | 1.0.0 | Privacy check result |
| `mvs_privacy_levels` | `array` | 1.0.0 | Available privacy levels |
| `mvs_default_privacy` | `string` | 1.0.0 | Default privacy for new media |
| `mvs_access_rule_check` | `bool` | 1.0.0 | Access rule evaluation |
| `mvs_media_response` | `array` | 1.0.0 | REST media response data |
| `mvs_album_response` | `array` | 1.0.0 | REST album response data |
| `mvs_media_query_args` | `array` | 1.0.0 | Media list query args |
| `mvs_rest_rate_limit` | `int` | 1.0.0 | Rate limit per endpoint |
| `mvs_grid_columns` | `int` | 1.0.0 | Grid column count |
| `mvs_thumbnail_size` | `string` | 1.0.0 | Thumbnail size name |
| `mvs_player_config` | `array` | 1.1.0 | Media player configuration |
| `mvs_player_sources` | `array` | 1.1.0 | Media player source URLs |
| `mvs_checkout_process` | `array` | 1.0.0 | Payment checkout handling |
| `mvs_media_pricing` | `array` | 1.0.0 | Media pricing data |
| `mvs_watermark_enabled` | `bool` | 1.0.0 | Watermark on/off |
| `mvs_generate_watermark` | `string` | 1.0.0 | Watermark file path |
| `mvs_watermark_config` | `array` | 1.0.0 | Watermark settings |
| `mvs_signed_url_ttl` | `int` | 1.0.0 | Signed URL time-to-live |
| `mvs_notification_types` | `array` | 1.1.0 | Registered notification types |
| `mvs_feed_query` | `array` | 1.1.0 | Activity feed query args |
| `mvs_user_profile_data` | `array` | 1.1.0 | Public user profile fields |

---

## Bridge Pattern (Standalone → BuddyPress)
## Bridge Pattern (Standalone → BuddyPress)

Every social service works standalone, with optional BP enhancement:

```php
// Example: FollowService.php
public function follow( int $user_id, int $target_id ): bool {
    // Always: our own table
    $this->insert_follow( $user_id, $target_id );

    // Bridge: sync to BP friends when available
    if ( function_exists( 'friends_add_friend' ) ) {
        friends_add_friend( $user_id, $target_id );
    }

    // Always: our own notification
    $this->notifications->create( $target_id, 'new_follower', $user_id );

    do_action( 'mvs_user_followed', $user_id, $target_id );
    return true;
}
```

| Service | Standalone | + BuddyPress |
|---------|-----------|-------------|
| Follows | `mvs_follows` table | Syncs to BP Friends |
| Notifications | `mvs_notifications` table | Syncs to BP Notifications |
| Activity | `mvs_activity` table | Reads from BP Activity instead |
| Messaging | `mvs_messages` table (Pro) | Syncs to BP Messages |
| User profiles | WordPress user + our REST | BP profile tabs added |
| Groups | Not available | BP group tabs + media |

---

## Security Checklist (Every Release)
