---
journey: media-list-and-actions
plugin: wpmediaverse
priority: high
roles: [administrator, subscriber]
covers: [MV-ADM-004, MV-ADM-005, MV-ADM-006, MV-ADM-007, MV-ADM-008, all-media-list, bulk-actions, row-actions, optimize, documents-pro-gate]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "20+ media items across type/privacy/status; one AI-flagged item; one oversized image; one item with a missing thumbnail"
  - "Free-only site: WPMediaVerse Pro NOT active, no legacy pre-2.4.0 document rows"
estimated_runtime_minutes: 8
---

# Owner works the All Media admin list end-to-end, then confirms Documents stays hidden in Free

**Why this journey exists**: `admin.php?page=mvs-media` (`includes/Admin/MediaListPage.php`)
is the backend entry point for the whole media library (Coding Rule 18) — filters, bulk
actions, per-row actions, and the Optimize/repair-thumb pair all have to work together
on one screen, at 2000+ rows without falling over. It also verifies the Documents admin
screen (Pro-gated by `mvs_documents_enabled`, default `false`) is genuinely absent on a
Free-only site, not just hidden by CSS.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- List: `$SITE_URL/wp-admin/admin.php?page=mvs-media`
- For the big-site check: `wp mvs` has no seed command for this, so seed via
  `wp eval-file` inserting 2000+ `mvs_media_index` rows directly, or reuse an existing
  large dataset if one is already seeded on this install.

## Steps

### 1. List renders with live status-tab counts
- **Action**: open the page.
- **Expect**: `mvs-stat-card` tiles for All/Published/Pending/Draft/Trash with live
  counts; columns Thumb, Title, Author, Type, Privacy, Status, AI, Date.
- **On fail**: `includes/Admin/MediaListPage.php` (stat tiles / status tabs).

### 2. Filter by Type, Privacy, and search combine via GET
- **Action**: filter by Type, then add a Privacy filter, then a title search — confirm
  all three combine (URL carries all three GET params, results narrow further each
  time), not each resetting the others.
- **Expect**: filtered result set matches the AND of all active filters.
- **On fail**: `MediaListPage.php` query-building for `$_GET` filters.

### 3. Two distinct empty states
- **Action**: apply a filter combination that matches nothing; note the empty state;
  then clear filters on a genuinely empty library (or a test install with zero media).
- **Expect**: filtered-empty shows "No media matches your filters" + a visible "Clear
  filters" link (`admin.php?page=mvs-media` base URL, `.button` class per the source);
  truly-empty (no filters, no media at all) shows "No media yet... Once users upload
  media it will appear here." These two must not be conflated.
- **On fail**: `MediaListPage.php` empty-state branch (~L243-260).

### 4. Big-site pagination at 2000+ rows
- **Action**: with 2000+ seeded rows, page through results (`paginate_links()`, 20/page)
  and observe response time.
- **Expect**: server-side LIMIT/OFFSET pagination — no full-table scan, no client-side
  slicing of a giant result set. Confirm via `mysql_query "EXPLAIN ..."` or by checking
  the query in `MediaListPage.php` carries `LIMIT`/`OFFSET`.
- **On fail**: `MediaListPage.php` list query.

### 5. Capability-less direct visit is a hard `wp_die()`
- **Action**: as a role without `manage_options`/`upload_mvs_media`, hit the URL
  directly.
- **Expect**: `wp_die()` permission page, not an empty list rendered for a role that
  shouldn't see the screen at all.

### 6. Bulk actions: Trash, Restore, Delete permanently
- **Action**: select several Published items, "Move to Trash", Apply; confirm the
  singular/plural notice; switch to the Trash tab, select items, "Restore"; select
  items, "Delete permanently" — confirm the native JS confirm fires FIRST with
  "Permanently delete the selected media? This cannot be undone." (`confirmDelete`
  i18n string, `MediaListPage.php` L45) before any request.
- **Expect**: exact `_n()` message "%d item moved to Trash." / "%d items moved to
  Trash." (verified string, `MediaListPage.php` L73) with correct singular for exactly
  1 item; nonce `mvs_bulk_nonce` / action `mvs_bulk_media` required; redirect happens
  on the `load-` hook (no "headers already sent" warning).
- **On fail**: `MediaListPage.php` bulk-action handler.

### 7. Bulk with nothing selected — client-side only
- **Action**: click Apply with zero items checked.
- **Expect**: "No media selected." (`noMedia` i18n string, L44) shown client-side — no
  network request fires (`read_network_requests` shows nothing new).
- **On fail**: bulk-action JS guard before form submit.

### 8. Row actions render conditionally on status
- **Action**: hover a Published row (Trash link only, no Restore/Delete Permanently);
  switch to Trash view (Restore + Delete Permanently only, no Trash link).
- **Expect**: action set matches status exactly as described.

### 9. AI Review row action
- **Action**: on the AI-flagged item, click "AI Review" (`admin.php?page=mvs-media&view=ai-review&media_id=X`).
- **Expect**: mini-page shows suggested description/tags with accept/reject controls.
  Visit the same URL with a media_id that no longer exists (delete it after copying the
  URL) — expect "Media not found." with a link back to All Media, not a fatal.
- **On fail**: `MediaListPage.php::render_ai_review_page()` / the "Media not found" branch (L610, L959).

### 10. Optimize row action
- **Action**: trigger "Optimize" on the oversized image row (nonce
  `mvs_optimize_media_{id}`).
- **Expect**: success notice "Optimized media #%1$d: %2$s to %3$s (saved %4$s%%)." with
  real numbers substituted. Run Optimize a SECOND time on the same now-optimized file —
  it must not further shrink or corrupt it (idempotent).
- **On fail**: `MediaListPage.php` optimize case (~L843-871), `ImageOptimizationService`.

### 11. Repair-thumb row action — eligible vs. not
- **Action**: trigger repair-thumb on an item with a missing thumbnail that DOES have a
  local original or an embedded video cover; then on one that has neither.
- **Expect**: eligible item's thumbnail is regenerated; the ineligible item's failure
  message explains WHY ("needs its original file on local disk; a video needs a cover
  image embedded in the file") — not a generic failure.
- **On fail**: `MediaListPage.php` repair_thumb case (L826-833), `StorageRepairService`.

### 12. Documents admin screen is genuinely absent in Free (cross-ref MV-ADM-008)
- **Action**: with Pro inactive and no legacy document rows, look for a "Documents"
  item under the MediaVerse admin menu; then attempt `admin.php?page=mvs-documents`
  directly (or whatever `DocumentListPage`'s slug resolves to).
- **Expect**: no menu entry appears (gated by `apply_filters( 'mvs_documents_enabled',
  false )` in `Core\Plugin.php` L2106, consumed at the `add_submenu_page()` call site
  L1192); direct URL visit does not render the list (submenu was never registered, so
  WP's own admin-menu guard refuses it). This is the Free-side half of the check that
  Pro's own journey (`wpmediaverse-pro/audit/journeys/admin/02-documents-toggle-gates-surfaces.md`
  step 6) exercises from the other direction — do not duplicate Pro's steps here, only
  confirm the absence.
- **On fail**: `includes/Core/Plugin.php::documents_enabled()` (L2106) if it defaults `true`.

## Pass criteria

ALL of the following hold:

1. Filters combine correctly and both empty states are distinct and correctly worded.
2. 2000+ row pagination stays server-side (LIMIT/OFFSET), no full-scan.
3. Capability-less access is a hard `wp_die()`.
4. Bulk trash/restore/delete-permanently work with correct pluralization and the destructive confirm gates delete-permanently only.
5. Row actions (View/Edit/Trash/Restore/Delete/AI Review) render conditionally on status and AI-flag presence; a stale AI Review link fails gracefully.
6. Optimize produces a real before/after message and is idempotent on a second run; repair-thumb explains ineligibility rather than failing silently.
7. Documents admin screen and menu entry are absent on a Free-only site.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Filtered-empty and truly-empty show the same message | Empty-state branch collapsed | `includes/Admin/MediaListPage.php` |
| "1 items moved to Trash" (wrong plural) | `_n()` misused or hardcoded string | `MediaListPage.php` bulk handler |
| Delete Permanently fires without confirm | JS confirm binding missing/removed | admin media-list JS |
| Optimize corrupts a re-optimized file | Pipeline not idempotent | `Services/ImageOptimizationService.php` |
| Documents menu appears on Free | `mvs_documents_enabled` defaults true or gate removed | `includes/Core/Plugin.php` |
