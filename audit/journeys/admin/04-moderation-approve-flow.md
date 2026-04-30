---
journey: moderation-approve-flow
plugin: wpmediaverse
priority: high
roles: [administrator]
covers: [moderation-queue, capability-moderate_mvs_media]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin"
  - "At least one media item in 'pending' status (seed via wp eval)"
estimated_runtime_minutes: 3
---

# Admin approves a pending media item; status flips to published

## Steps

### 1. Auto-login admin
- **Action**: `playwright_navigate $SITE_URL/wp-admin/?autologin=1`
- **Expect**: dashboard loads.

### 2. Open Moderation page
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=wpmediaverse-moderation`
- **Expect**: page renders, table contains at least one pending row. Capture `PENDING_ID`.

### 3. Click Approve
- **Action**: click `.mvs-approve-button[data-id="$PENDING_ID"]` (or hit REST `/moderation/$PENDING_ID/approve`).
- **Expect**: HTTP 200 from REST, row disappears from queue or shows status "approved".

### 4. Verify DB
- **Action**: `mysql_query "SELECT status FROM wp_mvs_media_index WHERE id=$PENDING_ID"`
- **Expect**: `status='published'`.

### 5. Verify counts
- **Action**: `curl -H 'X-WP-Nonce: $NONCE' $SITE_URL/wp-json/mvs/v1/moderation/counts`
- **Expect**: `pending` count decremented by 1.

## Pass criteria

Approve action transitions DB status to 'published' AND moderation/counts decrements.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Approve returns 403 | Cap `moderate_mvs_media` not granted | `includes/Capabilities/MediaCapabilities.php` |
| DB unchanged | Approve handler swallowed error | `includes/REST/Controller/ModerationController.php::approve_item` |
| Row stuck in queue | Cache not invalidated | `includes/Services/ModerationService.php` |
