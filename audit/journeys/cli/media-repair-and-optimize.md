---
journey: cli-media-repair-and-optimize
plugin: wpmediaverse
priority: normal
roles: [shell]
covers: [MV-CLI-008, MV-CLI-013, MV-CLI-016, MV-CLI-017, wp-cli-media-repair]
prerequisites:
  - "Shell/SSH access with WP-CLI, `wp mvs` registered"
  - "A library with image media, some with a reachable local file and some without (cloud-only in a Pro context)"
  - "A valid OpenAI key (MV-SET-029) for the backfill-ai real-call path"
estimated_runtime_minutes: 10
---

# Bulk media-repair CLI commands (thumbnails, placeholder color, optimize, AI backfill) agree with their admin-UI equivalents and skip cleanly

**Why this journey exists**: each of these four commands is the bulk/CLI
counterpart of a per-item admin action (Regenerate, optimize row action, AI
analyze). The site owner's expectation is that the CLI and the admin single-item
action produce IDENTICAL results, and that "can't process this one" (no local
file, non-image, budget exhausted) is distinguishable from a genuine failure.
Grounded against `includes/CLI/Commands.php`.

## Setup

- A mixed library: some images with a reachable local original, at least one
  with only a cloud-only original (Pro), at least one non-image ID to test
  input validation.
- MV-SET-008 (optimize quality), MV-SET-019 (thumbnail sizes), MV-SET-029/031/032
  (AI key/features/budget) at their current values — read them, don't guess.

## Steps

### 1. MV-CLI-008 — `wp mvs regenerate-thumbnails`
- **Action**: `wp mvs regenerate-thumbnails --only-missing --dry-run`, then a
  real run; also `wp mvs regenerate-thumbnails --media-ids=<a-non-image-id>`.
- **Assert**: per-item skip logging "Skip (no reachable local file): media
  {id}" for rows `get_filesystem_path()` can't resolve (cloud-only originals);
  final tally "{verb} {N} image(s); skipped {S}; failed {F}." — skipped
  (benign, no local file) is a SEPARATE counter from failed (genuine
  regeneration error), never conflated. A non-image ID in `--media-ids` is
  skipped cleanly (query is scoped to `media_types: ['image']`), not a batch
  abort.

### 2. MV-CLI-013 — `wp mvs backfill-placeholder-color`
- **Action**: `wp mvs backfill-placeholder-color --media-id=<a-video-id>`;
  then `wp mvs backfill-placeholder-color --media-id=999999999` (nonexistent);
  then a bulk `--dry-run` pass.
- **Assert**: a non-image `--media-id` errors "Media #{id} is not an image
  (type: {type})." — explicit, not a silent no-op. A nonexistent `--media-id`
  errors clearly rather than silently doing nothing. Bulk mode: an unreadable
  original (corrupt/missing) logs a warning and is skipped, not fatal to the
  batch. The command only sets the placeholder-color meta; it never rewrites
  the image file itself (diff the file's mtime/hash before/after).

### 3. MV-CLI-016 — `wp mvs optimize` / `wp mvs optimize-bulk`
- **Action**: `wp mvs optimize` with no `<media_id>` argument; then `wp mvs
  optimize <real-image-id>`; then `wp mvs optimize-bulk --dry-run`, then for
  real with and without `--include-variants`.
- **Assert**: missing/invalid `<media_id>` errors "Missing or invalid
  <media_id>." A real single-item run reports "Media #{id}: {before} ->
  {after} (saved {pct}%). Variants processed: {n}." — the SAME
  `image_optimization` service and commit-only-if-smaller rule the admin
  "Optimize" row action (MV-ADM-007) uses, so CLI and admin-UI numbers for the
  same media ID must agree if run back-to-back on an unoptimized copy.
  `optimize-bulk` on a library with nothing eligible (all already carrying
  `_mvs_optimized_at`) reports "No image rows match the filters." with exit
  0, not an error code. Per-item errors in a bulk run are surfaced in the
  final "{N} processed. {S} skipped. {F} failed." tally, never swallowed
  silently.

### 4. MV-CLI-017 — `wp mvs backfill-ai`
- **Action**: with nothing needing backfill, run `wp mvs backfill-ai`; then
  `wp mvs backfill-ai --dry-run` with real candidates; then a real (or
  `--sync`) run with MV-SET-032's monthly budget set very low so it exhausts
  mid-run; then run the command TWICE in a row on the same candidate set.
- **Assert**: nothing-to-do case: "No media need AI backfill." Dry-run: "{N}
  media would be processed (dry run)." Without `--sync`, each item is
  queued to the async Action Scheduler job `mvs_ai_process_media` (or
  processed inline as a fallback when Action Scheduler is unavailable); with
  `--sync`, each item calls `AIService::process()` inline immediately, which
  re-checks the budget on every single call — so exhausting MV-SET-032's cap
  mid-batch turns every remaining item into a `failed` count (via
  `is_wp_error()`), not a fatal or an infinite retry. Final line: "AI backfill
  done. Queued: {Q}, processed inline: {P}, failed: {F}." Running twice does
  NOT double-charge or reprocess items that already ran (whether they
  produced a description or not, or even failed) — the "needs backfill" query
  (`media_ids_missing_meta('ai_status', ...)`) is keyed on whether AI has EVER
  run, not on the result; only `--force` reprocesses regardless.

## Pass criteria

1. `regenerate-thumbnails` and `backfill-placeholder-color` both distinguish
   "expected skip" (no local file, wrong type) from "genuine failure" in their
   final tallies — never one bucket hiding the other.
2. `optimize`/`optimize-bulk` produce numbers that agree with the admin
   single-item Optimize action for the same media ID (same service, same
   commit-only-if-smaller rule).
3. `backfill-ai` respects the monthly budget cap mid-run and never
   double-processes an already-attempted item on a second run without
   `--force`.
4. None of the four commands fatals on a bad/nonexistent `--media-id`; each
   gives an explicit, actionable error instead.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Skipped/failed conflated in `regenerate-thumbnails` tally | a code path incrementing the wrong counter | `includes/CLI/Commands.php::regenerate_thumbnails` |
| CLI `optimize` and admin row action disagree on savings % | two separate optimization code paths instead of one shared `image_optimization` service call | `includes/Services/ImageOptimizationService.php`, admin row-action handler |
| `backfill-ai` re-charges an already-processed item on a second run | "needs backfill" query keyed on result content instead of whether AI ever ran | `includes/CLI/Commands.php::backfill_ai` |
| `backfill-ai` keeps calling the provider after budget exhausted | mid-run budget check missing (only checked once at start) | `includes/Services/AIService.php` |
