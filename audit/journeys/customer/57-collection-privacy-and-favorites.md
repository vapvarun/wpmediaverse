---
journey: collection-privacy-and-favorites
plugin: wpmediaverse
priority: high
roles: [subscriber, anonymous]
covers: [MV-COL-002, MV-COL-003, MV-COL-004, MV-COL-005, MV-COL-006, MV-FAV-001, MV-FAV-002, MV-FAV-003, MV-FAV-004, collection-privacy, favorites-collection, favorite-toggle-card]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Member A and Member B exist; Member B owns a public item"
  - "Member A has 10+ favourited items across different titles/dates for the listing step"
estimated_runtime_minutes: 8
---

# Collections are Public/Members-only-only and never cascade privacy; Favorites is the one automatic, locked collection

## Setup

- Member A (`?autologin=<memberA>`), Member B (`?autologin=<memberB>`, owns `$PUBLIC_ITEM` and a `members`-privacy item `$MEMBERS_ITEM`).

## Steps

### 1. Collection privacy select offers only two choices (MV-COL-002)
- **Action**: open the create/edit modal for any of Member A's collections; inspect the privacy select's options; compare against an album's privacy select (4+ levels).
- **Expect**: ONLY "Public" and "Members Only" appear on the collection — no "Private" or "Friends" option anywhere.
- **Action**: `mysql_query "UPDATE wp_postmeta SET meta_value='private' WHERE post_id=<a collection id> AND meta_key='_mvs_collection_privacy'"` (simulate a legacy/other stored value); reload the collection.
- **Expect**: it reads back coerced to `public`, not `private`.

### 2. Collection privacy never cascades to contained items (MV-COL-003)
- **Action**: as Member A, create/curate a collection containing `$PUBLIC_ITEM` (owned by Member B, not Member A); change the collection's privacy from Public to Members Only; Save.
- **Expect**: NO confirm dialog fires (there is nothing being widened on someone else's item — deliberate contrast with MV-ALB-003). `mysql_query "SELECT privacy FROM wp_mvs_media_index WHERE media_id=$PUBLIC_ITEM"` is STILL `public` — Member A's collection-privacy change never touched Member B's photo.
- **Action**: as Member A (non-owner curator), attempt to add `$MEMBERS_ITEM` (Member B's members-only item, which Member A CAN view) to the same collection.
- **Expect**: refused — `CollectionService::may_contain()` only allows public media or the curator's own media into a collection; a non-owner cannot gather someone else's non-public item even if they can view it. Also confirm a document cannot be added to any collection.

### 3. Favorites is the one automatic, locked collection (MV-COL-004)
- **Action**: as Member A, favourite 2-3 items across different pages (Explore, a single-media page); open `/my-media/favorites/` (or the Favorites card in the Collections panel — NOT on the main dashboard rail since 2.6.0).
- **Expect**: all favourited items appear; the card never carries a "Private:" title prefix that other private collections might show.
- **Action**: `DELETE /mvs/v1/collections/{favorites_id}` as Member A.
- **Expect**: `400 mvs_favorites_collection_locked` — cannot be deleted.
- **Action**: un-favourite one of the items from elsewhere (a grid card).
- **Expect**: it disappears from the Favorites listing live (or on next visit) with no separate "remove from collection" action.

### 4. Manual "Save to a collection" is gated off in stock Free (MV-COL-005)
- **Action**: open the Save/Favourite action on a media item from the lightbox, the single-media page, and the upload modal.
- **Expect**: CONSISTENT absence everywhere — no multi-collection picker on any surface; "Save" is simply the plain Favourite on/off toggle in all three places (`mvs_collections_enabled` filter defaults `false`).
- **Action**: read the Collections dashboard tab's intro copy.
- **Expect**: wording matching the "gathered by rules you set" framing (Free never promises a manual Save).
- **Action**: open the Collections tab's create modal and check whether a "manual" type option is offered.
- **Expect (report, don't silently accept)**: the catalog flags this as an open product question — a manual collection type is selectable in the create modal even though Free has no per-item Save-to-collection UI to ever fill it beyond the automatic Favorites. Confirm whether `collectionType: 'manual'` is still offered; if so, note it as the documented open gap, not a new bug.

### 5. Collection single page (MV-COL-006)
- **Action**: view a PUBLIC smart collection (with matching items) logged out at `/collection/<slug>/`.
- **Expect**: renders its rule-matched items to the visitor.
- **Action**: view a Members Only collection logged out, both at its dedicated single page AND embedded via `[mvs_collection id="…"]` on an unrelated, publicly-cached page.
- **Expect**: BOTH surfaces deny the logged-out visitor identically — the shortcode respects the same container privacy gate as the dedicated page (added 2.5.1 specifically because it previously leaked members-only contents).
- **Action**: view a collection whose rule currently matches 0 items, and separately a manual collection with 0 saved items.
- **Expect**: two DIFFERENT empty-state messages — "no items match rules" for the smart/rule case vs. an equivalent-but-distinct message for "nothing saved yet" on the manual case.

### 6. Favourite from a grid card, without opening the item (MV-FAV-001)
- **Action**: on Explore, click the favourite icon directly on a card (do not open the lightbox); click it again.
- **Expect**: same toggle as MV-MED-004; the icon updates immediately on the card itself with no need to open the item.

### 7. Favorites tab listing: search + sort (MV-FAV-002)
- **Action**: open the Favorites panel with 10+ favourited items; search by a known title fragment; sort by Title, then Date, then Favourited (default).
- **Expect**: the list narrows/reorders correctly for each control. An empty-favorites state ("nothing favourited yet") reads distinctly from a filtered-to-zero search result ("no results for {term}").
- **Action**: un-favourite an item from within this listing itself.
- **Expect**: it disappears from the list immediately, no refresh required.

### 8. Favouriting respects the view-privacy gate (MV-FAV-003)
- **Action**: as Member A, favourite Member B's `$MEMBERS_ITEM` (viewable, since Member A is a logged-in member).
- **Expect**: succeeds.
- **Action**: attempt to favourite a `private` item belonging to Member B that Member A cannot view (direct API call: `POST /mvs/v1/media/{privateId}/favorite`).
- **Expect**: refused via the same `PrivacyService::can_view()` gate used elsewhere (404, matching MV-MED-004's edge case) — no existence leak.

### 9. Un-favourite fires no notification (MV-FAV-004)
- **Action**: as Member A, favourite Member B's `$PUBLIC_ITEM` (Member B should get a `media_favorite` notification — check Member B's notification bell/DB). Then un-favourite it.
- **Expect**: exactly ONE `media_favorite` notification exists for Member B from this pair of actions (the initial favourite only); un-favouriting creates no new notification and does not retract the earlier one — `mysql_query "SELECT COUNT(*) FROM wp_mvs_notifications WHERE type='media_favorite' AND recipient_id=<memberB id> AND ..."` confirms exactly 1.

## Pass criteria

ALL of the following hold:
1. Collection privacy is Public/Members-only only; any other stored value coerces to Public.
2. A collection's privacy change never rewrites the privacy of contained items; `may_contain()` blocks a non-owner from gathering someone else's non-public media or any document.
3. Favorites is exactly one, always-private, undeletable (`mvs_favorites_collection_locked`) collection per member; un-favouriting elsewhere is reflected live.
4. No multi-collection Save picker anywhere in Free — consistently a plain Favourite toggle.
5. A public collection (and its shortcode embed) is visible to a visitor; a Members Only one denies a visitor identically on both the dedicated page and the shortcode; rule-zero and manual-zero show distinct empty states.
6. Grid-card favourite toggles without opening the item; the Favorites listing search/sorts correctly with distinct empty states; favouriting an unviewable item is refused via the privacy gate; un-favouriting fires no notification.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| "Private"/"Friends" appears on a collection's privacy select | collection privacy UI reusing the album's full privacy list | `src/blocks/dashboard-view/view.js` (collection modal) |
| Collection privacy change alters a contained item's own privacy | a cascade helper mistakenly applied to collections | `includes/Services/CollectionService.php` |
| `[mvs_collection]` shortcode leaks a Members Only collection to a visitor | the 2.5.1 shortcode-specific privacy gate removed | shortcode render path, `includes/Services/CollectionService.php` |
| Favorites collection can be deleted | `mvs_favorites_collection_locked` guard removed | `includes/REST/Controller/CollectionController.php` |
| A multi-collection "Save to…" picker appears anywhere | `mvs_collections_enabled` filter flipped to true, or a surface not checking it | wherever the Save/Favourite action renders per surface |
| Un-favouriting creates a notification | notification hook not scoped to the create-favourite path only | `includes/Social/FavoriteService.php`, `includes/Social/NotificationService.php` |
