---
journey: api-media-crud-and-social
plugin: wpmediaverse
priority: critical
roles: [anonymous, member, owner, other-member, administrator]
covers: [MV-API-001, MV-API-002, MV-API-003, MV-API-004, MV-API-005, MV-API-006, MV-API-007, MV-API-008, MV-API-009, media-existence-masking, media-privacy-gate]
prerequisites:
  - "Site reachable at $SITE_URL, mvs/v1 namespace registered"
  - "Members A (owner) and B (same role, no 'others' caps), an admin account"
  - "A's media: one PUBLIC, one PRIVATE item; at least one member without upload_mvs_media"
  - "MV-SET-026 (reporting) toggled on for the report-tracking step"
estimated_runtime_minutes: 15
---

# Media CRUD, view/download/share/report tracking, signed URLs, comments and reactions all honor the same existence-masking / ownership rules

**Why this journey exists**: `MediaController`, `StatsController`,
`SignedUrlController`, `CommentController`, `ReactionController` and
`FavoriteController` together are the single largest attack surface in the
plugin — nine catalog areas, one shared privacy contract. A denied read must
look exactly like a missing item; a denied write must say so explicitly. This
file is compact by design: MV-API-002 (upload) already has full narrative
coverage in `customer/01-media-upload-public.md`, so its block here only
cross-references the ownership/permission contract, not the whole upload flow.
Grounded against `includes/REST/Controller/MediaController.php`,
`StatsController.php`, `SignedUrlController.php`, `CommentController.php`,
`ReactionController.php`, `FavoriteController.php`.

## Setup

- `$SITE_URL/wp-json/mvs/v1/` reachable.
- Member A: owns one public item (`$PUB_ID`) and one private item
  (`$PRIV_ID`).
- Member B: same role as A, no `edit_others_mvs_medias` /
  `delete_others_mvs_medias`.
- A member C without `upload_mvs_media` for the create-refusal step.

## Steps

### 1. MV-API-001 — Media: list and single read
- **Action**: unauthenticated `GET /mvs/v1/media`; unauthenticated `GET
  /mvs/v1/media/{$PRIV_ID}`; then the same read as A (owner).
- **Assert**: list returns only PUBLIC items, `X-WP-Total` reflects the
  visible set only (`RateLimiter::check('media_read', 120, 60)` gates the
  list too — burst past 120/min in 60s returns a rate-limit error). The
  private-item read returns `404 mvs_not_found` — byte-for-byte the same
  shape as a nonexistent ID (verify by also requesting `/media/999999999` and
  diffing the two responses: same status, same code, same message). As A,
  the same request returns normally. `GET /mvs/v1/media?slug=` (empty string)
  must not 500 (the `is_string()` + non-empty-after-trim guard in
  `get_items()`); `?include=id1,id2` returns items in request order, capped
  at 100, privacy-gated per ID.

### 2. MV-API-002 — Media: create (upload)
- **Action**: as member C (no `upload_mvs_media`), `POST /mvs/v1/media`.
- **Assert**: `403 mvs_forbidden` — permission_callback is
  `create_item_permissions_check`, never `__return_true`, on the create
  method of this route.
- **Cross-ref**: the full upload flow (limits, dedup, initial privacy,
  disallowed-type errors) is exercised end-to-end in
  `customer/01-media-upload-public.md` — do not re-walk it here.

### 3. MV-API-003 — Media: update and delete
- **Action**: as B, `PUT` and `DELETE` on A's public item; as B, `PUT` and
  `DELETE` on A's private item; as B, `PUT`/`DELETE` on id `99999999`; as A,
  edit/delete their own item; as admin, edit B's item.
- **Assert**: step 1 (B on A's PUBLIC item) → `403 mvs_forbidden` ("You do
  not have permission to edit/delete this media item.") — existence is
  NOT masked here, unlike the read routes (explicit-403 ownership pattern).
  Steps 2 and 3 (B on A's PRIVATE item, and on a nonexistent id) → the
  IDENTICAL `404 mvs_not_found` ("Media item not found.") for both — compare
  status, error code AND message string. Steps 4/5 succeed. A privacy CHANGE
  on a locked item (MV-SET-011) returns `403 mvs_privacy_locked` to the
  OWNER specifically, a different case from ownership refusal.
- **Sweep**: repeat the same B-vs-A pattern on `PUT/DELETE
  /mvs/v1/albums/{id}` (+ `/reorder`, `/items`, `/cover`), collections,
  `/media/{id}/comments/{cid}`, bulk "move to album", and deleting/unsending
  a message in someone else's conversation — the catalog names all of these
  as sharing this exact rule; spot-check at least the album sub-routes here.

### 4. MV-API-004 — Media: bulk actions
- **Action**: as a member, `POST /mvs/v1/media/bulk` on only owned items;
  then repeat including one item they don't own in the same `media_ids`
  array.
- **Assert**: single flat `edit_mvs_medias` check gates the whole endpoint
  (`bulk_permissions_check`), not per-action "edit others"/"delete others".
  The mixed-ownership batch is NOT refused outright — the response reports
  `requested` (IDs sent) and `skipped` = `requested - processed` (confirmed
  in code: `$data['skipped'] = max(0, $requested - (int)($data['processed']
  ?? 0))`), so the caller can tell some items were silently dropped. An empty
  or malformed `media_ids` is a clean `400`, not a fatal. A batch containing
  a document media_id is refused wholesale (`400 mvs_document_route`) —
  documents route through Pro's own document endpoints, not this one.

### 5. MV-API-005 — Media: replace file
- **Action**: as A, `POST /mvs/v1/media/{$PUB_ID}/replace` with a new file;
  note reactions/views/comments/album membership before and after.
- **Assert**: media ID, stats, reactions, views and album membership survive
  UNCHANGED (permission_callback is the same `update_item_permissions_check`
  as a plain edit — no bypass path); only thumbnail/variant metadata clears
  and regenerates. The same MIME/size/duplicate/EXIF rules as MV-API-002 are
  enforced on replace too — attempt a disallowed type/oversized file via
  `/replace` and confirm the same specific error as a fresh upload would
  give, and confirm a document is hard-refused via `/replace` exactly like
  the media-library upload path.

### 6. MV-API-006 — Media: view/download/share/report tracking
- **Action**: logged-out `POST /mvs/v1/media/{$PUB_ID}/view`; `POST
  .../report` with MV-SET-026 off, then on; report the same item twice; A
  reports their own item.
- **Assert**: view route is `permission_callback: '__return_true'` — succeeds
  logged out, increments the view counter (visible on MV-ADM-017). Report
  with reporting OFF is refused. Report with it ON lands in the Pending
  moderation queue and counts toward MV-SET-027's auto-hide threshold. A
  second report of the same item by the same reporter is refused `400` ("You
  may have already reported this."), not a silent duplicate. A reporting
  their OWN item is NOT blocked (only self-reporting a USER is blocked) —
  confirmed quirk, not a bug. Viewing a PRIVATE item you can't see must NOT
  record a view — the privacy gate blocks before the tracking side effect.

### 7. MV-API-007 — Media: signed URL, access, group, stats
- **Action**: as A, `GET /mvs/v1/media/{$PRIV_ID}/signed-url` (via
  `SignedUrlController::get_signed_url_permissions_check`); use it within TTL
  (MV-SET-009) and again past expiry; as B (no view rights), `GET
  .../{$PRIV_ID}/group` (via `MediaController`) and `GET .../stats` (via
  `StatsController`, `permission_callback: '__return_true'` at the route but
  privacy-checked inside — a private item's stats route returns the SAME
  `404 mvs_not_found` a nonexistent item would, per the code comment "Hidden
  looks exactly like missing").
- **Assert**: expired signed URL on the private item is refused; expired
  signed URL on a PUBLIC item still serves (documented cache-friendliness
  exception). `/group` on a private/album-linked item applies the same
  per-item privacy check as every other read. A tampered signature (flip one
  character) fails `hash_equals()` and is refused outright, no partial trust.

### 8. MV-API-008 — Media: comments
- **Action**: post a comment on a viewable item, edit it within the edit
  window (`mvs_comment_edit_window`, default 900s), then attempt an edit past
  the window; post the identical comment text twice within 60 seconds; as B,
  attempt to comment on A's private item B can't view.
- **Assert**: edit within window succeeds; past window, edit is refused with
  a message that explicitly says the window has closed (not a silent
  refusal). The 60-second duplicate-text guard blocks the second identical
  post. Comment routes use `create_item_permissions_check` for writes,
  `__return_true` for reads (with the same internal privacy check as
  everything else). Commenting on an unviewable private item is refused
  BEFORE the comment is created (mirrors MV-API-006's view-before-track
  ordering). `wp-admin > Comments > All Comments` never lists MVS comments by
  default (dedicated `comment_type`, explicitly filtered out of default
  comment queries).

### 9. MV-API-009 — Media: reactions and favorites
- **Action**: as A, react to an item with emoji 1, then react again with
  emoji 2; react with emoji 2 again (toggle-off); favorite the item then
  check `GET /mvs/v1/me/favorites`; unauthenticated `POST
  /mvs/v1/media/{id}/reactions` and `.../favorite`; un-favorite something
  never favorited.
- **Assert**: reacting with a second emoji REPLACES the first (one reaction
  per user per item, confirmed — not additive); reacting the identical way
  twice removes it (toggle). The favorited item appears in `/me/favorites`
  (MV-API-015). Unauthenticated attempts on either route → `401
  mvs_unauthorized`. DELETE on a never-reacted/favorited item is a graceful
  no-op, not an error. Reacting to a private item you can't view is refused,
  matching the comment/view pattern above.

## Pass criteria

1. Every read-route denial (list, single, stats, group, signed-url) is
   indistinguishable from "doesn't exist" — same status, code, message.
2. Every write-route denial on media (update/delete/bulk/replace) uses the
   explicit-403-for-wrong-owner / 404-for-nonexistent split, consistently.
3. `replace` never resets stats/reactions/views/album membership and enforces
   the same upload validation rules as create.
4. View/download/share tracking never fires on content the caller can't
   view; report/comment/reaction routes gate identically.
5. Bulk actions report `requested`/`skipped` honestly and never touch a
   document media_id.
6. A tampered or expired signed URL is refused on anything non-public, with
   no partial-trust fallback.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Private item read returns 403 instead of 404 | existence-masking removed from `get_item_permissions_check` | `includes/REST/Controller/MediaController.php` |
| `replace` resets reaction/view counts | replace path re-creating the media row instead of updating in place | `includes/REST/Controller/MediaController.php::replace_file` (known debt row, see CLAUDE.md) |
| Bulk endpoint silently drops items with no `skipped` count in the response | `filter_allowed_ids()` change not reflected in the response shape | `includes/REST/Controller/BulkController.php::handle_bulk` |
| Duplicate-report guard fails to block a second report | uniqueness check dropped from `ReportService` | `includes/Social/ReportService.php` |
| A view/comment/reaction fires on an unviewable private item | privacy check ordered AFTER the side-effect write | `includes/REST/Controller/MediaController.php`, `CommentController.php`, `ReactionController.php` |
