---
journey: moderation-approve-flow
plugin: wpmediaverse
priority: high
roles: [administrator]
covers: [moderation-queue, capability-moderate_mvs_media]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin"
  - "At least one media item with moderation_status='pending' (seed via wp eval)"
estimated_runtime_minutes: 3
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

## Pass criteria

Approve action transitions DB `moderation_status` to 'approved' AND moderation/counts decrements.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Approve returns 403 | Cap `moderate_mvs_media` not granted | `includes/Capabilities/MediaCapabilities.php` |
| DB unchanged | Approve handler swallowed error | `includes/REST/Controller/ModerationController.php::approve_item` |
| Row stuck in queue | Cache not invalidated | `includes/Services/ModerationService.php` |
