# Plan: Collections membership unification + journey completion

Status: PROPOSED (awaiting approval to implement). Owner decision needed on the canonical store (see Decision).

## Problem

Manual collections do not reflect items added through the Pro "Save to collection" picker:
a manual collection populated via the picker shows **0 items and no cover** on the My Media
dashboard (reproduced live: collection "vapvarun" with 1 image saved → "0 items").

## Root cause (confirmed at code level)

Manual-collection membership has **two disconnected stores**:

- **Pro writes** membership to `wp_mvs_pro_collection_items` — `CollectionItemsService::set_membership()`
  (the picker's add/remove). Its UNIQUE key is `(user_id, media_id, collection_id)`, so it
  correctly supports a media being in **multiple** collections for one user.
- **Free reads** manual membership from `wp_mvs_favorites WHERE collection_id = X` —
  `CollectionController::prepare_collection_response()` (count + `favorites` payload) and
  `get_collection_cover_url()`. The `[mvs_collection]` shortcode and `FavoriteService` use the
  same store via the `mvs_collection_media_ids` filter.

The two stores never reconcile, so picker-added items are invisible to the count/cover/shortcode.

**Why the split exists:** `wp_mvs_favorites` has `UNIQUE KEY unique_favorite (media_id, user_id)`
(Migrator:409). That constraint allows only **one** row per (media, user), so it cannot represent
a media living in multiple manual collections (nor favorite + collection at once). Pro added a
proper multi-membership table but never bridged it into Free's read paths — leaving the feature
half-built on both sides.

## Decision (recommended)

**Canonical manual-membership store = `wp_mvs_pro_collection_items`** (only it models multi-membership).
Keep **Free Pro-agnostic**: Free's manual read paths resolve members through the existing
`mvs_collection_media_ids` filter; Pro supplies the data by hooking that filter. `mvs_favorites`
remains the favorites store (heart) and a no-Pro fallback for single-collection membership.

Alternative considered and rejected: make `mvs_favorites` canonical — rejected because its
`(media_id, user_id)` unique key cannot hold multi-collection membership without a schema change
that would also collide with the favorite row.

## Changes

### Free (`wpmediaverse`)
1. `CollectionController::prepare_collection_response()` — manual branch: compute members via
   `FavoriteService::get_collection_media_ids($id)` (which already applies the
   `mvs_collection_media_ids` filter) instead of the raw `mvs_favorites` query. Set `total` =
   count of resolved ids for manual collections (currently `total` is only set for smart).
2. Confirm the dashboard count field the JS reads (`view.js` loadCollections enrich) is populated
   for manual collections from the resolved ids; adjust the enrich call if it only reads `total`.
3. `get_collection_cover_url()` already runs the filter — verify it covers the manual branch.

### Pro (`wpmediaverse-pro`)
4. Hook `mvs_collection_media_ids` → return member media_ids for the collection from
   `CollectionItemsService` (most-recent-first, honor `$limit`). Merge with any incoming ids so a
   site using both stores is not lost.
5. Optionally hook `mvs_collection_response` to expose `total`/`items` for manual collections if a
   payload shape Free cannot fill is needed (prefer #4 only; avoid double sources).

### Data migration
6. One-time migrator: copy legacy `mvs_favorites` rows that carry a non-null `collection_id` into
   `mvs_pro_collection_items` (idempotent INSERT IGNORE), then stop writing collection membership
   to `mvs_favorites`. Leave favorite rows (collection_id NULL) untouched. Gate behind a DB-version
   bump; never destructive.

### QA (close the process gap that let this ship green)
7. Add to `docs/qa/AGENT_SMOKE_RUNBOOK.md` a **Collections lifecycle** journey:
   create manual collection → add a media from the lightbox Save picker → open My Media →
   Collections → assert it shows the right **count + cover** → remove → assert it updates.
8. Add two cross-cutting runbook rules: **no silent action** (every toggle/submit shows
   saving/saved/error) and **no dead-end** (anything created/saved has a reachable view).

## Live verification (must run on mediaverse, not buddynext)

NB: shell `wp` and `mcp-local-wp` bind to **buddynext** here (cwd). Verify against the real
mediaverse DB via the web (temp mu-plugin logging `$wpdb`) or wp-cli pointed at mediaverse's socket.

1. Create a manual collection, add 1 media via the picker → dashboard shows 1 item + that media's
   cover. 2. Add the same media to a second manual collection → both show it (multi-membership).
   3. Remove from one → count/cover update. 4. `[mvs_collection id=...]` renders the same members.
   5. Re-run the (now idempotent) seeder → exactly one of each demo collection.

## Already shipped this pass (context)

Decouple (heart = one-tap favorite; separate Save control), picker feedback/states, "View your
collections" link, seeder idempotency, banner removal, button-color fix, points pill. This plan
finishes the remaining foundational gap (the two-store split) + the QA coverage.
