---
journey: watermark-ingest-paths
plugin: wpmediaverse
priority: high
roles: [administrator, author]
covers: [watermark-replace-file, watermark-sideload-skip, watermark-fail-closed]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "WPMediaVerse Pro active (Pro owns the Watermarker stamp)"
  - "PHP GD extension available"
  - "dev-auto-login mu-plugin installed"
estimated_runtime_minutes: 8
---

# The watermark applies to exactly the ingest paths that publish new member bytes

Companion to journey 15, which covers the fresh-upload stamp. This one pins the
*boundary*: which ingest paths stamp, which deliberately do not, and what happens
when the stamp cannot be drawn.

`WatermarkService::stamp_new_upload()` is the single place `mvs_watermark_stamp_file`
is fired. The rule it encodes:

> Stamp what the member publishes now; never re-process what is already in the library.

| Ingest path | Bytes | Stamps? |
|---|---|---|
| `UploadService::handle()` | new upload | yes |
| `MediaController::replace_file()` | new member-chosen file | yes |
| `UploadService::sideload_external_file()` | already in library (repair, CLI migration) | **no** |

The sideload omission is deliberate. That path re-ingests bytes that are either
already stamped — and nothing records that a file was stamped, so a second pass
would draw a **second** watermark — or are pre-existing legacy media the admin
never agreed to alter. Do not "fix" it by adding a call.

## Setup

- Admin (`?autologin=1`).
- Enable + scope watermark to all uploads:
  ```sql
  UPDATE wp_options SET option_value='1'    WHERE option_name='mvs_watermark_enabled';
  UPDATE wp_options SET option_value='all'  WHERE option_name='mvs_watermark_apply';
  UPDATE wp_options SET option_value='text' WHERE option_name='mvs_watermark_type';
  ```
- Two distinct fixture images (see journey 01 for the generator): an upload and a
  replacement. Record `md5` of both before starting.

## Steps

### 1. A replacement file is watermarked (Basecamp 10073917553)

Before the fix, `replace_file()` never fired the stamp: a member could upload,
get a watermark, then replace the file with the same image and receive it back
clean. One click removed the watermark.

- **Action**: upload the first fixture through `/my-media/`. Note the `media_id`.
- **Action**: `POST /wp-json/mvs/v1/media/{id}/replace` with the second fixture as
  the `file` multipart param.
- **Assert**: HTTP 200.
- **Assert**: the stored original at `wp-content/uploads/wpmediaverse/<Y>/<m>/<replacement>`
  has an `md5` **different** from the replacement fixture — it was modified.
- **Assert**: visually, the stored original carries the watermark text in the
  configured position.
- **Assert**: `mvs_media_index.file_hash` equals `sha256(clean replacement fixture)`,
  **not** the hash of the stored (stamped) bytes. The stamp must run after the
  source hash is taken, mirroring `handle()` — dup detection matches the user's
  source, never the post-encode bytes.

### 2. The stamp runs BEFORE the file is stored

`replace_file()` calls `$driver->store()` early. If the stamp ran after it, the
stored original would go out clean while its WebP/AVIF siblings — cut later from
the temp file — would carry the mark.

- **Assert**: the stored original AND its `.webp` sibling both carry the watermark.
  Fetch both (CDN URL when a cloud driver is active) and inspect.

### 3. Sideload does NOT stamp (no double watermark)

- **Action**: with watermarking enabled, register a counting filter on
  `mvs_watermark_stamp_file` and call
  `UploadService::sideload_external_file( $media_id, $already_stamped_path, 'image', 'image/jpeg' )`.
- **Assert**: the filter fires **0** times.
- **Assert**: the sideloaded file's `md5` is unchanged — no second watermark drawn.

### 4. The stamp fails CLOSED, never silently (Basecamp 10073499080)

On a host without GD, `wp_get_image_editor()` returns `WP_Image_Editor_Imagick`,
whose internal handle is not a `GdImage`. `Watermarker` draws via a reflected GD
resource, so nothing is drawn. Before the fix it still called `save()` — silently
re-encoding the member's photo for nothing — and returned `true`, so the
fail-open guard in `UploadService` never fired.

- **Action**: simulate a GD-less host by forcing the editor at a late priority:
  ```php
  add_filter( 'wp_image_editors', fn() => array( 'WP_Image_Editor_Imagick' ), 99 );
  ```
- **Action**: call `Watermarker::stamp_file( false, $path, 'image/jpeg', 1 )`.
- **Assert**: it returns `false`.
- **Assert**: the file's `md5` is **unchanged** — no pointless lossy re-encode.
- **Action**: call `WatermarkService::stamp_new_upload()` under the same filter.
- **Assert**: an error row lands in `mvs_error_log` with `context = 'watermark'`.

### 5. Non-images are ignored without logging

- **Action**: call `stamp_new_upload( $path, 'video/mp4', 1 )`.
- **Assert**: returns `false`, fires the filter **0** times, and writes **no**
  `mvs_error_log` row. Video and audio are never watermarked; the guard must not
  produce a false failure log on every video upload.

## Regression history

- 2026-07-08 — replace-file bypass (10073917553); Imagick-only silent no-op left
  un-watermarked, re-encoded images with no trace (10073499080, correction).
