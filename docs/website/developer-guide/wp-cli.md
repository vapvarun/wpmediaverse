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

Flush all WPMediaVerse caches - both object cache groups and plugin-managed transients. Run this after making direct database changes or when stale data is suspected.

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

Generate poster frames for video media items that don't have a `thumb_large` meta entry yet. Uses ffmpeg to extract a frame at the second mark of each video. Idempotent - videos that already have a poster are skipped unless `--force` is passed.

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

Backfill BuddyPress activity thumbnails for media items that were imported without them (e.g., bulk imports or migrations from another plugin). Modifies the `bp_activity` table directly, so take a database backup before running without `--dry-run`. Requires the BuddyPress Activity component to be active; the command exits early otherwise.

```bash
# Backfill every migrated source.
wp mvs backfill-activity-thumbnails

# Only backfill activities imported from rtMedia.
wp mvs backfill-activity-thumbnails --source=rtmedia

# Preview what would be updated without writing to the database.
wp mvs backfill-activity-thumbnails --dry-run
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--source=<source>` | `all` | Only backfill activities from a specific migration source: `rtmedia`, `mediapress`, `buddyboss`, or `all` |
| `--dry-run` | off | Count eligible records without updating them |

---

## wp mvs sync-activity-privacy

Recompute the BuddyPress activity `hide_sitewide` flag for every existing media activity. Walks each `mvs_media_upload` activity plus every `activity_update` carrying `_mvs_media_ids` meta and recomputes `hide_sitewide` from the linked media's effective privacy (media + parent album, most-restrictive wins). Run once after upgrading to bring legacy activity rows in line with the privacy-sync behaviour. Idempotent - re-runs only touch rows that have actually drifted.

Requires BuddyPress with the Activity component active; the command exits early otherwise.

```bash
# Preview which activity rows would change.
wp mvs sync-activity-privacy --dry-run

# Apply the recomputed flags.
wp mvs sync-activity-privacy
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | off | Show what would change without writing to the database |

---

## wp mvs relocalize-private

Heal pre-1.4.0 non-public media rows whose stored URL meta still points at a prior cloud bucket. When media uploaded to a cloud driver (S3 / BunnyCDN / R2 / DigitalOcean Spaces) was later switched to a restricted privacy level (members / friends / private / group / custom), the URL meta was not relocalized, so `SignedUrlService` 403s on every read because the cloud URL fails its `wpmediaverse/` containment check. The 1.4.0 listener fixes this going forward; this command heals older rows (Basecamp #9925110293).

Safe to re-run - rows that already have local URLs are skipped.

Added in 1.4.0.

```bash
# Report what would change without writing.
wp mvs relocalize-private --dry-run

# Heal all affected rows.
wp mvs relocalize-private

# Inspect a single media row.
wp mvs relocalize-private --media-id=47
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | off | Report what would change without writing |
| `--media-id=<id>` | 0 (all) | Only inspect this single media row |
| `--limit=<n>` | 0 (all) | Stop after inspecting this many candidate rows |

---

## wp mvs migrate-storage

Migrate every stored media file from one storage driver to another. Idempotent - re-running skips media already present on the destination. Only public media is migrated by default (non-public media stays local to preserve privacy). The active `mvs_storage_driver` option is NOT flipped automatically; flip it manually via `wp option update` once the run completes cleanly.

Added in 1.3.0.

```bash
# Dry run: report what would move without touching files.
wp mvs migrate-storage --from=local --to=s3 --dry-run

# Migrate but keep source copies for a verification window.
wp mvs migrate-storage --from=local --to=s3 --keep-source

# Full migration.
wp mvs migrate-storage --from=local --to=s3

# Migrate a single media row for testing.
wp mvs migrate-storage --from=local --to=s3 --media-id=42
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--from=<driver>` | required | Source driver slug: `local`, `s3`, `bunnycdn` |
| `--to=<driver>` | required | Destination driver slug. Must differ from `--from` |
| `--dry-run` | off | Walk the media list and report without transferring files |
| `--keep-source` | off | Skip post-verify source-side deletion |
| `--media-id=<id>` | 0 (all) | Migrate one specific media row |
| `--limit=<n>` | 0 (all) | Stop after this many rows |
| `--include-non-public` | off | Also migrate non-public media (only when cloud bucket is private) |

**Note:** `s3` and `bunnycdn` drivers require WPMediaVerse Pro.

---

## wp mvs cloud-thumbs-backfill

Push thumbnail variants to the active cloud driver for images uploaded before 1.3.0 (when cloud-side thumbnails landed). Downloads each original from cloud, regenerates three size variants locally, and uploads each variant. Only processes public images whose thumbnail meta still points at a local URL.

Run `wp mvs migrate-storage` first so original files are already on cloud.

Added in 1.3.0.

```bash
# Dry run.
wp mvs cloud-thumbs-backfill --dry-run

# Process all eligible images.
wp mvs cloud-thumbs-backfill

# Limit for testing.
wp mvs cloud-thumbs-backfill --limit=100

# Single media row.
wp mvs cloud-thumbs-backfill --media-id=42
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | off | Report what would migrate without downloading or uploading |
| `--media-id=<id>` | 0 (all) | Process a single media row |
| `--limit=<n>` | 0 (all) | Stop after this many rows |

**Requirements:** Active driver must not be `local`. Pro plugin required for `s3`/`bunnycdn`.

---

## wp mvs cleanup-local

Delete local copies of media that are verified on the active cloud driver. Use after a `wp mvs migrate-storage --keep-source` run once the cloud driver has been confirmed to serve all media correctly.

**Irreversible.** The command verifies each file exists on cloud before deleting the local copy; a failed verification keeps the local file. Only public media is processed.

Added in 1.3.0.

```bash
# Preview what would be deleted.
wp mvs cleanup-local --dry-run

# Delete originals and thumbnail variants.
wp mvs cleanup-local

# Keep local thumbnail variants; delete originals only.
wp mvs cleanup-local --keep-thumbs

# Single media row.
wp mvs cleanup-local --media-id=42
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | off | Walk the candidate list and report without deleting |
| `--media-id=<id>` | 0 (all) | Process a single media row |
| `--limit=<n>` | 0 (all) | Stop after this many rows |
| `--keep-thumbs` | off | Delete only the original file; keep local thumbnail variants |

---

## wp mvs optimize

Optimize a single media row: re-encode the original and emit WebP (and optionally AVIF) siblings. Resume-safe via the `_mvs_optimized_at` meta sentinel - already-optimized media is skipped unless `--force` is passed.

Added in 1.3.0.

```bash
wp mvs optimize 42
wp mvs optimize 42 --include-variants --force
```

**Synopsis:**

```
wp mvs optimize <media_id> [--include-variants] [--force]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `<media_id>` | required | Media row ID to optimize |
| `--include-variants` | off | Also re-process every thumbnail variant |
| `--force` | off | Re-run even when `_mvs_optimized_at` is already set |

**Output:** Reports bytes before and after, percent saved, and variant count.

---

## wp mvs optimize-bulk

Bulk-optimize every image in the library matching the given filters. Resume-safe: rows already marked with `_mvs_optimized_at` are skipped automatically. Rows that previously failed (marked `_mvs_optimize_failed`) are also skipped unless `--include-failed` is passed.

Added in 1.3.0.

```bash
# Process all unoptimized images, including variants.
wp mvs optimize-bulk --include-variants

# JPEG only, dry run.
wp mvs optimize-bulk --mime=image/jpeg --dry-run

# Cap to first 500 rows.
wp mvs optimize-bulk --limit=500

# Skip first 200 rows (manual offset resume).
wp mvs optimize-bulk --offset=200
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--limit=<n>` | 0 (all) | Cap rows processed |
| `--offset=<n>` | 0 | Skip the first N rows (manual resume) |
| `--mime=<types>` | all images | Comma-separated MIME types to include (e.g. `image/jpeg,image/png`) |
| `--media-type=<type>` | all | Restrict to one `media_type` column value (e.g. `photo`) |
| `--include-variants` | off | Process thumbnail variants alongside the original |
| `--dry-run` | off | Report what would be processed without writing |
| `--include-failed` | off | Re-process rows previously marked as failed |

**Output:** Reports processed/skipped/failed counts and total bytes saved.

---

## wp mvs backfill_ai

Run AI description + tagging on image media that was uploaded before AI moderation was enabled (or before `mvs_ai_auto_describe` / `mvs_ai_auto_tag` were turned on). Only images with no `ai_status` meta are picked up unless `--force` is passed. Each match is queued to the same async Action Scheduler job that runs on upload (`mvs_ai_process_media`) unless `--sync` is passed to process inline.

Added in 1.6.0.

```bash
# Preview how many images would be processed.
wp mvs backfill_ai --dry-run

# Cap the run for a first pass.
wp mvs backfill_ai --limit=200

# Process inline instead of queueing (respects the AI budget cap per item).
wp mvs backfill_ai --sync

# Reprocess every image, including ones already attempted.
wp mvs backfill_ai --force
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--limit=<n>` | 0 (all) | Max media to process |
| `--sync` | off | Process inline instead of queueing the async job |
| `--dry-run` | off | List how many media would be processed without doing it |
| `--force` | off | Reprocess all image media, including ones already attempted |

**Output:** Reports queued, processed inline, and failed counts.

---

## wp mvs repair-storage

Heal two pre-1.8.0 storage inconsistencies without deleting or moving anything - the source file is only ever copied:

- **Absolute file paths** left over from a plugin migration (rtMedia / MediaPress / BuddyBoss) that 404 because the path never matched a valid URL. Re-sideloaded into `uploads/wpmediaverse/` as a relative path.
- **Stranded thumbnails** - an older "Migrate all" moved the original to cloud but left thumbnails on local disk, so they 404 at the cloud URL. Pushed to the active cloud driver.

Idempotent and safe to re-run. On the admin side this repair already runs automatically in the background after update; use this command for headless sites or to run it on demand.

```bash
# Report how many rows need repair without changing anything.
wp mvs repair-storage --dry-run

# Apply the repair.
wp mvs repair-storage
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | off | Report how many rows need repair without changing anything |

---

## wp mvs cert

Run the functional certification gate: a boot smoke test across every registered REST route plus a "dead toggle" oracle check (settings/options that are read but never wired to real behavior). Exits non-zero on any failure, so CI and the release pipeline can gate identically on any machine.

Added in 1.8.1.

```bash
# Run every check.
wp mvs cert

# Run only the boot smoke check.
wp mvs cert boot

# Run only the dead-toggle contract check, machine-readable output.
wp mvs cert contract --porcelain
```

**Synopsis:**

```
wp mvs cert [<check>] [--porcelain]
```

**Arguments:**

| Argument | Default | Description |
|----------|---------|-------------|
| `<check>` | `all` | Which check to run: `all`, `contract`, or `boot` |

**Options:**

| Option | Description |
|--------|-------------|
| `--porcelain` | Emit the machine-readable JSON ledger instead of the human-readable table |

**Output (table mode):** one `[PASS]` / `[FAIL]` / `[HOLE]` row per check, followed by a `Summary: N pass, N fail, N hole.` line. A `[HOLE]` marks a tracked gap (not yet a regression, but not proven either) rather than a failure. The command calls `WP_CLI::error()` (exit code 1) when any check fails.

---

## wp mvs competitions tick **(Pro)**

Run one competitions scheduler tick immediately, instead of waiting for the recurring Action Scheduler job. A tick fires every competition transition hook once - activating scheduled challenges, closing challenge entries, finalizing expired challenges, starting registered tournaments, and resolving expired matches - so any challenge or tournament whose deadline has passed advances right away. Useful for debugging on a site where Action Scheduler / WP-Cron is not firing, or to force an immediate state advance after editing competition rows.

Requires WPMediaVerse Pro with a competition feature enabled (challenges, tournaments, or battles).

```bash
wp mvs competitions tick
```

This command takes no options or arguments.

**Output:**

```
Success: Competitions tick executed.
```

---

## wp mvs competitions recompute **(Pro)**

Force the one-shot competitions catch-up migration to run again. Clears the internal migration flag, re-fires every transition hook once, and re-marks the flag as done. Use this when competition DB rows are edited by hand and you need the scheduler to re-derive their state. Reports how many non-finalized, non-cancelled competitions remain after the pass.

Requires WPMediaVerse Pro with a competition feature enabled.

```bash
wp mvs competitions recompute
```

This command takes no options or arguments.

**Output:**

```
Success: Recomputed 3 competition(s).
```

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
- **0** - success
- **1** - error (WP_CLI::error())
