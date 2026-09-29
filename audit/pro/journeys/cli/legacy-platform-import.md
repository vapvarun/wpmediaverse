---
journey: legacy-platform-import
plugin: wpmediaverse-pro
priority: normal
roles: [administrator]
covers: [MV-IMP-001, MV-IMP-002, MV-IMP-003, cli-import, legacy-import]
prerequisites:
  - "Server/SSH access with WP-CLI"
  - "rtMedia, MediaPress, and/or BuddyBoss Platform source data seeded (tables present with rows)"
estimated_runtime_minutes: 10
---

# Three CLI importers, one shared contract: dry-run previews truthfully, a real run is idempotent on re-run, and private albums never leak public

**Why this journey exists**: all three importers extend `AbstractBatchImporter::run_source()` and are held to the identical CLI progress/summary contract. The one bug this journey specifically re-tests: a private/friends-only rtMedia album's privacy historically diverged between the CLI importer and the admin migration card (the CLI copy was correct, the admin copy leaked private albums as public before the 2.4.0 fix consolidating both onto `AlbumMarkerLookupTrait::create_rtmedia_album()`).

## Setup

- Site: `$SITE_URL`; WP-CLI available.

## Steps

### 1. rtMedia dry-run previews without writing
- **Action**: `wp mvs import-rtmedia --dry-run`.
- **Expect**: "Would import {N} media item(s)..." — zero writes: no album, no media row, no meta key created. Progress bar per source.

### 2. rtMedia real run and idempotent re-run
- **Action**: `wp mvs import-rtmedia`; then run it again unchanged.
- **Expect**: first run imports; each imported row deduped against `mvs_media_meta` using the `rtmedia_id` meta key. Second run reports everything "Skipped (already imported)" — not re-imported or duplicated. Final line format: "Imported {N} media item(s). Skipped {N} (already imported). Errors: {N}."

### 3. rtMedia private album privacy carries over correctly
- **Action**: import a source item belonging to a private or friends-only rtMedia album.
- **Expect**: the resulting MVS album's privacy correctly reflects the source's restricted state — never escalated to public.
- **On fail**: `AlbumMarkerLookupTrait::create_rtmedia_album()` — this is the exact 2.4.0-fixed regression class; re-verify it hasn't regressed.

### 4. rtMedia `--skip-albums` and `--offset` resume
- **Action**: `wp mvs import-rtmedia --skip-albums`; separately, interrupt a run and resume with `--offset=<n>`.
- **Expect**: `--skip-albums` imports media but never calls `find_or_create_album`/`add_to_album`; `--offset` resumes mid-batch correctly.

### 5. MediaPress dry-run, real run, gallery mapping
- **Action**: `wp mvs import-mediapress --dry-run`, then the real run.
- **Expect**: identical CLI contract to rtMedia (shared base class); dedup meta key `mpp_id`; galleries map 1:1 to MVS albums via `mpp_gallery_id`, title/author pulled from the gallery post.

### 6. MediaPress with a trashed/deleted gallery post
- **Action**: import media linked to a gallery post that has since been trashed/deleted.
- **Expect**: falls back cleanly ("Imported Album" title, fallback author) — no error.

### 7. BuddyBoss Platform, per-table and combined
- **Action**: `wp mvs import-buddyboss --source=media --dry-run`; then run without `--source` (defaults to `all`).
- **Expect**: `run_source()` runs once PER table (media/document/video); counters are NOT reset between tables within one invocation, so `--source=all`'s final summary is a combined total. Dedup keys: `bb_media_id`, `bb_document_id`, `bb_video_id`. Album links come from `bp_media_context` (context_type='album').

### 8. BuddyBoss with the media component's table absent
- **Action**: (if reproducible) run against a BuddyBoss install with `bp_media_albums` missing.
- **Expect**: `create_buddyboss_album()` guards with `SHOW TABLES LIKE` and falls back to an untitled album under the fallback author — never a fatal.

### 9. Per-row failures never abort the batch
- **Action**: seed one deliberately malformed source row alongside good ones; run the import.
- **Expect**: a `WP_CLI::warning()` line "Failed to import {label}: {error message}" for that row; the batch continues to completion.

## Pass criteria

1. All three importers' dry-run performs zero writes and reports "Would import."
2. A second full run reports full dedup ("Skipped (already imported)") with no duplication, for all three.
3. rtMedia private/friends album privacy carries over correctly, matching the admin migration card's behaviour (see `migration-admin.md`).
4. `--skip-albums`/`--offset`/`--source` flags behave exactly as documented.
5. A malformed row logs a warning and the batch continues, never aborts.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Second run re-imports/duplicates | dedup meta key check missing or querying the wrong key | `AbstractBatchImporter::run_source()` |
| Private rtMedia album imports as public | album privacy derivation regressed off `AlbumMarkerLookupTrait` | `AlbumMarkerLookupTrait::create_rtmedia_album()` |
| Dry-run performs writes | `--dry-run` flag not gating the actual insert calls | importer's dry-run branch |
| A malformed row aborts the whole batch | exception not caught per-row | importer's per-row try/catch |
