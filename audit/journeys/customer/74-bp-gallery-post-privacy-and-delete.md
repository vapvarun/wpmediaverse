---
journey: bp-gallery-post-privacy-and-delete
plugin: wpmediaverse
priority: critical
roles: [subscriber, other-member, anonymous]
covers: [bp-activity-privacy-sync, bp-activity-media-delete]
prerequisites:
  - "BuddyPress active with the activity component (the Apache wp-env site)"
  - "dev-auto-login mu-plugin installed"
estimated_runtime_minutes: 8
---

# A BuddyPress post follows its media: privacy and deletes (Basecamp 10392976094)

An upload-page batch becomes ONE activity post. Its items carry the post id as
`bp_activity_id`; unlike composer posts it has no `_mvs_media_ids` list.
`ActivitySyncIntegration::activity_media_ids()` reads either, and both the privacy
sync and the delete cleanup use it.

## Steps

### 1. Batch upload (Member A)
- **Action**: upload 3 photos together from `/upload-media/`.
- **Expect**: one activity post; every item's `bp_activity_id` is that post; the post's
  `item_id` is the first item (cover).

### 2. Strictest item wins
- **Action**: make the second item Only me, then Members, then public again (My Media or
  `PATCH /mvs/v1/media/{id}`).
- **Expect**: Only me -> post `hide_sitewide 1`, level private, gone for Member B and the
  visitor; Members -> level members; public again -> public. With the cover private, the
  post stays private whatever the other items do.

### 3. Deleting the cover keeps the post
- **Action**: delete the cover.
- **Expect**: the post still exists with the other two photos and the privacy of the
  strictest remaining item. (Before 2.6.2 the cover's `item_id` match deleted the post.)

### 4. Deleting the last item removes the post
- **Action**: delete the remaining items.
- **Expect**: the post is gone.

### 5. Group posts are not touched by a media id that equals a group id
- **Action**: with a group whose id equals a profile media id, delete that media.
- **Expect**: the group's upload post (its `item_id` is the group id) stays.
