# Media Upload

WPMediaVerse provides a frontend upload form powered by the WordPress Interactivity API. Users upload files directly from the front end — no admin access required.

[screenshot: Frontend upload form with drag-and-drop zone, title field, and privacy dropdown]

## Adding the Upload Form

**Gutenberg Block:** Add the **WPMediaVerse: Media Upload** block to any page or post.

**Shortcode:**
```
[mvs_upload]
[mvs_upload max_files="5" show_privacy="true"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `max_files` | `10` | Maximum number of files the user can upload at once |
| `show_privacy` | `true` | Whether to show the privacy level selector |

## Upload Validation

Every upload goes through these checks in order:

1. **MIME type check** — file content is inspected (not just the extension) against your allowed types list.
2. **File size check** — measured server-side against `mvs_max_upload_size` (default 100 MB).
3. **Extension block list** — blocks PHP, shell, and other executable extensions even if the MIME passes.
4. **Double extension block** — rejects filenames like `photo.php.jpg`.
5. **Duplicate detection** — computes a SHA-256 hash and compares against existing uploads. Behavior depends on your **Duplicate Detection** setting (warn, skip, or allow).

## EXIF Stripping

When **Strip EXIF Data** is enabled (default), GPS coordinates and device metadata are removed from JPEG images before storage. Non-GPS EXIF data (camera model, focal length) is retained.

## Storage Path

Files are stored at:

```
wp-content/uploads/wpmediaverse/YYYY/MM/filename.ext
```

WordPress's `wp_unique_filename()` prevents filename collisions.

## Programmatic Upload

Use the `UploadService` to upload files from code:

```php
$upload_service = WPMediaVerse\Core\Plugin::container()->get( 'upload' );

$result = $upload_service->handle(
    $_FILES['my_file'],
    get_current_user_id(),
    array(
        'title'       => 'My File',
        'privacy'     => 'public',
        'description' => 'Optional description',
    )
);

if ( is_wp_error( $result ) ) {
    // Handle error.
} else {
    $media_id = $result; // int — new mvs_media post ID.
}
```

## Actions Fired During Upload

- `mvs_before_media_insert` — fires before the `mvs_media` post is created.
- `mvs_before_upload_form` — fires before the upload form HTML is rendered (used by Pro for quota display).
- `mvs_media_uploaded` — fires after the post is created and indexed. Passes `$media_id` (int).

## Media Post Meta

Each `mvs_media` post stores:

| Meta Key | Type | Description |
|----------|------|-------------|
| `_mvs_file_url` | string | Public URL of the stored file |
| `_mvs_file_path` | string | Relative storage path |
| `_mvs_media_type` | string | `image`, `video`, or `audio` |
| `_mvs_file_type` | string | Full MIME type (e.g., `image/jpeg`) |
| `_mvs_file_size` | int | File size in bytes |
| `_mvs_privacy` | string | Privacy level (see Privacy & Access Control) |
| `_mvs_sha256` | string | SHA-256 hash for duplicate detection |
| `_mvs_group_id` | int | BuddyPress group ID (for group privacy) |
