---
journey: flickr-layout
plugin: wpmediaverse-pro
priority: normal
roles: [anonymous, member]
covers: [MV-LAY-004, MV-LAY-008, flickr-layout]
prerequisites:
  - "Pro active; mvs_pro_feed_layout = flickr"
estimated_runtime_minutes: 4
---

# Flickr justified rows fill the row width cleanly on Explore and scope correctly on a profile

## Setup

- `wp option update mvs_pro_feed_layout flickr`
- Member `<owner>` with published media; a run that ends with one very wide or very narrow trailing image

## Steps

### 1. Justified rows, no ragged/blank row ends
- **Action**: visit `$SITE_URL/explore/`.
- **Expect**: images resized to fill the full row width at a consistent row height; no ragged or blank row endings, including the last (possibly incomplete) row.
- **On fail**: `includes/Frontend/Layouts/FlickrLayout.php` row-justification math.

### 2. Lightbox is the shared lightbox, not layout-specific
- **Action**: click a tile.
- **Expect**: opens the SAME shared lightbox used by every layout — no EXIF panel, no Flickr-specific carousel.

### 3. Shared empty/search/tag states
- **Action**: trigger an empty result (zero media, zero search matches, invalid tag).
- **Expect**: byte-identical wording to Instagram/Pinterest/Dribbble (one shared partial/strings).

### 4. Profile scoping
- **Action**: visit `/media/@<owner>/`.
- **Expect**: justified gallery limited to that member's media, same query engine as Explore.

## Pass criteria

1. No ragged/blank trailing row, including with one wide/narrow last image.
2. Lightbox is identical across layouts (no Flickr-specific UI).
3. Empty states are byte-identical to the other three layouts.
4. Profile feed is scoped to the profile's author.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Blank/ragged trailing row | row-fill algorithm doesn't handle a partial final row | `includes/Frontend/Layouts/FlickrLayout.php` |
| Lightbox differs from other layouts | layout-specific lightbox variant instead of the shared one | Flickr feed-card partial |
| Empty-state wording differs | copy not pulled from the shared strings source | `templates/layouts/flickr/` |
