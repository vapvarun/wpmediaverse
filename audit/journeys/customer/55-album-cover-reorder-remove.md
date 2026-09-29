---
journey: album-cover-reorder-remove
plugin: wpmediaverse
priority: high
roles: [subscriber]
covers: [MV-ALB-006, MV-ALB-007, MV-ALB-008, album-cover, album-reorder, album-item-remove, album-delete]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "An album owned by Member A with 3+ items including at least one image and one video/audio item"
estimated_runtime_minutes: 6
---

# Setting an album cover, reordering items, and removing items/deleting the album

## Setup

- Member A (`?autologin=<memberA>`), owns `$ALBUM_ID` with 3+ items (image `$IMG_1`, video/audio `$AV_1`, image `$IMG_2`).

## Steps

### 1. Set cover — image accepted, video/audio rejected (MV-ALB-006)
- **Action**: open the album edit modal; click "Set cover" on `$IMG_1`; Save.
- **Expect**: `PUT /mvs/v1/albums/$ALBUM_ID/cover` succeeds; the album card/single page now shows `$IMG_1` as the cover.
- **Action**: try "Set cover" on `$AV_1` (video/audio item).
- **Expect**: rejected as a cover candidate — either the control is not offered on a non-image item, or attempting it 400s `mvs_cover_not_in_album`-style (verify which; either is acceptable per the catalog, but note which actually ships).
- **Action**: with `$IMG_1` selected as cover, deselect it from the album's item picker (without saving yet).
- **Expect**: the cover selection clears client-side immediately — Save must not then 400 with `mvs_cover_not_in_album` because a de-selected item can no longer be the cover.
- **Action**: remove every image from the album programmatically, leaving only non-image items, and reload the album page.
- **Expect**: cover resolution falls back per the documented order — first image → first item of any renderable type → no cover shown. Confirm which of the three actually renders.

### 2. Reorder album items (MV-ALB-007)
- **Action**: in the album edit view, drag `$IMG_2` to the first position.
- **Expect**: `PUT /mvs/v1/albums/$ALBUM_ID/reorder` fires; the UI reorders immediately (optimistic).
- **Action**: reload the album page.
- **Expect**: the new order persists — `mysql_query "SELECT media_id, position FROM wp_mvs_album_items WHERE album_id=$ALBUM_ID ORDER BY position"` shows `$IMG_2` first, with no gaps in the `position` sequence.
- **Action**: force the reorder request to fail (offline/blocked), then drag an item.
- **Expect**: the UI reverts to the last known-good order rather than leaving the client and server disagreeing.

### 3. Remove one item, releasing it cleanly (MV-ALB-008, item remove)
- **Action**: remove `$AV_1` from the album via its per-item remove control.
- **Expect**: `DELETE /mvs/v1/albums/$ALBUM_ID/items/$AV_1` returns 200/204; `$AV_1` is gone from `mvs_album_items` for this album but the underlying media row (`wp_mvs_media_index`) is untouched — the item still exists in the library, just no longer in this album (own privacy restored per MV-ALB-005, covered in customer/54).

### 4. Delete the whole album (MV-ALB-008, album delete)
- **Action**: click "Delete album"; observe which button has initial focus in the confirm dialog; confirm.
- **Expect**: Cancel is focused by default. Confirming issues `DELETE /mvs/v1/albums/$ALBUM_ID`; the album is removed from every listing (Albums tab, `/album/`) without a full reload where feasible; `wp_delete_post()` removes the album CPT row, but `$IMG_1`/`$IMG_2` remain in `wp_mvs_media_index` — they are not deleted, only released from the album.

## Pass criteria

ALL of the following hold:
1. An image can be set as cover; a video/audio item cannot; deselecting the current cover clears the selection client-side so Save never 400s on a stale cover reference; cover falls back sensibly when nothing is pinned.
2. Reorder persists across reload with no position gaps; a failed reorder reverts the UI rather than desyncing from the server.
3. Removing an item from an album releases it without touching the underlying media row.
4. Deleting an album goes through a Cancel-focused confirm, removes the album post, and leaves every contained photo in the library.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A video/audio item can be set as album cover | image-only type check missing on `PUT /albums/{id}/cover` | `includes/REST/Controller/AlbumController.php` (cover endpoint) |
| Save 400s with `mvs_cover_not_in_album` after deselecting the cover in the picker | client-side cover-selection clearing on deselect missing | `src/blocks/dashboard-view/view.js` (album edit modal) |
| Reorder does not persist after reload | `position` column not written atomically, or read query ignores it | `includes/REST/Controller/AlbumController.php` (reorder endpoint) |
| Removing an item from an album deletes the underlying media | item-remove route calling media delete instead of the album-items delete | `includes/REST/Controller/AlbumController.php` |
| Deleting an album deletes contained photos | album delete not scoped to the CPT + `mvs_album_items` release | `includes/Services/AlbumService.php`, `includes/REST/Controller/AlbumController.php::delete_item()` |
