---
journey: grid-thumbnail-size
plugin: wpmediaverse
priority: high
roles: [anonymous, subscriber]
covers: [thumbnail-size-setting, video-poster-fallback]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Explore page populated with image + video media"
estimated_runtime_minutes: 3
---

# Grids load the configured thumbnail size, and videos always have a poster

**Why this journey exists**: Grid/feed tiles used to hardcode the 1024px `large` variant on every image (5-10x the bytes for a ~300px tile) because `SettingsHelper::get_thumbnail_size()` and the `mvs_thumbnail_size` setting were never actually called in the grid path. Separately, posterless videos shipped an empty `thumbnail_url` and rendered as blank/black tiles. 1.7.0 routes every grid/feed thumbnail through the configured size (default `medium`) and falls back to the bundled default poster for posterless videos. (Basecamp cards: "Grids always load oversized large thumbnail" + "Uploaded videos get no poster".)

> Responsive `srcset` was prototyped then reverted - it added per-tile signed-URL generation that worsened the render N+1 on shared hosting. It is deferred to the static-serve performance rework, where URLs become cheap static strings. Do not re-add srcset on the server-render path until then.

## Steps

### 1. Open the explore page
- **Action**: `playwright_navigate $SITE_URL/explore-media/`
- **Expect**: grid renders, no broken/blank tiles.

### 2. Default image src is the configured size, not large
- **Action**: evaluate `document.querySelector('.mvs-grid-item img').getAttribute('src')`
- **Expect**: the `src` is the medium variant — `mvs_size=medium` (local driver) or a `-300x*` CDN file (cloud). It is NOT the `1024` variant.

### 3. Videos always have a poster
- **Action**: evaluate `Array.from(document.querySelectorAll('.mvs-grid-item video')).map(v=>v.getAttribute('poster'))`
- **Expect**: every value is non-empty — a real generated poster, or the bundled `assets/images/default-video-poster.svg` for posterless videos. No `null`/empty posters.

### 4. Setting takes effect
- **Action**: `wp option update mvs_thumbnail_size large`, reload, re-check step 2; then restore (`wp option update mvs_thumbnail_size medium`).
- **Expect**: with `large`, the img `src` switches to the 1024 variant — proving the setting is now honored (it was previously ignored).

### 5. Mobile sanity
- **Action**: resize to 390px, reload.
- **Expect**: grid stacks cleanly, posters/play overlays visible, no horizontal overflow.
