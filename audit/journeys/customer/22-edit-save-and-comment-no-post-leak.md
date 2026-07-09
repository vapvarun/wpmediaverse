---
journey: edit-save-and-comment-no-post-leak
plugin: wpmediaverse
priority: critical
roles: [author, subscriber]
covers: [media-edit-persist, comment-post-id-zero, reaction-toggle]
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

## Pass criteria

ALL hold:
1. Title + privacy edits persist to the DB and survive reload.
2. A posted comment renders on the media.
3. Every `mvs_comment` has `comment_post_ID = 0` and `mvs_media_id` meta.
4. Zero `mvs_comment` rows with a non-zero `comment_post_ID`; no leak onto posts/pages.
5. Reaction toggles active and writes a row.

## Fail diagnostics

- Edit not saved → the modal PUT/POST field mapping in `src/blocks/shared-ui/view.js` (dashboard modal), or `MediaController::update_item` args (must declare title/description/privacy/tags/categories).
- Comment stored with `comment_post_ID = media_id` → `Social/CommentService::add()` regressed; must insert `comment_post_ID = 0` + `add_comment_meta(MEDIA_META_KEY)`. Also confirm `CommentService::register_query_guard()` is hooked (excludes `mvs_comment` from foreign comment queries).
- Media comment appears on a post → the `pre_get_comments` guard (`exclude_media_comments_from_foreign_queries`) is not registered, and/or legacy rows were not healed by Migrator v19.
