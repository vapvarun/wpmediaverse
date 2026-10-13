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

### 2. The post follows its most open item; each tile its own item (owner decision 13 Oct 2026)
- **Action**: make the first item (the cover) Only me (My Media or `POST /mvs/v1/media/{id}`).
- **Expect**: the post stays listed (`hide_sitewide 0`, level public) for Member B and the visitor,
  showing the other items only; the owner still sees all of them. No tile of the private item is
  printed for anyone else (`ActivityContentIntegration::refresh_broadcast_urls()`,
  `ActivityMediaLinkage::render()`).
- **Action**: make every item Only me.
- **Expect**: the post is hidden sitewide, as before.
- **Fails on 8d86e49e**: the whole post vanished for everyone when one item was private.

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
