# Your First Upload

Upload your first photo in two minutes - drag, drop, set privacy, done. Here is exactly how it works.

## Option 1: Use the Upload Block or Shortcode

Add the upload form to any page using either the Gutenberg block or shortcode.

**In the Block Editor:** Add the **WPMediaVerse: Media Upload** block to your page.

**In the Classic Editor or any text area:**
```
[mvs_upload]
```

![Frontend media upload form with drag-and-drop area and privacy selector](../images/upload-page.png)

## Option 2: Upload via REST API

Use the REST endpoint directly (for custom integrations):

```bash
curl -X POST https://yoursite.com/wp-json/mvs/v1/media \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -F "file=@/path/to/image.jpg" \
  -F "title=My First Upload" \
  -F "privacy=public"
```

## The Upload Process

When you upload a file, WPMediaVerse:

1. **Validates** the MIME type against your allowed file types list.
2. **Checks the file size** against your configured maximum (default: 100 MB).
3. **Scans for duplicate files** using SHA-256 hash comparison.
4. **Strips EXIF GPS data** from images (if enabled - on by default).
5. **Stores the file** using your configured storage driver (local by default).
6. **Creates a record in the `mvs_media_index` table** with the title, privacy level, and file metadata (media is not stored as a WordPress post).
7. **Runs AI analysis** if auto-analyze is enabled (requires OpenAI API key).
8. **Runs AI moderation** if auto-moderate is enabled.
9. **Records BuddyPress activity** if BuddyPress is active.

## Supported File Types

By default, WPMediaVerse accepts:

| Type | Formats |
|------|---------|
| Images | JPEG, PNG, GIF, WebP |
| Video | MP4, WebM |
| Audio | MP3 (MPEG), OGG |

You can customize allowed file types in **Media > Settings > General**.

## Setting Privacy on Upload

The upload form offers these privacy levels:

| Level | Who Can See It |
|-------|---------------|
| Public | Everyone, including logged-out visitors |
| Members Only | Any logged-in WordPress user |
| Friends | BuddyPress friends of the uploader (requires BuddyPress) |
| Group | Members of a specific BuddyPress group (requires BuddyPress) |
| Private | Only the uploader and administrators |
| Custom | A specific list of user IDs (managed via API or access rules) |

The default privacy level is set in **Media > Settings > General > Default Privacy Level**.

## After Uploading

Your uploaded media appears:
- On the media archive page (`/wp-json/mvs/v1/media` in the API)
- In the **Media > All Media** list in your admin dashboard
- In the **Media** tab on your BuddyPress profile (if BuddyPress is active)
- In your media dashboard (use `[mvs_dashboard]` shortcode)
