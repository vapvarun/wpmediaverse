---
journey: theme-independence
plugin: wpmediaverse
priority: critical
roles: [subscriber, anonymous]
covers: [theme-independence, hidden-attribute-honoured, control-sizing-owned]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "A theme WE DO NOT SHIP is installed and can be activated (Astra, or a Twenty* default)"
  - "Test user journey_subscriber with at least 2 media items"
estimated_runtime_minutes: 6
---

# The plugin looks and behaves the same on a theme we do not ship

**Why this journey exists**: most installs are not on BuddyX, BuddyX Pro or Reign
and never will be. Anything that only holds on our own themes is broken for the
majority of the install base, and it is invisible to us precisely because we test
on our own stack.

On 2026-09-02 three defects were found this way, all of them present for months
and all of them passing every existing journey:

- `.mvs-bulk-bar` (a bulk **Delete** bar reading "0 selected") and
  `.mvs-dashboard-loading` (a permanent "Loading…" under a finished grid) rendered
  on every dashboard section and on Explore. They set the HTML `hidden` attribute
  correctly and were declared `display:flex`, which beats it at equal specificity.
  They *looked* right on Reign only because Reign ships `[hidden]{display:none}`
  and enqueues **after** us, so source order decided it. Astra ships no such reset.
- A no-JS "Apply" fallback button showed next to filters that already apply
  themselves.
- `.mvs-panel-toolbar__select` had `max-width:none`, so Astra's
  `select{width:100%}` stretched both Explore controls to the full content column
  (1160px each, stacked) where Reign rendered them at ~110px.

The common shape: **we left a decision to the theme and it happened to go our way
on the themes we look at.**

## Setup

- Note the active theme so it can be restored: `wp theme list --status=active`.
- Activate a theme we do not ship: `wp theme activate astra` (or `twentytwentyfive`).
- Surfaces to walk: `/explore-media/` and every My Media section
  (`/my-media/`, `/albums/`, `/collections/`, `/favorites/`, `/documents/`, `/profile/`).

> Restore the original theme at the end of the journey, pass or fail.

## Steps

### 1. Nothing marked `hidden` is visible, on any surface (REQUIRED)
- **Action**: for each surface, logged in as `journey_subscriber`, evaluate in the page:

      const bad = [...document.querySelectorAll('[hidden]')]
        .filter(e => e.offsetParent !== null
                  && e.getBoundingClientRect().height > 0);
      // report bad.map(e => e.className)

- **Expect**: `bad` is empty on every surface.
- **Note**: measure on the **live page**, never on markup injected into a detached
  container — an element inside a correctly hidden ancestor computes `display:flex`
  in isolation and reports a false positive. `offsetParent !== null` is what
  distinguishes "actually on screen" from "styled flex but masked by a hidden parent".
- **On fail**: a `.mvs-*` rule sets `display` to something other than `none` and no
  `[hidden]` guard beats it. The fix belongs in the single namespaced rule in
  `assets/css/frontend.css` (`[class*="mvs-"][hidden]`), **not** in a new
  per-selector exception — the per-selector allowlist is what let this ship.

### 2. The bulk bar is hidden at rest and appears on selection
- **Action**: on `/my-media/`, confirm `.mvs-bulk-bar` is not visible. Focus the grid,
  press `Ctrl/Cmd+A`, re-check. Press `Escape`.
- **Expect**: hidden with nothing selected; visible and reading "N selected" after
  select-all; hidden again after Escape.
- **Why both halves**: hiding it permanently would also satisfy step 1. The feature
  has to still work.
- **On fail**: `src/blocks/dashboard-view/view.js` (`hasBulkSelection`),
  `templates/partials/dashboard-content.php`.

### 3. Our controls are sized by us, not by the theme (REQUIRED)
- **Action**: on `/explore-media/` at 1440 wide, measure every visible
  `.mvs-panel-toolbar__select`.
- **Expect**: each is a sane control width (roughly 100–320px) and **not** the full
  width of the content column; two adjacent selects sit on the **same row**
  (`|top(a) - top(b)| < 4`). `document.documentElement.scrollWidth - clientWidth <= 1`.
- **On fail**: the control declares no width constraint and inherits the theme's
  `select{width:100%}`. Give it an intrinsic `width`/`min-width`/`max-width` in
  `assets/css/frontend.css`.

### 4. No JS-only affordance is duplicated by its no-JS fallback
- **Action**: with JS enabled, look for a submit/"Apply" control alongside any input
  that already applies itself on change.
- **Expect**: the fallback is not visible.
- **On fail**: it is `hidden` in markup but visible for the same reason as step 1.

### 5. Mobile parity on the foreign theme
- **Action**: `playwright_resize 390 844`, repeat steps 1 and 3 on Explore and
  `/my-media/`.
- **Expect**: no horizontal overflow; nothing `hidden` becomes visible at this width.

### 6. Restore the site's theme
- **Action**: `wp theme activate <original>`.
- **Expect**: the original theme is active. Re-run step 1 on one surface to confirm
  the plugin is still correct on our own theme too — a fix for a foreign theme must
  not regress ours.

## Pass criteria

ALL of the following hold, **on a theme the project does not ship**:
1. Zero elements carrying `hidden` are visible, on Explore and all six My Media sections.
2. The bulk bar is hidden at rest, appears on select-all, and hides again on Escape.
3. Toolbar selects are plugin-sized and share a row; no horizontal overflow at 1440.
4. No no-JS fallback control is visible while JS is running.
5. Steps 1 and 3 also hold at 390x844.
6. The original theme is restored and step 1 still passes on it.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A `hidden` element renders | `display` set on a `.mvs-*` rule with no `[hidden]` guard, and the theme ships no `[hidden]` reset | `assets/css/frontend.css` — the `[class*="mvs-"][hidden]` rule |
| It renders on Astra but not Reign | you are relying on the theme's reset winning on source order | same; the guard must be ours |
| Bulk bar never appears on Ctrl+A | over-broad hide, or the selection store broke | `src/blocks/dashboard-view/view.js` |
| A select spans the content column | `max-width:none`, theme decides | `assets/css/frontend.css` `.mvs-panel-toolbar__select` |
| Passes here, fails on a third theme | the assertion is too narrow — widen the selector, not the exception list | — |
