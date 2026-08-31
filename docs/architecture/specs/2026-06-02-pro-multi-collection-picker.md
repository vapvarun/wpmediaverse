# Pro Multi-Collection "Save to" Picker — Design Spec

> **STATUS: IMPLEMENTED (Pro, as of 2.4.0).** Verified in code: the
> `mvs_pro_collection_items` table exists in `wpmediaverse-pro/includes/Core/Migrator.php`,
> served by `includes/Collections/CollectionItemsService.php` +
> `CollectionItemsController.php` (`GET/POST /mvs-pro/v1/media/{id}/collections`),
> with the Free-side seam in `wpmediaverse/src/blocks/shared-ui/view.js`
> (`mvs-collections-click` cancelable event and the inline "+ New collection"
> field). The "awaiting approval" line below is historical.

**Date:** 2026-06-02 · **Status:** awaiting approval · **Basecamp:** #9937408893 (Free card; Free part = "works as is", Pro part = this spec)

## Goal

Let members add a media item to **multiple** manual collections from a YouTube-"Save to"-style picker. Multi-collection is a **Pro** feature; Free keeps its existing single "Favorites".

## Tiering (decided)

- **Free — no functional change.** `mvs_favorites` stays as today and is treated as each user's **default/first "Favorites" collection** (the fallback). The collection CRUD (`mvs_collection` CPT, `CollectionService`, `CollectionController`, `GET/POST /collections`) stays in Free as plumbing; Pro builds on top via `Plugin::free_service()`.
- **Pro — new multi-collection UX.** Multi-membership lives in a **separate Pro table**. The picker's **first row is "Favorites"** and toggling it writes **Free** data (`mvs_favorites`); the other rows write the Pro table. Includes **"+ New collection"** inline.

## Data model

- **Free `mvs_favorites`** (unchanged): the Favorites membership. `collection_id = NULL` = plain favorite. UNIQUE(media_id, user_id) keeps it one-per-user.
- **Pro `mvs_pro_collection_items`** (new, Pro Migrator): `id`, `user_id`, `media_id`, `collection_id`, `created_at`, `UNIQUE(user_id, media_id, collection_id)`. Holds membership in any collection **other than** Favorites. (Favorites is never written here — it's Free's table.)

## Backend (Pro)

REST namespace `mvs-pro/v1`:
- `GET  /media/{id}/collections` → for the current user: `{ favorites: bool, collections: [{id,title,member:bool}], }`. Reads Free `mvs_favorites` for `favorites` + the Free collections list + the Pro table for `member`.
- `POST /media/{id}/collections` `{collection_id, member:bool}` → add/remove a Pro-table row. (Favorites toggling continues to use Free's existing `POST /media/{id}/favorite`.)
- Create-collection reuses Free `POST /collections` (manual), then adds the Pro-table row.

## Frontend (Pro) — the integration crux

The favorite/bookmark buttons live in **Free** blocks (`media-social`, `shared-ui`, `dashboard-view` view.js). Pro must augment that click to open the picker. Three options (pick one — see decision below):

- **A. Free extension point (recommended).** Free's `toggleFavorite` first runs a JS hook (`wp.hooks.applyFilters('mvs.favorite.click', …)` or a registered global). With no Pro, it's a **no-op** and the plain toggle runs (Free behavior identical). Pro registers a handler that opens the Pro picker instead. Tiny, behavior-preserving Free change.
- **B. Pro DOM interception.** Pro enqueues JS that capture-phase-intercepts clicks on the favorite button selector, `stopPropagation`s before the Interactivity handler, and opens the picker. No Free change, but fragile (depends on event ordering vs the Interactivity runtime).
- **C. Pro-owned button.** Pro hides Free's favorite button and renders its own "Save" control with the picker. No Free change, but duplicates UI and risks drift.

Picker UI (Pro): a popover anchored to the button — checkbox rows (Favorites first, then the user's manual collections, checked = member), a "+ New collection" inline input, done on outside-click. Toggling a row fires the matching POST. Built as a Pro script-module + style; rebuilt via Pro's wp-scripts.

## Phases

1. **Pro table** — Pro Migrator: create `mvs_pro_collection_items` (+ version bump).
2. **Pro REST** — the two endpoints above (read membership, toggle Pro membership), reusing Free services for collections + favorites.
3. **Free extension point** (if option A) — add the no-op `mvs.favorite.click` hook to Free's favorite action.
4. **Pro picker UI** — popover, fetch membership, wire toggles + inline create; enqueue + build.
5. **Verify (browser + DB)** — add a media to Favorites + two Pro collections + create one inline; confirm `mvs_favorites` holds the Favorites row and `mvs_pro_collection_items` holds the others; removing from one leaves the rest.

## Risks

- **UI integration** (above) is the main design risk — option A needs a small Free hook; B/C avoid it but are more fragile/duplicative.
- Pro Migrator version bump — Pro's Migrator is lower-churn than Free's, low collision risk.
- No Free schema change (the earlier `mvs_favorites` UNIQUE-key concern is avoided by using a Pro table).
