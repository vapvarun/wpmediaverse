# MediaVerse Standalone — QA batch 2026-07-07 (card + fix plan)

Scope: the MediaVerse-owned bugs from the 2026-07-07 QA batch that do NOT depend on BuddyNext.
Profile/member/notification items are handled by the separate MV↔BN integration plan.
Basecamp column: Bugs (9667036607). Card creation pending (Basecamp MCP offline — create when reconnected).

Status: **CONFIRMED** (code-verified) · **NEEDS-REPRO** (root cause narrowed, confirm in browser before fix).

---

### MV-1 · Old image comments leak onto posts/pages — CONFIRMED / Critical (data)
- **Root cause:** current code stores media comments with `comment_post_ID = 0` (`Social/CommentService.php:127-146`, `comment_type='mvs_comment'`, media id in meta `mvs_media_id`). **Earlier versions stored `comment_post_ID = media_id`**, which collides with `wp_posts.ID` — those legacy rows still render under the colliding post/page. New comments are safe; legacy rows are not, and there is **no cleanup migration**.
- **Live check:** this local site has 4 `mvs_comment` rows, all `comment_post_ID=0` (clean) — the leak reproduces only on sites that commented before the fix.
- **Fix:** add a `Migrator` step that sets `comment_post_ID = 0` for every `wp_comments` row where `comment_type='mvs_comment' AND comment_post_ID <> 0` (idempotent). Files: `includes/Core/Migrator.php` (+ version bump).

### MV-2 · Private / members-only images show as broken in the member's Media tab — CONFIRMED / High
- **Root cause:** the QUERY is correct — `REST/Controller/UserController.php:229` gives the owner `1=1` (sees all own media incl. private). So the item is returned; the **thumbnail URL for private media doesn't resolve in the profile-grid context** (broken image), while /media (which routes through the signed-URL path) renders it. The profile Media tab isn't signing/serving the private thumbnail.
- **Fix:** route the member-media-grid thumbnail through the same signed-URL / `MediaUrl::thumb()` path used by /media so private/members thumbnails resolve for the owner. Files: `REST/Controller/UserController.php` (thumbnail_url build for the user-media response) + the profile-grid renderer.

### MV-3 · "Please enter an album name" error even when a name was entered — NEEDS-REPRO / Medium
- **Root cause (frontend):** validation lives in TWO places — `assets/js/bp-activity-media.js:556` (BP composer album create) and `src/blocks/shared-ui/view.js:938` (shared-ui album create). Both show the string when the title input `.trim()` is empty. The false-positive is most likely a **stale/wrong input reference** (reading the wrong `#mvs-bp-album-title` instance, or a duplicated handler firing on the wrong form).
- **Dedup note:** two parallel album-create implementations — consolidate to one path while fixing (do NOT patch both).
- **Fix:** reproduce which surface throws (BP composer vs shared-ui modal), correct the input reference, and unify the two create paths. Files: `assets/js/bp-activity-media.js`, `src/blocks/shared-ui/view.js`.

### MV-4 · Visitors on /media see a comment form + dead reactions — NEEDS-REPRO / Medium
- **Root cause:** `templates/media-single.php` already gates most write-affordances on `is_user_logged_in()` (reactions get `mvs-reactions--readonly` at :441; comment area gated at :621). So it may be **partially fixed** — confirm as a logged-out visitor exactly what still renders (comment textarea? clickable reactions?) and hide any remaining write-only controls, leaving only Download/Open.
- **Fix:** after browser repro, hide the comment composer + make reactions display-only for `!is_user_logged_in()`. Files: `templates/media-single.php` (+ any JS that re-adds the composer).

### MV-5 · Editing an image (title/description/tags) doesn't save — NEEDS-REPRO / High
- **Root cause:** the REST path is correct — `REST/Controller/MediaController.php` `update_item()` persists title (:17), description (:37), tags (:74), categories (:113). So the server saves; the bug is **frontend** (the shared-ui Edit Media modal at `src/blocks/shared-ui/view.js:155+` not sending all fields / not on the PUT path) or **stale cache** (media not re-fetched after save, so the UI shows old values). (My earlier browser attempt failed on a stale element ref — inconclusive.)
- **Fix:** reproduce the edit→save→reload flow; verify the modal PUTs title/description/tags and that the response/cache refreshes. Files: `src/blocks/shared-ui/view.js` (+ cache invalidation if applicable).

---

## Fix order (best-for-everyone)
1. **MV-1** cleanup migration — data-safety, small, no UI. Ship first.
2. **MV-5** + **MV-3** — reproduce both in the browser (same edit/album surfaces), fix the frontend wiring, consolidate the duplicate album-create path.
3. **MV-2** — private-thumbnail signing in the profile grid.
4. **MV-4** — confirm visitor state, hide remaining write controls.

## Cross-cutting
No duplicate/dead code: MV-3 unifies the two album-create implementations; all fixes reuse existing seams (`MediaUrl`, `update_item`, the signed-URL path) rather than adding parallel logic.
