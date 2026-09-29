---
journey: dribbble-layout
plugin: wpmediaverse-pro
priority: normal
roles: [anonymous, member]
covers: [MV-LAY-005, MV-LAY-009, dribbble-layout]
prerequisites:
  - "Pro active; mvs_pro_feed_layout = dribbble"
  - "At least one media item with views > 0 and one with zero views; one animated GIF"
estimated_runtime_minutes: 4
---

# Dribbble shot grid shows correctly-sized stat icons and conditional view counts

## Setup

- `wp option update mvs_pro_feed_layout dribbble`
- Member `<owner>` with published media; one item with `mvs_media_views` rows, one with none; one GIF

## Steps

### 1. Hover reveals title overlay + stat icons at styled size
- **Action**: visit `$SITE_URL/explore/`; hover a card.
- **Expect**: hover reveals a title overlay; footer shows author avatar/name, a heart (likes) icon, and — ONLY when `views > 0` — an eye (views) icon. Both icons render at the styled 14x14px size (`dribbble.css`), never the browser-default SVG viewBox size (this is the exact regression MV-LAY-016 exists to catch on blocks; verify it holds on the site-wide Explore path too).
- **On fail**: `assets/css/dribbble.css` icon sizing rule, or `includes/Frontend/Layouts/DribbbleLayout.php` view-count conditional.

### 2. Views icon is hidden, not "0", when zero
- **Action**: locate the zero-view item's card.
- **Expect**: no eye icon rendered at all — not an eye icon reading "0".

### 3. Screen-reader text uses full words
- **Action**: inspect the stat icons' accessible text (screen reader / accessibility tree).
- **Expect**: `"%s like"`/`"%s likes"`, `"%s view"`/`"%s views"` — full words, not a bare number with no unit.

### 4. Animated GIF shows a static thumbnail on hover
- **Action**: hover the seeded GIF's card.
- **Expect**: static thumbnail, like any other image — no animation preview.

### 5. Profile scoping, one continuous grid
- **Action**: visit `/media/@<owner>/`.
- **Expect**: shot-grid feed scoped to that member's media, rendered as one continuous grid (same as Explore).

## Pass criteria

1. Stat icons render at 14x14px on every surface, never raw SVG size.
2. Views icon is absent (not "0") when views = 0.
3. Accessible text uses full words, not bare numbers.
4. GIFs show a static hover thumbnail.
5. Profile feed is scoped correctly.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Stat icons oversized on Explore | `dribbble.css` not enqueued on this path | `includes/Frontend/Layouts/DribbbleLayout.php::enqueue_assets()` |
| "0 views" shown instead of hidden | conditional missing around the views icon | Dribbble feed-card partial |
| Screen reader announces a bare number | i18n string not using the singular/plural full-word form | Dribbble feed-card partial |
