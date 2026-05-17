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
| `mvs_theme_json_transport` | filter | Free | 1.0 |
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
| `mvs_rest_polling_intervals` | filter | Free | 1.0 |
| `mvs_reaction_added` | action | Free | 1.0 |
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
| `mvs_allowed_message_types` | filter | Free | 1.0 |
| `mvs_show_online_status` | filter | Free | 1.0 |
| `mvs_dm_max_upload_size` | filter | Free | 1.0 |
| `mvs_settings_sidebar_after` | action | Free | 1.0 |
| `mvs_settings_before_save` | action | Free | 1.1 |
| `mvs_dashboard_widgets` | action | Free | 1.1 |
| `mvs_settings_sections` | filter | Free | 1.0 |
| `mvs_settings_group_labels` | filter | Free | 1.0 |
| `mvs_media_flagged` | action | Free | 1.0 |
| `mvs_moderation_changed` | action | Free | 1.0 |
| `mvs_should_ai_analyze` | filter | Free | 1.1 |
| `mvs_ai_result` | filter | Free | 1.1 |
| `mvs_ai_moderation_result` | filter | Free | 1.1 |
| `mvs_openai_api_key` | filter | Free | 1.0 |
| `mvs_media_deleted` | action | Free | 1.0 |
| `mvs_watermark_invalidated` | action | Free | 1.0 |
| `mvs_watermarks_invalidated_all` | action | Free | 1.0 |
| `mvs_storage_driver` | filter | Free | 1.0 |
| `mvs_watermark_enabled` | filter | Free | 1.0 |
| `mvs_watermark_config` | filter | Free | 1.0 |
| `mvs_generate_watermark` | filter | Free | 1.0 |
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
| `mvs_story_created` | action | Free | 1.0 |
| `mvs_story_expired` | action | Free | 1.0 |
| `mvs_privacy_can_view` | filter | Free | 1.0 |
| `mvs_bp_upload_activity_recorded` | action | Free | 1.0 |
| `mvs_bp_comment_activity_recorded` | action | Free | 1.0 |
| `mvs_buddynext_active` | filter | Free | 1.0 |
| `mvs_pro_transcode_complete` | action | Pro | 1.0 |
| `mvs_pro_captions_generated` | action | Pro | 1.0 |
| `mvs_pro_transcode_presets` | filter | Pro | 1.1 |
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
20. [Common Recipes](#20-common-recipes)

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
| `mvs_theme_json_transport` | Filter the transport method used to pass theme data to JS | `$method` (string) | `string` |

---

## 2. Upload Pipeline

### `mvs_media_uploaded`

Fires after a new media post is created, stored, and indexed. This is the primary hook for post-upload processing — use it for gamification, activity feeds, external pipelines, and analytics.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | The new `mvs_media` post ID |
| `$file_data` | array | File data. Keys: `mime`, `file_path`, `file_url`, `file_size`, `file_type`, `file_hash`, `media_type`, `privacy`, `user_id`, `is_first` (1.2.3+) |
| `$user_id` | int | Uploader user ID (1.2.3+) |
| `$media_type` | string | Resolved type: `photo`, `video`, `audio`, `document` (1.2.3+) |

**Backward compatibility:** Listeners registered with `accepted_args=1` or `=2` continue to work unchanged — the new positional args are appended.

```php
/**
 * Award gamification points on every upload, plus a one-time "first upload" badge.
 *
 * @since 1.2.3
 *
 * @param int    $media_id   The new media post ID.
 * @param array  $file_data  File data (now includes user_id and is_first).
 * @param int    $user_id    Uploader user ID.
 * @param string $media_type 'photo' | 'video' | 'audio' | 'document'.
 */
add_action( 'mvs_media_uploaded', function( int $media_id, array $file_data, int $user_id, string $media_type ) {
    wb_gamification_award( $user_id, 'mvs_upload_photo', 10 );

    if ( ! empty( $file_data['is_first'] ) ) {
        wb_gamification_award_badge( $user_id, 'first_upload' );
    }
}, 10, 4 );
```

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
| `mvs_rest_polling_intervals` | Real-time polling intervals in ms | `$intervals` (array) | Plugin defaults | 1.0 |

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

### Additional Social Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_reaction_added` | action | Reaction added to media | `$media_id`, `$user_id`, `$reaction_type` | 1.0 |
| `mvs_favorite_toggled` | action | Favorite added or removed | `$media_id`, `$user_id`, `$action` (`added`/`removed`) | 1.0 |
| `mvs_mentions_created` | action | @mentions parsed from a comment | `$media_id`, `$mentioned_ids`, `$context`, `$comment_id` | 1.0 |
| `mvs_user_unfollowed` | action | Follow relationship removed | `$follower_id`, `$following_id` | 1.0 |
| `mvs_media_shared` | action | Media shared to external platform | `$media_id`, `$user_id`, `$platform` | 1.0 |
| `mvs_report_submitted` | action | Content report filed | `$report_id`, `$reporter_id`, `$target_type`, `$target_id`, `$reason` | 1.0 |
| `mvs_user_blocked` | action | User blocked another user | `$blocker_id`, `$blocked_id` | 1.0 |
| `mvs_tags_merged` | action | Two tags merged | `$source_id`, `$target_id`, `$posts` | 1.0 |
| `mvs_activity_types` | filter | Register activity feed types | `$types` (array) | 1.0 |
| `mvs_activity_max_media` | filter | Max media items per activity post | `$count` (int), default `6` | 1.0 |

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

### Additional Notification Filters

| Filter | Description | Parameters | Since |
|--------|-------------|------------|-------|
| `mvs_notification_data` | Filter notification data array before insert | `$data` (array), `$type` (string) | 1.1 |

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
| `mvs_allowed_message_types` | filter | Allowed message type slugs | `$types` (array) | 1.0 |
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
| `mvs_settings_sections` | filter | Register settings sidebar sections | `$sections` (array) | 1.0 |
| `mvs_settings_group_labels` | filter | Override settings group labels | `$labels` (array) | 1.0 |

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

### Additional AI & Moderation Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_media_flagged` | action | AI flags a media item | `$media_id`, `$result` (array) | 1.0 |
| `mvs_ai_result` | filter | Filter combined AI output | `$output` (array), `$media_id` | 1.1 |
| `mvs_ai_moderation_result` | filter | Filter moderation result before flagging | `$result` (array), `$media_id` | 1.1 |

---

## 11. Storage & Files

### `mvs_storage_driver`

Filters the active storage driver slug. Use this to switch drivers per environment without changing the database setting.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$driver_slug` | string | Current driver slug (e.g., `local`, `s3`, `b2`) |

**Returns:** `string`

```php
/**
 * Override the storage driver from a wp-config.php constant.
 *
 * @since 1.0
 *
 * @param string $driver Current driver slug.
 * @return string
 */
add_filter( 'mvs_storage_driver', function( string $driver ) {
    return defined( 'MVS_STORAGE_DRIVER' ) ? MVS_STORAGE_DRIVER : $driver;
} );
```

---

### `mvs_watermark_config`

Filters the watermark configuration array before the watermark image is composed.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$config` | array | Keys: `position`, `opacity`, `image_url`, `text` |

**Returns:** `array`

```php
/**
 * Force bottom-right watermark at 30% opacity.
 *
 * @since 1.0
 *
 * @param array $config Watermark configuration.
 * @return array
 */
add_filter( 'mvs_watermark_config', function( array $config ) {
    $config['opacity']  = 0.3;
    $config['position'] = 'bottom-right';
    return $config;
} );
```

---

### Additional Storage Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_media_deleted` | action | Media permanently deleted | `$media_id`, `$author_id` | 1.0 |
| `mvs_watermark_invalidated` | action | Single watermark cache cleared | `$media_id` | 1.0 |
| `mvs_watermarks_invalidated_all` | action | All watermark caches cleared | none | 1.0 |
| `mvs_watermark_enabled` | filter | Enable/disable watermark per media item | `$enabled` (bool), `$media_id` | 1.0 |
| `mvs_generate_watermark` | filter | Override watermark generation entirely | `$url`, `$media_id`, `$file_path`, `$file_url`, `$config` | 1.0 |

---

## 12. User Profiles

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

### Additional Access Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_access_rule_created` | action | Access rule created for a media item | `$rule_id`, `$media_id`, `$rule_type`, `$rule_value` | 1.0 |
| `mvs_access_rule_deleted` | action | Access rule removed | `$rule_id`, `$media_id`, `$rule_type` | 1.0 |
| `mvs_access_granted` | action | User granted access to restricted media | `$grant_id`, `$media_id`, `$user_id`, `$source` | 1.0 |
| `mvs_access_revoked` | action | User access revoked | `$media_id`, `$user_id` | 1.0 |
| `mvs_story_created` | action | Story published | `$media_id`, `$user_id`, `$expires_at` (signature changed in 1.2.3) | 1.0 |
| `mvs_album_items_added` | action | Media added to an album | `$album_id`, `$actor_id`, `$media_ids`, `$added` (signature changed in 1.2.3) | 1.0 |
| `mvs_story_expired` | action | Story expired and removed | `$media_id` | 1.0 |

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

### Additional BuddyPress Hooks

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_bp_upload_activity_recorded` | action | Upload activity saved to BP activity stream | `$activity_id`, `$media_id` | 1.0 |
| `mvs_bp_comment_activity_recorded` | action | Comment activity saved to BP activity stream | `$activity_id`, `$comment_id` | 1.0 |

---

## 15. Video Processing (Pro)

### `mvs_pro_transcode_complete` **(Pro)**

Fires when all transcode presets for a video have finished processing.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | Media post ID |
| `$results` | array | Per-preset result map |
| `$final_status` | string | Overall status: `complete`, `partial`, `failed` |

```php
/**
 * Notify the uploader when video transcoding is done.
 *
 * @since 1.0
 *
 * @param int    $media_id     Media post ID.
 * @param array  $results      Per-preset results.
 * @param string $final_status Overall transcode status.
 */
add_action( 'mvs_pro_transcode_complete', function( int $media_id, array $results, string $final_status ) {
    if ( 'complete' === $final_status ) {
        $author_id = (int) get_post_field( 'post_author', $media_id );
        my_send_transcode_ready_email( $author_id, $media_id );
    }
}, 10, 3 );
```

---

### `mvs_pro_transcode_presets` **(Pro)** **(New in 1.1)**

Filters the transcode quality presets before encoding starts. Use this to add, remove, or modify quality levels.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$presets` | array | Array of preset definitions (slug, bitrate, resolution) |
| `$media_id` | int | Media post ID |

**Returns:** `array`

```php
/**
 * Remove the 4K preset to save storage costs.
 *
 * @since 1.1
 *
 * @param array $presets  Transcode preset definitions.
 * @param int   $media_id Media post ID.
 * @return array
 */
add_filter( 'mvs_pro_transcode_presets', function( array $presets, int $media_id ) {
    return array_filter( $presets, fn( $p ) => '2160p' !== $p['slug'] );
}, 10, 2 );
```

---

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
| `mvs_challenge_winner_named` | action | Fires once per top-3 rank when a challenge is finalized — fires before `mvs_challenge_finalized` | `$challenge_id`, `$user_id`, `$rank` (1, 2, or 3) | 1.2.3 |
| `mvs_challenge_finalized` | action | Voting ends, winners determined | `$challenge_id`, `$results` | 1.0 |

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

---

### Tournaments

| Hook | Type | Description | Parameters | Since |
|------|------|-------------|------------|-------|
| `mvs_tournament_created` | action | Tournament created | `$competition_id`, `$args`, `$created_by` | 1.0 |
| `mvs_tournament_started` | action | Registration closes, bracket generated | `$tournament_id` | 1.0 |
| `mvs_tournament_match_resolved` | action | Single bracket match resolved | `$match_id`, `$winner_id` | 1.0 |
| `mvs_tournament_finalized` | action | Tournament ends, champion crowned | `$competition_id`, `$champion_id` | 1.0 |

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

## 20. Common Recipes

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
