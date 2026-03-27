# Hooks & Filters

All hooks and filters use the `mvs_` prefix.

---

## Actions

### `mvs_before_media_insert`

Fires before the `mvs_media` post is created during an upload. Use this to validate or modify upload args before database insertion.

**Parameters:** none (fires during UploadService processing)

```php
add_action( 'mvs_before_media_insert', function() {
    // E.g., check quota before insertion.
} );
```

---

### `mvs_media_uploaded`

Fires after a new media post is created, stored, and indexed. This is the primary hook for post-upload processing.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | The new `mvs_media` post ID |

```php
add_action( 'mvs_media_uploaded', function( int $media_id ) {
    // Send a notification, trigger a third-party sync, etc.
} );
```

---

### `mvs_before_upload_form`

Fires before the upload form HTML is rendered (both block and `[mvs_upload]` shortcode). Use this to display quota information or custom notices.

**Parameters:** none

```php
add_action( 'mvs_before_upload_form', function() {
    echo '<p class="mvs-quota-info">You have used X of Y MB.</p>';
} );
```

---

### `mvs_reaction_added`

Fires when a user adds a reaction to a media item.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | Media post ID |
| `$user_id` | int | User who added the reaction |
| `$reaction_type` | string | Reaction type (e.g., `love`, `wow`) |

```php
add_action( 'mvs_reaction_added', function( int $media_id, int $user_id, string $type ) {
    // Send push notification, update leaderboard, etc.
}, 10, 3 );
```

---

### `mvs_comment_created`

Fires when a comment is posted on a media item.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$comment_id` | int | New comment ID |
| `$media_id` | int | Media post ID |
| `$user_id` | int | Commenting user ID |

```php
add_action( 'mvs_comment_created', function( int $comment_id, int $media_id, int $user_id ) {
    // Record engagement metrics.
}, 10, 3 );
```

---

### `mvs_mentions_created`

Fires when @mentions are parsed from a new comment.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$mentioned_user_ids` | int[] | Array of mentioned user IDs |
| `$comment_id` | int | Comment containing the mentions |

```php
add_action( 'mvs_mentions_created', function( array $user_ids, int $comment_id ) {
    // Custom mention notifications.
}, 10, 2 );
```

---

### `mvs_media_moderated`

Fires when a media item's moderation status changes.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | Media post ID |
| `$action` | string | Moderation action: `approved`, `flagged`, `rejected` |

```php
add_action( 'mvs_media_moderated', function( int $media_id, string $action ) {
    if ( 'rejected' === $action ) {
        // Notify the media owner.
    }
}, 10, 2 );
```

---

### `mvs_album_items_added`

Fires when media items are added to an album.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$album_id` | int | Album post ID |
| `$media_ids` | int[] | Array of added media post IDs |
| `$user_id` | int | User who added the items |

---

### `mvs_media_group_assigned`

Fires when a media item is assigned to a BuddyPress group.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$media_id` | int | Media post ID |
| `$group_id` | int | BuddyPress group ID |

---

### `mvs_register_ai_providers`

Fires during plugin init to allow registering custom AI providers.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$ai_service` | `AIService` | The AI service instance |

```php
add_action( 'mvs_register_ai_providers', function( $ai_service ) {
    $ai_service->register_provider( new MyAIProvider() );
} );
```

---

## Filters

### `mvs_upload_args`

Filters the upload arguments before file processing. Return a `WP_Error` to reject the upload.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$upload_args` | array | Array with keys: `mime`, `media_type`, `file_size`, `file_name` |
| `$user_id` | int | Uploading user ID |

**Returns:** `array|WP_Error`

```php
add_filter( 'mvs_upload_args', function( array $args, int $user_id ) {
    // Reject uploads larger than 10 MB for subscribers.
    if ( $args['file_size'] > 10 * MB_IN_BYTES && ! user_can( $user_id, 'upload_files' ) ) {
        return new WP_Error( 'quota_exceeded', 'Upload limit exceeded for your plan.' );
    }
    return $args;
}, 10, 2 );
```

---

### `mvs_privacy_can_view`

Filters the privacy access check result. Return `null` to use the built-in check, `true` to grant access, or `false` to deny.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$result` | bool\|null | Current result (`null` = use default) |
| `$media_id` | int | Media post ID |
| `$user_id` | int | User ID (0 for anonymous) |
| `$privacy` | string | Media privacy level |

**Returns:** `bool|null`

```php
add_filter( 'mvs_privacy_can_view', function( $result, int $media_id, int $user_id, string $privacy ) {
    if ( 'group' === $privacy && my_custom_group_check( $media_id, $user_id ) ) {
        return true;
    }
    return $result;
}, 10, 4 );
```

---

### `mvs_locate_template`

Filters the resolved template path. Use this to provide templates from a custom location.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$template` | string | Resolved template file path |
| `$template_name` | string | Template filename (e.g., `media-single.php`) |
| `$template_path` | string | Subdirectory within the template directory |

**Returns:** `string`

```php
add_filter( 'mvs_locate_template', function( string $template, string $name ) {
    $custom = get_stylesheet_directory() . '/my-theme-media/' . $name;
    return file_exists( $custom ) ? $custom : $template;
}, 10, 2 );
```

---

### `bp_activity_allowed_tags` (BP filter extended by WPMediaVerse)

WPMediaVerse extends this filter to allow its custom HTML attributes through BP kses sanitization. This is handled internally and does not require developer configuration.

---

## BuddyPress-Specific Actions

These actions are fired by `BuddyPressIntegration` and only run when BuddyPress is active.

| Hook | When | Parameters |
|------|------|------------|
| `mvs_bp_upload_activity_recorded` | After upload activity is saved to BP | `$activity_id`, `$media_id` |
| `mvs_bp_comment_activity_recorded` | After comment activity is saved to BP | `$activity_id`, `$comment_id` |
