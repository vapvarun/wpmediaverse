---
journey: bp-comment-sync-and-upload-lightbox
plugin: wpmediaverse
priority: high
roles: [subscriber, other-member]
covers: [bp-activity-comment-sync, bp-activity-lightbox-gallery]
prerequisites:
  - "BuddyPress active with the activity component (the Apache wp-env site)"
  - "dev-auto-login mu-plugin installed"
estimated_runtime_minutes: 6
---

# A BuddyPress post follows its media comments, and an Upload-page post steps in the lightbox

A media comment is mirrored onto the item's activity post as an activity comment.
`ActivitySyncIntegration` stores the mirror id in comment meta
`_mvs_bp_activity_comment_id`, threads a reply under its parent's mirror, and on
core's `delete_comment` / `edit_comment` removes or rewrites the mirror. The delete
also removes the owner's `update_reply` / `comment_reply` notifications.

## Steps

### 1. Comment and reply mirror, threaded (Member B, then Member A)
- **Action**: Member B comments on Member A's photo in the Explore lightbox; Member A replies.
- **Expect**: two `activity_comment` rows on the post; the reply's `secondary_item_id`
  is the comment's row; Member A has an "commented on one of your updates" notification.

### 2. Edit shows on the post
- **Action**: Member A edits the reply within the edit window.
- **Expect**: the reply's activity comment shows the new text.

### 3. Delete removes the thread from the post
- **Action**: Member B deletes the comment.
- **Expect**: both `wp_comments` rows and both `activity_comment` rows are gone; the
  activity post no longer lists them; Member A's notification for it is gone.

### 4. Upload-page post steps in the lightbox (1440 and 390)
- **Action**: Member A uploads 2+ photos from `/upload-media/`; on `/activity/` click the
  first photo of that post.
- **Expect**: counter `1 / N` and a Next arrow; ArrowRight shows `2 / N`. Same as a
  composer post with 2 photos.

## Pass criteria
- Steps 1-4 all hold for an older post whose stored content has no
  `.mvs-activity-media-grid` wrapper (the grid is built in the browser).

## Fail diagnostics
- Mirror rows stay after a delete: `ActivitySyncIntegration::delete_mirrored_activity_comment()`
  or the meta was never written in `sync_media_comment_to_activity()`.
- Lightbox opens one item: `assets/js/bp-activity-media.js` `openWithMediaId()` must look
  at `li.activity-item` before the browser-built grid, which carries no media ids.
