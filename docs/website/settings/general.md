# General Settings

Access these settings at **Media > Settings > General**.

[screenshot: General settings tab showing all sections]

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

## Direct Messages Section

| Option | Default | Description |
|--------|---------|-------------|
| Enable Direct Messages | Enabled | Allow users to send direct messages to each other. |
| Who Can Send Messages | Members Only | Restrict who can initiate direct messages. |

## Defining the API Key via wp-config.php

Instead of storing your OpenAI API key in the database, define it as a constant:

```php
// wp-config.php
define( 'MVS_OPENAI_API_KEY', 'sk-...' );
```

The settings page shows a notice when this constant is detected and the database field is ignored.
