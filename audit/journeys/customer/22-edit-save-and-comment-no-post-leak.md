---
journey: edit-save-and-comment-no-post-leak
plugin: wpmediaverse
priority: critical
roles: [author, subscriber]
covers: [MV-MED-003, MV-MED-005, MV-MED-006, MV-MED-007, MV-MED-011, media-edit-persist, comment-post-id-zero, reaction-toggle]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
estimated_runtime_minutes: 6
---

# Member edits persist, and a media comment never leaks onto a WP post

**Member expectation**: when a member edits their media (title / description /
privacy) in the edit modal and saves, the change persists after reload. When a
member comments on media, the comment shows on that media and NEVER appears under
an unrelated blog post/page.

Guards:
- Edit modal save path (`shared-ui`/dashboard modal → `POST /media/{id}` →
  `MediaController::update_item`). Members reported "edits don't save".
- The site-breaking comment collision (Basecamp): media comments must store
  `comment_post_ID = 0` (media id in `mvs_media_id` meta), never `comment_post_ID =
  media_id` — otherwise a media comment surfaces on the post/page sharing that id.

## Setup

- Member A (`?autologin=<memberA>`) owns one image `$MEDIA_ID` (slug `$SLUG`).
- Note a real post whose ID equals `$MEDIA_ID` if one exists (the collision target);
  else just assert no `mvs_comment` ever carries a non-zero `comment_post_ID`.

## Steps

### 1. Open the edit modal and change title + privacy
- **Action**: `playwright_navigate $SITE_URL/my-media/?autologin=<memberA>`; open the item's Edit; set Title = "Journey Edit <ts>"; set Privacy = "Members"; click "Save changes".
- **Expect**: modal closes without error; no console error.

### 2. Verify the edit persisted (DB + reload)
- **Action**: `mysql_query "SELECT title, privacy FROM wp_mvs_media_index WHERE media_id=$MEDIA_ID"`; reload the page and re-open Edit.
- **Expect**: DB `title = 'Journey Edit <ts>'` AND `privacy = 'members'`; the reopened modal shows the new values.

### 3. Post a comment on a public media as a member
- **Action**: navigate to a PUBLIC `/media/{publicSlug}/`; type a comment in `.mvs-comment-form textarea`; submit.
- **Expect**: the comment appears in `.mvs-comment-list`; no console error.

### 4. The comment is stored detached from the WP post-ID namespace
- **Action**: `mysql_query "SELECT comment_ID, comment_post_ID, comment_type FROM wp_comments WHERE comment_content LIKE 'Journey%' ORDER BY comment_ID DESC LIMIT 1"` and the matching `mvs_media_id` meta.
- **Expect**: `comment_type = 'mvs_comment'`, `comment_post_ID = 0`, and `wp_commentmeta` has `mvs_media_id = <the media id>`.

### 5. The comment does NOT surface on any WP post/page
- **Action**: `mysql_query "SELECT COUNT(*) FROM wp_comments WHERE comment_type='mvs_comment' AND comment_post_ID <> 0"`; open the post/page whose ID equals the commented media id (if any).
- **Expect**: count is 0; the post/page comment thread does NOT show the media comment.

### 6. Reaction toggles and persists
- **Action**: on the same public media, click the `like` reaction (`.mvs-reaction-btn[data-reaction-type="like"]`).
- **Expect**: the button gains `.active`; a row exists in `wp_mvs_reactions` for that media + user.

### 7. Edit own comment within the edit window (MV-MED-006)
- **Action**: immediately after posting the comment from step 3, click its `Edit` button (`.mvs-comment-actions [data-wp-on--click="actions.startEditComment"]`), change the text, click `Save`.
- **Expect**: `PUT /mvs/v1/media/{media_id}/comments/{comment_id}` returns 200; the comment text updates in place with no page reload; `wp_comments.comment_content` reflects the new text.

### 8. Edit control disappears once the window has closed, and the server independently refuses
- **Action**: `mysql_query "UPDATE wp_comments SET comment_date='2020-01-01 00:00:00', comment_date_gmt='2020-01-01 00:00:00' WHERE comment_ID=<the same comment>"`; reload the media page.
- **Expect**: the `Edit` button is NOT rendered for this comment (`hideEditComment` getter in `src/blocks/media-social/view.js` evaluates `canEdit` from `commentAge < editWindow`, computed server-side and passed as `commentEditWindow`). Confirm the server refuses too: `PUT /mvs/v1/media/{media_id}/comments/{comment_id}` on the backdated comment returns 403 `mvs_edit_expired` — button-hidden and server-refused must agree; this is the same check MV-MED-020 makes for the Delete affordance.

### 9. Delete a comment (MV-MED-007)
- **Action**: as the comment's author, click `Delete` (`.mvs-comment-actions [data-wp-on--click="actions.deleteComment"]`); confirm in the dialog (Cancel focused by default).
- **Expect**: `DELETE /mvs/v1/media/{media_id}/comments/{comment_id}` returns 204; the comment disappears from `.mvs-comment-list` without a page reload; the row is gone from `wp_comments` (`wp_delete_comment( $id, true )` in `CommentService::delete()`).
- **Action**: as a moderator (`moderate_mvs_media` capability) who is NOT the author, delete a different member's comment on the same media.
- **Expect**: succeeds (204) — moderators may delete anyone's comment, confirmed by `CommentService::delete()`'s `user_can( $user_id, 'moderate_mvs_media' )` check.

## Pass criteria

ALL hold:
1. Title + privacy edits persist to the DB and survive reload.
2. A posted comment renders on the media.
3. Every `mvs_comment` has `comment_post_ID = 0` and `mvs_media_id` meta.
4. Zero `mvs_comment` rows with a non-zero `comment_post_ID`; no leak onto posts/pages.
5. Reaction toggles active and writes a row.
6. A comment edited inside the 15-minute window saves; past the window the Edit button is hidden AND the server independently returns 403 `mvs_edit_expired`.
7. The comment author can delete their own comment (204, DB row removed); a moderator can delete another member's comment.

## Fail diagnostics

- Edit not saved → the modal PUT/POST field mapping in `src/blocks/shared-ui/view.js` (dashboard modal), or `MediaController::update_item` args (must declare title/description/privacy/tags/categories).
- Comment stored with `comment_post_ID = media_id` → `Social/CommentService::add()` regressed; must insert `comment_post_ID = 0` + `add_comment_meta(MEDIA_META_KEY)`. Also confirm `CommentService::register_query_guard()` is hooked (excludes `mvs_comment` from foreign comment queries).
- Media comment appears on a post → the `pre_get_comments` guard (`exclude_media_comments_from_foreign_queries`) is not registered, and/or legacy rows were not healed by Migrator v19.
- Edit succeeds past the window / Edit button still shown past the window → `includes/REST/Controller/CommentController.php::update_item()` (`mvs_comment_edit_window` check), or `src/blocks/media-social/view.js` `canEdit`/`hideEditComment` getters not reading the same window.
- Delete returns 403 for the comment's own author → `includes/Social/CommentService.php::delete()` ownership/capability check regressed.
