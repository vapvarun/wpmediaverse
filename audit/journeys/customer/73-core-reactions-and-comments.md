---
journey: core-reactions-and-comments
plugin: wpmediaverse
priority: critical
roles: [subscriber, other-member, anonymous]
covers: [core-reactions, core-comments]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Run on BOTH environments: mediaverse.local and the Apache wp-env site"
estimated_runtime_minutes: 12
---

# Members react and comment the way they expect from Facebook or Instagram (core path 5)

Check at BOTH levels: the code flow below, then the browser as each role.

## Code flow to confirm

- Reactions: `REST/Controller/ReactionController.php` (POST toggle, DELETE remove; login + block
  check; `can_view` 404 for a non-viewer), `Social/ReactionService.php` (one row per member, six
  types), counts in `mvs_media_stats.reactions` (what members see) and `mvs_media_index.reaction_count`.
- Comments: `REST/Controller/CommentController.php` (`/media/{id}/comments`, edit/delete only the
  author via `own_item_permissions_check`), `Social/CommentService.php` (stored as `mvs_comment`,
  `comment_post_ID` 0, auto-approved), count in `mvs_media_stats.comments`.
- Notifications: `NotificationService::on_reaction` / `on_comment` (owner only).

## Setup

- A public photo owned by Member A. Member B, a visitor.

## Steps

### 1. React, switch, remove (Member B, lightbox)
- **Action**: open the photo in the lightbox, tap Love; then Like; then Like again.
- **Expect**: Love pressed, count 1; Like replaces it (still one row, count 1); tapping it again
  removes it (no row, count 0). The Explore tile counts match `mvs_media_stats`.

### 2. Comment, edit (Member B)
- **Action**: post a comment in the lightbox; Edit it; Save.
- **Expect**: it appears at once with Edit and Delete for its author; the edit persists after reload.

### 3. Owner's view (Member A)
- **Action**: open the photo; open the bell on `/my-media/`.
- **Expect**: Member B's comment shows with only "Report comment" for Member A (no Edit/Delete of
  someone else's words); the bell lists "commented on" and "reacted to".
- **Known gaps (decide, not bugs today)**: the owner cannot remove a comment on their own photo; a
  reaction toggled off and on notifies twice; a deleted comment's notification stays; a member is
  not notified when someone replies to them.

### 4. Delete (Member B)
- **Action**: delete the comment.
- **Expect**: gone for everyone; `mvs_media_stats.comments` back to 0.

### 5. Visitor
- **Action**: logged out, tap a reaction; read comments.
- **Expect**: "Please log in to react." prompt; comments readable on a public item; "Log in to comment".

### 6. Private item
- **Action**: make the photo private; as Member B call `GET/POST /mvs/v1/media/{id}/reactions` and
  `.../comments`.
- **Expect**: 404 on every one (same as a missing item).
