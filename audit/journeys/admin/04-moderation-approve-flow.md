---
journey: moderation-approve-flow
plugin: wpmediaverse
priority: high
roles: [administrator]
covers: [moderation-queue, capability-moderate_mvs_media, MV-ADM-012, MV-ADM-013]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin"
  - "At least one media item with moderation_status='pending' (seed via wp eval)"
  - "At least 2 more pending items and 2 rejected items for the bulk-action steps"
estimated_runtime_minutes: 5
---

# Admin approves a pending media item; moderation_status flips to approved

## Setup

The queue is empty on a clean install (all demo media is `approved`). Seed exactly one pending item from existing media so nothing is created or deleted, then restore it at the end:

- **Seed**: pick one approved media id and flip it to pending, capturing the original so it can be restored:
  ```bash
  PENDING_ID=$(wp eval 'global $wpdb; $t=$wpdb->prefix."mvs_media_index"; $id=(int)$wpdb->get_var("SELECT media_id FROM $t WHERE moderation_status=\"approved\" ORDER BY media_id ASC LIMIT 1"); $wpdb->update($t,["moderation_status"=>"pending"],["media_id"=>$id]); echo $id;')
  ```
- **Cleanup (always run, even on fail)**: `wp eval 'global $wpdb; $wpdb->update($wpdb->prefix."mvs_media_index",["moderation_status"=>"approved"],["media_id"=>'"$PENDING_ID"']);'`

## Steps

### 1. Auto-login admin
- **Action**: `playwright_navigate $SITE_URL/wp-admin/?autologin=1`
- **Expect**: dashboard loads.

### 2. Open Moderation page
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=mvs-moderation&tab=pending`
- **Expect**: page renders, table contains at least one pending row. Capture `PENDING_ID`.

### 3. Click Approve
- **Action**: click `.mvs-approve-button[data-id="$PENDING_ID"]` (or hit REST `/moderation/$PENDING_ID/approve`).
- **Expect**: HTTP 200 from REST, row disappears from queue or shows status "approved".

### 4. Verify DB
- **Action**: `mysql_query "SELECT moderation_status FROM wp_mvs_media_index WHERE media_id=$PENDING_ID"`
- **Expect**: `moderation_status='approved'`.

### 5. Verify counts
- **Action**: `curl -H 'X-WP-Nonce: $NONCE' $SITE_URL/wp-json/mvs/v1/moderation/counts`
- **Expect**: `pending` count decremented by 1.

### 6. Reject a single item with a reason
- **Action**: on a second pending item, POST `mvs_moderation_action=reject` (nonce
  `mvs_moderation_nonce`/action `mvs_moderation_action`) with a reason string via the
  admin form (or the row's Reject button).
- **Expect**: redirect notice reads exactly "Media item rejected." and the item leaves
  the Pending tab. `mysql_query` confirms `moderation_status='rejected'`.
- **On fail**: `includes/Admin/ModerationQueue.php::handle_actions()` single-action branch.

### 7. Rejected item stays visible to its owner, refused to everyone else
- **Action**: as the rejected item's owner, view its permalink, signed file URL and
  thumbnail URL — all must still resolve. As a different logged-in member (or logged
  out), request the same three URLs.
- **Expect**: owner/moderator sees the item (enforced in `PrivacyService::check_access()`);
  every other viewer is refused at all three surfaces alike. The owner's view must be
  visibly distinguishable as rejected (e.g. a "Rejected" badge), not indistinguishable
  from a normal approved item.
- **On fail**: `includes/Services/PrivacyService.php::check_access()`.

### 8. Bulk approve (REQUIRED — MV-ADM-013 explicitly names bulk, not just single)
- **Action**: seed 2 more pending items; on the Moderation queue, select both, Bulk
  Actions > "Approve Selected" (`value="bulk_approve"`), Apply (nonce
  `mvs_moderation_bulk_nonce`/action `mvs_moderation_bulk`).
- **Expect**: redirect with `updated=bulk_approved&count=2`; notice reads "2 items
  approved." (correct `_n()` plural). Repeat with exactly 1 item selected and confirm
  "1 item approved." (correct singular — a tester selecting exactly 1 item must not see
  "1 items"). DB: both items' `moderation_status` flip to `approved`.
- **On fail**: `ModerationQueue.php::handle_actions()` bulk branch (`bulk_approve` case,
  ~L95-104).

### 9. Bulk reject
- **Action**: seed 2 rejected-eligible (pending) items; select both, Bulk Actions >
  "Reject Selected" (`value="bulk_reject"`), Apply.
- **Expect**: redirect with `updated=bulk_rejected&count=2`; notice "2 items rejected."
  with correct singular/plural as in step 8. DB: both items' `moderation_status` flip
  to `rejected`.
- **On fail**: `ModerationQueue.php::handle_actions()` bulk branch (`bulk_reject` case).

### 10. Multi-actor concurrency — already-handled item
- **Action**: open the moderation queue in two admin sessions (or capture the
  Approve link for one pending item, have a second admin approve/reject it first, then
  submit the first captured action against the now-resolved item).
- **Expect**: a clear "already handled" outcome (no duplicate action fired, no silent
  no-op that leaves the acting admin thinking nothing happened) — not a fatal, not a
  second moderation entry.
- **On fail**: `ModerationQueue.php::handle_actions()` — check whether it re-reads
  current status before acting or blindly re-applies.

### 11. Moderation queue tabs — dynamic AI Flagged, live counts, positive empty state
- **Action**: with `mvs_ai_auto_moderate` off and `moderation/counts` reporting
  `flagged: 0`, open `$SITE_URL/wp-admin/admin.php?page=mvs-moderation`. Then flip
  `mvs_ai_auto_moderate` on (or flag an item) and reload. Switch between Pending
  Review / Resolved-Rejected / AI Flagged tabs, noting each tab's count badge against
  a fresh `moderation/counts` call. Finally, view a tab with zero items in that status.
- **Expect**: with AI moderation off and zero flagged, "AI Flagged" tab is absent
  entirely — not shown-but-empty (`ModerationQueue::build_tabs()`). With AI moderation
  on OR any flagged item, the tab returns. "Pending Review" and "Resolved / Rejected"
  are always present. The first tab in the built set (`array_key_first($tabs)`) is the
  default when no `?tab=` is given. Each tab's badge count matches `moderation/counts`
  exactly. An empty queue tab shows "Queue is Clear" / "No items in this queue. All
  clear!" — a positive empty state, not a bare table.
- **On fail**: `includes/Admin/ModerationQueue.php::build_tabs()` (AI Flagged
  inclusion logic), `render_page()` (default-tab resolution and empty-state copy).

## Pass criteria

1. Single approve/reject each transition DB `moderation_status` correctly and show the exact documented notice.
2. `moderation/counts` decrements/updates to match every single and bulk action.
3. Bulk approve and bulk reject both work, with correct `_n()` singular/plural in the notice for 1 vs. many items.
4. A rejected item stays visible (with a visible "Rejected" badge) to its owner/moderator and is refused to everyone else at permalink, signed file URL, and thumbnail URL alike.
5. Acting on an already-resolved item produces a clear "already handled" outcome, not a duplicate action or silent no-op.
6. The AI Flagged tab appears/disappears per the documented rule, the default tab is the first non-empty-by-rule tab, and an empty tab shows the positive "Queue is Clear" copy.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Approve returns 403 | Cap `moderate_mvs_media` not granted | `includes/Capabilities/MediaCapabilities.php` |
| DB unchanged | Approve handler swallowed error | `includes/REST/Controller/ModerationController.php::approve_item` |
| Row stuck in queue | Cache not invalidated | `includes/Services/ModerationService.php` |
