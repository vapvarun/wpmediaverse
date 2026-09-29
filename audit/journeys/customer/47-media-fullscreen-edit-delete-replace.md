---
journey: media-fullscreen-edit-delete-replace
plugin: wpmediaverse
priority: high
roles: [author, subscriber]
covers: [MV-MED-010, MV-MED-012, MV-MED-015, lightbox-fullscreen, media-delete, media-replace]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Two distinct fixture images (see customer/01-media-upload-public.md for the generator)"
estimated_runtime_minutes: 6
---

# A member toggles fullscreen on a lightbox image, deletes their own media, and replaces a file without losing its identity

## Setup

- Member A (`?autologin=<memberA>`) owns one public image `$MEDIA_ID_1` (for delete) and a second public image `$MEDIA_ID_2` (for replace), both inside an album `$ALBUM_ID` the member also owns.
- A third fixture image on disk, distinct from both uploads, as the replacement file.

## Steps

### 1. Fullscreen toggle from the lightbox (MV-MED-010)
- **Action**: open `$MEDIA_ID_1` in the lightbox from Explore; click the Fullscreen button.
- **Expect**: the browser's native Fullscreen API activates on the image panel (`document.fullscreenElement` is set); the lightbox toolbar (reactions, favourite, share, etc.) remains clickable while in fullscreen.
- **Action**: press `F`.
- **Expect**: toggles fullscreen off (`state.lightboxFullscreen` flips) — same as clicking the button.
- **Action**: re-enter fullscreen, then focus the comment textarea and type the letter "f" as part of a word (e.g. "Office").
- **Expect**: the `f` character is typed into the textarea; fullscreen does NOT toggle — the shortcut is suppressed while a text field has focus (`isTextEntry` guard in `src/blocks/shared-ui/view.js::handleLightboxKeydown`).
- **Action**: press `Esc` once while in fullscreen.
- **Expect**: exits fullscreen ONLY — the lightbox itself stays open. Press `Esc` a second time.
- **Expect**: now the lightbox closes. A single `Esc` press never does both in the same press.

### 2. Delete own media (MV-MED-012)
- **Action**: on the dashboard, open `$MEDIA_ID_1`'s Delete control; observe which button has initial focus in the confirm dialog; confirm.
- **Expect**: Cancel is focused by default (not the destructive action). Confirming issues `DELETE /wp-json/mvs/v1/media/$MEDIA_ID_1` returning 204.
- **Verify removal everywhere**: `mysql_query "SELECT * FROM wp_mvs_media_index WHERE media_id=$MEDIA_ID_1"` returns no row; the item is gone from Explore and the dashboard without a full page reload; if it was inside `$ALBUM_ID`, `mysql_query "SELECT * FROM wp_mvs_album_items WHERE media_id=$MEDIA_ID_1"` also returns no row (no dangling album tile).
- **Action**: double-click Delete rapidly on a second throwaway item.
- **Expect**: only one DELETE request fires — the confirm-dialog gate prevents a second trigger before the first completes.

### 2b. The single-media page redirects away after self-delete
- **Action**: as the owner, open `$MEDIA_ID_1`'s own `/media/{slug}/` page directly, then delete it from that page's own Delete control (not the dashboard).
- **Expect**: after the DELETE succeeds, the browser navigates away from the now-gone single-media URL (observed in-browser which target it lands on) rather than re-rendering a 404 in place.

### 3. Replace a file, keep the identity (MV-MED-015)
- **Action**: open `$MEDIA_ID_2`'s Edit modal; note its current permalink, view count (`mysql_query "SELECT views FROM wp_mvs_media_stats WHERE media_id=$MEDIA_ID_2"`), and album membership; trigger the file-replace control with the third fixture; save.
- **Expect**: `POST /wp-json/mvs/v1/media/$MEDIA_ID_2/replace` returns 200. A success toast reads "File replaced!".
- **Verify identity survives**: `$MEDIA_ID_2` is unchanged (same numeric id, same slug/permalink); `mysql_query "SELECT views FROM wp_mvs_media_stats WHERE media_id=$MEDIA_ID_2"` shows the SAME view count as before (stats not reset); the item is still present in `$ALBUM_ID`'s `mvs_album_items` row; the lightbox/single-media page for `$MEDIA_ID_2` shows the NEW image content at the SAME URL — no new/duplicate item appears anywhere in Explore or the dashboard.
- **Action**: attempt a replace with an oversize/disallowed file (reuse the guard from MV-UPL-005).
- **Expect**: rejected with the same MIME/size/dangerous-extension guard as a fresh upload — no bypass via replace; a "Replace failed." toast shown, the original file remains untouched.

## Pass criteria

ALL of the following hold:
1. Fullscreen toggles via button and `F`; the `f` keystroke is not intercepted while typing in a field; `Esc` exits fullscreen first, closes the lightbox on the next press.
2. Delete: Cancel-focused confirm; 204 on confirm; DB row + album-item row both gone; double-click does not double-delete; the owner's own single-media page navigates away after self-delete.
3. Replace: 200 + "File replaced!" toast; the media id, permalink, view-count and album membership are unchanged; the served content is the new file; an invalid replacement file is rejected by the same guard as a fresh upload.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `F` toggles fullscreen while typing in the comment box | `isTextEntry` guard missing/regressed | `src/blocks/shared-ui/view.js::handleLightboxKeydown` |
| A single `Esc` both exits fullscreen and closes the lightbox | the `if (state.lightboxFullscreen)` branch removed | `src/blocks/shared-ui/view.js::handleLightboxKeydown` |
| Deleted item's album tile remains | `MediaRepository::delete_cascade()` not clearing `mvs_album_items` | `includes/Repository/MediaRepository.php::delete_cascade()` |
| Replace resets view count / loses album membership | `replace_file()` writing through a path that touches stats/album rows | `includes/REST/Controller/MediaController.php::replace_file()` |
| Replace accepts a disallowed file | replace bypassing the shared MIME/size/extension validator | `includes/Services/UploadService.php`, `includes/REST/Controller/MediaController.php::replace_file()` |
