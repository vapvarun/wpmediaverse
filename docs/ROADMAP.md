# WPMediaVerse — Product Roadmap

## Vision

WPMediaVerse is a complete media platform for WordPress — standalone first, BuddyPress-enhanced second, mobile app in the future. Every feature is REST API-first, using native WordPress APIs only (no third-party plugin dependencies).

**Built by** [vapvarun](https://github.com/vapvarun) & [Wbcom Designs](https://wbcomdesigns.com/)

---

## Architecture Principles

- **Standalone first** — every feature works with just WordPress + WPMediaVerse
- **BuddyPress optional** — BP enriches web experience (activity, profile tabs, groups); plugin bridges to BP when detected
- **REST API first** — all features accessible via `mvs/v1/` endpoints for future mobile app
- **Native WordPress only** — no third-party plugin dependencies; auth via WP Application Passwords (5.6+)
- **Pro = extension** — Pro hooks into free via filters/actions, never duplicates free code
- **Zero legacy JS** — 100% WordPress Interactivity API on frontend

---

## Release Plan

### Free 1.0.0 — Core Media Platform (COMPLETE)

Core media management with social features, AI moderation, and BuddyPress integration.

**Shipped:**
- 3 CPTs (mvs_media, mvs_album, mvs_collection)
- 9 custom database tables
- 15 capabilities across 5 roles
- Upload with MIME validation, EXIF stripping, duplicate detection
- 6-level privacy system (public, loggedin, friends, group, private, custom)
- Social: reactions (6 types), threaded comments, favorites, @mentions, sharing
- Albums, playlists, smart collections, stories (24h)
- AI moderation (OpenAI Vision) with approve/reject queue
- 8 Gutenberg blocks (Interactivity API)
- 6 shortcodes, 4 overridable templates
- BuddyPress: activity stream, profile/group media tabs, notifications
- 40+ REST API endpoints
- WP-CLI (8 commands), webhooks (HMAC-SHA256), rtMedia import
- Access rules engine (6 rule types), signed URLs, lock overlay
- 154+ PHPUnit tests

---

### Free 1.1.0 — Video/Audio + Social Foundation (NEXT)

Make the plugin a complete standalone social media platform — no BuddyPress required.

#### Sprint 1: Media Playback

| ID | Feature | Description | New/Modified Files |
|----|---------|-------------|-------------------|
| F1 | Video/audio metadata | Extract duration, resolution, codec via `wp_read_video_metadata()` / `wp_read_audio_metadata()`. Store as `_mvs_duration`, `_mvs_width`, `_mvs_height` post meta. Return in REST responses. | UploadService, MediaController |
| F2 | Video thumbnails | Fix `get_media_thumbnail_html()` — use WP attachment poster for video, placeholder SVGs for audio/documents. Fixes blank cards in activity stream, profile grid, explore feed. | BuddyPressIntegration, templates |
| F3 | Custom media player | Replace browser-default `<video>`/`<audio>` with styled HTML5 player. Controls: play/pause, progress bar with seek, volume slider, fullscreen, speed (0.5x–2x), mute. Poster image for video. Responsive. Keyboard accessible. Interactivity API store. | media-player block rewrite, CSS |
| F4 | Playlist playback UI | Sequential playback for audio albums: track list with title + duration, play/next/prev, auto-advance, shuffle/repeat, now-playing indicator. | album-viewer block, new store |
| F5 | Play event tracking | New event types: `play`, `pause`, `complete`. Track `position_seconds`. REST: `POST /media/{id}/play-event`. StatsService aggregates: total_plays, avg_watch_time, completion_rate. | MediaController, StatsService, new `mvs_play_events` table |

#### Sprint 2: Social Foundation (Standalone)

| ID | Feature | Description | New Files |
|----|---------|-------------|-----------|
| F8 | Notification system | Native notification center (no BuddyPress required). Table: `mvs_notifications` (user_id, type, actor_id, media_id, read_at). Service: create, mark read, count unread. Controller: `GET /me/notifications`, `POST /me/notifications/read`, `GET /me/notifications/count`. UI: notification bell in templates. BP bridge: sync to BP notifications when active. | `NotificationService`, `NotificationController`, `mvs_notifications` table, notification template partial |
| F9 | User profiles | Public user profile page showing name, bio, avatar, stats, recent media grid, follow button. REST: `GET /users/{id}` (profile), `GET /users/{id}/media` (public media). Template: `user-profile.php`. WordPress user meta for bio. | `UserService`, `UserController`, user-profile template |
| F10 | Follow system | Follow/unfollow users. Table: `mvs_follows` (follower_id, following_id, status, created_at). REST: `POST/DELETE /users/{id}/follow`, `GET /me/following`, `GET /me/followers`, `GET /users/{id}/followers`, `GET /users/{id}/following`. Notification on new follower. BP bridge: sync to BP friends when active. | `FollowService`, `FollowController`, `mvs_follows` table |
| F11 | Report & block | Report media or users. Block users (hidden from feed, search, comments). Tables: `mvs_reports` (reporter_id, target_type, target_id, reason, status), `mvs_blocks` (blocker_id, blocked_id). REST: `POST /media/{id}/report`, `POST /users/{id}/report`, `POST/DELETE /users/{id}/block`, `GET /me/blocked`. Admin report queue (extends moderation). Required for App Store approval. | `ReportService`, `ReportController`, `mvs_reports` + `mvs_blocks` tables |
| F12 | Standalone activity feed | Native activity feed without BuddyPress. Table: `mvs_activity` (user_id, type, media_id, content, created_at). Auto-records: uploads, reactions, comments, follows. REST: `GET /feed` (public), `GET /feed?scope=following` (followed users only). Template: `activity.php`. BP bridge: when BP active, reads from BP activity instead. | `ActivityService`, `ActivityController`, `mvs_activity` table, activity template |
| F13 | User discovery | Search users by name/username. Suggested users (mutual follows, shared groups). REST: `GET /users/search?q=term`, `GET /users/suggested`. | `UserController` endpoints |

#### Sprint 3: UX Polish

| ID | Feature | Description |
|----|---------|-------------|
| F14 | Comment editing | `PUT /media/{id}/comments/{cid}` — edit own comments within 15 min window |
| F15 | Cursor pagination | All list endpoints support `?cursor=xxx&limit=20` with `next_cursor` + `has_more` in response. Page param still works (backward compatible). |
| F16 | Draft/scheduled media | Support `status=draft` and `publish_at` date on upload. Cron publishes scheduled media. |
| F6 | Settings Pro indicators | Show Pro features as disabled/locked fields with Pro badge in free SettingsPage. Upgrade link per section. |
| F7 | Abilities API | Register WPMediaVerse abilities via WP 6.9+ Abilities API. 17 free abilities. Category: `wpmediaverse`. REST discoverable for app feature detection. |

**New Database Tables (Free 1.1.0):**

| Table | Columns | Purpose |
|-------|---------|---------|
| `mvs_follows` | id, follower_id, following_id, status (active/pending), created_at | User follow relationships |
| `mvs_notifications` | id, user_id, type, actor_id, media_id, comment_id, read_at, created_at | Native notification system |
| `mvs_activity` | id, user_id, type, media_id, album_id, content, created_at | Standalone activity feed |
| `mvs_reports` | id, reporter_id, target_type (media/user), target_id, reason, details, status, created_at | Content/user reporting |
| `mvs_blocks` | id, blocker_id, blocked_id, created_at | User blocking |
| `mvs_play_events` | id, media_id, user_id, event_type (play/pause/complete), position_seconds, created_at | Video/audio play tracking |

**New Services (Free 1.1.0):**

| Service | Responsibility |
|---------|---------------|
| `NotificationService` | Create, list, mark read, count unread, prune old |
| `FollowService` | Follow/unfollow, list followers/following, BP bridge |
| `UserService` | Public profiles, user search, suggested users |
| `ReportService` | Report media/users, admin queue, auto-hide threshold |
| `ActivityService` | Record activities, feed queries, BP bridge |

**New REST Controllers (Free 1.1.0):**

| Controller | Endpoints |
|-----------|-----------|
| `UserController` | `GET /users/{id}`, `GET /users/{id}/media`, `GET /users/search`, `GET /users/suggested` |
| `FollowController` | `POST/DELETE /users/{id}/follow`, `GET /me/following`, `GET /me/followers`, `GET /users/{id}/followers`, `GET /users/{id}/following` |
| `NotificationController` | `GET /me/notifications`, `POST /me/notifications/read`, `GET /me/notifications/count` |
| `ReportController` | `POST /media/{id}/report`, `POST /users/{id}/report`, `POST/DELETE /users/{id}/block`, `GET /me/blocked` |
| `ActivityController` | `GET /feed`, `GET /feed?scope=following` |

---

### Free 1.2.0 — Discovery & Intelligence

| ID | Feature | Description |
|----|---------|-------------|
| F17 | Trending algorithm | Weighted score: `(reactions × 3 + comments × 5 + views) / age_hours^1.5`. REST: `GET /feed?type=trending` |
| F18 | Hashtag pages | Dedicated `/tag/{slug}/` pages with media grid + stats |
| F19 | Comment likes | Upvote comments, sort by popularity |
| F20 | Story enhancements | Configurable expiry, seen-by list, auto-advance viewer, story reactions |
| F21 | Advanced search | Date range, duration, file size, resolution filters |
| F22 | Media carousel block | Slider/carousel for featured media |
| F23 | User preferences | `mvs_user_preferences` table — notification settings, privacy defaults per user |

---

### Pro 1.0.0 — Monetization + Storage

Pro plugin: `wpmediaverse-pro` (requires free ≥ 1.1.0)

| ID | Feature | Hook/Interface |
|----|---------|---------------|
| P-S1 | Plugin scaffold + license | Activation check, `WPMediaVersePro\` namespace |
| P-S2 | Settings extension | All 5 tabs + new Monetization tab, enables locked free fields |
| P-PAY1 | Stripe payment gateway | `mvs_checkout_process` filter |
| P-PAY2 | WooCommerce integration | `mvs_checkout_process` filter, WC product type |
| P-ST1 | Amazon S3 storage | `mvs_storage_driver` filter, `StorageDriverInterface` |
| P-ST2 | BunnyCDN storage | `mvs_storage_driver` filter, `StorageDriverInterface` |
| P-WM1 | Image watermarking | `mvs_generate_watermark` + `mvs_watermark_enabled` filters |
| P-PRV1 | Advanced privacy UI | Frontend per-media/album/group privacy controls |
| P-ACC1 | Access rules frontend | Pricing panel, rule builder, earnings dashboard |
| P-ADM1 | Pro admin assets | Conditional fields, connection tests, live preview |

---

### Pro 1.1.0 — AI + Video Intelligence

| ID | Feature | Hook/Interface |
|----|---------|---------------|
| P-AI1 | Google Vision provider | `mvs_ai_providers` action, `AIProviderInterface` |
| P-AI2 | AWS Rekognition provider | `mvs_ai_providers` action, `AIProviderInterface` |
| P-AI3 | AI fallback chain | Provider priority, comparison mode, per-category thresholds |
| P-VID1 | Video transcoding | FFmpeg + cloud transcoding, HLS adaptive streaming |
| P-VID2 | Video chapters + resume | Chapter markers, saved playback position per user |
| P-VID3 | Video analytics | Watch heatmaps, completion rates, engagement scoring |
| P-VID4 | Auto-captions | Whisper API transcription, .vtt subtitle generation |
| P-MSG1 | Direct messaging | `mvs_conversations` + `mvs_messages` tables, media sharing in DMs |
| P-PUSH1 | Push notifications | Firebase/APNs, device token management, rich notifications |
| P-GATE1 | Email gates | Lead capture before/during video, Mailchimp/ConvertKit hooks |

---

### Future (Evaluated, Not Committed)

| Feature | Priority | Notes |
|---------|----------|-------|
| Mobile app (React Native / Flutter) | HIGH | REST API already covers all features |
| Recommendation engine | MEDIUM | Based on reaction history, tag overlap, similar users |
| Live streaming (RTMP/HLS ingest) | LOW | Requires media server infrastructure |
| Adobe Lightroom sync | LOW | REST API bridge, photographer market |
| Multi-site network support | MEDIUM | Per-site vs network-wide settings |
| AR/VR media (360° photos) | LOW | Three.js, niche market |

### Explicitly Out of Scope

- Social login (other plugins handle this)
- User registration/profiles (WordPress handles this)
- Email marketing (integrate via hooks, not build)
- SEO (Yoast/RankMath handle this)
- Page builders (blocks + shortcodes cover all builders)
- Form builders (not our domain)

---

## Coding Standards

### PHP
- WordPress Coding Standards (WPCS) via PHP_CodeSniffer
- PSR-4 autoloading (`WPMediaVerse\` → `includes/`)
- PHP 7.4+ minimum
- Every public method has full PHPDoc with `@since` tag
- Every hook has inline docblock with params and `@since`

### JavaScript
- WordPress Interactivity API exclusively — zero jQuery, zero legacy IIFE
- `@wordpress/scripts` build with multi-block config
- Safe DOM methods only (createElement, textContent). Never innerHTML.
- All REST calls include `X-WP-Nonce` header + `credentials: 'same-origin'`

### CSS
- BEM-inspired with `mvs-` prefix
- Mobile-first responsive (breakpoints: 480px, 768px, 1024px)
- No `!important` — ever

### Naming Conventions
| Element | Convention | Example |
|---------|-----------|---------|
| Hook prefix | `mvs_` | `mvs_media_uploaded` |
| Option prefix | `mvs_` | `mvs_max_upload_size` |
| Meta key prefix | `_mvs_` | `_mvs_privacy` |
| CSS class prefix | `mvs-` | `mvs-media-grid` |
| JS store namespace | `mvs/` | `mvs/media-player` |
| REST namespace | `mvs/v1` | `/wp-json/mvs/v1/media` |
| Block namespace | `mvs/` | `mvs/media-grid` |
| Table prefix | `mvs_` | `{$wpdb->prefix}mvs_follows` |
| Constant prefix | `MVS_` | `MVS_VERSION` |
| Pro hook prefix | `mvs_pro_` | `mvs_pro_watermark_applied` |
| Pro option prefix | `mvs_pro_` | `mvs_pro_stripe_key` |

---

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

- [ ] All user input sanitized (`sanitize_text_field`, `absint`, `esc_url`)
- [ ] All output escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`)
- [ ] All DB queries use `$wpdb->prepare()`
- [ ] All REST endpoints have `permission_callback`
- [ ] All file uploads validate MIME server-side
- [ ] EXIF GPS stripped from images
- [ ] No direct file access (ABSPATH check)
- [ ] CSRF protection via nonces
- [ ] IDOR checks (user owns resource before modify/delete)
- [ ] Rate limiting on write endpoints
- [ ] API keys never exposed in responses
- [ ] Signed URLs use HMAC-SHA256 with timing-safe comparison
- [ ] Blocked users excluded from all queries

---

## Versioning

- **Semantic Versioning**: MAJOR.MINOR.PATCH
- **Major**: Breaking hook signature changes, DB schema requiring migration
- **Minor**: New features, new hooks, non-breaking additions
- **Patch**: Bug fixes, security patches
- **Deprecation policy**: 2 minor versions warning via `_deprecated_hook()`, removed in next major

---

## Competitive Positioning

| Feature | rtMedia | BuddyBoss | MediaPress | WPMediaVerse |
|---------|---------|-----------|------------|-------------|
| Standalone (no BP) | No | No | No | **Yes** |
| Custom video player | No | No | No | **Yes** (1.1.0) |
| AI moderation | No | No | No | **Yes** |
| Monetization | No | No | No | **Yes** (Pro) |
| Cloud storage | No | Cloudflare only | No | **S3 + CDN** (Pro) |
| Follow system | No | Platform only | No | **Yes** (1.1.0) |
| REST API (headless) | No | Limited | No | **40+ endpoints** |
| Interactivity API | No | No | No | **100%** |
| Mobile app ready | No | Their app only | No | **Yes** |
| Active development | Declining | Yes | Minimal | **Yes** |
