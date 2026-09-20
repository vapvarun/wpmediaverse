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

### 2b. Every sticky surface is CLICKABLE once it is stuck (REQUIRED)
- **Why**: visible is not the same as reachable. `.mvs-bulk-bar`, `.mvs-dashboard-tabs`
  and `.mvs-dashboard-rail` are all `position: sticky` and all used to park at a flat
  offset from the top of the viewport, which on any theme that pins its own header put
  them *behind* it — rendered, `offsetParent !== null`, passing step 1 and step 2, and
  completely unclickable. At 390 the tab strip held 0-70 inside Reign's 0-81 header band
  and every tab hit-tested to the site logo. Basecamp 10320911387.
- **Action**: on `/my-media/`, `/explore-media/` and `/media/@<user>/`, select 2 items,
  scroll ~1200px so the surfaces are stuck, then hit-test every control:

      const reach = el => { const r = el.getBoundingClientRect();
        const x = Math.round(r.left + r.width/2), y = Math.round(r.top + r.height/2);
        // the <=860px tab strip scrolls horizontally; a tab past the fold is the
        // designed affordance, not occlusion — skip it rather than fail it.
        if (x < 0 || x >= innerWidth) return 'offscreen-x';
        const hit = document.elementFromPoint(x, y);
        return !!hit && (hit === el || el.contains(hit)); };

      const bar = document.querySelector('.mvs-bulk-bar');
      // bar: .mvs-bulk-count, .mvs-bulk-album, .mvs-bulk-album-label + button
      // tabs/rail: every a|button inside .mvs-dashboard-tabs / .mvs-dashboard-rail

- **Expect**: every on-screen control `true`, at 1440 **and** 390, on **both** a theme
  with a sticky header (Reign) and one without. Also assert the surfaces do not overlap
  **each other**: at 390, `bar.top >= tabs.bottom` and `tabs.top >= header.bottom`.
- **Expect (variables)**: `--mvs-sticky-top` equals the bottom edge of the pinned
  **chrome** (admin bar + theme header) and is `0px` when the theme pins nothing.
  `--mvs-sticky-stack` equals that plus any of OUR pinned surfaces above the bar, so at
  390 it stays non-zero even with no theme header — the tab strip is pinned by design
  and the bar has to clear it. Both must be **constant across frames** while scrolling:
  sample them over ~6 frames and assert one distinct value. A changing value means a
  surface is reading a variable it also contributes to, and it will walk down the page.
- **On fail**: `assets/js/frontend/sticky-top.js` is not enqueued, or its measurement
  missed the pinned element. Check both variables on `<html>` against the real bottom
  edges. Note the plugin puts an `mvs-page` class on `<body>`, so the "is this ours"
  test in that file deliberately discounts a `closest()` hit that lands on `<body>` —
  without that, the chrome walk skips the entire page and silently publishes 0.

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
2b. Once stuck, every on-screen control of `.mvs-bulk-bar`, `.mvs-dashboard-tabs` and
   `.mvs-dashboard-rail` passes the `elementFromPoint` hit-test at 1440 and 390, with and
   without a sticky theme header; the surfaces do not overlap each other; and
   `--mvs-sticky-top` / `--mvs-sticky-stack` hold constant across frames while scrolling.
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
| A sticky surface is visible but a click lands on the theme's header | the sticky offset ignores the pinned chrome; the variable is stale or 0 | `assets/js/frontend/sticky-top.js`, the `top:` of `.mvs-bulk-bar` / `.mvs-dashboard-tabs` / `.mvs-dashboard-rail` |
| Both variables read `0px` on a theme that clearly has a sticky header | the "is this ours" skip matched `<body>` (it carries `mvs-page`), so every element was skipped | `pinnedBottom()` in `assets/js/frontend/sticky-top.js` |
| A sticky surface drifts further down on every scroll | it reads a variable it also contributes to — wrong variable, or a missing exclusion | `update()` in `assets/js/frontend/sticky-top.js` |
| Bulk bar overlaps the tab strip at 390 | the bar is on `--mvs-sticky-top` (chrome only) instead of `--mvs-sticky-stack` | `.mvs-bulk-bar` in `assets/css/frontend.css` |
| A select spans the content column | `max-width:none`, theme decides | `assets/css/frontend.css` `.mvs-panel-toolbar__select` |
| Passes here, fails on a third theme | the assertion is too narrow — widen the selector, not the exception list | — |
