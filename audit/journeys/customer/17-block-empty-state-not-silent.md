---
journey: block-empty-state-not-silent
plugin: wpmediaverse
priority: high
roles: [administrator, anonymous]
covers: [render-state-rule-11, lock-overlay, block-empty-state]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Free active; no fixture data required - the whole point is the MISCONFIGURED case"
estimated_runtime_minutes: 3
---

# A misconfigured block tells the editor why, and tells the visitor nothing

**Why this journey exists**: `qa/rules/RENDER-STATE-RULES.md` uses
`blocks/lock-overlay/render.php` as its worked BAD example - a bare `return;`
when no media is selected, with the comment "block disappears from the page,
user has no idea why". The rule shipped; the fix to the file it named did not.
Both guards in that file still returned bare, so an editor who dropped a Lock
Overlay block and had not yet picked a media item saw an empty page area and no
explanation. Nothing in local-CI enforces Rule 11, and no journey covered it, so
it regressed silently and would have again.

The second half matters as much as the first: the hint is for whoever can fix
it. A configuration notice rendered to a logged-out reader is noise at best and
an information leak at worst, so the empty state is gated on `edit_posts`.

## Steps

### 1. No media selected, as someone who can fix it
- **Action**:
  ```bash
  wp eval 'wp_set_current_user(1); echo strlen(trim(do_blocks("<!-- wp:mvs/lock-overlay {\"mediaId\":0} /-->")));'
  ```
- **Expect**: non-zero. The markup contains `mvs-empty-state-frontend` and the
  text "Select a media item to protect in the block settings."

### 2. Referenced media no longer exists
- **Action**: same call with `{"mediaId":99999999}` (an id absent from
  `mvs_media_index`).
- **Expect**: non-zero, and the message names the missing media ("The referenced
  media no longer exists."), NOT the generic "select an item" wording - the two
  branches say different things because they need different fixes.

### 3. The visitor sees nothing at all
- **Action**: repeat steps 1 and 2 with `wp_set_current_user(0)`.
- **Expect**: exactly 0 bytes in both cases. A reader must never be shown a
  block-configuration hint.

### 4. The working path is untouched
- **Action**: `[mvs_lock_overlay id="<a real media id>"]` via `do_shortcode()`.
- **Expect**: renders the lock overlay as before. The guards only fire on
  misconfiguration; adding them must not change the configured case.

### 5. The rule holds across the class, not just this file
- **Action**: for each of `media-player`, `pdf-viewer`, `album-viewer`,
  `member-photos` (Free) and `pro-tournament`, `pro-challenge`, `pro-battle`
  (Pro), confirm every bare `return;` in `render.php` is preceded by an
  editor-gated notice (`render_block_empty_state()` or
  `SafeRender::admin_notice()`).
- **Expect**: no render path returns bare without telling an editor why. This
  step is the one that catches the NEXT block to get it wrong - Rule 22: key the
  check on what makes it true, not on a list of the blocks we happen to know.
