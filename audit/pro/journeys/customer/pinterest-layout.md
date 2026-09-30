---
journey: pinterest-layout
plugin: wpmediaverse-pro
priority: normal
roles: [anonymous, member]
covers: [MV-LAY-003, MV-LAY-007, pinterest-layout]
prerequisites:
  - "Pro active; mvs_pro_feed_layout = pinterest"
  - "A member with published media including some very tall images"
estimated_runtime_minutes: 4
---

# Pinterest masonry preserves proportions on Explore and scopes correctly on a profile

## Setup

- `wp option update mvs_pro_feed_layout pinterest`
- Member `<owner>` with published media, including one very tall image

## Steps

### 1. Explore masonry columns
- **Action**: visit `$SITE_URL/explore/` at 1440px, then resize to tablet-landscape, tablet-portrait, and phone widths.
- **Expect**: CSS-columns masonry preserving original image proportions, no large gaps. Column count follows viewport: 4 desktop / 3 tablet-landscape / 2 tablet-portrait / 1 phone. The Grid Columns setting has no effect here (it only applies to the square-grid layout).
- **On fail**: `includes/Frontend/Layouts/PinterestLayout.php` / `templates/layouts/pinterest/` breakpoints.

### 2. Tall image doesn't unbalance the layout
- **Action**: locate the seeded very-tall image at 1440px.
- **Expect**: it does not create an oversized single column that visually unbalances the grid.

### 3. Shared empty state
- **Action**: visit Explore under Pinterest on a site/search/tag with zero matches.
- **Expect**: identical empty-state hero/wording to every other layout (shared partial).

### 4. Profile scoping
- **Action**: visit `/media/@<owner>/`.
- **Expect**: masonry feed under the profile header, filtered to `author_id = <owner>`, same query engine as Explore.

### 5. Zero-visible-items author shows the plain empty hero
- **Action**: as a viewer for whom every item of some other author is private, visit that author's profile.
- **Expect**: plain empty hero, not an error, and not a feed exposing items the viewer cannot open.

## Pass criteria

1. Masonry column count is responsive per the four documented breakpoints.
2. A very tall image doesn't unbalance the grid.
3. Empty/search/tag empty states match every other layout exactly.
4. Profile feed is scoped to the profile's author only.
5. A viewer with zero visible items from an author sees the plain empty hero, never leaked private items.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Column count wrong at a breakpoint | CSS-columns breakpoint missing/wrong | Pinterest layout stylesheet |
| Empty-state wording differs from other layouts | copy hardcoded per-layout instead of shared partial | `includes/Frontend/Layouts/PinterestLayout.php` |
| Profile shows another author's items | author_id scoping missing from the query | `AbstractConnectorFeedLayout::query_args()` |
