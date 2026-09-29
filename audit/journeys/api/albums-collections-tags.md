---
journey: api-albums-collections-tags
plugin: wpmediaverse
priority: high
roles: [anonymous, member, owner, other-member, moderator]
covers: [MV-API-010, MV-API-011, MV-API-012, album-item-privacy-leak, collection-container-privacy]
prerequisites:
  - "Site reachable at $SITE_URL, mvs/v1 namespace registered"
  - "Member A owns an album with a mix of public/private items; member B has no 'others' caps"
  - "A members-privacy smart collection whose rules match some OTHER member's private media"
  - "A moderator-capable account and a plain-member account for tag management"
estimated_runtime_minutes: 12
---

# Albums, collections and tags never leak a private item through a container the viewer can't individually see into

**Why this journey exists**: the 2.5.1 authorization round specifically closed
gaps where a container (album, collection) could surface a private item's
thumbnail/title to a viewer who couldn't view that item directly. This journey
re-tests exactly that class of regression, plus the ownership/capability rules
each container type layers on top. Grounded against
`includes/REST/Controller/AlbumController.php`, `CollectionController.php`,
`TagController.php`.

## Setup

- Album `$ALBUM_ID` owned by A, containing a mix of public items and at least
  one private item belonging to A.
- A `members`-privacy smart collection `$COLL_ID` whose rules would otherwise
  match a private item belonging to a DIFFERENT member (not A, not the
  collection's curator).
- Member B: same role as A, no `edit_others_mvs_medias`/
  `delete_others_mvs_medias`.
- A moderator-capable account (`moderate_mvs_media`) and a plain member for
  tags.

## Steps

### 1. MV-API-010 — Albums: CRUD, items, cover, reorder
- **Action**: as B, `GET /mvs/v1/albums/{$ALBUM_ID}/items`.
- **Assert**: only items B is individually allowed to view are listed — this
  goes through the single shared `AlbumService::viewable_item_ids()` (the
  2.5.1 fix collapsed what used to be per-renderer copies that could drift);
  a private item in the album that B can't view is absent, not present with
  a redacted thumbnail.
- **Action**: as B, `PUT /mvs/v1/albums/{$ALBUM_ID}` (not owner, no cap).
- **Assert**: `401` if logged out, `403` if logged in without the cap — album
  edit/delete uses the EXPLICIT-403 ownership pattern (not masked as 404),
  confirmed distinct from media's own write-refusal shape in
  `media-crud-and-social.md` step 3.
- **Action**: as A, reorder items (`PUT/PATCH /mvs/v1/albums/{$ALBUM_ID}/reorder`)
  and set a cover (`.../cover`).
- **Assert**: both succeed for the owner.
- **Action**: a batch "Add to album" (bulk) with a mixed audio+video selection
  into an audio-only playlist-style album.
- **Assert**: the batch add goes through the SAME `AlbumService::add_items()`
  method a single add uses (2.5.1 unification) — the mixed batch is
  rejected/filtered the same way a single mismatched add would be, not
  silently accepted just because it's a batch. "Add to album" never removes
  from a prior album — an item added to a second album remains in the first
  (Add, not Move).
- **Assert (render leak check)**: fetch the album's public listing page/API
  response as an anonymous or non-viewing user and confirm no private item's
  thumbnail or title string appears anywhere in the payload — not just
  absent from a `count`, absent from the markup/JSON entirely.

### 2. MV-API-011 — Collections: CRUD, items, rules
- **Action**: logged out, `GET /mvs/v1/collections/{$COLL_ID}` on the
  `members`-privacy collection.
- **Assert**: refused — this is the container-level gate `[mvs_collection]`
  gained in 2.5.1 (previously missing entirely).
- **Action**: as a permitted member (logged in, matches `members` privacy),
  `GET /mvs/v1/collections/{$COLL_ID}/items`.
- **Assert**: resolved items EXCLUDE the other member's private media even
  though the smart rule would otherwise match it —
  `CollectionService::may_contain()` only allows public media, or the
  curator's own, never someone else's private item, and never a document (a
  rule targeting `media_type=document` never matches anything, by design).
- **Action**: as a non-owner, `PUT/DELETE /mvs/v1/collections/{$COLL_ID}` and
  `.../rules` (both gated by `owner_permissions_check`).
- **Assert**: refused for the non-owner; succeeds for the curator.
- **Action**: set the collection's stored privacy to an invalid/garbage
  value directly in the DB, then `GET` it.
- **Assert**: `get_privacy()` coerces it back to `public` defensively — never
  a fatal, never an unrecognized-privacy bypass.

### 3. MV-API-012 — Tags: list, cloud, create, merge, update, delete
- **Action**: logged out, `GET /mvs/v1/tags` and `GET /mvs/v1/tags/cloud`.
- **Assert**: both succeed fully public, no auth required.
- **Action**: as a plain member, `POST /mvs/v1/tags` (create) then `DELETE
  /mvs/v1/tags/{id}`.
- **Assert**: create succeeds if the member has `upload_mvs_media` (tied to
  upload capability, `manage_options` also qualifies); `DELETE` as a plain
  member → `403 mvs_forbidden` ("You do not have permission to manage
  tags.") — delete/update/merge require `moderate_mvs_media`
  (`admin_check`), a strictly higher bar than create.
- **Action**: as the moderator, `POST /mvs/v1/tags/merge` merging tag X into
  tag Y; then attempt a self-merge (source === target); then merge with a
  nonexistent source/target id.
- **Assert**: a valid merge produces the same batched result as MV-ADM-011's
  admin-UI merge tool (same underlying hook, `mvs_tags_merged`). Self-merge
  → `400 mvs_same_tag`. Stale/nonexistent tag id → `404 mvs_not_found`. Both
  guards are enforced independently at the API layer, not only in the
  wp-admin form.

## Pass criteria

1. `GET /albums/{id}/items` and any album render never expose a private
   item's data to a viewer who individually cannot view that item.
2. `GET /collections/{id}` on a `members`-privacy collection is refused to a
   logged-out/non-permitted visitor at the container level.
3. A smart collection's resolved items never include another member's
   private media, and never a document, regardless of what the stored rule
   matches.
4. Batch "Add to album" enforces the same privacy clamp and type
   restrictions (e.g. audio-only playlist) as a single add.
5. Tag create (upload cap) vs. tag manage (moderate cap) stay on separate,
   correctly-ordered capability tiers; merge guards (self-merge, stale id)
   are enforced server-side, not only in the admin UI.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A private album item's thumbnail appears to a non-viewing member | a renderer bypassing `AlbumService::viewable_item_ids()` with its own query | `includes/Services/AlbumService.php`, `includes/REST/Controller/AlbumController.php` |
| Logged-out visitor can read a `members`-privacy collection | the `[mvs_collection]` container gate missing or bypassed | `includes/Services/CollectionService.php`, `includes/REST/Controller/CollectionController.php` |
| Smart collection surfaces another member's private item | `CollectionService::may_contain()` rule evaluated before the privacy filter | `includes/Services/CollectionService.php` |
| Batch "Add to album" accepts a mixed selection a single add would reject | batch path not routed through the shared `AlbumService::add_items()` | `includes/Services/AlbumService.php` |
| Tag merge allows a self-merge or a stale target | guard removed from `merge_tags()` | `includes/REST/Controller/TagController.php::merge_tags` |
