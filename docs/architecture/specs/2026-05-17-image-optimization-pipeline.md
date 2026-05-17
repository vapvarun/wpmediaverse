# Image Optimization Pipeline

**Status:** Accepted
**Version target:** 1.2.2
**Date:** 2026-05-17

## Problem

WPMediaVerse stores files entirely outside the WordPress attachment system: `UploadService::process_upload()` calls `$driver->store()` and `multi_resize()` writes thumbnail variants, but `wp_handle_upload`, `wp_insert_attachment`, and `wp_generate_attachment_metadata` are never invoked. Every image compression plugin in the ecosystem (EWWW, Imagify, Smush, ShortPixel, Optimole, WebP Express, Converter for Media) hooks one of those four moments. Result: customers running compression plugins see them sit idle while WPMediaVerse uploads pile up unoptimized.

The architecture deliberately avoids the CPT path (see CLAUDE.md table comment for `mvs_media_index`: "Authoritative media record (no CPT dependency)"). We will not change that. We need a way to be compression-friendly without inheriting the attachment system.

## Decision

Ship a small in-plugin image optimization service plus a single filter that external compression plugins can hook. No per-plugin adapters. No shadow attachments.

The pipeline has three responsibilities:

1. **Lossless re-encode of originals** on write — strip metadata (EXIF is preserved separately in `mvs_media_meta`), progressive JPEG, PNG level 9. Uses `WP_Image_Editor` (Imagick preferred, GD fallback). Default on.
2. **WebP variant emission** alongside every thumbnail size produced by `multi_resize()`. Stored as `thumb_<size>_webp` meta on `mvs_media_meta`. Default on when the active editor supports `image/webp`. Customers who already serve WebP via server config can disable.
3. **`mvs_optimize_image` filter dispatch** at every disk-write moment. Returns the same path or a replacement path. External compression plugins (or customers' own mu-plugins) hook this to apply their tooling.

AVIF variants, `Accept:`-header content negotiation in `/serve`, and async dispatch via Action Scheduler are explicitly **out of scope for 1.2.2** and tracked for 1.3.0.

## Filter contract

```php
/**
 * @param string $file_path Absolute path to the file on local disk. Modifying
 *                          the file in place is allowed; the path itself is
 *                          authoritative for downstream readers.
 * @param array  $context   {
 *     @type int    $media_id Media row id (mvs_media_index).
 *     @type string $variant  'original' | 'large' | 'medium' | 'thumb'.
 *     @type string $mime     Source MIME type.
 *     @type int    $user_id  Uploader user id.
 * }
 *
 * @return string|WP_Error  Same path, replacement path, or WP_Error to abort the pass.
 *                           Returning WP_Error logs the failure and keeps the
 *                           original file untouched — the upload is NOT aborted.
 */
$file_path = apply_filters( 'mvs_optimize_image', $file_path, $context );
```

Listeners must:
- Operate on the local filesystem path passed in. The file is guaranteed to exist on local disk regardless of the active storage driver (cloud drivers receive the optimized bytes via `$driver->store()` after this filter runs).
- Return a string path (same or replacement). Replacements must exist on disk before return.
- Never throw. Use `WP_Error` for soft failure.

Listeners may:
- Modify the file in place and return the same path.
- Write a new file (e.g. `.webp` sibling) and return that path.
- Read `$context['variant']` to skip thumbnail variants if they only want originals.

## Meta keys added

| Key | Type | Notes |
|-----|------|-------|
| `original_webp` | URL | WebP sibling of the original file (JPG/PNG/GIF source) |
| `thumb_large_webp` | URL | WebP sibling of `thumb_large` |
| `thumb_medium_webp` | URL | WebP sibling of `thumb_medium` |
| `thumb_thumb_webp` | URL | WebP sibling of `thumb_thumb` |

WebP siblings are produced for every JPG/PNG/GIF input the active editor can decode. The original keeps its source format and URL untouched — `file_url` in `mvs_media_index` is authoritative. The WebP sibling lives next to it on disk (`foo.jpg` -> `foo.webp`) and is referenced only via the new meta key.

Readers may opportunistically prefer the `_webp` variant when the browser sent `Accept: image/webp` (current `/serve` does not do this yet — 1.3.0). Until then, the WebP siblings are stored but unused by the plugin's own renderers. Customers using Cache Enabler or .htaccess rewrite for WebP get the benefit immediately.

## Settings added

| Key | Type | Default | Section | Sanitizer |
|-----|------|---------|---------|-----------|
| `mvs_optimize_originals` | bool | `true` | Storage | `rest_sanitize_boolean` |
| `mvs_generate_webp` | bool | `true` | Storage | `rest_sanitize_boolean` |

Both registered via `SettingsRegistrar::register_general_settings()` under option group `_storage`. `mvs_generate_webp` UI hint reads "Requires Imagick or GD with WebP support" and is auto-disabled when the editor cannot produce WebP.

## Dispatch points in `UploadService.php`

| Line (current) | Pass | What gets filtered |
|----------------|------|--------------------|
| Just before 237 (`$driver->store()`) | original | `$file['tmp_name']` — temp upload file. Cloud driver receives the optimized bytes. |
| Just before 237 (`$driver->store()`) | original-webp | WebP sibling produced from the temp file. Stored as `original_webp` meta after `$driver->store()` succeeds for the WebP sibling. |
| Inside `generate_thumbnails()` per-size loop near 743 | each variant | Local variant path written by `multi_resize()` |
| Inside per-size loop near 743 | variant-webp | WebP sibling of each variant. Stored as `thumb_<size>_webp` meta. |

## Service shape

```
WPMediaVerse\Services\ImageOptimizationService
├── optimize( string $file_path, array $context ): string         // returns final path
├── emit_webp_sibling( string $file_path, array $context ): ?string  // null on failure, absolute local path on success
├── optimize_media( int $media_id, array $opts = [] ): array      // bulk-friendly; opts: include_variants, force
├── is_webp_supported(): bool
└── is_enabled( string $type ): bool                              // 'originals' | 'webp'
```

Registered in DI container as `image_optimization`. No constructor args (settings read via `get_option` at call time so toggles are live).

`optimize_media()` is the public surface used by the CLI commands and (in 1.3.0) the admin bulk UI. It encapsulates: fetch row from `MediaRepository`, download cloud original to temp if needed, run optimize() + emit_webp_sibling() for original and (optionally) variants, persist meta, write `_mvs_optimized_at` sentinel. Returns `[ 'media_id' => int, 'bytes_before' => int, 'bytes_after' => int, 'variants_processed' => int, 'errors' => string[] ]`.

## Failure modes

| Scenario | Behavior |
|----------|----------|
| Editor unavailable (no Imagick + GD) | Lossless pass is a no-op. Logged at info level once per request. Upload continues. |
| WebP not supported by editor | WebP pass is a no-op. Setting auto-disables in UI. |
| Filter returns `WP_Error` | Logged at warning level. Original/variant file is kept. Upload continues. |
| Filter returns path that doesn't exist | Logged at warning level. Falls back to input path. |
| Lossless pass increases file size (rare; happens with already-optimized inputs) | Keep the smaller file. |

## WP-CLI commands

Customers migrating from EWWW/Smush expect a `wp media regenerate`-style bulk command. We ship two subcommands under the existing `wp mvs` namespace (`includes/CLI/Commands.php`):

### `wp mvs optimize <media_id> [--include-variants] [--force]`

Optimize a single media row.

| Flag | Effect |
|------|--------|
| `--include-variants` | Also re-process every `thumb_*` variant (default: original only) |
| `--force` | Re-run even if the file was already optimized this session (uses an in-process guard) |

Output: per-file before/after byte counts and savings percentage.

### `wp mvs optimize-bulk [--limit=N] [--offset=N] [--mime=image/jpeg,image/png] [--media-type=photo] [--include-variants] [--dry-run] [--include-failed]`

Iterate `mvs_media_index` and run optimization across every matching row.

| Flag | Effect |
|------|--------|
| `--limit=N` | Cap rows processed (default: no cap) |
| `--offset=N` | Skip the first N rows (resume support) |
| `--mime=...` | Comma list of MIME types to include (default: every `image/*`) |
| `--media-type=...` | Restrict to one `media_type` column value |
| `--include-variants` | Process `thumb_*` variants alongside the original |
| `--dry-run` | Report savings estimate without writing |
| `--include-failed` | Re-process rows previously marked as failed in `mvs_media_meta._mvs_optimize_failed` |

Output: progress bar (via `WP_CLI\Utils\make_progress_bar`), per-batch summary, final totals (rows processed, total bytes saved, failures).

Bulk runs write a sentinel `_mvs_optimized_at` (timestamp) into `mvs_media_meta` so a re-run resumes from where it left off without `--force`. Failures write `_mvs_optimize_failed` with the error code so the next run can skip them by default.

For cloud-driver installs, the bulk command downloads each file via `$driver->download()` (already shipped in 1.2.2), optimizes locally, then re-uploads. This is the same pattern `cloud-thumbs-backfill` already uses, so no new contract.

## Admin surface

Three additions to the existing admin pages:

1. **Settings toggles** in the Storage section (see Settings table above).
2. **Notice** next to the toggles: "Already have a media library to optimize? Run `wp mvs optimize-bulk` from WP-CLI to process existing uploads. The optimization pipeline only runs on new uploads automatically."
3. **Per-image surface on `MediaListPage`** (matches the existing `repair_thumb` admin-php nonced pattern — no JS/REST in 1.2.2):
   - **"Optimization" column** showing one of: `Optimized −14.2%` (savings vs. original), `Not optimized`, `Failed (mime_unsupported)`. Hovering shows the absolute byte savings.
   - **Row action "Optimize"** — admin-php link with nonce (`action=optimize&media_id=X`) that calls `ImageOptimizationService::optimize_media()` and redirects back with a success/error notice. Same shape as the existing `Repair thumb` action.
   - **Row action "Details"** — links to a new read-only mini-page (`admin.php?page=mvs-media&view=details&media_id=X`). The mini-page is rendered by a new `MediaListPage::render_detail()` method and shows: file path, dimensions, MIME, file hash, original size, optimized size, savings %, last optimized timestamp, all WebP variant URLs, all thumb_* URLs, all relevant media_meta keys. Inline action buttons: **Re-optimize**, **Repair thumb**, **Trash**. No field editing in 1.2.2 — that lands in 1.3.0 alongside title/description/privacy editing.

A REST endpoint for `optimize` is **deferred to 1.3.0** to keep the 1.2.2 surface consistent with existing admin actions. External automation in 1.2.2 uses the CLI command or `optimize_media()` from PHP.

A full admin bulk UI with progress bar is **out of scope for 1.2.2** and lands in 1.3.0. The CLI command + per-row action covers the operational gap.

## Meta keys added (consolidated)

| Key | Type | Purpose |
|-----|------|---------|
| `original_webp` | URL | WebP sibling of the original |
| `thumb_large_webp` | URL | WebP sibling of `thumb_large` |
| `thumb_medium_webp` | URL | WebP sibling of `thumb_medium` |
| `thumb_thumb_webp` | URL | WebP sibling of `thumb_thumb` |
| `_mvs_optimized_at` | int (unix ts) | Last successful optimization; gates bulk-run resume |
| `_mvs_optimize_failed` | string (error code) | Last failure; default-skipped by bulk run unless `--include-failed` |
| `_mvs_bytes_before` | int | Original file size in bytes; used by admin column to compute savings |
| `_mvs_bytes_after` | int | Optimized file size in bytes |

All eight keys live in the existing `mvs_media_meta` table. **No new database tables.**

## What is NOT in scope

- AVIF variant generation. Imagick supports it in WP 6.5+ but encoding cost is high enough that we need queue infrastructure first. Ships in 1.3.0.
- `/serve` content negotiation. The variant URLs are stored but not selected by the plugin's own renderer in 1.2.2. Ships in 1.3.0 alongside cloud-aware `/serve`.
- Action Scheduler async dispatch. Sync pass is fast enough for typical uploads (lossless re-encode is ~10–80ms per image). Customers with abnormal upload patterns can wire it themselves via the filter.
- Per-plugin adapter classes (EWWW, Imagify, etc.). Documented mu-plugin snippets in `docs/development/COMPRESSION_INTEGRATIONS.md` instead. Five years from now we will not be maintaining four adapters whose vendor APIs have all renamed twice.
- Admin bulk UI with progress (CLI only in 1.2.2; admin UI in 1.3.0).
- Screen Options for the All Media admin table (toggle which columns are visible). Deferred to 1.3.0 where we add a shared screen-options helper applied uniformly across All Media + Stats + other admin tables, rather than bolting it onto one page. Current table renders custom HTML rather than extending `WP_List_Table`, so the helper has to be custom.
- Lightbox WebP serving. The lightbox is driven by the Interactivity API and reads image URLs out of `state.lightboxMediaData` (populated from a REST endpoint). To serve WebP here we need to (a) extend the REST media schema to include `original_webp`, (b) add `lightboxImageWebpUrl` / `lightboxHideImageWebp` getters in `src/blocks/shared-ui/view.js`, (c) update the template to `<picture>` with state-bound `<source>`, and (d) rebuild block assets. That's a four-surface change — deferred to 1.3.0 to keep 1.2.2 ship-stable. Until then the lightbox serves the full-size JPEG/PNG. WebP `<picture>` rendering still works everywhere `media_thumbnail()` is the renderer (grids, BP activity, dashboard cards, single-media view).

## Migration / backward compatibility

- New installs: optimization on, WebP on.
- Existing installs: same defaults but no backfill. Existing media keeps its original on-disk format and existing thumb_* URLs. A backfill CLI command (`wp mvs optimize-backfill`) ships in 1.3.0.
- Pro: no changes. The cloud thumbnail upload path already iterates per size — WebP siblings are uploaded alongside existing variant siblings using the same `$cloud_driver->store()` call.

## Test coverage

- Lossless pass shrinks a known unoptimized JPEG fixture.
- WebP variant is emitted with correct file extension and stored under the right meta key.
- Filter fires with expected context for original and each thumbnail variant.
- Disabled `mvs_optimize_originals` setting skips the lossless pass but still dispatches the filter (so EWWW etc. can still hook).
- Disabled `mvs_generate_webp` setting skips WebP emission.
- Non-image MIME (video, audio) bypasses the service entirely.

## Open questions

None at design time. Implementation will surface edge cases for the per-MIME pass; resolve there.
