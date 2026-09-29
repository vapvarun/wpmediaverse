---
journey: layout-blocks-and-mobile
plugin: wpmediaverse-pro
priority: high
roles: [administrator, anonymous]
covers: [MV-LAY-013, MV-LAY-014, layout-blocks, mobile-390]
prerequisites:
  - "Pro active; a few published media items"
  - "Auto-login mu-plugin available"
estimated_runtime_minutes: 8
---

# The four feed blocks work independent of the site-wide layout, and every layout survives 390px

## Setup

- Admin `?autologin=admin`
- Blocks: `mvs/pro-instagram-feed`, `mvs/pro-flickr-feed`, `mvs/pro-pinterest-feed`, `mvs/pro-dribbble-feed` (block inserter category "MediaVerse Pro")

## Steps

### 1. Insert each block, override scope/perPage
- **Action**: insert each of the 4 feed blocks into a post; set `perPage` and `scope` (public / followers / self) per block; save; view the front end. Insert two of the same page with different `scope` values.
- **Expect**: each block renders its OWN layout's feed regardless of the site-wide "Layout" setting (e.g. a Dribbble block works on a site whose global layout is Instagram). `scope`/`perPage` genuinely change the rendered query — verify by comparing the two differently-scoped instances on one page.
- **On fail**: `src/blocks/pro-<skin>-feed/render.php` not passing block attributes into `render_feed()`.

### 2. Editor preview is live, not a placeholder
- **Action**: in the block editor, select each block.
- **Expect**: a live/representative preview of real data, not a static mock box. `align: wide/full` visibly stretches the block when selected.

### 3. `scope: "self"` for a logged-out visitor
- **Action**: set a block's `scope` to `self`, publish, view logged out.
- **Expect**: a logged-out-appropriate empty/prompt state — never an attempt to resolve "self" to user id 0 with an unexplained empty result.
- **On fail**: `src/blocks/pro-<skin>-feed/render.php` scope resolution for anonymous visitors.

### 4. All four layouts at 390px on Explore
- **Action**: for each of instagram/pinterest/flickr/dribbble, `wp option update mvs_pro_feed_layout <skin>`; resize to 390px; load Explore.
- **Expect**: Instagram collapses to a single column; Pinterest/Flickr/Dribbble collapse to 1-2 columns; no horizontal scrollbar (`document.documentElement.scrollWidth <= window.innerWidth + 1`), no clipped text.

### 5. Touch targets and logged-out banner at 390px
- **Action**: at 390px, inspect Load More button, tag chips, sort submit, and (Instagram) story avatars.
- **Expect**: all meet the plugin's touch-floor token (read `--mvs-touch-min` at runtime, don't retype the number — Coding Rule 22's corollary). The logged-out banner (if present) doesn't overlap or push the feed off-screen.

### 6. Profile at 390px under at least two layouts
- **Action**: load a member profile at 390px under Instagram and Pinterest.
- **Expect**: same responsive behavior as Explore; no horizontal scroll.

### 7. Long tag/category names don't overflow
- **Action**: seed a very long tag name; view its chip at 390px on any layout.
- **Expect**: wraps or truncates; never forces the tag row past viewport width.

## Pass criteria

1. Each feed block renders its own layout independent of the site-wide setting; `scope`/`perPage` genuinely alter the query.
2. Editor preview reflects real data.
3. `scope: "self"` for an anonymous visitor shows an appropriate prompt, not a silent empty result.
4. All four layouts render with no horizontal scroll at 390px, on Explore and profile.
5. Touch targets meet the runtime `--mvs-touch-min` token; long tag names never overflow the viewport.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Block ignores its own `scope`/`perPage` | render.php not threading attributes through | `src/blocks/pro-<skin>-feed/render.php` |
| Editor shows a static placeholder | block.json `render` not wired to a live REST preview | block's `edit.js` |
| Horizontal scroll at 390px on one layout | missing `@media` breakpoint | that layout's stylesheet |
| Touch target under the token | hardcoded px instead of `var(--mvs-touch-min)` | layout/toolbar CSS |
