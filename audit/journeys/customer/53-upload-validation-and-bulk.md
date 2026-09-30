---
journey: upload-validation-and-bulk
plugin: wpmediaverse
priority: critical
roles: [subscriber]
covers: [MV-UPL-005, MV-UPL-006, MV-UPL-007, MV-UPL-008, upload-rejection, duplicate-detection, upload-privacy-album, bulk-actions]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "A file larger than Max Upload Size, a renamed .exe (or other blocklisted extension), and a 0-byte file"
  - "At least one existing album; several of Member A's own media items for the bulk step"
estimated_runtime_minutes: 8
---

# Bad uploads are refused honestly, duplicates are handled per the configured mode, privacy/album selection at upload time works, and bulk actions apply to many items at once

## Setup

- Member A (`?autologin=<memberA>`).
- `mvs_max_upload_size` at its default (100MB) — use a file just over that ceiling.
- `mvs_duplicate_action` starting at its default `warn`.

## Steps

### 1. Empty file is refused before any DB row (MV-UPL-005)
- **Action**: `curl -X POST -H 'X-WP-Nonce: $NONCE' -b cookies.txt -F 'file=@/dev/null;filename=empty.jpg' -F 'privacy=public' $SITE_URL/wp-json/mvs/v1/media`.
- **Expect**: 400 `mvs_empty_file`; `mysql_query "SELECT COUNT(*) FROM wp_mvs_media_index WHERE title LIKE '%empty%'"` confirms no row was created.

### 2. Disallowed / blocklisted extension is refused regardless of claimed MIME
- **Action**: rename a text file to `shell.php.jpg` (double-extension smuggling) and upload it; separately upload a real `.exe` renamed to `.jpg`.
- **Expect**: both refused `400 mvs_invalid_type` — the 24-entry extension blocklist catches the dangerous extension even though the outer name claims an image type. No DB row created for either.

### 3. Oversize file is refused, measured server-side
- **Action**: upload a file just over `mvs_max_upload_size` (default 100MB); if impractical to generate a real 100MB+ file, temporarily lower `mvs_max_upload_size` to something small (e.g. 1MB via `wp option update`) and upload a file just over that.
- **Expect**: `400 mvs_file_too_large`, error message names the MB ceiling. Confirm the check is server-side: a request that spoofs a small `Content-Length` header but sends a genuinely large body must still be caught by `filesize()` on the received temp file, not by trusting the client header.
- **Action**: in the modal UI, verify all three refusals (empty, disallowed, oversize) show a SPECIFIC, readable error message — never a generic "upload failed" — and that in a gallery upload the other valid files are unaffected.

### 4. Duplicate detection modes (MV-UPL-006)
- **Action**: with `mvs_duplicate_action = warn` (default), upload a fixture, then upload the IDENTICAL file again.
- **Expect**: second upload succeeds (201) with a warning surfaced to the member that is visibly different from a hard failure — the member should understand it went through.
- **Action**: `wp option update mvs_duplicate_action skip`; upload the identical file a third time.
- **Expect**: refused (409-style; no third DB row created).
- **Action**: `wp option update mvs_duplicate_action allow`; upload it a fourth time.
- **Expect**: succeeds with NO warning at all — both copies simply exist. Restore `mvs_duplicate_action` to its original value.

### 5. Privacy + album selection at upload time (MV-UPL-007)
- **Action**: with `mvs_allow_user_privacy` ON, upload a photo choosing a non-default privacy and picking an EXISTING album from "Add to album".
- **Expect**: item created with the chosen privacy, then `POST /albums/{id}/items` adds it to the album — if the album's privacy differs from what you picked, the album's privacy wins (per MV-ALB-002) and the item's shown privacy after save reflects the album's, not your original pick. Verify the UI actually surfaces this (the item's displayed privacy badge/label after save), not silently.
- **Action**: repeat, this time choosing "+ Create new album…" and typing a name.
- **Expect**: a new album is created and the item joins it the same way.
- **Action**: `wp option update mvs_allow_user_privacy 0`; reopen the upload modal.
- **Expect**: the Privacy select is not rendered at all (not shown-then-ignored). Restore the option afterward.

### 6. Bulk actions on your own media (MV-UPL-008)
- **Action**: on the My Media dashboard, select 3+ of Member A's own items via checkboxes; choose "Move to album"; repeat separately with "Change privacy" and "Add tags".
- **Expect**: each bulk call (`POST /mvs/v1/media/bulk`, `action`: `move_to_album`/`change_privacy`/`add_tags`) applies to every selected item in one request; a success notice states the count affected.
- **Action**: select 0 items and attempt a bulk action.
- **Expect**: a friendly error, and NO request sent at all (check the network log) — never a silent no-op or a call with an empty id list.
- **Action**: select items including a non-audio item and bulk "move to album" into a playlist-type album.
- **Expect**: the server-side per-item check skips the non-audio item(s) rather than failing the entire batch — verify the audio item(s) moved and the non-audio ones did not.
- **Action**: bulk-select and choose Delete.
- **Expect**: the shared confirm dialog (Cancel focused) fires before any delete request — no destructive bulk action skips it.

## Pass criteria

ALL of the following hold:
1. Empty, blocklisted-extension, and oversize uploads are all refused with their specific error code and no DB row created; the size check is server-side.
2. Duplicate detection behaves per mode: Warn (proceeds + visible warning), Skip (blocked), Allow (no check, both exist).
3. Upload-time privacy + album selection works; album privacy overrides the picked privacy when stricter, and this is surfaced in the UI; the Privacy select disappears entirely when `mvs_allow_user_privacy` is off.
4. Bulk move/privacy/tag actions apply to every selected item in one call; zero-selection is refused client-side with no request; a playlist-album bulk-move skips non-audio items without failing the batch; bulk Delete goes through the confirm dialog.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Oversize upload accepted because client `Content-Length` was spoofed | server trusting a client-reported size instead of `filesize()` on the temp file | `includes/Services/UploadService.php` |
| Double-extension file (`x.php.jpg`) accepted | blocklist check bypassed or incomplete | `includes/Services/UploadService.php` |
| Duplicate "Skip" mode still creates a second row | `mvs_duplicate_action` not read, or hash comparison broken | `includes/Services/UploadService.php` |
| Album's privacy does NOT override the upload-time pick | `AlbumService::apply_album_privacy()` not called on the add-to-album follow-up | `includes/Services/AlbumService.php` |
| Bulk action with 0 selected sends a request anyway | client-side guard missing before the `POST /media/bulk` call | `src/blocks/dashboard-view/view.js` |
| Bulk move-to-playlist-album fails the whole batch on a non-audio item | server-side per-item type check removed from the bulk handler | `includes/REST/Controller/BulkController.php` |
