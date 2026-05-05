# WP-CLI Commands

> Endpoints and hooks marked **(Pro)** require WPMediaVerse Pro.


WPMediaVerse registers all its commands under the `wp mvs` namespace.

## wp mvs stats

Display plugin statistics in a table.

```bash
wp mvs stats
```

**Output:**

```
+-------------------+-------+
| Metric            | Value |
+-------------------+-------+
| Published Media   | 342   |
| Albums            | 18    |
| Total Views       | 9841  |
| Total Reactions   | 512   |
| Total Favorites   | 203   |
| DB Version        | 5     |
| Plugin Version    | 1.0.0 |
+-------------------+-------+
```

---

## wp mvs migrate

Run or check database migrations.

```bash
# Run all pending migrations.
wp mvs migrate

# Check if migrations are needed (dry run).
wp mvs migrate --check
```

**Options:**

| Option | Description |
|--------|-------------|
| `--check` | Only check if migrations are needed; do not run them |

**Examples:**

```bash
# Typical update workflow:
wp mvs migrate --check
wp mvs migrate
```

---

## wp mvs prune-views

Delete old per-view tracking records to keep the `wp_mvs_media_views` table from growing indefinitely.

```bash
# Prune views older than 90 days (default).
wp mvs prune-views

# Prune views older than 30 days.
wp mvs prune-views --days=30

# Preview how many rows would be deleted.
wp mvs prune-views --dry-run

# Combine options.
wp mvs prune-views --days=30 --dry-run
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--days=<days>` | `90` | Retain records newer than this many days |
| `--dry-run` | off | Show count without deleting |

---

## wp mvs cleanup-expired

Remove expired custom access grants from the `wp_mvs_access_grants` table.

```bash
# Cleanup with default batch size (100 grants at a time).
wp mvs cleanup-expired

# Use a larger batch size for faster cleanup on large sites.
wp mvs cleanup-expired --batch-size=500
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--batch-size=<size>` | `100` | Number of grants to process per batch |

---

## wp mvs reindex

Rebuild the `wp_mvs_media_index` table from all published `mvs_media` posts. Run this if the index becomes out of sync (e.g., after a direct database import or migration from another plugin).

```bash
# Reindex with default batch size.
wp mvs reindex

# Use smaller batches to reduce memory usage on large sites.
wp mvs reindex --batch-size=50
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--batch-size=<size>` | `100` | Number of posts to process per batch |

The command outputs progress as it processes each batch and shows a final count of indexed items.

---

## wp mvs cache-flush

Flush all WPMediaVerse caches — both object cache groups and plugin-managed transients. Run this after making direct database changes or when stale data is suspected.

```bash
wp mvs cache-flush
```

**Output:**

```
Success: WPMediaVerse caches flushed.
```

---

## wp mvs moderation-stats

Display the current moderation queue statistics broken down by status.

```bash
wp mvs moderation-stats
```

**Output:**

```
+----------+-------+
| Status   | Count |
+----------+-------+
| Pending  | 14    |
| Approved | 3281  |
| Rejected | 57    |
+----------+-------+
```

---

## wp mvs generate-video-thumbnails

Generate poster frames for video media items that don't have a `thumb_large` meta entry yet. Uses ffmpeg to extract a frame at the second mark of each video. Idempotent — videos that already have a poster are skipped unless `--force` is passed.

Recommended after upgrading to 1.2.0 to backfill posters for any video uploaded before the fix. Without a poster, video media renders a black frame in the lightbox until playback starts; with a poster, the first frame appears immediately.

```bash
# Backfill missing posters (skips videos that already have one).
wp mvs generate-video-thumbnails

# Dry-run to count what would be processed.
wp mvs generate-video-thumbnails --dry-run

# Re-extract posters even when they already exist (e.g. after switching ffmpeg builds).
wp mvs generate-video-thumbnails --force
```

**Requirements:**

- ffmpeg available on the host system (check with `which ffmpeg`).

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--force` | off | Overwrite existing `thumb_large` posters |
| `--dry-run` | off | Count eligible videos without writing to disk or DB |

Added in 1.2.0.

---

## wp mvs backfill-activity-thumbnails

Backfill BuddyPress activity thumbnails for media items that were imported without them (e.g., bulk imports or migrations from another plugin). Processes items in batches to avoid memory exhaustion.

```bash
# Backfill with default batch size.
wp mvs backfill-activity-thumbnails

# Use a smaller batch on memory-constrained hosts.
wp mvs backfill-activity-thumbnails --batch-size=25

# Preview what would be updated without writing to the database.
wp mvs backfill-activity-thumbnails --dry-run
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--batch-size=<size>` | `50` | Number of activity records to process per batch |
| `--dry-run` | off | Count eligible records without updating them |

---

## Scheduling Maintenance with WP-CLI Cron

Add these commands to your server cron for automated maintenance:

```bash
# /etc/cron.d/wpmediaverse

# Prune old views weekly.
0 2 * * 0 www-data wp --path=/var/www/html mvs prune-views --days=90

# Cleanup expired access grants daily.
0 3 * * * www-data wp --path=/var/www/html mvs cleanup-expired
```

## Exit Codes

All commands follow WP-CLI conventions:
- **0** — success
- **1** — error (WP_CLI::error())
