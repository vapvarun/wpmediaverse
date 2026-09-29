---
journey: tags-management
plugin: wpmediaverse
priority: normal
roles: [administrator]
covers: [MV-ADM-009, MV-ADM-010, MV-ADM-011, tags-list, tag-edit, tag-merge]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "20+ tags, at least two of them each linked to several media items"
estimated_runtime_minutes: 6
---

# Owner searches/sorts the Tags list, edits a tag, then merges two tags and confirms reassignment

**Why this journey exists**: Tags admin (`includes/Admin/TagManagementPage.php`) is the
one place an owner can clean up a media library's taxonomy without touching the
database directly. The merge tool is destructive and, above a threshold, runs as a
background Action Scheduler batch (`mvs_tag_merge_batch`) rather than one giant query —
this journey has to prove the reassignment actually lands, not just that the confirm
dialog fired.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Tags: `$SITE_URL/wp-admin/admin.php?page=mvs-tags`
- Pick two tags: `SOURCE_TAG` (several linked media) and `TARGET_TAG`.

## Steps

### 1. Tags list — search, clear, sort, paginate
- **Action**: search by tag name; confirm a "Clear" link appears next to the search box
  while a search is active (`admin.php?page=mvs-tags` base URL); clear it; sort by Name,
  then by Count; page through results if 20+ tags exist.
- **Expect**: sortable Name/Count columns; the "Clear" link only shows with an active
  search.
- **On fail**: `includes/Admin/TagManagementPage.php` (search/sort query building).

### 2. Single empty-state message (no filtered-vs-empty split, unlike All Media)
- **Action**: search for a tag name that matches nothing.
- **Expect**: "No Tags Found" / "No tags found." — the SAME message used for a genuinely
  empty tag library. Do not expect a distinct "no results for your search" variant; this
  is documented as intentionally different from the All Media list's two-state pattern.

### 3. Bulk delete via top+bottom bulk-actions bar
- **Action**: select several tags still linked to media, Bulk Actions > Delete, Apply
  (nonce `bulk-tags`).
- **Expect**: WP admin list-table convention (bulk bar top AND bottom); tags are removed
  and the media rows that referenced them are NOT broken — the tag reference simply
  drops.
- **On fail**: `TagManagementPage.php` bulk-delete handler.

### 4. Tag edit screen — rename, slug, Linked Media table
- **Action**: open a tag's edit screen (`&action=edit&tag_id=X`); change name and slug,
  Update; confirm the "Edit Tag: %s" title and the Linked Media table (ID/Title/Type/
  Date) lists correctly, paginating if the tag has many items.
- **Expect**: "This tag is linked to %d media item(s)." count message matches the actual
  linked count. A tag with zero linked media shows "No media items are linked to this
  tag." rather than an empty table with bare headers.
- **On fail**: `TagManagementPage.php` edit-screen render + Linked Media query.

### 5. Slug collision doesn't fatal
- **Action**: edit a tag's slug to collide with an existing term's slug, Update.
- **Expect**: WP's own term-slug uniqueness handling kicks in (auto-suffix or rejection)
  — no fatal error.

### 6. Delete a single tag from the edit screen
- **Action**: use the delete link on the edit screen.
- **Expect**: JS confirm "Are you sure you want to delete this tag?" fires before the
  request.

### 7. Merge tool — small merge (inline, synchronous)
- **Action**: on `SOURCE_TAG`'s edit screen, "Merge Into Another Tag" > select
  `TARGET_TAG`, submit; confirm the JS confirm "Merge this tag into the selected tag?
  This cannot be undone." fires first (nonce `mvs_merge_tags_{source_id}`).
- **Expect**: reassignment runs inline for a small tag (below `MERGE_LARGE_THRESHOLD`
  in `TagManagementPage.php`); confirm via DB every media item previously carrying
  `SOURCE_TAG` now carries `TARGET_TAG`, and `SOURCE_TAG` term is gone or emptied.
- **On fail**: `TagManagementPage.php::handle_merge()` / `process_merge_batch()`.

### 8. Merge tool — large merge runs as a background batch
- **Action**: if a tag with linked-media count above `MERGE_LARGE_THRESHOLD` is
  available (seed one if not), merge it; confirm the response redirects with
  `merged=queued` rather than `merged=1`, and that `mvs_tag_merge_batch` shows up as a
  scheduled Action Scheduler action (`wp action-scheduler list` or the AS admin screen).
- **Expect**: large merges never run as one giant synchronous query — they are handed to
  `as_enqueue_async_action( TagManagementPage::MERGE_HOOK, ... )`. Wait for the batch to
  complete (or run it manually) and re-verify reassignment as in step 7.
- **On fail**: `TagManagementPage.php::handle_merge()` threshold branch.

### 9. Merge validation errors
- **Action**: attempt to merge a tag into itself; attempt a merge where the target tag
  was deleted between page-load and submit.
- **Expect**: "Choose two different tags to merge." for the self-merge case; "One of the
  selected tags no longer exists." for the stale-target case. Also confirm "No other
  tags exist to merge into." shows when only one tag exists site-wide.
- **On fail**: `TagManagementPage.php::handle_merge()` validation branch.

## Pass criteria

ALL of the following hold:

1. Search/Clear/sort/pagination work and the single empty-state message is used consistently (no filtered-vs-empty split).
2. Bulk delete removes tags without breaking media rows that referenced them.
3. Tag edit persists name/slug changes and the Linked Media table/count is accurate, including the zero-linked-media empty message.
4. A small merge reassigns every linked media item from source to target tag inline; a merge above the threshold runs via Action Scheduler and still lands the same reassignment.
5. Self-merge and stale-target merge attempts are rejected with the exact documented messages.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Merged media still carries the old tag | Batch didn't complete, or reassignment query wrong | `TagManagementPage.php::process_merge_batch()` |
| Large merge blocks the request (no queued state) | Threshold branch not routing to Action Scheduler | `TagManagementPage.php::handle_merge()` |
| Search shows a different empty message than an empty library | An extra empty-state branch was added, diverging from documented single-message behavior | `TagManagementPage.php` |
| Deleting a linked tag corrupts the media row | Delete handler touches media rows directly instead of just detaching the term | `TagManagementPage.php` bulk-delete handler |
