# Migration Tools

> Endpoints and hooks marked **(Pro)** require WPMediaVerse Pro.


WPMediaVerse Pro includes WP-CLI commands to import media from three popular WordPress media plugins. Each command reads the source plugin's data, maps it to WPMediaVerse's `mvs_media` post type and `wp_mvs_media_index` table, and preserves the original upload dates, author attribution, and file URLs.

**WPMediaVerse Pro is required.** The migration commands are not available in the free plugin.

Always run with `--dry-run` first to review what will be imported before committing changes.

> **Updated in 1.2.0:** the **Tools > Migration** admin page is now a generic shell that hosts per-platform cards (rtMedia, MediaPress, BuddyBoss). Each platform owns its own detection, batch-run, dedup, and progress logic via a `WPMediaVersePro\Integrations\<Platform>\MigrationAdmin` class. Two pre-existing detection bugs were fixed in the same pass: the **Imported** count was always `0` regardless of actual progress, and the MediaPress dedup query was running against an undefined `$wpdb`. All three CLI importers (`import-rtmedia`, `import-mediapress`, `import-buddyboss`) extend the new `AbstractBatchImporter` base class - same flag set, same batched-fetch loop, same dedup behaviour.

---

## wp mvs import-rtmedia

Import media from [rtMedia](https://rtmedia.io/).

The command reads `rt_rtm_media` table records and their associated meta, creates `mvs_media` posts with matching post dates and authors, and inserts index rows.

```bash
# Preview the import without making any changes.
wp mvs import-rtmedia --dry-run

# Run the full import.
wp mvs import-rtmedia

# Use a smaller batch size to reduce memory usage on large sites.
wp mvs import-rtmedia --batch-size=50
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | off | Preview counts and field mapping; do not write any data |
| `--batch-size=<n>` | `50` | Number of rtMedia records to process per batch |
| `--skip-albums` | off | Skip rtMedia album import (albums are recreated as WPMediaVerse albums by default) |
| `--offset=<n>` | `0` | Start from a specific offset (for resuming) |

**What is mapped:**

| rtMedia field | WPMediaVerse destination |
|---------------|--------------------------|
| `media_author` | `post_author` on `mvs_media` post |
| `media_date` | `post_date` on `mvs_media` post (original date preserved) |
| `attachment_id` | Resolved to file URL; stored in `mvs_media_index.file_url` |
| `activity_id` | Stored as `_mvs_source_activity_id` post meta for reference |
| Album membership | Re-created as WPMediaVerse album relationships |
| Media type | Mapped to `image`, `video`, or `audio` based on MIME type |

**Dry-run output example:**

```
Found 842 rtMedia records.
842 would be created (0 skipped as duplicates).
Albums: 14 would be created.
Run without --dry-run to execute.
```

---

## wp mvs import-mediapress

Import media from [MediaPress](https://buddydev.com/mediapress/).

The command queries `mpp-media` custom posts (falling back to attachments carrying `_mpp_is_mpp_media` meta on older installs) and their gallery associations, then creates matching `mvs_media` posts and index rows.

```bash
# Preview the import.
wp mvs import-mediapress --dry-run

# Run the full import.
wp mvs import-mediapress

# Resume from a specific offset on a large site.
wp mvs import-mediapress --offset=200
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | off | Preview counts; do not write any data |
| `--batch-size=<n>` | `50` | Number of MediaPress media posts to process per batch |
| `--skip-albums` | off | Skip gallery/album import |
| `--offset=<n>` | `0` | Start from a specific offset (for resuming) |

**What is mapped:**

| MediaPress field | WPMediaVerse destination |
|-----------------|--------------------------|
| `post_author` | `post_author` on `mvs_media` post |
| `post_date` | `post_date` on `mvs_media` post (original date preserved) |
| `_mpp_media_manager_id` | Resolved to file URL via attachment |
| Gallery | Re-created as WPMediaVerse album |
| `mpp_media_type` | Mapped to `image`, `video`, or `audio` |
| Privacy (gallery-level) | Mapped to WPMediaVerse privacy: `mpp-public` → `public`, `mpp-members` → `members`, `mpp-friends` → `followers`, `mpp-private` → `private` |

---

## wp mvs import-buddyboss

Import media from [BuddyBoss Platform](https://www.buddyboss.com/) (BuddyBoss Media component).

The command reads BuddyBoss media entries stored as custom posts and creates matching `mvs_media` posts.

```bash
# Preview the import.
wp mvs import-buddyboss --dry-run

# Run the full import.
wp mvs import-buddyboss

# Import only from a specific BuddyBoss source table.
wp mvs import-buddyboss --source=video
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | off | Preview counts; do not write any data |
| `--batch-size=<n>` | `50` | Number of BuddyBoss media posts to process per batch |
| `--source=<source>` | `all` | Which table to import from: `media`, `document`, `video`, or `all` |
| `--skip-albums` | off | Skip album import (BuddyBoss albums are recreated as WPMediaVerse albums by default) |
| `--offset=<n>` | `0` | Start from a specific offset (for resuming) |

**What is mapped:**

| BuddyBoss field | WPMediaVerse destination |
|----------------|--------------------------|
| `post_author` | `post_author` on `mvs_media` post |
| `post_date` | `post_date` on `mvs_media` post (original date preserved) |
| `bb_media_id` attachment | Resolved to file URL; stored in `mvs_media_index.file_url` |
| Album membership | Re-created as WPMediaVerse album relationships |
| Group ID (`_bb_media_group_id`) | Stored as `_mvs_bp_group_id` post meta for BuddyPress integration |
| Privacy | Mapped from BuddyBoss privacy levels to WPMediaVerse equivalents |

---

## Post-Migration Steps

After running any import command, run the following to ensure the index is consistent and all view stats are initialised:

```bash
wp mvs reindex
wp mvs migrate
```

Check imported media at **Media > All Media** in wp-admin. Filter by the original author to verify counts match.

![wp-admin Media list filtered by imported author](../images/admin-media-list.png)

---

## Preserving Original File URLs

All three import commands store the original file URL in `mvs_media_index.file_url` without moving or copying the files. Your existing URLs continue to work. If you later change the storage driver in WPMediaVerse Pro (e.g., moving to Amazon S3), use `wp mvs migrate-storage` to batch-transfer files.

---

## Handling Import Errors

If a batch fails, the command outputs the failing record IDs and continues. Review errors with:

```bash
wp mvs import-rtmedia 2>&1 | tee import-log.txt
```

Check `wp-content/debug.log` for detailed error traces when `WP_DEBUG_LOG` is enabled:

```php
// wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

## Exit Codes

All import commands follow WP-CLI conventions:
- **0** - completed (including dry-run)
- **1** - fatal error (source plugin tables not found, Pro license inactive, etc.)
