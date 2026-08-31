# Migration Tools

> Endpoints and hooks marked **(Pro)** require WPMediaVerse Pro.


WPMediaVerse Pro includes WP-CLI commands to import media from three popular WordPress media plugins. Each command reads the source plugin's data and inserts a row into the `wp_mvs_media_index` table (plus its `wp_mvs_media_meta` values), preserving the original upload dates and author attribution.

**WPMediaVerse Pro is required.** The migration commands are not available in the free plugin.

Always run with `--dry-run` first to review what will be imported before committing changes.

> **Updated in 1.2.0:** the **WPMediaVerse > Import** admin page is now a generic shell that hosts per-platform cards (rtMedia, MediaPress, BuddyBoss). Each platform owns its own detection, batch-run, dedup, and progress logic via a `WPMediaVersePro\Integrations\<Platform>\MigrationAdmin` class. Two pre-existing detection bugs were fixed in the same pass: the **Imported** count was always `0` regardless of actual progress, and the MediaPress dedup query was running against an undefined `$wpdb`. All three CLI importers (`import-rtmedia`, `import-mediapress`, `import-buddyboss`) extend the new `AbstractBatchImporter` base class - same flag set, same batched-fetch loop, same dedup behaviour.

---

## wp mvs import-rtmedia

Import media from [rtMedia](https://rtmedia.io/).

The command reads `rt_rtm_media` records (skipping `media_type = 'album'` rows) and their linked WP attachments, and inserts a `wp_mvs_media_index` row per item with the original date and author preserved.

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
| `media_author` | `post_author` on the index row |
| WP attachment `post_date_gmt` | `created_at` on the index row (original date preserved) |
| `media_id` (the WP attachment ID) | Resolved to a file URL; stored as the `file_url` meta |
| `id` | Stored as the `rtmedia_id` meta - this is also the dedup key on re-runs |
| `context` / `context_id` | Stored as `rtmedia_context` / `rtmedia_context_id` meta; a `group` context also writes a `group_id` meta |
| `album_id` | Re-created as a WPMediaVerse album relationship |
| `media_type` | `photo` → `image`, `video` → `video`, `music` → `audio`. Anything else (including `document` and `other`) is decided by reading the file, and falls back to `legacy_document` rather than being labelled a document |
| `privacy` | Mapped to a WPMediaVerse privacy level, then escalated from the linked BuddyPress activity so private/hidden-group media never becomes public |

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

The command queries `mpp-media` custom posts when that post type exists, and otherwise falls back to attachments carrying `_mpp_is_mpp_media` meta (the shape the current wp.org build uses). It reads their gallery associations and inserts a `wp_mvs_media_index` row per item.

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
| `post_author` | `post_author` on the index row |
| `post_date_gmt` | `created_at` on the index row (original date preserved) |
| `_mpp_src` (fallback: `wp_get_attachment_url()` on the item itself) | Stored as the `file_url` meta |
| Post ID | Stored as the `mpp_id` meta - this is also the dedup key on re-runs |
| `_mpp_gallery_id` | Re-created as a WPMediaVerse album |
| `_mpp_media_type` | `photo` → `image`, `video` → `video`, `music`/`audio` → `audio`. `doc` is deliberately not mapped: the file is read instead, so an unopenable `.psd`/`.zip` lands as `legacy_document` rather than a fake document |
| `_mpp_privacy` | `public` → `public`, `loggedin` → `members`, `friends` → `friends`, `onlyme` → `private`. An unknown value falls back to `public` |
| `_mpp_component` / `_mpp_component_id` | Stored as `mpp_component` / `mpp_component_id` meta. A `groups` component forces `group` privacy; a `private` source post status forces `private` |

---

## wp mvs import-buddyboss

Import media from [BuddyBoss Platform](https://www.buddyboss.com/) (BuddyBoss Media component).

The command reads BuddyBoss's own tables - `bp_media`, `bp_document` and `bp_video` (albums live in `bp_media_albums`) - and creates matching `mvs_media` posts. Some BuddyBoss versions store file references as WP attachment IDs; the importer handles both schemas automatically.

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
| `user_id` | `post_author` on the index row |
| `created_at` (legacy schema: `date_created`) | `created_at` on the index row (original date preserved) |
| `file_url`, or `attachment_id` on the legacy schema | Stored as the `file_url` meta |
| `id` | Stored as the `bb_media_id` / `bb_document_id` / `bb_video_id` meta, per source table - this is also the dedup key on re-runs |
| `album_id`, or the `bp_media_context` link table | Re-created as a WPMediaVerse album relationship |
| `group_id` | Stored as the `bb_group_id` meta |
| `privacy` | Mapped from BuddyBoss privacy levels to WPMediaVerse equivalents |

---

## Post-Migration Steps

After running any import command, run the following so every imported index row has its stats row and the schema is at the current version:

```bash
wp mvs reindex
wp mvs migrate
```

Check imported media at **WPMediaVerse > All Media** in wp-admin. Filter by the original author to verify counts match.

![wp-admin Media list filtered by imported author](../images/admin-media-list.png)

---

## What Happens to the Source Files

All three import commands **copy** each source file into `uploads/wpmediaverse/` as a relative path, via the same `UploadService::sideload_external_file()` the plugin uses for its own uploads, and run the normal variant pipeline over the copy. The source attachment is only ever copied - never moved, never deleted - so the source plugin's existing URLs keep working.

The imported copy is what `file_url` ends up pointing at, which is what lets imported media be stored, served, watermarked, and migrated exactly like an uploaded item. If you later change the storage driver in WPMediaVerse Pro (e.g., moving to Amazon S3), use `wp mvs migrate-storage` to batch-transfer those files.

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
- **1** - fatal error (the source plugin's tables were not found)

The importers do not check the Pro licence. The EDD licence buys updates, not access, so an expired licence never blocks an import.
