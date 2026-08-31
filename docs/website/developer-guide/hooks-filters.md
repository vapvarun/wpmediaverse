# Hooks & Filters Reference

All WPMediaVerse hooks use the `mvs_` prefix. Pro-only hooks require WPMediaVerse Pro to be active and are labeled **(Pro)**. Hooks introduced in version 1.1 are labeled **(New in 1.1)**.

---

## Quick Reference

| Hook Name | Type | Free/Pro | Since |
|-----------|------|----------|-------|
| `mvs_loaded` | action | Free | 1.0 |
| `mvs_pro_loaded` | action | Pro | 1.0 |
| `mvs_ai_providers` | action | Free | 1.0 |
| `mvs_theme_json` | filter | Free | 1.0 |
| `mvs_before_media_insert` | action | Free | 1.0 |
| `mvs_media_uploaded` | action | Free | 1.0 (signature extended in 1.2.3) |
| `mvs_before_upload_form` | action | Free | 1.0 |
| `mvs_before_thumbnail_generation` | action | Free | 1.1 |
| `mvs_after_thumbnail_generation` | action | Free | 1.1 |
| `mvs_upload_args` | filter | Free | 1.0 |
| `mvs_allowed_file_types` | filter | Free | 1.1 |
| `mvs_max_upload_size` | filter | Free | 1.1 |
| `mvs_upload_directory` | filter | Free | 1.1 |
| `mvs_media_metadata` | filter | Free | 1.0 |
| `mvs_before_content` | action | Free | 1.0 |
| `mvs_after_content` | action | Free | 1.0 |
| `mvs_before_template_render` | action | Free | 1.1 |
| `mvs_after_template_render` | action | Free | 1.1 |
| `mvs_dashboard_before_content` | action | Free | 1.0 |
| `mvs_dashboard_tabs` | action | Free | 1.0 |
| `mvs_dashboard_panels` | action | Free | 1.0 |
| `mvs_dashboard_after_content` | action | Free | 1.0 |
| `mvs_locate_template` | filter | Free | 1.0 |
| `mvs_template_variables` | filter | Free | 1.1 |
| `mvs_body_classes` | filter | Free | 1.1 |
| `mvs_reserved_media_paths` | filter | Free | 1.0 |
| `mvs_before_explore_grid` | action | Free | 1.0 |
| `mvs_after_explore_grid` | action | Free | 1.1 |
| `mvs_feed_query_args` | filter | Free | 1.1 |
| `mvs_feed_sort_options` | filter | Free | 1.1 |
| `mvs_media_response` | filter | Free | 1.0 |
| `mvs_album_response` | filter | Free | 1.1 |
| `mvs_collection_response` | filter | Free | 1.1 |
| `mvs_rest_pagination_max` | filter | Free | 1.1 |
| `mvs_explore_query_args` | filter | Free | 1.0 |
| `mvs_parent_route` | filter | Free | 1.0 |
| `mvs_reaction_added` | action | Free | 1.0 |
| `mvs_reaction_removed` | action | Free | 1.0 |
| `mvs_favorite_added` | action | Free | 1.0 |
| `mvs_share_recorded` | action | Free | 1.0 |
| `mvs_media_group_assigned` | action | Free | 1.0 |
| `mvs_album_cover_set` | action | Free | 1.0 |
| `mvs_album_items_added` | action | Free | 1.0 |
| `mvs_tag_term_count` | filter | Free | 1.0 |
| `mvs_user_badge_html` | filter | Free | 1.0 |
| `mvs_reaction_toggled` | action | Free | 1.0 |
| `mvs_favorite_toggled` | action | Free | 1.0 |
| `mvs_comment_created` | action | Free | 1.0 |
| `mvs_mentions_created` | action | Free | 1.0 |
| `mvs_user_followed` | action | Free | 1.0 |
| `mvs_user_unfollowed` | action | Free | 1.0 |
| `mvs_media_shared` | action | Free | 1.0 |
| `mvs_report_submitted` | action | Free | 1.0 |
| `mvs_user_blocked` | action | Free | 1.0 |
| `mvs_tags_merged` | action | Free | 1.0 |
| `mvs_activity_types` | filter | Free | 1.0 |
| `mvs_activity_max_media` | filter | Free | 1.0 |
| `mvs_notification_created` | action | Free | 1.1 |
| `mvs_should_send_notification` | filter | Free | 1.1 |
| `mvs_notification_data` | filter | Free | 1.1 |
| `mvs_notification_types` | filter | Free | 1.0 |
| `mvs_notification_message` | filter | Free | 1.0 |
| `mvs_push_send` | action | Free | 2.4.0 |
| `mvs_push_should_send` | filter | Free | 2.4.0 |
| `mvs_conversation_created` | action | Free | 1.0 |
| `mvs_message_sent` | action | Free | 1.0 |
| `mvs_message_request_accepted` | action | Free | 1.0 |
| `mvs_message_deleted` | action | Free | 1.0 |
| `mvs_message_reaction_added` | action | Free | 1.0 |
| `mvs_voice_message_sent` | action | Free | 1.0 |
| `mvs_conversation_read` | action | Free | 1.0 |
| `mvs_can_send_message` | filter | Free | 1.0 |
| `mvs_dm_access_level` | filter | Free | 1.0 |
| `mvs_dm_message_rate_limit` | filter | Free | 1.0 |
| `mvs_dm_convo_rate_limit` | filter | Free | 1.0 |
| `mvs_message_max_length` | filter | Free | 1.0 |
| `mvs_message_types` | filter | Free | 1.0 |
| `mvs_dm_allowed_file_types` | filter | Free | 1.0 |
| `mvs_messaging_poll_intervals` | filter | Free | 1.0 |
| `mvs_messaging_transport` | filter | Free | 1.0 |
| `mvs_show_online_status` | filter | Free | 1.0 |
| `mvs_dm_max_upload_size` | filter | Free | 1.0 |
| `mvs_settings_sidebar_after` | action | Free | 1.0 |
| `mvs_settings_before_save` | action | Free | 1.1 |
| `mvs_settings_render_{renderer}` | action | Free | 1.0 |
| `mvs_dashboard_widgets` | action | Free | 1.1 |
| `mvs_settings_sections` | filter | Free | 1.0 |
| `mvs_settings_group_labels` | filter | Free | 1.0 |
| `mvs_hide_submenu_slugs` | filter | Free | 1.0 |
| `mvs_moderation_tabs` | filter | Free | 1.0 |
| `mvs_stats_tabs` | filter | Free | 1.0 |
| `mvs_comment_edit_window` | filter | Free | 1.1 |
| `mvs_should_render_chat_panel` | filter | Free | 1.2 |
| `mvs_page_id_{slot}` | filter | Free | 1.2 |
| `mvs_user_data_purged` | action | Free | 1.2 |
| `mvs_media_flagged` | action | Free | 1.0 |
| `mvs_moderation_changed` | action | Free | 1.0 |
| `mvs_should_ai_analyze` | filter | Free | 1.1 |
| `mvs_ai_result` | filter | Free | 1.1 |
| `mvs_ai_moderation_result` | filter | Free | 1.1 |
| `mvs_openai_api_key` | filter | Free | 1.0 |
| `mvs_media_deleted` | action | Free | 1.0 |
| `mvs_media_trashed` | action | Free | 2.4.0 |
| `mvs_media_restored` | action | Free | 2.4.0 |
| `mvs_has_custom_avatar` | filter | Free | 2.4.0 |
| `mvs_media_drive_access` | filter | Free | 2.4.0 |
| `mvs_storage_driver` | filter | Free | 1.0 |
| `mvs_watermark_enabled` | filter | Free | 1.0 |
| `mvs_watermark_stamp_file` | filter | Free | 1.0 |
| `mvs_watermark_font_path` | filter | Pro | 1.0 |
| `mvs_cloud_thumbnail_url` | filter | Free | 1.3.0 |
| `mvs_cloudops_allow_non_public_to_cloud` | filter | Free | 1.3.0 |
| `mvs_filename_strategy` | filter | Free | 1.3.0 |
| `mvs_thumbnail_sizes` | filter | Free | 1.3.0 |
| `mvs_thumbnail_size_resolved` | filter | Free | 1.3.0 |
| `mvs_can_repair_thumb` | filter | Free | 1.2.3 |
| `mvs_repair_media_thumb` | filter | Free | 1.2.3 |
| `mvs_watermark_font_path` | filter | Pro | 1.0 |
| `mvs_webhook_sslverify` | filter | Free | 1.3.0 |
| `mvs_profile_updated` | action | Free | 1.0 |
| `mvs_avatar_uploaded` | action | Free | 1.0 |
| `mvs_avatar_deleted` | action | Free | 1.0 |
| `mvs_user_display_name` | filter | Free | 1.0 |
| `mvs_user_profile_url` | filter | Free | 1.0 |
| `mvs_profile_data` | filter | Free | 1.0 |
| `mvs_profile_update_fields` | filter | Free | 1.0 |
| `mvs_avatar_allowed_types` | filter | Free | 1.0 |
| `mvs_avatar_max_size` | filter | Free | 1.0 |
| `mvs_access_rule_created` | action | Free | 1.0 |
| `mvs_access_rule_deleted` | action | Free | 1.0 |
| `mvs_access_granted` | action | Free | 1.0 |
| `mvs_access_revoked` | action | Free | 1.0 |
| `mvs_story_created` | action | Pro | 1.0 (moved from Free in 1.9.0) |
| `mvs_story_expired` | action | Pro | 1.0 (moved from Free in 1.9.0) |
| `mvs_privacy_can_view` | filter | Free | 1.0 |
| `mvs_buddynext_active` | filter | Free | 1.0 |
| `mvs_pro_captions_generated` | action | Pro | 1.0 |
| `mvs_pro_poster_frame` | filter | Pro | 1.1 |
| `mvs_pro_analytics_recorded` | action | Pro | 1.1 |
| `mvs_pro_analytics_event_data` | filter | Pro | 1.1 |
| `mvs_pro_analytics_summary` | filter | Pro | 1.1 |
| `mvs_layout_assets` | action | Pro | 1.0 |
| `mvs_before_layout_render` | action | Pro | 1.1 |
| `mvs_active_layout` | filter | Pro | 1.0 |
| `mvs_layout_modes` | filter | Pro | 1.0 |
| `mvs_layout_template_map` | filter | Pro | 1.0 |
| `mvs_layout_config` | filter | Pro | 1.1 |
| `mvs_pro_credits_added` | action | Pro | 1.0 |
| `mvs_pro_woo_package_assigned` | action | Pro | 1.0 |
| `mvs_pro_woo_package_reverted` | action | Pro | 1.0 |
| `mvs_pro_memberpress_package_assigned` | action | Pro | 1.0 |
| `mvs_pro_memberpress_package_reverted` | action | Pro | 1.0 |
| `mvs_pro_pmpro_package_assigned` | action | Pro | 1.0 |
| `mvs_pro_pmpro_package_reverted` | action | Pro | 1.0 |
| `mvs_quota_render_mapping_fields` | action | Pro | 1.0 |
| `mvs_quota_save_mapping` | action | Pro | 1.0 |
| `mvs_pro_before_quota_check` | filter | Pro | 1.1 |
| `mvs_pro_quota_source` | filter | Pro | 1.1 |
| `mvs_challenge_created` | action | Pro | 1.0 |
| `mvs_challenge_entry_submitted` | action | Pro | 1.0 |
| `mvs_challenge_finalized` | action | Pro | 1.0 |
| `mvs_battle_created` | action | Pro | 1.0 |
| `mvs_battle_accepted` | action | Pro | 1.0 |
| `mvs_battle_resolved` | action | Pro | 1.0 |
| `mvs_tournament_created` | action | Pro | 1.0 |
| `mvs_tournament_started` | action | Pro | 1.0 |
| `mvs_tournament_match_resolved` | action | Pro | 1.0 |
| `mvs_tournament_finalized` | action | Pro | 1.0 |
| `mvs_autopilot_challenge_created` | action | Pro | 1.0 |
| `mvs_autopilot_create_failed` | action | Pro | 1.0 |
| `mvs_autopilot_no_theme_available` | action | Pro | 1.0 |
| `mvs_autopilot_pool_reset` | action | Pro | 1.0 |
| `mvs_streak_milestone` | action | Pro | 1.0 |
| `mvs_challenge_winner_named` | action | Pro | 1.2.3 |
| `mvs_challenge_activated` | action | Pro | 1.5.0 |
| `mvs_challenge_voting_started` | action | Pro | 1.5.0 |
| `mvs_battle_cancelled` | action | Pro | 1.5.0 |
| `mvs_tournament_cancelled` | action | Pro | 1.5.0 |
| `mvs_tournament_updated` | action | Pro | 1.5.0 |
| `mvs_competition_status_changed` | action | Pro | 1.5.0 |
| `mvs_competitions_tick_ran` | action | Pro | 1.5.0 |
| `mvs_activate_scheduled_challenges` | action | Pro | 1.5.0 |
| `mvs_close_challenge_entries` | action | Pro | 1.5.0 |
| `mvs_finalize_expired_challenges` | action | Pro | 1.5.0 |
| `mvs_start_registered_tournaments` | action | Pro | 1.5.0 |
| `mvs_resolve_expired_matches` | action | Pro | 1.5.0 |
| `mvs_pro_leaderboard_xp_rows` | filter | Pro | 1.2.0 |
| `mvs_challenge_email_created_subject` | filter | Pro | 1.5.0 |
| `mvs_challenge_email_created_body` | filter | Pro | 1.5.0 |
| `mvs_challenge_email_entry_subject` | filter | Pro | 1.5.0 |
| `mvs_challenge_email_entry_body` | filter | Pro | 1.5.0 |
| `mvs_challenge_email_winner_subject` | filter | Pro | 1.5.0 |
| `mvs_challenge_email_winner_body` | filter | Pro | 1.5.0 |
| `mvs_challenge_email_participant_subject` | filter | Pro | 1.5.0 |
| `mvs_challenge_email_participant_body` | filter | Pro | 1.5.0 |
| `mvs_connectors` | filter | Pro | 1.5.0 |
| `mvs_media_imported` | action | Pro | 1.5.0 |
| `mvs_media_exported` | action | Pro | 1.5.0 |
| `mvs_optimize_image` | filter | Free | 1.3.0 |
| `mvs_optimize_jpeg_quality` | filter | Free | 1.3.0 |
| `mvs_webp_quality` | filter | Free | 1.3.0 |
| `mvs_avif_quality` | filter | Free | 1.3.0 |
| `mvs_default_video_poster_url` | filter | Free | 1.3.0 |
| `mvs_media_privacy_changed` | action | Free | 1.3.0 |
| `mvs_serve_public_cloud_direct` | filter | Free | 1.4.0 |
| `mvs_public_cloud_thumbnail_url` | filter | Free | 1.4.0 |
| `mvs_public_cloud_file_url` | filter | Free | 1.4.0 |
| `mvs_broadcast_thumbnail_ttl` | filter | Free | 1.5.0 |
| `mvs_stable_public_urls` | filter | Free | 1.7.0 |
| `mvs_public_media_max_age` | filter | Free | 1.7.0 |
| `mvs_public_local_thumbnail_url` | filter | Free | 1.7.0 |
| `mvs_public_local_file_url` | filter | Free | 1.7.0 |
| `mvs_suppress_bp_comment_notification` | filter | Free | 2.0.0 |
| `mvs_ai_moderation_terms` | filter | Free | 1.8.0 |
| `mvs_default_thumbnail_style` | filter | Free | 1.8.0 |
| `mvs_grid_thumb_size_key` | filter | Free | 1.8.0 |
| `mvs_storage_repair_enabled` | filter | Free | 1.8.0 |
| `mvs_strip_dead_bp_links` | filter | Free | 1.7.1 |
| `mvs_dead_bp_link_patterns` | filter | Free | 1.7.1 |
| `mvs_dm_denial_message` | filter | Free | 1.8.0 |
| `mvs_dm_denial_reason` | filter | Free | 1.8.0 |
| `mvs_collections_enabled` | filter | Free | 1.8.0 |
| `mvs_app_config_features` | filter | Free | 1.9.0 |
| `mvs_app_config_branding` | filter | Free | 1.9.0 |
| `mvs_app_config_layout` | filter | Free | 1.9.0 |
| `mvs_app_interests_cache_ttl` | filter | Free | 1.9.0 |
| `mvs_suggestions_cache_ttl` | filter | Free | 1.9.0 |
| `mvs_pro_collections_manage_url` | filter | Pro | 1.8.0 |
| `mvs_pro_compete_points_url` | filter | Pro | 1.8.0 |

---

## Table of Contents

1. [Plugin Lifecycle](#1-plugin-lifecycle)
2. [Upload Pipeline](#2-upload-pipeline)
3. [Template System](#3-template-system)
4. [Explore & Feed](#4-explore--feed)
5. [REST API](#5-rest-api)
6. [Social & Engagement](#6-social--engagement)
7. [Notifications](#7-notifications)
8. [Direct Messages](#8-direct-messages)
9. [Admin & Settings](#9-admin--settings)
10. [AI & Moderation](#10-ai--moderation)
11. [Storage & Files](#11-storage--files)
12. [User Profiles](#12-user-profiles)
13. [Access & Privacy](#13-access--privacy)
14. [BuddyPress Integration](#14-buddypress-integration)
15. [Video Processing (Pro)](#15-video-processing-pro)
16. [Analytics (Pro)](#16-analytics-pro)
17. [Layout System (Pro)](#17-layout-system-pro)
18. [Quota System (Pro)](#18-quota-system-pro)
19. [Competitions (Pro)](#19-competitions-pro)
20. [Connectors (Pro)](#21-connectors-pro)
21. [Common Recipes](#22-common-recipes)

---

## 1. Plugin Lifecycle

### `mvs_loaded`

Fires after the free plugin is fully initialized and the DI container is ready. Use this instead of `plugins_loaded` when you need access to WPMediaVerse services.

**Parameters:** none

```php
/**
 * Bootstrap a third-party integration after MVS is ready.
 *
 * @since 1.0
 */
add_action( 'mvs_loaded', function() {
    // Safe to resolve services from the container here.
} );
```

---

### `mvs_pro_loaded` **(Pro)**

Fires after WPMediaVerse Pro is fully initialized.

**Parameters:** none

```php
/**
 * Run Pro-specific setup after the Pro plugin is ready.
 *
 * @since 1.0
 */
add_action( 'mvs_pro_loaded', function() {
    // Pro services are available here.
} );
```

---

### `mvs_ai_providers`

Fires during plugin init to allow registering custom AI providers with the AI service.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$ai_service` | `AIService` | The AI service instance |

```php
/**
 * Register a custom AI provider.
 *
 * @since 1.0
 *
 * @param AIService $ai_service The AI service instance.
 */
add_action( 'mvs_ai_providers', function( $ai_service ) {
    $ai_service->register_provider( new MyCustomAIProvider() );
} );
```

---

### Additional Lifecycle Filters

| Filter | Description | Parameters | Returns |
|--------|-------------|------------|---------|
| `mvs_theme_json` | Filter theme.json data passed to the frontend JS bundle | `$data` (array) | `array` |

---

## 2. Upload Pipeline

### `mvs_media_uploaded`

Fires after a new media post is created, stored, and indexed. This is the primary hook for post-upload processing - use it for gamification, activity feeds, external pipelines, and analytics.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | The new `mvs_media` post ID |
| `$file_data` | array | File data. Keys: `mime`, `file_path`, `file_url`, `file_size`, `file_type`, `file_hash`, `media_type`, `privacy`, `user_id`, `is_first` (1.2.3+) |
| `$user_id` | int | Uploader user ID (1.2.3+) |
| `$media_type` | string | Resolved type: `photo`, `video`, `audio`, `document` (1.2.3+) |

**Backward compatibility:** Listeners registered with `accepted_args=1` or `=2` continue to work unchanged - the new positional args are appended.

```php
/**
 * Run post-upload side effects, e.g. push to an external pipeline or analytics.
 *
 * @since 1.2.3
 *
 * @param int    $media_id   The new media post ID.
 * @param array  $file_data  File data (now includes user_id and is_first).
 * @param int    $user_id    Uploader user ID.
 * @param string $media_type 'photo' | 'video' | 'audio' | 'document'.
 */
add_action( 'mvs_media_uploaded', function( int $media_id, array $file_data, int $user_id, string $media_type ) {
    my_external_pipeline_notify( $media_id, $user_id );

    if ( ! empty( $file_data['is_first'] ) ) {
        my_flag_first_upload( $user_id );
    }
}, 10, 4 );
```

> **Gamification note:** XP for uploads is **not** awarded by calling a function inside this hook. The separate WB Gamification plugin already consumes `mvs_media_uploaded` (and other `mvs_*` actions) through its WPMediaVerse integration, and the per-action point value is resolved through the `wb_gam_points_for_action` filter. To change upload XP, filter `wb_gam_points_for_action` rather than calling an award function here.

---

### `mvs_upload_args`

Filters the upload arguments before file processing. Return a `WP_Error` to reject the upload.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$args` | array | Keys: `mime`, `media_type`, `file_size`, `file_name` |
| `$user_id` | int | Uploading user ID |

**Returns:** `array|WP_Error`

```php
/**
 * Reject uploads over 10 MB for subscribers.
 *
 * @since 1.0
 *
 * @param array $args    Upload arguments.
 * @param int   $user_id Uploading user ID.
 * @return array|WP_Error
 */
add_filter( 'mvs_upload_args', function( array $args, int $user_id ) {
    if ( user_can( $user_id, 'subscriber' ) && $args['file_size'] > 10 * MB_IN_BYTES ) {
        return new WP_Error( 'quota_exceeded', __( 'Upload limit exceeded for your plan.', 'wpmediaverse' ) );
    }
    return $args;
}, 10, 2 );
```

---

### `mvs_before_thumbnail_generation` **(New in 1.1)**

Fires before WordPress `multi_resize` runs for a newly uploaded image. Use this to add or modify the sizes array.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | Media post ID |
| `$file_path` | string | Absolute path to the uploaded file |
| `$sizes` | array | Size definitions passed to `multi_resize` |

```php
/**
 * Add a custom 800×600 thumbnail size before generation.
 *
 * @since 1.1
 *
 * @param int    $media_id  Media post ID.
 * @param string $file_path Absolute file path.
 * @param array  $sizes     Size definitions.
 */
add_action( 'mvs_before_thumbnail_generation', function( int $media_id, string $file_path, array $sizes ) {
    // Log or modify $sizes via reference if the hook passes by reference,
    // otherwise use mvs_after_thumbnail_generation to inspect results.
}, 10, 3 );
```

---

### `mvs_after_thumbnail_generation` **(New in 1.1)**

Fires after all thumbnails are generated and stored in media meta.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | Media post ID |
| `$generated` | array | Map of size slug to generated file data |
| `$file_path` | string | Absolute path to the source file |

```php
/**
 * Push newly generated thumbnails to an external CDN.
 *
 * @since 1.1
 *
 * @param int    $media_id  Media post ID.
 * @param array  $generated Generated thumbnail map.
 * @param string $file_path Source file path.
 */
add_action( 'mvs_after_thumbnail_generation', function( int $media_id, array $generated, string $file_path ) {
    foreach ( $generated as $size => $data ) {
        my_cdn_push( $data['path'] );
    }
}, 10, 3 );
```

---

### Additional Upload Filters

| Filter | Description | Parameters | Since |
|--------|-------------|------------|-------|
| `mvs_before_media_insert` | Fires before the `mvs_media` post is created | none | 1.0 |
| `mvs_before_upload_form` | Fires before the upload form HTML renders | none | 1.0 |
| `mvs_allowed_file_types` | Filter allowed MIME types array | `$types` (array) | 1.1 |
| `mvs_max_upload_size` | Filter max upload size in bytes | `$max_size` (int), `$user_id` (int) | 1.1 |
| `mvs_upload_directory` | Filter upload subdirectory path | `$subdir` (string), `$user_id` (int), `$media_type` (string) | 1.1 |
| `mvs_media_metadata` | Filter extracted metadata before storage | `$metadata` (array), `$file_path` (string), `$media_id` (int) | 1.0 |

---

## 3. Template System

### `mvs_locate_template`

Filters the resolved template file path. Use this to serve templates from a custom theme directory.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$template` | string | Resolved absolute file path |
| `$template_name` | string | Template filename (e.g., `media-single.php`) |
| `$template_path` | string | Subdirectory within the template directory |

**Returns:** `string`

```php
/**
 * Load MVS templates from the active theme's /mvs/ subfolder.
 *
 * @since 1.0
 *
 * @param string $template      Resolved template path.
 * @param string $template_name Template filename.
 * @return string
 */
add_filter( 'mvs_locate_template', function( string $template, string $template_name ) {
    $custom = get_stylesheet_directory() . '/mvs/' . $template_name;
    return file_exists( $custom ) ? $custom : $template;
}, 10, 2 );
```

---

### `mvs_dashboard_tabs`

Fires inside the dashboard shortcode to allow registering custom tabs.

**Parameters:** none

```php
/**
 * Register a custom "Collections" tab on the user dashboard.
 *
 * @since 1.0
 */
add_action( 'mvs_dashboard_tabs', function() {
    echo '<button class="mvs-tab" data-panel="collections">' . esc_html__( 'Collections', 'my-plugin' ) . '</button>';
} );
```

---

### `mvs_dashboard_panels`

Fires inside the dashboard shortcode to allow registering custom panel content.

**Parameters:** none

```php
/**
 * Render content for the custom "Collections" panel.
 *
 * @since 1.0
 */
add_action( 'mvs_dashboard_panels', function() {
    echo '<div id="mvs-panel-collections" class="mvs-panel">';
    // Panel content here.
    echo '</div>';
} );
```

---

### Additional Template Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_before_content` | action | Before main template content in full-page templates | none | 1.0 |
| `mvs_after_content` | action | After main template content | none | 1.0 |
| `mvs_before_template_render` | action | Before a template part renders | `$template_name`, `$args` | 1.1 |
| `mvs_after_template_render` | action | After a template part renders | `$template_name`, `$args` | 1.1 |
| `mvs_dashboard_before_content` | action | Before dashboard shortcode content | none | 1.0 |
| `mvs_dashboard_after_content` | action | After dashboard shortcode content | none | 1.0 |
| `mvs_template_variables` | filter | Filter template variables before render | `$args` (array), `$template_name` (string) | 1.1 |
| `mvs_body_classes` | filter | Filter MVS body CSS classes | `$classes` (array) | 1.1 |
| `mvs_reserved_media_paths` | filter | Filter reserved URL paths under `/media/` | `$paths` (array) | 1.0 |

---

## 4. Explore & Feed

### `mvs_feed_query_args` **(New in 1.1)**

Filters the query arguments used to fetch the media feed. Use this to add custom ordering, meta queries, or taxonomy filters.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$query_args` | array | `WP_Query` arguments for the feed |
| `$request` | WP_REST_Request | The incoming REST request |

**Returns:** `array`

```php
/**
 * Add a custom meta query to the media feed.
 *
 * @since 1.1
 *
 * @param array           $query_args Feed query arguments.
 * @param WP_REST_Request $request    Incoming REST request.
 * @return array
 */
add_filter( 'mvs_feed_query_args', function( array $query_args, $request ) {
    $query_args['meta_query'][] = [
        'key'     => '_featured',
        'value'   => '1',
        'compare' => '=',
    ];
    return $query_args;
}, 10, 2 );
```

---

### `mvs_feed_sort_options` **(New in 1.1)**

Filters the available sort options shown in the explore feed UI.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$options` | array | Associative array of `slug => label` |

**Returns:** `array`

```php
/**
 * Add a "Most Commented" sort option to the explore feed.
 *
 * @since 1.1
 *
 * @param array $options Existing sort options.
 * @return array
 */
add_filter( 'mvs_feed_sort_options', function( array $options ) {
    $options['most_commented'] = __( 'Most Commented', 'my-plugin' );
    return $options;
} );
```

---

### Additional Explore Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_before_explore_grid` | action | Before the explore grid renders | none | 1.0 |
| `mvs_after_explore_grid` | action | After the explore grid renders | none | 1.1 |

---

## 5. REST API

### `mvs_media_response`

Filters the REST API response array for a single media item. Use this to add or remove fields before the response is sent.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$data` | array | REST response data array |
| `$media_id` | int | Media post ID |

**Returns:** `array`

```php
/**
 * Append custom post meta to the media REST response.
 *
 * @since 1.0
 *
 * @param array $data     REST response data.
 * @param int   $media_id Media post ID.
 * @return array
 */
add_filter( 'mvs_media_response', function( array $data, int $media_id ) {
    $data['location'] = get_post_meta( $media_id, '_location', true );
    return $data;
}, 10, 2 );
```

---

### Additional REST Filters

| Filter | Description | Parameters | Default | Since |
|--------|-------------|------------|---------|-------|
| `mvs_album_response` | Filter album REST response | `$data` (array), `$album_id` (int) | Raw album data | 1.1 |
| `mvs_collection_response` | Filter collection REST response | `$data` (array), `$collection_id` (int) | Raw collection data | 1.1 |
| `mvs_rest_pagination_max` | Max `per_page` for media feed endpoint | `$maximum` (int) | `100` | 1.1 |
| `mvs_explore_query_args` | Filter the `WP_Query` args used by the `/media/` explore template | `$query_args` (array), `$profile` (WP_User\|null) | Template query | 1.0 |
| `mvs_parent_route` | Filter the resolved parent route slug for a template context | `$parent` (string), `$context` (string), `$args` (array) | Resolved slug | 1.0 |
| `mvs_rest_require_auth` | Community privacy gate: return `true` to require authentication for the gated REST namespaces (BuddyNext turns this on when the host community is private) | `$require` (bool), `$request` (WP_REST_Request) | `false` | 2.2 |
| `mvs_rest_can_access` | When `mvs_rest_require_auth` is on, decides whether the current request may pass; blocked requests get `401 mvs_community_private` | `$allowed` (bool), `$request` (WP_REST_Request) | `is_user_logged_in()` | 2.2 |
| `mvs_rest_gated_route_prefixes` | Route prefixes the community privacy gate covers. Pro appends `/mvs-pro/v1/` so its public reads (e.g. tournament brackets) are gated too; sites can add further prefixes | `$prefixes` (string[]), `$request` (WP_REST_Request) | `array( '/mvs/v1/' )` | 2.2 |

---

### Native App Config (1.9.0)

These filters back the `GET /app/config` response consumed by the native mobile app (see [REST API Reference](rest-api.md)). Free seeds sane defaults; Pro (or a white-label add-on) supplies its own values.

#### `mvs_app_config_features`

Filters the `features` boolean map returned by `GET /app/config`. Free seeds its always-on capabilities plus the messaging gate (derived from `mvs_dm_access`); Pro filters in its own toggles (battles, challenges, tournaments, boosts, streaks, video, stories, …).

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$features` | array<string,bool> | Default: `messaging`, `reactions`, `comments`, `favorites`, `albums`, `collections`, `follows`, `notifications`, `activity` (all `true` except `messaging`, which follows `mvs_dm_access`) |

**Returns:** `array<string,bool>`

```php
add_filter( 'mvs_app_config_features', function( array $features ) : array {
    $features['my_custom_feature'] = true;
    return $features;
} );
```

---

#### `mvs_app_config_branding`

Filters the white-label branding array returned by `GET /app/config`. No Free setting drives this — Pro's Mobile App Branding settings (accent color, logo, login background, dark-mode default) populate it. Do not add site name/description/icon here; those come from the core `/wp-json/` index.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$branding` | array | Default: `accent_color`, `logo_url`, `login_bg_url` all `null`; `dark_mode_default` `false` |

**Returns:** `array`

```php
add_filter( 'mvs_app_config_branding', function( array $branding ) : array {
    $branding['accent_color'] = '#1a73e8';
    return $branding;
} );
```

---

#### `mvs_app_config_layout`

Filters the feed layout slug (`grid|instagram|pinterest|flickr|dribbble`) reported to the app so it presents media the way the site owner configured Explore. Pro supplies it from its `mvs_pro_feed_layout` setting; Free always defaults to `grid`.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$layout` | string | Default `'grid'` |

**Returns:** `string`

```php
add_filter( 'mvs_app_config_layout', static fn() => 'grid' );
```

---

#### `mvs_app_interests_cache_ttl`

Filters the cache TTL (seconds) for the interest-list transient behind `GET /app/interests`. `0` disables caching.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$ttl` | int | Default `HOUR_IN_SECONDS` |

**Returns:** `int`

```php
add_filter( 'mvs_app_interests_cache_ttl', static fn() => 6 * HOUR_IN_SECONDS );
```

---

#### `mvs_suggestions_cache_ttl`

Filters the cache TTL (seconds) for the suggested-creators candidate pool behind `GET /users/suggested`. `0` disables caching.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$ttl` | int | Default `HOUR_IN_SECONDS` |

**Returns:** `int`

```php
add_filter( 'mvs_suggestions_cache_ttl', static fn() => 15 * MINUTE_IN_SECONDS );
```

---

#### `mvs_pro_collections_manage_url` **(Pro)**

Filters the URL the frontend "Save to collection" picker links to for "View your collections" (defaults to the My Media dashboard's Collections tab). Lets a site that places the dashboard elsewhere repoint the link.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$url` | string | Default: permalink of the page at slug `my-media` + `#collections`, or `''` if that page doesn't exist |

**Returns:** `string`

```php
add_filter( 'mvs_pro_collections_manage_url', function( string $url ) : string {
    return home_url( '/my-photos/#collections' );
} );
```

---

#### `mvs_pro_compete_points_url` **(Pro)**

Filters the URL the Compete hub's points-balance chip links to (normally the WB Gamification rewards hub page). Returning an empty string degrades the chip to a non-linked span.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$url` | string | Default: permalink of the `wb_gam_hub_page_id` option's page, or `''` if not configured |

**Returns:** `string`

```php
add_filter( 'mvs_pro_compete_points_url', function( string $url ) : string {
    return home_url( '/rewards/' );
} );
```

---

## 6. Social & Engagement

### `mvs_comment_created`

Fires when a comment is posted on a media item.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | Media post ID |
| `$user_id` | int | Commenting user ID |
| `$comment_id` | int | New comment ID |
| `$content` | string | Comment text |
| `$source` | string | Source context: `web`, `bp_activity`, etc. |

```php
/**
 * Send a Slack alert when a comment is posted.
 *
 * @since 1.0
 *
 * @param int    $media_id   Media post ID.
 * @param int    $user_id    Commenter user ID.
 * @param int    $comment_id New comment ID.
 * @param string $content    Comment text.
 * @param string $source     Comment source.
 */
add_action( 'mvs_comment_created', function( int $media_id, int $user_id, int $comment_id, string $content, string $source ) {
    my_slack_notify( "New comment on media #{$media_id}" );
}, 10, 5 );
```

---

### `mvs_reaction_toggled`

Fires when a user adds, changes, or removes a reaction on a media item.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | Media post ID |
| `$user_id` | int | User who changed the reaction |
| `$reaction_type` | string\|null | Reaction slug (e.g., `love`) or `null` if removed |
| `$action` | string | `added`, `changed`, or `removed` |

```php
/**
 * Award points when a reaction is added.
 *
 * @since 1.0
 *
 * @param int         $media_id      Media post ID.
 * @param int         $user_id       User ID.
 * @param string|null $reaction_type Reaction slug or null.
 * @param string      $action        add, change, or remove.
 */
add_action( 'mvs_reaction_toggled', function( int $media_id, int $user_id, $reaction_type, string $action ) {
    if ( 'added' === $action ) {
        my_award_points( get_post_field( 'post_author', $media_id ), 1 );
    }
}, 10, 4 );
```

---

### `mvs_user_followed`

Fires when a follow relationship is created.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$follower_id` | int | User who followed |
| `$following_id` | int | User who was followed |

```php
/**
 * Send a "new follower" notification.
 *
 * @since 1.0
 *
 * @param int $follower_id  User who followed.
 * @param int $following_id User who was followed.
 */
add_action( 'mvs_user_followed', function( int $follower_id, int $following_id ) {
    my_send_follower_notification( $following_id, $follower_id );
}, 10, 2 );
```

---

### `mvs_collections_enabled` **(New in 1.8.0)**

Gates whether the lightbox and single-media page render a separate "Save to collection" control next to the favorite heart. Favoriting (a one-tap like) and saving to a named collection are deliberately separate actions in the UI; there is no bundled Free collections-management backend, so this defaults to `false` and stays hidden until a collections backend (e.g. WPMediaVerse Pro) enables it.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$enabled` | bool | Whether to render the Save control. Default `false` |

**Returns:** `bool`

```php
/**
 * Enable the "Save to collection" control from a custom collections backend.
 *
 * @since 1.8.0
 *
 * @param bool $enabled Whether the Save control renders. Default false.
 * @return bool
 */
add_filter( 'mvs_collections_enabled', '__return_true' );
```

**Frontend companion.** In the shared-ui lightbox (Interactivity API store), the Save button calls the `actions.lightboxOpenCollections` action, which dispatches a `mvs-collections-click` `CustomEvent` (bubbling) carrying `detail: { mediaId }`. A collections backend listens for this event on the document to open its own picker UI — the lightbox itself has no picker.

```js
document.addEventListener( 'mvs-collections-click', ( event ) => {
	myCollectionsPicker.open( event.detail.mediaId );
} );
```

---

### Additional Social Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_reaction_added` | action | Reaction added to media | `$media_id`, `$user_id`, `$reaction_type` | 1.0 |
| `mvs_reaction_removed` | action | Reaction removed from media | `$media_id`, `$user_id` | 1.0 |
| `mvs_favorite_added` | action | Favorite added (fires in addition to `mvs_favorite_toggled`) | `$media_id`, `$user_id` | 1.0 |
| `mvs_favorite_toggled` | action | Favorite added or removed | `$media_id`, `$user_id`, `$action` (`added`/`removed`) | 1.0 |
| `mvs_share_recorded` | action | Share recorded against a media item | `$media_id`, `$user_id` | 1.0 |
| `mvs_media_group_assigned` | action | Media assigned to a BuddyPress group after upload | `$media_id`, `$group_id` | 1.0 |
| `mvs_album_cover_set` | action | Album cover image set | `$album_id`, `$media_id` | 1.0 |
| `mvs_tag_term_count` | filter | Filter the displayed media count for a tag term | `$count` (int), `$term_taxonomy_id` (int) | 1.0 |
| `mvs_user_badge_html` | filter | Inject HTML for a user badge next to an author name (Pro streak/verified badges) | `$html` (string), `$user_id` (int) | 1.0 |
| `mvs_mentions_created` | action | @mentions parsed from a comment | `$media_id`, `$mentioned_ids`, `$context`, `$comment_id` | 1.0 |
| `mvs_user_unfollowed` | action | Follow relationship removed | `$follower_id`, `$following_id` | 1.0 |
| `mvs_media_shared` | action | Media shared to external platform | `$media_id`, `$user_id`, `$platform` | 1.0 |
| `mvs_report_submitted` | action | Content report filed | `$report_id`, `$reporter_id`, `$target_type`, `$target_id`, `$reason` | 1.0 |
| `mvs_user_blocked` | action | User blocked another user | `$blocker_id`, `$blocked_id` | 1.0 |
| `mvs_tags_merged` | action | Two tags merged | `$source_id`, `$target_id`, `$posts` | 1.0 |
| `mvs_activity_types` | filter | Register activity feed types | `$types` (array) | 1.0 |
| `mvs_activity_max_media` | filter | Max media items rendered into one activity post. Applies to both routes into the feed: the BuddyPress composer, and carousel/gallery uploads (2.3.0), which pass the group key as a second argument | `$count` (int), default `6`; `$media_group` (string, carousel path only) | 1.0 |

---

## 7. Notifications

### `mvs_notification_created` **(New in 1.1)**

Fires after a notification is stored in the database. Use this to push notifications to a custom channel.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$notification_id` | int | New notification record ID |
| `$user_id` | int | Recipient user ID |
| `$type` | string | Notification type slug (e.g., `comment`, `follow`) |
| `$actor_id` | int | User who triggered the notification |
| `$media_id` | int | Related media post ID (0 if not media-related) |

```php
/**
 * Send an email for comment notifications.
 *
 * @since 1.1
 *
 * @param int    $notification_id Notification record ID.
 * @param int    $user_id         Recipient user ID.
 * @param string $type            Notification type.
 * @param int    $actor_id        Triggering user ID.
 * @param int    $media_id        Related media ID.
 */
add_action( 'mvs_notification_created', function( int $notification_id, int $user_id, string $type, int $actor_id, int $media_id ) {
    if ( 'comment' !== $type ) {
        return;
    }
    $user  = get_userdata( $user_id );
    $actor = get_userdata( $actor_id );
    wp_mail(
        $user->user_email,
        __( 'New comment on your media', 'my-plugin' ),
        sprintf( __( '%s commented on your photo.', 'my-plugin' ), $actor->display_name )
    );
}, 10, 5 );
```

---

### `mvs_should_send_notification` **(New in 1.1)**

Filters whether a notification should be stored and dispatched. Return `false` to suppress.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$should_send` | bool | Whether to send (default `true`) |
| `$user_id` | int | Recipient user ID |
| `$type` | string | Notification type slug |
| `$actor_id` | int | Triggering user ID |
| `$media_id` | int | Related media ID |

**Returns:** `bool`

```php
/**
 * Suppress notifications during a scheduled import.
 *
 * @since 1.1
 *
 * @param bool   $should_send Whether to send the notification.
 * @param int    $user_id     Recipient user ID.
 * @param string $type        Notification type.
 * @param int    $actor_id    Triggering user ID.
 * @param int    $media_id    Related media ID.
 * @return bool
 */
add_filter( 'mvs_should_send_notification', function( bool $should_send, int $user_id, string $type, int $actor_id, int $media_id ) {
    if ( get_transient( 'my_plugin_import_running' ) ) {
        return false;
    }
    return $should_send;
}, 10, 5 );
```

---

### `mvs_push_send` **(New in 2.4.0)**

Fires when a new in-app notification is created, so a push-delivery integration can send it to the member's registered devices. Devices are registered via `POST /mvs/v1/me/devices` (see [REST API Reference](rest-api.md)) and backed by `Social/PushService.php`. Whether this action fires at all is gated by `mvs_push_should_send`.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Recipient user ID |
| `$tokens` | array | The recipient's registered device push tokens |
| `$payload` | array | Notification payload to deliver (title, body, data) |

```php
/**
 * Deliver an MVS push notification through a third-party gateway.
 *
 * @since 2.4.0
 *
 * @param int   $user_id Recipient user ID.
 * @param array $tokens  Registered device push tokens.
 * @param array $payload Notification payload.
 */
add_action( 'mvs_push_send', function( int $user_id, array $tokens, array $payload ) {
    my_push_gateway_send( $tokens, $payload );
}, 10, 3 );
```

---

### `mvs_push_should_send` **(New in 2.4.0)**

Filters whether a push notification should be delivered for a new in-app notification. Return `false` to suppress the `mvs_push_send` dispatch.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$should_send` | bool | Whether to dispatch the push (default `true`) |
| `$user_id` | int | Recipient user ID |
| `$payload` | array | Notification payload |

**Returns:** `bool`

```php
/**
 * Suppress pushes for a member who has muted a conversation.
 *
 * @since 2.4.0
 *
 * @param bool  $should_send Whether to dispatch the push.
 * @param int   $user_id     Recipient user ID.
 * @param array $payload     Notification payload.
 * @return bool
 */
add_filter( 'mvs_push_should_send', function( bool $should_send, int $user_id, array $payload ) {
    return $should_send;
}, 10, 3 );
```

---

### Additional Notification Filters

| Filter | Description | Parameters | Since |
|--------|-------------|------------|-------|
| `mvs_notification_data` | Filter notification data array before insert | `$data` (array), `$type` (string) | 1.1 |
| `mvs_notification_types` | Filter the list of allowed notification type slugs | `$types` (array) | 1.0 |
| `mvs_notification_message` | Override the rendered message label for a notification type. Return a non-null string to replace the default | `$label` (string\|null), `$type` (string), `$actor_name` (string), `$media_title` (string) | 1.0 |

---

## 8. Direct Messages

### `mvs_can_send_message`

Filters whether a user is allowed to send a DM to a recipient.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$can` | bool | Current permission result |
| `$sender_id` | int | Sending user ID |
| `$recipient_id` | int | Receiving user ID |

**Returns:** `bool`

```php
/**
 * Block DMs from unverified accounts.
 *
 * @since 1.0
 *
 * @param bool $can          Current permission.
 * @param int  $sender_id    Sender user ID.
 * @param int  $recipient_id Recipient user ID.
 * @return bool
 */
add_filter( 'mvs_can_send_message', function( bool $can, int $sender_id, int $recipient_id ) {
    if ( ! my_is_verified( $sender_id ) ) {
        return false;
    }
    return $can;
}, 10, 3 );
```

---

### `mvs_message_sent`

Fires after a DM is stored in the database.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$message_id` | int | New message ID |
| `$conversation_id` | int | Conversation the message belongs to |
| `$sender_id` | int | Sending user ID |
| `$recipient_ids` | int[] | Array of recipient user IDs |

```php
/**
 * Push a DM via WebSocket after storage.
 *
 * @since 1.0
 *
 * @param int   $message_id      New message ID.
 * @param int   $conversation_id Conversation ID.
 * @param int   $sender_id       Sender user ID.
 * @param int[] $recipient_ids   Recipient user IDs.
 */
add_action( 'mvs_message_sent', function( int $message_id, int $conversation_id, int $sender_id, array $recipient_ids ) {
    my_websocket_push( $conversation_id, $message_id );
}, 10, 4 );
```

---

### `mvs_dm_denial_reason` **(New in 1.8.0)**

Filters the reason code returned when `MessagingService` blocks a conversation because the sender is blocked by the recipient. Return one of the codes `denial_message()` understands (`blocked`, `dms_disabled`, `mutual_follow_required`, `account_too_new`, `rate_limited`, `not_participant`, `content_too_long`, …) so the human-readable message stays consistent.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$reason` | string | Default `'blocked'` |
| `$sender_id` | int | Sending user ID |
| `$recipient_id` | int | Recipient user ID |

**Returns:** `string`

```php
/**
 * Report a custom denial reason for a bespoke blocking rule.
 *
 * @since 1.8.0
 *
 * @param string $reason       Default 'blocked'.
 * @param int    $sender_id    Sender user ID.
 * @param int    $recipient_id Recipient user ID.
 * @return string
 */
add_filter( 'mvs_dm_denial_reason', function( string $reason, int $sender_id, int $recipient_id ) : string {
    return my_plugin_is_shadow_blocked( $sender_id, $recipient_id ) ? 'blocked' : $reason;
}, 10, 3 );
```

---

### `mvs_dm_denial_message` **(New in 1.8.0)**

Filters the human-readable message shown for a DM denial reason code. Apps consuming `mvs/v1` directly may localize from the `reason` code instead of this string; use this filter for per-site or per-locale message overrides.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$message` | string | Default message for the reason code |
| `$reason` | string | Reason code (see `mvs_dm_denial_reason`) |

**Returns:** `string`

```php
/**
 * Customize the "blocked" denial message shown to senders.
 *
 * @since 1.8.0
 *
 * @param string $message Default message.
 * @param string $reason  Reason code.
 * @return string
 */
add_filter( 'mvs_dm_denial_message', function( string $message, string $reason ) : string {
    return 'blocked' === $reason ? __( 'This member is not accepting messages from you.', 'my-plugin' ) : $message;
}, 10, 2 );
```

---

### Additional DM Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_conversation_created` | action | New DM conversation created | `$conv_id`, `$user_a`, `$participants` | 1.0 |
| `mvs_message_request_accepted` | action | Message request accepted | `$conversation_id`, `$user_id` | 1.0 |
| `mvs_message_deleted` | action | Message deleted | `$message_id`, `$user_id`, `$is_unsend` | 1.0 |
| `mvs_message_reaction_added` | action | Emoji reaction added to a message | `$message_id`, `$user_id`, `$emoji` | 1.0 |
| `mvs_voice_message_sent` | action | Voice message sent | `$message_id`, `$conversation_id`, `$duration` | 1.0 |
| `mvs_conversation_read` | action | Conversation marked as read | `$conversation_id`, `$user_id` | 1.0 |
| `mvs_dm_access_level` | filter | Override DM access check result | `$access`, `$sender_id`, `$recipient_id` | 1.0 |
| `mvs_dm_message_rate_limit` | filter | Max messages/minute per user | `$limit` (int), default `20` | 1.0 |
| `mvs_dm_convo_rate_limit` | filter | Max new conversations/hour | `$limit` (int), default `10` | 1.0 |
| `mvs_message_max_length` | filter | Max message character length | `$length` (int), default `2000` | 1.0 |
| `mvs_message_types` | filter | Allowed message type slugs | `$types` (array), default `text, media_share, image, video, audio, voice, file, system` | 1.0 |
| `mvs_dm_allowed_file_types` | filter | Allowed MIME types for DM attachments. Since 2.2.0 the default is media only (image/video/audio) - `application/pdf` was removed; add it back here per-site to re-allow documents | `$types` (array), default image + video + audio MIME list | 1.0 |
| `mvs_messaging_poll_intervals` | filter | Polling intervals (ms) for the chat client | `$intervals` (array), keys `active`/`list`/`background` | 1.0 |
| `mvs_messaging_transport` | filter | Swap the messaging transport object (e.g. WebSocket instead of REST polling) | `$transport` (TransportInterface) | 1.0 |
| `mvs_show_online_status` | filter | Filter online status visibility | `$show` (bool), `$viewer_id`, `$user_id` | 1.0 |
| `mvs_dm_max_upload_size` | filter | Max DM attachment size in bytes | `$bytes` (int), default `10 * MB_IN_BYTES` | 1.0 |

---

## 9. Admin & Settings

### `mvs_settings_before_save` **(New in 1.1)**

Fires before settings are saved to the database. Use this to validate or transform settings values.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$option_page` | string | Settings page slug being saved |

```php
/**
 * Log settings saves for auditing.
 *
 * @since 1.1
 *
 * @param string $option_page Settings page slug.
 */
add_action( 'mvs_settings_before_save', function( string $option_page ) {
    error_log( "MVS settings page '{$option_page}' saved by user " . get_current_user_id() );
} );
```

---

### `mvs_dashboard_widgets` **(New in 1.1)**

Fires after the built-in overview page widgets render. Use this to add custom stat cards to the admin overview.

**Parameters:** none

```php
/**
 * Add a custom stat card to the MVS admin overview.
 *
 * @since 1.1
 */
add_action( 'mvs_dashboard_widgets', function() {
    $count = my_plugin_get_exports_count();
    echo '<div class="mvs-stat-card"><span class="mvs-stat-number">' . esc_html( $count ) . '</span><span class="mvs-stat-label">' . esc_html__( 'Exports', 'my-plugin' ) . '</span></div>';
} );
```

---

### Additional Admin Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_settings_sidebar_after` | action | After settings sidebar renders | none | 1.0 |
| `mvs_settings_render_{renderer}` | action | Renders a custom settings section. The dynamic part is the section's `renderer` key (e.g. `mvs_settings_render_pages`) | `$section` (array) | 1.0 |
| `mvs_settings_sections` | filter | Register settings sidebar sections | `$sections` (array) | 1.0 |
| `mvs_settings_group_labels` | filter | Override settings group labels | `$labels` (array) | 1.0 |
| `mvs_hide_submenu_slugs` | filter | Hide admin submenu slugs under the MVS menu | `$slugs` (array) | 1.0 |
| `mvs_moderation_tabs` | filter | Filter the tabs shown on the moderation queue page | `$tabs` (array) | 1.0 |
| `mvs_stats_tabs` | filter | Filter the tabs shown on the stats page | `$tabs` (array) | 1.0 |
| `mvs_comment_edit_window` | filter | Seconds a user can still edit a comment after posting | `$seconds` (int), default `15 * MINUTE_IN_SECONDS` | 1.1 |
| `mvs_should_render_chat_panel` | filter | Whether the floating chat panel renders on the current request | `$render` (bool), `$visibility` (string, one of `everywhere`/`logged_in`/`bp_pages`) | 1.2 |
| `mvs_page_id_{slot}` | filter | Override the resolved page ID for a plugin page slot (e.g. `mvs_page_id_explore`). The dynamic part is the slot slug | `$page_id` (int), `$slot` (string) | 1.2 |
| `mvs_user_data_purged` | action | Fires after a user's MVS data is erased (GDPR / account deletion) | `$user_id` (int) | 1.2 |

---

## 10. AI & Moderation

### `mvs_moderation_changed`

Fires when a media item's moderation status changes via the REST API or admin UI.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | Media post ID |
| `$status` | string | New status: `approved`, `rejected`, `flagged` |
| `$old_status` | string | Previous moderation status |
| `$user_id` | int | Moderator user ID (0 if system-triggered) |

```php
/**
 * Notify the media owner of a moderation decision.
 *
 * @since 1.0
 *
 * @param int    $media_id   Media post ID.
 * @param string $status     New moderation status.
 * @param string $old_status Previous moderation status.
 * @param int    $user_id    Moderator user ID.
 */
add_action( 'mvs_moderation_changed', function( int $media_id, string $status, string $old_status, int $user_id ) {
    if ( 'rejected' === $status ) {
        $author_id = (int) get_post_field( 'post_author', $media_id );
        my_send_rejection_email( $author_id, $media_id );
    }
}, 10, 4 );
```

---

### `mvs_should_ai_analyze` **(New in 1.1)**

Filters whether the AI pipeline should analyze a given media item. Return `false` to skip.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$should_analyze` | bool | Whether to analyze (default `true`) |
| `$media_id` | int | Media post ID |

**Returns:** `bool`

```php
/**
 * Skip AI analysis for video media.
 *
 * @since 1.1
 *
 * @param bool $should_analyze Whether to run AI analysis.
 * @param int  $media_id       Media post ID.
 * @return bool
 */
add_filter( 'mvs_should_ai_analyze', function( bool $should_analyze, int $media_id ) {
    if ( 'video' === get_post_meta( $media_id, '_mvs_media_type', true ) ) {
        return false;
    }
    return $should_analyze;
}, 10, 2 );
```

---

### `mvs_openai_api_key`

Filters the OpenAI API key used for AI moderation and tagging. Use this to supply the key from a secrets manager.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$key` | string | API key from plugin settings |

**Returns:** `string`

```php
/**
 * Load the OpenAI API key from a wp-config.php constant.
 *
 * @since 1.0
 *
 * @param string $key API key from settings.
 * @return string
 */
add_filter( 'mvs_openai_api_key', function( string $key ) {
    return defined( 'OPENAI_API_KEY' ) ? OPENAI_API_KEY : $key;
} );
```

---

### `mvs_ai_moderation_terms` **(New in 1.8.0)**

Supplies the full list of moderation terms — the enabled built-in categories (nudity, violence, hate, self-harm, drugs, spam) plus the owner's custom flag terms — to AI providers that don't read Free's options directly. Unusually for a filter, **Free is the consumer that registers the default callback**, not the one that calls `apply_filters()`: `Core\Plugin` does `add_filter( 'mvs_ai_moderation_terms', array( AIService::class, 'get_moderation_terms' ) )` so any provider (Pro's Claude/Anthropic provider calls `apply_filters( 'mvs_ai_moderation_terms', self::CATEGORIES )`) gets the site's real moderation criteria back instead of building its own hardcoded prompt.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$terms` | string[] | Default term list passed in by the caller (e.g. a provider's own fallback category constant) |

**Returns:** `string[]` — Free's registered callback ignores the incoming default and always returns `AIService::get_moderation_terms()` (enabled categories + `mvs_ai_moderation_custom_terms`, comma-split, deduped).

```php
/**
 * Read the site's configured AI moderation terms from a custom provider.
 *
 * @since 1.8.0
 *
 * @param string[] $terms Default terms (your provider's own fallback list).
 * @return string[]
 */
$terms = apply_filters( 'mvs_ai_moderation_terms', array( 'nudity', 'violence' ) );
```

---

### Additional AI & Moderation Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_media_flagged` | action | AI flags a media item | `$media_id`, `$result` (array) | 1.0 |
| `mvs_ai_result` | filter | Filter combined AI output | `$output` (array), `$media_id` | 1.1 |
| `mvs_ai_moderation_result` | filter | Filter moderation result before flagging | `$result` (array), `$media_id` | 1.1 |

---

## 11. Storage & Files

### `mvs_storage_driver`

Resolves the storage driver **instance** for a given driver slug. This is the registration point for custom drivers: the filter receives the current driver (`null` until something supplies one) and the configured driver name, and your callback returns a `StorageDriverInterface` instance only when the name matches your slug — otherwise it returns the incoming `$driver` unchanged. If no listener returns an instance, `StorageService` falls back to the built-in `LocalDriver`. See [Custom Storage Drivers](custom-storage-drivers.md) for the full contract.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$driver` | `StorageDriverInterface\|null` | The driver resolved so far (`null` until a listener supplies one) |
| `$name` | string | Configured driver slug (e.g., `local`, `s3`, `bunnycdn`) |

**Returns:** `StorageDriverInterface|null`

```php
/**
 * Register a custom storage driver for the `my_s3_compatible` slug.
 *
 * @since 1.0
 *
 * @param StorageDriverInterface|null $driver Driver resolved so far.
 * @param string                      $name   Configured driver slug.
 * @return StorageDriverInterface|null
 */
add_filter( 'mvs_storage_driver', function( $driver, string $name ) {
    return 'my_s3_compatible' === $name ? new MyS3CompatibleDriver() : $driver;
}, 10, 2 );
```

---

### `mvs_watermark_stamp_file`

Stamps a watermark onto a file in place, replacing the built-in pass entirely. Return `true` to tell WPMediaVerse the file has already been watermarked and it should not run its own compositor.

This is the extension point for sites that watermark with an external library or a service.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$stamped` | bool | Whether the file has been watermarked. Default `false`. |
| `$path` | string | Absolute path to the file to stamp. |
| `$mime` | string | Detected MIME type. |
| `$user_id` | int | Owner of the media item. |

**Returns:** `bool`

```php
/**
 * Watermark with an external library instead of the built-in compositor.
 *
 * @param bool   $stamped Whether the file is already watermarked.
 * @param string $path    Absolute file path.
 * @param string $mime    MIME type.
 * @param int    $user_id Media owner.
 * @return bool
 */
add_filter( 'mvs_watermark_stamp_file', function( bool $stamped, string $path, string $mime, int $user_id ) {
    if ( 'image/jpeg' !== $mime ) {
        return $stamped;
    }
    my_watermarker_stamp( $path );
    return true;
}, 10, 4 );
```

Two related filters complete the set:

| Filter | Free/Pro | Description | Parameters |
|--------|----------|-------------|------------|
| `mvs_watermark_enabled` | Free | Turn watermarking on or off at runtime, overriding the `mvs_watermark_enabled` option | `$enabled` (bool) |
| `mvs_watermark_font_path` | Pro | Absolute path to the TTF font used for text watermarks | `$path` (string) |

---

### Image Optimization (1.3.0)

#### `mvs_optimize_image`

Extension point for external optimizers (EWWW, Imagify, Smush, ShortPixel). Fires once per file pass: once for the lossless re-encode, once for each WebP sibling, and once for each AVIF sibling. The filter runs before the built-in pass, so a returning listener can fully replace the result.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$file_path` | string | Absolute path to the file on local disk |
| `$context` | array | Keys: `media_id` (int), `variant` (string, e.g. `original`, `original-webp`), `mime` (string), `user_id` (int) |

**Returns:** `string|WP_Error` - Return a file path string to replace the result. Return the same `$file_path` for an in-place edit. Return a `WP_Error` to log a warning and keep the original.

```php
/**
 * Delegate JPEG optimization to EWWW Image Optimizer.
 *
 * @since 1.3.0
 *
 * @param string $file_path Absolute path to file.
 * @param array  $context   { media_id, variant, mime, user_id }.
 * @return string
 */
add_filter( 'mvs_optimize_image', function( string $file_path, array $context ) {
    if ( 'image/jpeg' !== $context['mime'] ) {
        return $file_path;
    }
    ewww_image_optimizer( $file_path );
    return $file_path;
}, 10, 2 );
```

---

#### `mvs_optimize_jpeg_quality`

Filters the JPEG re-encode quality used by the built-in lossless pass. Range 0-100. Setting to 100 produces a near-lossless re-encode that still strips EXIF.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$quality` | int | Default `92` |
| `$context` | array | Keys: `media_id`, `variant`, `mime`, `user_id` |

**Returns:** `int`

```php
add_filter( 'mvs_optimize_jpeg_quality', function( int $quality, array $context ) : int {
    // Use 85 for thumbnails, 92 for originals.
    return str_contains( $context['variant'] ?? '', 'thumb' ) ? 85 : $quality;
}, 10, 2 );
```

---

#### `mvs_webp_quality`

Filters the WebP encoder quality. Range 0-100.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$quality` | int | Default `82` |
| `$context` | array | Keys: `media_id`, `variant`, `mime`, `user_id` |

**Returns:** `int`

```php
add_filter( 'mvs_webp_quality', function( int $quality ) : int {
    return 75; // Smaller files, still visually lossless for most photos.
} );
```

---

#### `mvs_avif_quality`

Filters the AVIF encoder quality. Range 0-100. AVIF encoding is CPU-intensive; only runs when the `mvs_generate_avif` setting is enabled.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$quality` | int | Default `50` |
| `$context` | array | Keys: `media_id`, `variant`, `mime`, `user_id` |

**Returns:** `int`

```php
add_filter( 'mvs_avif_quality', function( int $quality ) : int {
    return 40; // Lower = smaller file; AVIF retains quality well below 50.
} );
```

---

### Video Poster (1.3.0)

#### `mvs_default_video_poster_url`

Filters the fallback poster URL shown for a cover-less video (no embedded cover atom to extract via getID3). The URL is used at render time only and is never stored in media meta.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$url` | string | Default: plugin-bundled SVG asset URL |

**Returns:** `string`

```php
add_filter( 'mvs_default_video_poster_url', function( string $url ) : string {
    return get_stylesheet_directory_uri() . '/assets/video-placeholder.png';
} );
```

---

### Cloud URL Serving (1.4.0)

#### `mvs_serve_public_cloud_direct`

Controls whether public media stored on a cloud driver is served via a direct CDN URL rather than through the plugin's `/serve` proxy. Default is `true` (direct). Set to `false` to force all traffic through the proxy (e.g. for request logging or header injection).

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$allowed` | bool | Whether direct cloud serving is permitted. Default `true` |
| `$media_id` | int | Media ID |

**Returns:** `bool`

```php
add_filter( 'mvs_serve_public_cloud_direct', function( bool $allowed, int $media_id ) : bool {
    // Force all media through /serve for audit logging.
    return false;
}, 10, 2 );
```

---

#### `mvs_public_cloud_thumbnail_url`

Filters the direct CDN URL emitted for a cloud-hosted thumbnail. Use this to rewrite the URL to a custom domain (e.g. a CDN pull zone in front of S3) or return an empty string to fall back to the `/serve` proxy.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$thumb_url` | string | Cloud thumbnail URL |
| `$media_id` | int | Media ID |
| `$size` | string | Size key (e.g. `thumb_large`, `thumb_medium`) |

**Returns:** `string` - Return empty string to force `/serve` proxy for this thumbnail.

```php
add_filter( 'mvs_public_cloud_thumbnail_url', function( string $thumb_url, int $media_id, string $size ) : string {
    // Replace the raw S3 hostname with a CloudFront distribution.
    return str_replace( 'my-bucket.s3.amazonaws.com', 'cdn.example.com', $thumb_url );
}, 10, 3 );
```

---

#### `mvs_public_cloud_file_url`

Filters the direct CDN URL for a public media's original file. Companion to `mvs_public_cloud_thumbnail_url` for full-file reads. Return empty string to fall back to the `/serve` proxy.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$file_url` | string | Cloud file URL |
| `$media_id` | int | Media ID |
| `$size` | string | Always empty string for this filter (full-file context) |

**Returns:** `string`

```php
add_filter( 'mvs_public_cloud_file_url', function( string $file_url, int $media_id, string $size ) : string {
    return str_replace( 'my-bucket.s3.amazonaws.com', 'cdn.example.com', $file_url );
}, 10, 3 );
```

---

### Broadcast Thumbnail Expiry (1.5.0)

#### `mvs_broadcast_thumbnail_ttl`

Sets how long, in seconds, a broadcast thumbnail access URL stays valid. These URLs are minted by `MediaRepository::get_broadcast_thumbnail_url()` for thumbnails embedded in long-lived surfaces such as notification emails and RSS feeds, where the link may be opened long after it was generated. The default is `HOUR_IN_SECONDS` (3600). The serve-time privacy check still runs on every request, so this filter only controls the link's validity window, not the access decision.

Raise the value on sites that cache those surfaces at the CDN for longer than an hour, but keep it as short as your caching allows.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$ttl` | int | Time-to-live in seconds. Default `HOUR_IN_SECONDS` (3600) |
| `$media_id` | int | Media ID the thumbnail belongs to |
| `$size` | string | Size key (e.g. `thumb_large`, `thumb_medium`) |

**Returns:** `int` - The TTL in seconds.

```php
add_filter( 'mvs_broadcast_thumbnail_ttl', function( int $ttl, int $media_id, string $size ) : int {
    // Widen to 6 hours on a site that caches notification emails / RSS at the CDN.
    return 6 * HOUR_IN_SECONDS;
}, 10, 3 );
```

---

### Render-Stable Public URLs (1.7.0)

Public media is served through signed `/serve` URLs like everything else, but a fresh signature on every page render defeats browser and CDN caching. These four filters, all in `SignedUrlService`, control the render-stable/cacheable URL behavior for **public** media only — private/restricted media always keeps its rolling, per-request signature.

#### `mvs_stable_public_urls`

Whether public media gets a render-stable (cacheable) signed URL instead of a fresh signature every page load. Default `true`. The stable URL is bucketed to the current month plus a one-year offset, so it stays constant for about a month, then rotates. Access is still gated by privacy on every request (see `serve()`), so a long, stable expiry value is safe to cache. Set to `false` to fall back to the old per-request signature.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$stable` | bool | Whether to mint a render-stable URL. Default `true` |
| `$media_id` | int | Media ID |

**Returns:** `bool`

```php
add_filter( 'mvs_stable_public_urls', '__return_false' );
```

---

#### `mvs_public_media_max_age`

`Cache-Control: max-age` (seconds) sent by `/serve` for public media. Return `0` to keep public media on `no-store` (disable HTTP caching entirely).

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$max_age` | int | Default `WEEK_IN_SECONDS` (604800) |
| `$privacy` | string | Media privacy level (always `public` when this filter runs) |

**Returns:** `int`

```php
add_filter( 'mvs_public_media_max_age', function( int $max_age, string $privacy ) : int {
    return DAY_IN_SECONDS; // Shorter cache window for a fast-moving feed.
}, 10, 2 );
```

---

#### `mvs_public_local_thumbnail_url` / `mvs_public_local_file_url`

Local-storage escape hatch for public media. By default, public files stored on the `local` driver still stream through the signed `/serve` proxy. If you put a reverse proxy or static-file rule in front of `wp-content/uploads` (e.g. Nginx `location` block, a CDN pull zone with no cloud driver configured), return a non-empty URL from these filters to bypass `/serve` and point directly at that static URL. Default `''` keeps the existing `/serve` behavior.

**Parameters (`mvs_public_local_thumbnail_url`):**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$url` | string | Default `''` (keeps `/serve`) |
| `$media_id` | int | Media ID |
| `$size` | string | Size key (e.g. `thumb_large`) |
| `$rel_path` | string | Relative storage path |

**Parameters (`mvs_public_local_file_url`):**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$url` | string | Default `''` (keeps `/serve`) |
| `$media_id` | int | Media ID |
| `$rel_path` | string | Relative storage path |

**Returns:** `string` — non-empty to bypass `/serve`.

```php
add_filter( 'mvs_public_local_file_url', function( string $url, int $media_id, string $rel_path ) : string {
    return 'https://static.example.com/wpmediaverse/' . ltrim( $rel_path, '/' );
}, 10, 3 );
```

---

### Cloud, Thumbnails & Filenames

#### `mvs_cloudops_allow_non_public_to_cloud`

Controls whether a non-public (private/restricted) media item is allowed to be migrated to a cloud driver during a `CloudOps` migration. Default `false` — only public media is cloud-eligible, so private files stay on local disk. Return `true` to opt a specific item in.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$allow` | bool | Whether to allow the move. Default `false` |
| `$media_id` | int | Media ID |
| `$privacy` | string | Current privacy value |
| `$to` | string | Target driver slug |

**Returns:** `bool`

```php
add_filter( 'mvs_cloudops_allow_non_public_to_cloud', function( bool $allow, int $media_id, string $privacy, string $to ) : bool {
    // Allow "members" media to go to cloud, keep "private" local.
    return 'members' === $privacy ? true : $allow;
}, 10, 4 );
```

---

#### `mvs_default_thumbnail_style`

Filters the default grid thumbnail style for sites that have not explicitly saved the `mvs_thumbnail_style` option. The default flipped from `square` to `original` (masonry) in 1.8.0 so Explore and the media-grid show every image at its native aspect ratio instead of a center-cropped square. Use this filter to restore the old uniform-crop default without touching the site's saved option — an explicitly saved option always wins over the filtered default, since `register_setting()` only runs on `admin_init` and the frontend relies on this resolved default.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$default` | string | `'original'` (masonry) or `'square'` (uniform crop). Default `'original'` |

**Returns:** `string`

```php
/**
 * Restore the pre-1.8.0 uniform square-crop grid default.
 *
 * @since 1.8.0
 *
 * @param string $default 'original' or 'square'.
 * @return string
 */
add_filter( 'mvs_default_thumbnail_style', static fn() => 'square' );
```

---

#### `mvs_grid_thumb_size_key`

Filters the thumbnail size rung (`medium` or `large`) served for grid/masonry tiles. 1.8.0 raised the grid's default from `medium` to `large` so tiles stay sharp on HiDPI/retina screens (the `medium` rung visibly upscaled inside larger masonry tiles). Byte-conscious sites can drop back to `medium` with this filter instead of changing the `mvs_thumbnail_size` setting site-wide.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$key` | string | Resolved rung, `'medium'` or `'large'` |
| `$configured` | string | The raw `mvs_thumbnail_size` setting value |

**Returns:** `string` — must resolve to `'medium'` or `'large'`; any other value falls back to `'large'`.

```php
/**
 * Serve the smaller thumbnail rung for grid tiles on a bandwidth-constrained site.
 *
 * @since 1.8.0
 *
 * @param string $key        Resolved rung.
 * @param string $configured The mvs_thumbnail_size setting value.
 * @return string
 */
add_filter( 'mvs_grid_thumb_size_key', function( string $key, string $configured ) : string {
    return 'medium';
}, 10, 2 );
```

---

### Storage Repair (1.8.0)

#### `mvs_storage_repair_enabled`

Owner escape hatch for the automatic post-update storage repair pass (`Services\StorageRepairService`). The repair heals two pre-1.8.0 inconsistencies without deleting or moving anything — absolute file paths left over from a plugin migration (rtMedia / MediaPress / BuddyBoss), and thumbnails stranded on local disk after an older "Migrate all" — copying files into the library and correcting `file_path`/`file_url` only. It runs opt-out (default `true`), in bounded Action Scheduler batches, and is idempotent/resumable. Also gates `wp mvs repair-storage` (see [WP-CLI Commands](wp-cli.md)).

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$enabled` | bool | Whether the repair pass may run. Default `true` |

**Returns:** `bool`

```php
/**
 * Disable the automatic background storage repair on this site.
 *
 * @since 1.8.0
 *
 * @param bool $enabled Whether the repair pass is enabled. Default true.
 * @return bool
 */
add_filter( 'mvs_storage_repair_enabled', '__return_false' );
```

---

#### `mvs_filename_strategy`

Filters the stored-filename strategy used for new uploads. The built-in strategies are `hashed` (default) and `original`.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$strategy` | string | Resolved strategy slug |
| `$user_id` | int | Uploading user ID |

**Returns:** `string`

```php
add_filter( 'mvs_filename_strategy', function( string $strategy, int $user_id ) : string {
    return user_can( $user_id, 'manage_options' ) ? 'original' : $strategy;
}, 10, 2 );
```

---

### Additional Storage Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_media_deleted` | action | Media permanently deleted | `$media_id`, `$author_id`, `$permalink` (since 1.9.0) | 1.0 |
| `mvs_media_trashed` | action | Media moved to the trash. Same three arguments as `mvs_media_deleted`, so one listener can withdraw a mirror on either event | `$media_id`, `$author_id`, `$permalink` | 2.4.0 |
| `mvs_media_restored` | action | Media restored from the trash. Paired with `mvs_media_trashed` so a withdrawn mirror can be re-added | `$media_id`, `$author_id`, `$permalink` | 2.4.0 |
| `mvs_watermark_enabled` | filter | Enable/disable watermark per media item | `$enabled` (bool), `$media_id` | 1.0 |
| `mvs_cloud_thumbnail_url` | filter | Override the cloud URL stored for a generated thumbnail size at upload time. Return non-empty to use a custom URL | `$url` (string, empty), `$size_name` (string), `$media_id` (int) | 1.3.0 |
| `mvs_thumbnail_sizes` | filter | Filter the size definitions array used for thumbnail generation | `$sizes` (array) | 1.3.0 |
| `mvs_thumbnail_size_resolved` | filter | Filter the resolved default thumbnail size key | `$size` (string) | 1.3.0 |
| `mvs_can_repair_thumb` | filter | Whether the "Repair thumbnail" admin row action is offered for a media item | `$can` (bool), `$media_id` (int), `$file_type` (string), `$file_path` (string) | 1.2.3 |
| `mvs_repair_media_thumb` | filter | Let Pro / third parties regenerate thumbnails during an admin repair. Return the count of regenerated size-variants | `$regenerated` (int), `$media_id` (int), `$context` (array: `file_type`, `file_path`) | 1.2.3 |
| `mvs_watermark_font_path` **(Pro)** | filter | Path to the TTF font used for text watermarks | `$path` (string, empty), `$config` (array) | 1.0 |
| `mvs_webhook_sslverify` | filter | Whether outgoing webhook requests verify SSL (default off on local environments) | `$verify` (bool), `$url` (string) | 1.3.0 |

---

## 12. User Profiles

### `mvs_has_custom_avatar`

Whether a member has an avatar **they chose**, as opposed to a site default or a generated placeholder. Defaults to whether MediaVerse's own avatar store has one.

For avatar providers other than MediaVerse. Without it, `has_custom_avatar` answers "is there a row in OUR store", so a member who set their picture in another plugin is reported as having none while their real photograph is being served beside it — and anything gating on the flag (an upload-a-photo nudge, a profile-completion check) asks them for a picture they already have.

**Answer `true` only for a picture the member actually supplied.** Never for a site default or a generated initials placeholder — a seam that returns `true` for everyone is as useless as the bug it replaces, because nothing can then tell the two apart. This cannot be resolved by reading the avatar chain: core's `found_avatar` is set by placeholder generators too.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$has` | bool | True if MediaVerse's own avatar store has one |
| `$user_id` | int | User ID |

**Returns:** `bool`

```php
/**
 * Report avatars this plugin stores itself.
 *
 * @since 2.4.0
 *
 * @param bool $has     MediaVerse's own answer.
 * @param int  $user_id User ID.
 * @return bool
 */
add_filter( 'mvs_has_custom_avatar', function( bool $has, int $user_id ) {
    return $has || (bool) get_user_meta( $user_id, 'my_plugin_avatar', true );
}, 10, 2 );
```

---

### `mvs_user_profile_url`

Filters the public profile URL for a user. Override this to point at BuddyPress, a custom route, or any external profile system.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$url` | string | Default MVS profile URL |
| `$user_id` | int | User ID |

**Returns:** `string`

```php
/**
 * Use the WordPress author URL as the MVS profile URL.
 *
 * @since 1.0
 *
 * @param string $url     Default profile URL.
 * @param int    $user_id User ID.
 * @return string
 */
add_filter( 'mvs_user_profile_url', function( string $url, int $user_id ) {
    return get_author_posts_url( $user_id );
}, 10, 2 );
```

---

### `mvs_user_display_name`

Filters a user's display name everywhere MVS renders it. Pro uses this internally to append a streak badge.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$name` | string | Current display name |
| `$user_id` | int | User ID |

**Returns:** `string`

```php
/**
 * Append a verified checkmark to display names.
 *
 * @since 1.0
 *
 * @param string $name    Current display name.
 * @param int    $user_id User ID.
 * @return string
 */
add_filter( 'mvs_user_display_name', function( string $name, int $user_id ) {
    if ( my_is_verified( $user_id ) ) {
        $name .= ' ✓';
    }
    return $name;
}, 10, 2 );
```

---

### Additional Profile Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_profile_updated` | action | Profile saved | `$user_id`, `$fields` (array) | 1.0 |
| `mvs_avatar_uploaded` | action | Avatar uploaded | `$user_id`, `$attachment_id` | 1.0 |
| `mvs_avatar_deleted` | action | Avatar removed | `$user_id` | 1.0 |
| `mvs_profile_data` | filter | Filter profile data in REST response | `$data` (array), `$user_id` | 1.0 |
| `mvs_profile_update_fields` | filter | Filter allowed profile update fields | `$fields` (array), `$user_id` | 1.0 |
| `mvs_avatar_allowed_types` | filter | Filter allowed avatar MIME types | `$types` (array), `$user_id` | 1.0 |
| `mvs_avatar_max_size` | filter | Max avatar file size in bytes | `$bytes` (int), default `2 * MB_IN_BYTES` | 1.0 |

---

## 13. Access & Privacy

### `mvs_media_drive_access`

How much access a member has to a shared drive **for media**. Media and documents are two different things a member can put on a drive, and until 2.4.0 one filter answered for both — so a bridge could not allow photos in a Space while keeping files behind that Space's own files setting.

Defaults to whatever `mvs_document_drive_access` answered, so leaving this alone keeps existing behaviour exactly.

**This is a privacy boundary, not a placement hint.** The same answer governs both gates: which drive an upload lands on, AND who may read media already scoped to that drive. Answering `write` for someone who is not a member would expose that drive's media to them. The two gates share one resolver deliberately — if they disagreed, media would be stored scoped to a drive its own members could not open.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$level` | string | Access from `mvs_document_drive_access`: `none`, `read`, `write` or `own` |
| `$drive_type` | string | Drive type, e.g. `space` |
| `$drive_id` | int | Drive ID |
| `$user_id` | int | User being tested (0 for anonymous) |

**Returns:** `string`

```php
/**
 * Any member of a Space may post media to it, whatever the files setting says.
 *
 * @since 2.4.0
 *
 * @param string $level      Level from the document filter.
 * @param string $drive_type Drive type.
 * @param int    $drive_id   Drive ID.
 * @param int    $user_id    User being tested.
 * @return string
 */
add_filter( 'mvs_media_drive_access', function( string $level, string $drive_type, int $drive_id, int $user_id ) {
    if ( 'space' !== $drive_type ) {
        return $level;
    }
    return my_user_is_space_member( $user_id, $drive_id ) ? 'write' : $level;
}, 10, 4 );
```

---

### `mvs_privacy_can_view`

Filters the privacy access check result. Return `null` to use the built-in check, `true` to grant, or `false` to deny.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$result` | bool\|null | Current result (`null` = use default logic) |
| `$media_id` | int | Media post ID |
| `$user_id` | int | Viewing user ID (0 for anonymous) |
| `$privacy` | string | Media privacy level slug |

**Returns:** `bool|null`

```php
/**
 * Grant access to group members using a custom group check.
 *
 * @since 1.0
 *
 * @param bool|null $result   Current access result.
 * @param int       $media_id Media post ID.
 * @param int       $user_id  Viewing user ID.
 * @param string    $privacy  Privacy level.
 * @return bool|null
 */
add_filter( 'mvs_privacy_can_view', function( $result, int $media_id, int $user_id, string $privacy ) {
    if ( 'group' === $privacy && my_user_in_media_group( $media_id, $user_id ) ) {
        return true;
    }
    return $result;
}, 10, 4 );
```

---

### `mvs_media_privacy_changed`

Fires when a media item's privacy level changes. Fires from both `MediaRepository::set()` (single-field update path) and `MediaRepository::update()` (bulk update path). Does not fire when a row is first inserted.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | Media ID |
| `$new_privacy` | string | New privacy value (e.g. `public`, `members`, `friends`, `private`) |
| `$old_privacy` | string | Previous privacy value |

```php
/**
 * Sync BuddyPress activity visibility when a media item is made private.
 *
 * @since 1.3.0
 *
 * @param int    $media_id    Media ID.
 * @param string $new_privacy New privacy value.
 * @param string $old_privacy Previous privacy value.
 */
add_action( 'mvs_media_privacy_changed', function( int $media_id, string $new_privacy, string $old_privacy ) {
    if ( 'public' === $old_privacy && 'public' !== $new_privacy ) {
        // Hide related BuddyPress activity from sitewide stream.
        my_plugin_hide_bp_activity_for_media( $media_id );
    }
}, 10, 3 );
```

---

### Additional Access Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_access_rule_created` | action | Access rule created for a media item | `$rule_id`, `$media_id`, `$rule_type`, `$rule_value` | 1.0 |
| `mvs_access_rule_deleted` | action | Access rule removed | `$rule_id`, `$media_id`, `$rule_type` | 1.0 |
| `mvs_access_granted` | action | User granted access to restricted media | `$grant_id`, `$media_id`, `$user_id`, `$source` | 1.0 |
| `mvs_access_revoked` | action | User access revoked | `$media_id`, `$user_id` | 1.0 |
| `mvs_album_items_added` | action | Media added to an album | `$album_id`, `$actor_id`, `$media_ids`, `$added` (signature changed in 1.2.3) | 1.0 |

> **Stories moved to Pro in 1.9.0.** `mvs_story_created` and `mvs_story_expired` now fire from `WPMediaVersePro\Stories\StoryService` — see [Stories (Pro)](../pro-features/stories.md). The free plugin no longer ships a `StoryService`; the upload block's "Also share as a story" toggle only renders when the `mvs_stories_enabled` option is on, which Pro sets when it registers the feature.

---

## 14. BuddyPress Integration

These hooks only fire when BuddyPress is active. Use `mvs_buddynext_active` to detect whether the BuddyNext integration layer is running.

### `mvs_buddynext_active`

Filters whether the BuddyNext integration is considered active. Override this if you need to force or suppress the integration.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$active` | bool | Auto-detected active state |

**Returns:** `bool`

```php
/**
 * Force BuddyNext integration off in a staging environment.
 *
 * @since 1.0
 *
 * @param bool $active Auto-detected state.
 * @return bool
 */
add_filter( 'mvs_buddynext_active', function( bool $active ) {
    return defined( 'WP_STAGING' ) ? false : $active;
} );
```

---

### `mvs_strip_dead_bp_links` **(New in 1.7.1)**

Opt-in gate for cleaning up dead BuddyPress component links (`/members/`, `/groups/`, `/activity/`) from the site's nav menus when BuddyPress is inactive. **Off by default** — per Coding Rule #17, WPMediaVerse never edits a site owner's authored navigation on its own. Only enable this on a site where you specifically want the plugin to drop menu items that would 404 once BuddyPress is gone. The cleanup never removes an item that resolves to a real published page, and it fully bails when a sibling community plugin (detected via `mvs_buddynext_active`) owns those routes as live pages.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$enabled` | bool | Whether to run the cleanup. Default `false` |

**Returns:** `bool`

```php
/**
 * Opt this site into automatic dead-BP-link menu cleanup.
 *
 * @since 1.7.1
 *
 * @param bool $enabled Whether the cleanup runs. Default false.
 * @return bool
 */
add_filter( 'mvs_strip_dead_bp_links', '__return_true' );
```

---

### `mvs_dead_bp_link_patterns` **(New in 1.7.1)**

Filters the URL fragments treated as "dead" BuddyPress component links when `mvs_strip_dead_bp_links` is enabled and BuddyPress is inactive. Only consulted when the cleanup above actually runs.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$patterns` | string[] | Default `array( '/members/', '/groups/', '/activity/' )` |

**Returns:** `string[]`

```php
/**
 * Also treat a custom /community/ archive as a dead BP-style link.
 *
 * @since 1.7.1
 *
 * @param string[] $patterns Default members/groups/activity archives.
 * @return string[]
 */
add_filter( 'mvs_dead_bp_link_patterns', function( array $patterns ) : array {
    $patterns[] = '/community/';
    return $patterns;
} );
```

---

### `mvs_suppress_bp_comment_notification` **(New in 2.0.0)**

When a media comment is posted from inside a linked BuddyPress activity, BuddyPress already fires its own native "replied to your update" notification for the activity comment. Without this filter, `NotificationIntegration` also mirrors the MVS `media_comment` notification, giving the media owner two dropdown entries for one comment. The filter only suppresses the **BP-mirrored** notification (`bp_notifications_add_notification()`); the native MVS in-app notification row in `mvs_notifications` is untouched, so `/me/notifications` and any REST/app client still see it. Only applies when the comment's media has a linked `bp_activity_id` that BuddyPress itself will notify on.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$suppress` | bool | Whether to suppress the BP-mirrored notification. Default `true` |
| `$media_id` | int | Media ID the comment belongs to |
| `$user_id` | int | Recipient (media owner) user ID |
| `$actor_id` | int | Commenter user ID |

**Returns:** `bool`

```php
/**
 * Restore the old double-notify behavior on a site that wants both.
 *
 * @since 2.0.0
 *
 * @param bool $suppress Whether to suppress the BP mirror. Default true.
 * @param int  $media_id Media ID.
 * @param int  $user_id  Recipient (media owner) user ID.
 * @param int  $actor_id Commenter user ID.
 * @return bool
 */
add_filter( 'mvs_suppress_bp_comment_notification', '__return_false' );
```

---

## 15. Video Processing (Pro)

> Video transcoding was removed in 2.4.0 (MediaVerse embeds media, it does not
> process it), so the `mvs_pro_transcode_*` hooks no longer exist. The player
> uses the original file; posters come from the embedded cover atom or a
> default SVG. The hooks below cover the video features that remain.

### Additional Video Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_pro_captions_generated` | action | Whisper transcription saved as WebVTT | `$media_id`, `$vtt_url` | 1.0 |
| `mvs_pro_poster_frame` | filter | Filter video poster frame URL | `$poster_url`, `$media_id`, `$file_path` | 1.1 |

---

## 16. Analytics (Pro)

### `mvs_pro_analytics_event_data` **(Pro)** **(New in 1.1)**

Filters analytics event data before it is stored. Return `false` to skip recording the event entirely.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$event_data` | array | Event payload (user_id, timestamp, meta) |
| `$media_id` | int | Media post ID |
| `$event_type` | string | Event type slug (e.g., `view`, `download`) |

**Returns:** `array|false`

```php
/**
 * Strip PII from analytics events before storage.
 *
 * @since 1.1
 *
 * @param array  $event_data Event data payload.
 * @param int    $media_id   Media post ID.
 * @param string $event_type Event type slug.
 * @return array|false
 */
add_filter( 'mvs_pro_analytics_event_data', function( $event_data, int $media_id, string $event_type ) {
    unset( $event_data['ip_address'] );
    return $event_data;
}, 10, 3 );
```

---

### Additional Analytics Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_pro_analytics_recorded` | action | Analytics event stored | `$media_id`, `$event_type`, `$user_id` | 1.1 |
| `mvs_pro_analytics_summary` | filter | Filter dashboard analytics summary | `$summary` (array), `$media_id`, `$date_range` | 1.1 |

---

## 17. Layout System (Pro)

### `mvs_active_layout` **(Pro)**

Filters the active layout slug. Use this to switch layouts programmatically (e.g., per page or per user role).

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$slug` | string | Active layout slug from the database setting |

**Returns:** `string`

```php
/**
 * Show the Pinterest layout for mobile visitors.
 *
 * @since 1.0
 *
 * @param string $slug Active layout slug.
 * @return string
 */
add_filter( 'mvs_active_layout', function( string $slug ) {
    return wp_is_mobile() ? 'pinterest' : $slug;
} );
```

---

### Additional Layout Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_layout_assets` | action | After layout CSS/JS is enqueued | `$layout_instance`, `$slug` | 1.0 |
| `mvs_before_layout_render` | action | Before layout template loads | `$layout_slug`, `$template_name` | 1.1 |
| `mvs_layout_modes` | filter | Register custom layout modes | `$modes` (slug => class) | 1.0 |
| `mvs_layout_template_map` | filter | Override template file mapping | `$map` (array), `$layout_instance` | 1.0 |
| `mvs_layout_config` | filter | Filter layout configuration | `$config` (array), `$slug` (string) | 1.1 |

---

## 18. Quota System (Pro)

### `mvs_pro_quota_source` **(Pro)** **(New in 1.1)**

Filters the quota package assigned to a user. Use this to integrate a custom membership or LMS plugin.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$package` | array\|null | Package definition or `null` for the site default |
| `$user_id` | int | User ID |

**Returns:** `array|null`

```php
/**
 * Assign quota from a custom LMS based on course enrollment.
 *
 * @since 1.1
 *
 * @param array|null $package Quota package definition.
 * @param int        $user_id User ID.
 * @return array|null
 */
add_filter( 'mvs_pro_quota_source', function( $package, int $user_id ) {
    $course_id = my_lms_get_active_course( $user_id );
    if ( $course_id ) {
        return my_lms_get_quota_for_course( $course_id );
    }
    return $package;
}, 10, 2 );
```

---

### `mvs_pro_before_quota_check` **(Pro)** **(New in 1.1)**

Fires before quota enforcement runs. Return a `WP_Error` to reject the action before quota is checked.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$args` | array | Quota check args (media_type, count) |
| `$user_id` | int | User ID |

**Returns:** `array|WP_Error`

```php
/**
 * Block uploads during a scheduled maintenance window.
 *
 * @since 1.1
 *
 * @param array $args    Quota check arguments.
 * @param int   $user_id User ID.
 * @return array|WP_Error
 */
add_filter( 'mvs_pro_before_quota_check', function( $args, int $user_id ) {
    if ( get_option( 'my_plugin_maintenance_mode' ) ) {
        return new WP_Error( 'maintenance', __( 'Uploads are paused for maintenance.', 'my-plugin' ) );
    }
    return $args;
}, 10, 2 );
```

---

### Additional Quota Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_pro_credits_added` | action | Credits added to user quota | `$user_id`, `$media_type`, `$amount`, `$source` | 1.0 |
| `mvs_pro_woo_package_assigned` | action | WooCommerce order assigns a quota package | `$user_id`, `$product_id`, `$package_id`, `$order_status` | 1.0 |
| `mvs_pro_woo_package_reverted` | action | WooCommerce order cancelled, package reverted | `$user_id`, `$default`, `$order_status` | 1.0 |
| `mvs_pro_memberpress_package_assigned` | action | MemberPress membership assigns package | `$user_id`, `$membership_id`, `$package_id` | 1.0 |
| `mvs_pro_memberpress_package_reverted` | action | MemberPress membership expired, reverted | `$user_id`, `$default` | 1.0 |
| `mvs_pro_pmpro_package_assigned` | action | PMPro level assigns package | `$user_id`, `$level_id`, `$package_id` | 1.0 |
| `mvs_pro_pmpro_package_reverted` | action | PMPro level cancelled, reverted | `$user_id`, `$default` | 1.0 |
| `mvs_quota_render_mapping_fields` | action | Admin quota page renders mapping UI | none | 1.0 |
| `mvs_quota_save_mapping` | action | Admin saves quota mapping | none | 1.0 |

---

## 19. Competitions (Pro)

### Challenges

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_challenge_created` | action | Challenge created | `$competition_id`, `$args`, `$created_by` | 1.0 |
| `mvs_challenge_entry_submitted` | action | User submits an entry | `$challenge_id`, `$user_id`, `$media_id` | 1.0 |
| `mvs_challenge_activated` | action | A scheduled challenge transitions to active | `$challenge_id` | 1.5.0 |
| `mvs_challenge_voting_started` | action | A challenge closes entries and opens voting | `$challenge_id` | 1.5.0 |
| `mvs_challenge_winner_named` | action | Fires once per top-3 rank when a challenge is finalized - fires before `mvs_challenge_finalized` | `$challenge_id`, `$user_id`, `$rank` (1, 2, or 3) | 1.2.3 |
| `mvs_challenge_finalized` | action | Voting ends, winners determined | `$challenge_id`, `$results` | 1.0 |
| `mvs_competition_status_changed` | action | Any competition (challenge/battle/tournament) changes status | `$competition_id`, `$old_status`, `$new_status` | 1.5.0 |

```php
/**
 * Award scaled XP per rank without parsing the private $results shape.
 *
 * @since 1.2.3
 *
 * @param int $challenge_id Challenge competition ID.
 * @param int $user_id      Winning user ID.
 * @param int $rank         1, 2, or 3.
 */
add_action( 'mvs_challenge_winner_named', function( int $challenge_id, int $user_id, int $rank ) {
    $scale = array( 1 => 500, 2 => 250, 3 => 100 );
    if ( isset( $scale[ $rank ] ) ) {
        my_award_xp( $user_id, $scale[ $rank ] );
    }
}, 10, 3 );
```

---

### Battles

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_battle_created` | action | Battle created | `$competition_id`, `$challenger_id`, `$opponent_id` | 1.0 |
| `mvs_battle_accepted` | action | Opponent accepts battle | `$battle_id`, `$user_id` | 1.0 |
| `mvs_battle_resolved` | action | Battle voting ends, winner determined | `$battle_id`, `$winner_id`, `$loser_id` | 1.0 |
| `mvs_battle_cancelled` | action | Battle cancelled | `$battle_id` | 1.5.0 |

---

### Tournaments

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_tournament_created` | action | Tournament created | `$competition_id`, `$args`, `$created_by` | 1.0 |
| `mvs_tournament_started` | action | Registration closes, bracket generated | `$tournament_id` | 1.0 |
| `mvs_tournament_match_resolved` | action | Single bracket match resolved | `$match_id`, `$winner_id` | 1.0 |
| `mvs_tournament_finalized` | action | Tournament ends, champion crowned | `$competition_id`, `$champion_id` | 1.0 |
| `mvs_tournament_updated` | action | Tournament settings updated | `$tournament_id`, `$args` | 1.5.0 |
| `mvs_tournament_cancelled` | action | Tournament cancelled | `$tournament_id` | 1.5.0 |

---

### Autopilot

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_autopilot_challenge_created` | action | Autopilot creates a weekly challenge | `$competition_id`, `$theme` | 1.0 |
| `mvs_autopilot_create_failed` | action | Autopilot failed to create a challenge | `$error`, `$theme` | 1.0 |
| `mvs_autopilot_no_theme_available` | action | All themes in pool have been used | none | 1.0 |
| `mvs_autopilot_pool_reset` | action | Theme pool recycled | `$pool` | 1.0 |

---

### Streaks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_streak_milestone` | action | User reaches a streak milestone (7, 30, 100, 365 days) | `$user_id`, `$days`, `$xp_awarded` | 1.0 |

```php
/**
 * Send a congratulations email at streak milestones.
 *
 * @since 1.0
 *
 * @param int $user_id    User ID.
 * @param int $days       Streak day count reached.
 * @param int $xp_awarded XP awarded for this milestone.
 */
add_action( 'mvs_streak_milestone', function( int $user_id, int $days, int $xp_awarded ) {
    $user = get_userdata( $user_id );
    wp_mail(
        $user->user_email,
        sprintf( __( 'You reached a %d-day streak!', 'my-plugin' ), $days ),
        sprintf( __( 'Congratulations! You earned %d XP.', 'my-plugin' ), $xp_awarded )
    );
}, 10, 3 );
```

---

### Competition Scheduler

The competitions cron tick (`CompetitionsScheduler`) fires these process-stage actions on each run. They take no per-item parameters - listen to them to run side-effects around the lifecycle batch, or call them directly to force a stage. `mvs_competitions_tick_ran` reports the wall-clock time of the completed tick.

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_activate_scheduled_challenges` | action | Activate challenges whose start time has passed | none | 1.5.0 |
| `mvs_close_challenge_entries` | action | Close entry submission for challenges that hit the entry deadline | none | 1.5.0 |
| `mvs_finalize_expired_challenges` | action | Finalize challenges whose voting window has ended | none | 1.5.0 |
| `mvs_start_registered_tournaments` | action | Start tournaments whose registration window closed | none | 1.5.0 |
| `mvs_resolve_expired_matches` | action | Resolve battle/tournament matches past their deadline | none | 1.5.0 |
| `mvs_competitions_tick_ran` | action | Fires at the end of every scheduler tick | `$timestamp` (int, `time()`) | 1.5.0 |

---

### Challenge Email Templates

Each of these filters lets you override the subject or body of a challenge notification email. All are string filters; return the modified string. The trailing parameters give you the context to personalize the copy.

| Filter | Description | Parameters | Since |
|--------|-------------|------------|-------|
| `mvs_challenge_email_created_subject` | Subject of the "challenge created" email | `$subject` (string), `$challenge_id` (int), `$args` (array), `$created_by` (int) | 1.5.0 |
| `mvs_challenge_email_created_body` | Body of the "challenge created" email | `$body` (string), `$challenge_id` (int), `$args` (array), `$created_by` (int) | 1.5.0 |
| `mvs_challenge_email_entry_subject` | Subject of the "entry received" email | `$subject` (string), `$challenge_id` (int), `$user_id` (int), `$media_id` (int) | 1.5.0 |
| `mvs_challenge_email_entry_body` | Body of the "entry received" email | `$body` (string), `$challenge_id` (int), `$user_id` (int), `$media_id` (int) | 1.5.0 |
| `mvs_challenge_email_winner_subject` | Subject of the "you won" email | `$subject` (string), `$challenge_id` (int), `$user_id` (int), `$rank` (int) | 1.5.0 |
| `mvs_challenge_email_winner_body` | Body of the "you won" email | `$body` (string), `$challenge_id` (int), `$user_id` (int), `$rank` (int) | 1.5.0 |
| `mvs_challenge_email_participant_subject` | Subject of the "challenge ended" email to non-winning participants | `$subject` (string), `$challenge_id` (int), `$user_id` (int) | 1.5.0 |
| `mvs_challenge_email_participant_body` | Body of the "challenge ended" participant email | `$body` (string), `$challenge_id` (int), `$user_id` (int) | 1.5.0 |

---

### `mvs_pro_leaderboard_xp_rows` **(Pro)**

Supplies leaderboard rows for the `xp` metric. The Pro leaderboard renderer defers to this filter (it owns no XP store of its own), so a gamification plugin returns the ranked rows here. Return an array of `['user_id' => int, 'score' => int]` rows.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$rows` | array | Default empty array |
| `$per_page` | int | Row limit |
| `$window` | string | Time window slug (e.g. `all`, `month`, `week`) |

**Returns:** `array`

```php
add_filter( 'mvs_pro_leaderboard_xp_rows', function( array $rows, int $per_page, string $window ) : array {
    return my_gamification_top_xp( $per_page, $window ); // [ ['user_id'=>1,'score'=>500], ... ]
}, 10, 3 );
```

---

## 21. Connectors (Pro)

Pro's import/export connectors (e.g. Flickr) register through `mvs_connectors` and fire import/export actions as media moves in and out.

### `mvs_connectors`

Registers connector instances. Return your connector keyed by its slug.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$connectors` | array | Map of `slug => connector instance`. Default empty |

**Returns:** `array`

```php
add_filter( 'mvs_connectors', function( array $connectors ) : array {
    $connectors['my_service'] = new My_Service_Connector();
    return $connectors;
} );
```

---

### Additional Connector Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_media_imported` | action | A media item was imported from a connector | `$media_id` (int), `$connector` (string, e.g. `flickr`), `$remote_id` (string) | 1.5.0 |
| `mvs_media_exported` | action | A media item was exported to a connector | `$media_id` (int), `$connector` (string), `$remote_id` (string) | 1.5.0 |

---

## 22. Common Recipes

### Recipe 1: Custom upload size per user role

Give editors 50 MB and subscribers 10 MB using `mvs_max_upload_size`.

```php
/**
 * Set upload limits based on user role.
 *
 * @since 1.1
 *
 * @param int $max_size Default max size in bytes.
 * @param int $user_id  Uploading user ID.
 * @return int
 */
add_filter( 'mvs_max_upload_size', function( int $max_size, int $user_id ) {
    if ( user_can( $user_id, 'edit_others_posts' ) ) {
        return 50 * MB_IN_BYTES;
    }
    if ( user_can( $user_id, 'read' ) ) {
        return 10 * MB_IN_BYTES;
    }
    return $max_size;
}, 10, 2 );
```

---

### Recipe 2: Add a custom "Most Commented" sort option

Register the sort option with `mvs_feed_sort_options` and apply the ordering with `mvs_feed_query_args`.

```php
/**
 * Register the "Most Commented" sort option.
 *
 * @since 1.1
 *
 * @param array $options Existing sort options.
 * @return array
 */
add_filter( 'mvs_feed_sort_options', function( array $options ) {
    $options['most_commented'] = __( 'Most Commented', 'my-plugin' );
    return $options;
} );

/**
 * Apply the "most_commented" ordering to the feed query.
 *
 * @since 1.1
 *
 * @param array           $query_args WP_Query arguments.
 * @param WP_REST_Request $request    Incoming REST request.
 * @return array
 */
add_filter( 'mvs_feed_query_args', function( array $query_args, $request ) {
    if ( 'most_commented' === $request->get_param( 'sort' ) ) {
        $query_args['orderby'] = 'comment_count';
        $query_args['order']   = 'DESC';
    }
    return $query_args;
}, 10, 2 );
```

---

### Recipe 3: Skip AI analysis for videos

Use `mvs_should_ai_analyze` to prevent AI processing for video media types.

```php
/**
 * Skip AI analysis for video media.
 *
 * @since 1.1
 *
 * @param bool $should_analyze Whether to run AI analysis.
 * @param int  $media_id       Media post ID.
 * @return bool
 */
add_filter( 'mvs_should_ai_analyze', function( bool $should_analyze, int $media_id ) {
    return 'video' !== get_post_meta( $media_id, '_mvs_media_type', true );
}, 10, 2 );
```

---

### Recipe 4: Send an email for comment notifications

Use `mvs_notification_created` to dispatch an email when a comment notification is created.

```php
/**
 * Email the media owner when someone comments on their upload.
 *
 * @since 1.1
 *
 * @param int    $notification_id Notification record ID.
 * @param int    $user_id         Recipient user ID.
 * @param string $type            Notification type slug.
 * @param int    $actor_id        Triggering user ID.
 * @param int    $media_id        Related media post ID.
 */
add_action( 'mvs_notification_created', function( int $notification_id, int $user_id, string $type, int $actor_id, int $media_id ) {
    if ( 'comment' !== $type ) {
        return;
    }

    $recipient = get_userdata( $user_id );
    $actor     = get_userdata( $actor_id );
    $media_url = get_permalink( $media_id );

    wp_mail(
        $recipient->user_email,
        __( 'New comment on your photo', 'my-plugin' ),
        sprintf(
            /* translators: 1: commenter name, 2: media URL */
            __( '%1$s commented on your photo. View it here: %2$s', 'my-plugin' ),
            $actor->display_name,
            $media_url
        )
    );
}, 10, 5 );
```

---

### Recipe 5: Custom quota from an LMS plugin (Pro)

Use `mvs_pro_quota_source` to assign quota packages based on LearnDash course enrollment.

```php
/**
 * Map LearnDash course enrollment to MVS quota packages.
 *
 * @since 1.1
 *
 * @param array|null $package Current quota package or null for site default.
 * @param int        $user_id User ID.
 * @return array|null
 */
add_filter( 'mvs_pro_quota_source', function( $package, int $user_id ) {
    // Check if the user is enrolled in the "Pro Creator" course (ID: 123).
    if ( function_exists( 'sfwd_lms_has_access' ) && sfwd_lms_has_access( 123, $user_id ) ) {
        return [
            'photo_limit' => 500,
            'video_limit' => 50,
            'label'       => 'Pro Creator',
        ];
    }
    return $package;
}, 10, 2 );
```

---

### Recipe 6: Add custom fields to the media REST response

Use `mvs_media_response` to append custom post meta or computed values to the media API response.

```php
/**
 * Add location and EXIF data to the media REST response.
 *
 * @since 1.0
 *
 * @param array $data     REST response data array.
 * @param int   $media_id Media post ID.
 * @return array
 */
add_filter( 'mvs_media_response', function( array $data, int $media_id ) {
    $data['location']  = get_post_meta( $media_id, '_mvs_location', true );
    $data['camera']    = get_post_meta( $media_id, '_mvs_exif_camera', true );
    $data['focal_len'] = get_post_meta( $media_id, '_mvs_exif_focal_length', true );
    return $data;
}, 10, 2 );
```

---

### Recipe 7: Add a custom thumbnail size

Use `mvs_before_thumbnail_generation` to inject an additional size into the generation pipeline.

```php
/**
 * Generate an 800×600 "hero" thumbnail for every upload.
 *
 * Note: Register the size with add_image_size() so WordPress
 * creates it via multi_resize. Hook into upload_dir or the
 * upload pipeline to inject it into MVS-managed sizes.
 *
 * @since 1.1
 */
add_action( 'init', function() {
    add_image_size( 'mvs-hero', 800, 600, true );
} );

/**
 * Log when MVS thumbnail generation begins.
 *
 * @since 1.1
 *
 * @param int    $media_id  Media post ID.
 * @param string $file_path Absolute path to uploaded file.
 * @param array  $sizes     Size definitions passed to multi_resize.
 */
add_action( 'mvs_before_thumbnail_generation', function( int $media_id, string $file_path, array $sizes ) {
    // Inspect $sizes; the mvs-hero size is included automatically
    // once registered with add_image_size().
    error_log( 'Generating thumbnails for media #' . $media_id );
}, 10, 3 );
```

---

### Recipe 8: Redirect profile URLs to BuddyPress

Use `mvs_user_profile_url` to point all MVS profile links at the BuddyPress member profile.

```php
/**
 * Use the BuddyPress member profile URL for all MVS profile links.
 *
 * @since 1.0
 *
 * @param string $url     Default MVS profile URL.
 * @param int    $user_id User ID.
 * @return string
 */
add_filter( 'mvs_user_profile_url', function( string $url, int $user_id ) {
    if ( function_exists( 'bp_core_get_user_domain' ) ) {
        return bp_core_get_user_domain( $user_id );
    }
    return $url;
}, 10, 2 );
```

---

## 23. Additional Hooks Reference

Every remaining hook fired by Free and Pro, grouped by area. Descriptions come from each hook's own docblock at the call site.

Hooks marked **(Pro)** are fired by WPMediaVerse Pro and never run when only Free is active.

### Account deletion and member data

| Hook | Type | Arguments | Description |
|------|------|-----------|-------------|
| `mvs_account_deletion_cancelled` | action | `$user_id` | Fires when a member cancels their pending account deletion. |
| `mvs_account_deletion_executing` | action | `$user_id` | Fires immediately before a member's account is destroyed. |
| `mvs_account_deletion_grace_days` | filter | `self::DEFAULT_GRACE_DAYS` | Filter the account-deletion grace period, in days. Return 0 to delete immediately on request. |
| `mvs_account_deletion_password_required` | filter | `true, $user->ID` | Filter whether account deletion requires the account password. |
| `mvs_account_deletion_requested` | action | `$user_id, $when` | Fires when a member schedules their own account deletion. |
| `mvs_member_erase_map` | filter | `$map` | Filter the tables erased when a member is removed. The seam Pro uses to register its own member-bearing tables, so Pro data is covered by export, erasure, purge and verification without Pro reimplementing any of them. |
| `mvs_member_purged` | action | `$user_id` | Fires after a member has been purged from the mapped tables. |
| `mvs_member_retain_map` | filter | `$map` | Filter the tables retained (anonymised) when a member is erased. |
| `mvs_member_suspension_changed` | action | `$user_id, $suspended` | Fires when a member's suspension state changes. |
| `mvs_profile_privacy_levels` | filter | `$levels, $author_id, $viewer_id` | Filters the privacy levels a viewer may see in a single-author profile listing. Restore the pre-1.6.0 "everything except private" discoverability: add_filter( 'mvs_profile_privacy_levels', function () { return array( 'public', 'members', 'loggedin', 'friends', 'group', 'custom' ); } ); |

### Native app, auth and REST gating

| Hook | Type | Arguments | Description |
|------|------|-----------|-------------|
| `mvs_app_config_legal` | filter | `$legal` | Filter the legal/safety URLs handed to native clients. A site with no privacy policy set returns null for it, and the client falls back to its own. |
| `mvs_app_connect_bridge` | filter | `$info` | Filter the resolved app-connect bridge for this site. Tests use it to simulate the BuddyNext-active path; a site with an unusual auth topology can point the app at its own door. |
| `mvs_app_connect_schemes` | filter | `array( AppAuthorizeAccess::app_scheme()` | Filter the custom URL schemes the MediaVerse app-connect flow may deliver a credential to. Every scheme here can RECEIVE an Application Password — add a scheme only for an app you ship, never a wildcard. |
| `mvs_app_credential_issued` | action | `$user->ID, $app_id, $app_name` | Fires after a member exchanges their password for an app credential. The credential itself is deliberately NOT passed. |
| `mvs_app_page_ids` | filter | `$ids )` | Filters the pages that render with the plugin's full-bleed app template. A site owner can add a page (e.g. |
| `mvs_app_password_login_enabled` | filter | `$on` | Filter whether members may exchange a WordPress password for an Application Password. |
| `mvs_app_scheme` | filter | `self::DEFAULT_APP_SCHEME` | Filter the mobile app's deep-link scheme. |
| `mvs_app_template` | filter | `$resolved, $post_id` | Filters the fallback app-page template path. |
| `mvs_rest_gate_enabled` | filter | `true` | A per-site kill switch for the REST write gate. These plugins run on thousands of sites and the gate turns some previously-allowed writes into 403s; an owner who hits an unforeseen interaction needs a way out that is not a downgrade. Since 2.1.0. |
| `mvs_rest_gate_denied` | action | `$route, $method, $actor, $reason` | Fires when the write gate denies a request. |
| `mvs_rest_gate_map` | filter | `$map` | Filter the REST write-gate route map. The seam Pro uses to classify its own routes, so Pro never edits a Free file. |
| `mvs_rest_gate_resolve_targets` | filter | `array(), $resolver, $rule, $request` | Resolve targets for a custom gate resolver. Lets Pro (and third parties) supply resolvers for their own routes without touching this file. |
| `mvs_rest_gate_unresolved` | action | `$route, $method, $actor` | See the call site. |
| `mvs_rest_timestamp_keys` | filter | `array( 'created_at', 'updated_at', 'date', 'added_at', 'last_activity_at', 'last_read_at',` | Filters the response keys that receive an ISO-8601 `<key>_gmt` sibling. Pro / add-ons extend this to cover their own UTC timestamp fields. |
| `mvs_user_is_suspended` | filter | `$suspended, $actor` | Filter whether a member is suspended and barred from every write. The seam for third-party moderation/suspension plugins. |

### Upload and media pipeline

| Hook | Type | Arguments | Description |
|------|------|-----------|-------------|
| `mvs_apply_exif_orientation` | filter | `true, $file_path, $mime` | Filters whether EXIF orientation is applied on upload. Escape hatch for sites that already normalise orientation upstream (some CDNs and phone-upload apps do) and would otherwise pay for the re-encode twice. Since 2.3.0. |
| `mvs_filename_strategy_upgrade_default` | filter | `self::DEFAULT_FRESH` | Filter the default filename strategy applied when a site has not explicitly chosen one. Defaults to 'hashed' since 1.6.0. |
| `mvs_hold_uploads_for_moderation` | filter | `false, $user_id` | Filter: hold ALL new uploads for manual moderation before they go live. Default false - members publish immediately (the engagement-first default for this community platform; the only standing limit on a member is their Pro storage/upload quota). |
| `mvs_media_files_orphaned` | action | `$media_id, $orphaned_files` | Fires with every relative file path owned by a media item that is about to be torn down, so a cleanup listener can delete the bytes from disk and cloud asynchronously. |
| `mvs_media_replaced` | action | `$media_id, $file_data, get_current_user_id(), $media_type` | Fires after a file replacement has been fully processed. Use this hook (not mvs_media_uploaded) for replace-specific reactions such as re-generating captions or a poster. |
| `mvs_watermark_stamp_file` | filter | `false, $path, $mime, $user_id` | Stamp the admin watermark into bytes a member is publishing right now. THE RULE, stated once: stamp what the member publishes now; never re-process what is already in the library. |

### Albums and collections

| Hook | Type | Arguments | Description |
|------|------|-----------|-------------|
| `mvs_album_deleted` | action | `$post_id` | Fires after an album's custom-table rows are cleaned on permanent delete. |
| `mvs_album_inherit_privacy` | filter | `true, $album_id, $media_ids` | Filters whether media added to an album inherits the album's privacy. A member who creates a Private album and uploads into it expects the contents to be private. |
| `mvs_collection_deleted` | action | `$post_id` | Fires when a collection is permanently deleted. |
| `mvs_collection_media_ids` | filter | `array_map( 'absint', (array) $media_ids ), $collection_id, (int) $atts['per_page']` | See the call site. |

### Messaging

| Hook | Type | Arguments | Description |
|------|------|-----------|-------------|
| `mvs_dm_unarchive_on_activity` | filter | `true, $conversation_id, $sender_id ) ) { $wpdb->update( $part_table, array( 'is_archived' ` | See the call site. |
| `mvs_group_conversation_created` | action | `$conv_id, $creator_id, $all_ids, $opts` | Fires when a group conversation is created. |
| `mvs_message_content_check` | filter | `true, $content, $sender_id, $conversation_id` | See the call site. |
| `mvs_participant_added` | action | `$conversation_id, $user_id, $role` | Fires when a participant is added to a conversation. |
| `mvs_participant_removed` | action | `$conversation_id, $user_id` | Fires when a participant is removed from a conversation. |

### Templates and theming

| Hook | Type | Arguments | Description |
|------|------|-----------|-------------|
| `mvs_frontend_presence_keep_handles` | filter | `array( 'mvs-rest'` | See the call site. |
| `mvs_login_url` | filter | `$url, $redirect` | Filters the login URL MediaVerse links to across every surface. |
| `mvs_media_alt_text` | filter | `$alt, $media_id` | Filter the resolved alt text for a media image. |
| `mvs_media_single_actions` | action | `$mvs_media_id, $mvs_author_id, $mvs_is_owner` | Owner / add-on actions for the single-media social bar. The canonical media view every feed layout links to, so it is the one place an add-on can surface a per-media owner action (e.g. |
| `mvs_no_sidebar_templates` | filter | `$candidates` | Filters the page-template slugs treated as "no sidebar" for app pages. |
| `mvs_registration_url` | filter | `$url, $redirect` | Filters the registration URL MediaVerse links to. |
| `mvs_seo_title_separator` | filter | `'-'` | Point active SEO plugins at a virtual route's real title + canonical. These routes emit a custom query var (mvs_media_archive / mvs_profile_user / mvs_edit_profile), so WP mis-reads the main query as the blog home and Yoast / Rank Math print the Posts page's title + canonical (e.g. |
| `mvs_settings_render_` | action | `. $section['renderer'], $section` | Fires to render custom settings section content. |
| `mvs_single_media_redirect` | filter | `'', (int) $media['media_id'], (string) $slug` | Let a host redirect single-media URLs somewhere else instead of rendering the standalone page. BuddyNext uses this to send /media/{slug}/ to the activity the media was posted in, so media lives in the community feed rather than as a separate public page. |
| `mvs_suppress_frontend_ui` | filter | `$suppressed` | Whether MediaVerse must NOT paint its own front-end UI on this request. The single source of truth for the frontend-presence policy. |

### Serving, URLs and caching

| Hook | Type | Arguments | Description |
|------|------|-----------|-------------|
| `mvs_serve_expired_public_urls` | filter | `true, $media_id` | See the call site. |
| `mvs_user_avatar_url` | filter | `isset( $args['url'] ) ? (string) $args['url'] : '', $user_id, $size` | MV-scoped avatar seam. Because every get_avatar()/get_avatar_url() call passes through pre_get_avatar_data, hooking this one filter lets BuddyNext (or any integration) override the avatar image for a user everywhere MediaVerse renders it — templates, blocks, and REST payloads alike — without touc... |
| `mvs_viewer_thumbnail_ttl` | filter | `HOUR_IN_SECONDS, $media_id, $viewer_id, $size` | Filter the viewer-aware thumbnail TTL (seconds). Default 1 hour; the /serve endpoint re-checks privacy per request, so this is only a cache horizon, not a credential lifetime. |
| `mvs_viewer_url_ttl` | filter | `HOUR_IN_SECONDS, $media_id, $viewer_id` | Filter the viewer-aware full-file URL TTL (seconds). Default 1 hour; the /serve endpoint re-checks privacy per request, so this is only a cache horizon, not a credential lifetime. |

### Social, moderation and access rules

| Hook | Type | Arguments | Description |
|------|------|-----------|-------------|
| `mvs_explore_tag_cloud_limit` | filter | `20` | How many tags the Explore tag cloud requests. A community with a handful of curated tags and one with hundreds want different numbers, so this is not hardcoded. Clamped to 1-200, the range the `/tags/cloud` endpoint itself accepts. Honoured by both the Free Explore template and the Pro layout partial. Since 2.3.0. |
| `mvs_access_rule_types_ui` | filter | `$rule_types` | Filter the access-rule types offered in the rule-builder UIs. Pro hooks here to add monetization / code-grant rule types. |
| `mvs_ai_cost_per_call` | filter | `(float) get_option( 'mvs_ai_cost_per_call', 0.01 ), $provider_id` | Filter the estimated per-call AI cost used for budget tracking. |
| `mvs_comment_duplicate_window` | filter | `60, $media_id, $user_id` | Filters the duplicate-comment window, in seconds. Wide enough to absorb a double-click or a retry on a slow connection, short enough that deliberately repeating yourself later still works. |
| `mvs_feed_media_ids` | filter | `$int_ids, $request` | Filter the final list of media IDs returned by the feed query. Allows Pro to reorder results (e.g. |
| `mvs_reports_enabled` | filter | `$enabled` | See the call site. |

### BuddyPress integration

| Hook | Type | Arguments | Description |
|------|------|-----------|-------------|
| `mvs_activity_max_tags` | filter | `15` | See the call site. |
| `mvs_activity_media_ids` | filter | `$ids, $activity_id, $activity` | Filter the resolved media IDs for an activity, before linkage rows are written. Composer / group-post integrations should hook here to surface MVS media IDs they've collected from the form, instead of forcing this service to regex-parse saved content. |
| `mvs_object_media_set` | action | `$object_type, $object_id, $media_ids` | Fires after an object's media linkage has been (re)written. |

### Pro

| Hook | Type | Arguments | Description |
|------|------|-----------|-------------|
| `mvs_boosts_store_enqueued` **(Pro)** | action | - | See the call site. |
| `mvs_pro_captions_reaped` **(Pro)** | action | `$media_id` | Fires when the reaper fails a stalled captions job. |
| `mvs_pro_compete_summary_cache_ttl` **(Pro)** | filter | `MINUTE_IN_SECONDS` | Compete summary cache TTL in seconds. Set to 0 to disable caching. |
| `mvs_pro_inject_compete_nav` **(Pro)** | filter | `false ) ) { add_filter( 'wp_nav_menu_items', array( self::class, 'inject_compete_nav_link'` | See the call site. |
| `mvs_pro_leaderboard_cache_ttl` **(Pro)** | filter | `5 * MINUTE_IN_SECONDS` | Leaderboard cache TTL in seconds. Set to 0 to disable caching. |
| `mvs_pro_quota_default_applies_to_unassigned` **(Pro)** | filter | `false, $user_id` | The default package is assigned to NEW users at registration and is NOT applied retroactively to users without an explicit assignment, so marking or changing the default never silently re-quotas existing members (the reported bug). Unassigned = unlimited: quota is a soft blocker for selling premi... |
| `mvs_pro_quota_package_unassigned` **(Pro)** | action | `$user_id, $source` | Fires after a lapsed subscription clears a user's quota package assignment. |
| `mvs_pro_quota_revert_to_default_on_end` **(Pro)** | filter | `false, $user_id, $source ) ) { $default = $this->get_default_package(` | Filters whether a lapsed subscription reverts the user to the default package (pre-1.6.0 behaviour) instead of clearing the assignment. |
| `mvs_pro_quota_widget_visible` **(Pro)** | filter | `$visible, $summary` | Filters whether the quota usage widget renders for the current user. |

### Other

| Hook | Type | Arguments | Description |
|------|------|-----------|-------------|
| `mvs_media_privacy_clamped_by_album` | action | `$mid, $media_privacy, $effective, $album_id` | Fires when an item's privacy is tightened by its album. |

