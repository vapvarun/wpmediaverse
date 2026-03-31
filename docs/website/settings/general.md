# General Settings

Access these settings at **Media > Settings > General**.

![General settings tab](../images/admin-settings-general.png)

## General Section

| Option | Default | Description |
|--------|---------|-------------|
| Max Upload Size | 100 MB | Maximum file size per upload. Enter value in MB. The plugin reads this setting server-side — WordPress's `upload_max_filesize` PHP ini value also applies. |
| Allowed File Types | image/jpeg, image/png, image/gif, image/webp, video/mp4, video/webm, audio/mpeg, audio/ogg | Comma-separated list of allowed MIME types. |
| Default Privacy Level | Public | The privacy level assigned to new uploads when the user does not choose one. Options: Public, Members Only, Private. |
| Duplicate Detection | Warn (allow upload) | What to do when a user uploads a file with a SHA-256 hash matching an existing media item. Options: Warn (allow upload), Skip (reject duplicate), Allow (no check). |
| Strip EXIF Data | Enabled | When enabled, removes GPS coordinates and device information from uploaded JPEG images before storing them. |

## Storage Section

| Option | Default | Description |
|--------|---------|-------------|
| Storage Driver | Local (WordPress uploads) | Where uploaded files are stored. Local stores files in `wp-content/uploads/wpmediaverse/YYYY/MM/`. Amazon S3 and BunnyCDN require WPMediaVerse Pro. |
| Signed URL Expiry | 3600 (1 hour) | How long temporary signed URLs remain valid for private media files. Set in seconds. |

> **Changing the storage driver** only affects new uploads. Existing files remain in their original storage location. A notice appears after saving if the driver is changed.

## Pages Section

Assign existing WordPress pages to WPMediaVerse page roles. WPMediaVerse uses these assignments to generate links in the navigation, chat panel, and notification emails.

| Option | Option Key | Description |
|--------|-----------|-------------|
| Dashboard Page | `mvs_page_dashboard` | The main logged-in user dashboard. Displays the follow feed, quick upload, and activity summary. |
| Explore Page | `mvs_page_explore` | The public media browse archive. Used as the landing page for non-logged-in visitors. |
| Upload Page | `mvs_page_upload` | The dedicated upload form page. Linked from the dashboard and the navigation bar. |

Create a standard WordPress page for each role, then select it from the corresponding dropdown. Each page should contain only the matching WPMediaVerse shortcode (`[mvs_dashboard]`, `[mvs_explore]`, `[mvs_upload]`) with no other content.

> If a page assignment is empty, WPMediaVerse falls back to the site home URL for that link. Set all three pages to avoid broken navigation.
