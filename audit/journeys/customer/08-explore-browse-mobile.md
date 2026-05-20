---
journey: explore-browse-mobile
plugin: wpmediaverse
priority: critical
roles: [subscriber, anonymous]
covers: [explore-grid, search-filter, single-media, lightbox, mobile-responsive, i18n]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "At least 6 public media items exist (mixed image / video / audio)"
estimated_runtime_minutes: 8
---

# A visitor browses Explore, searches, opens a media item and the lightbox — on desktop and phone

**Why this journey exists**: Explore is the front door of a media community. A site owner expects the grid, the search/filter tabs, a single-media view, and the lightbox to work and look right on a phone (most community traffic is mobile). This journey is the smoke for the whole public browse path and the primary 100%-mobile gate for the frontend.

## Setup

- Site: `$SITE_URL`
- Explore page: `$SITE_URL/explore-media/`
- Test user: `journey_subscriber` (autologin via `?autologin=journey_subscriber`); also run key steps logged-out.

## Steps

### 1. Load Explore (desktop)
- **Action**: `playwright_resize 1280 800` then `playwright_navigate $SITE_URL/explore-media/`
- **Expect**: grid renders >= 6 cards; every `<img>` has `naturalWidth > 0` (no broken images); no console errors.
- **On fail**: `templates/explore.php`, `includes/Repository/MediaRepository.php::query`, `includes/Services/SignedUrlService.php`.

### 2. Search / filter
- **Action**: type a known title fragment in the Explore search box; click a category/type filter tab.
- **Expect**: grid updates to matching items; clearing search restores the full grid.
- **On fail**: explore search JS + `mvs_explore_query_args` filter path.

### 3. Open a single media item
- **Action**: click an image card.
- **Expect**: single-media view (or modal) shows the full image (`naturalWidth > 0`), title, author, and reaction/comment affordances.
- **On fail**: `templates/single-media.php`, `TemplateHelpers::picture_or_img`.

### 4. Lightbox
- **Action**: trigger the lightbox; navigate next/prev.
- **Expect**: lightbox opens, image loads, next/prev work, close works; ESC closes.
- **On fail**: interactivity-API lightbox getters.

### 5. Responsive check — mobile 390px (REQUIRED)
- **Action**: `playwright_resize 390 844`, reload Explore, screenshot; open search, a single item, and the lightbox at 390px, screenshot each.
- **Expect**: `document.documentElement.scrollWidth - window.innerWidth <= 1` on Explore, single view, and lightbox; grid reflows to 1-2 columns; search box + filter tabs reachable without horizontal scroll; lightbox controls >= 40px and not clipped; cards' author/tap targets usable.
- **On fail**: `assets/css/frontend.css` / block `style.css` missing `@media (max-width:640px)` rules.

### 6. Translation-readiness
- **Action**: grep `templates/explore.php`, `templates/single-media.php`, and the explore/lightbox JS for visible strings.
- **Expect**: all labels ("Search media", "No results", filter names, "by", reaction labels) wrapped in `__()/esc_html__()` with domain `wpmediaverse`; JS strings localized, not inlined.
- **On fail**: the template/JS emitting the literal.

### 7. Anonymous parity
- **Action**: repeat steps 1 + 5 logged-out.
- **Expect**: public media still renders for anonymous visitors at both viewports; private/members items are absent.

## Pass criteria

ALL of the following hold:
1. Explore grid renders public media with no broken images and no console errors.
2. Search and at least one filter narrow the grid; clearing restores it.
3. Single-media view and lightbox open and display the full asset.
4. No horizontal scroll at 390x844 on Explore, single view, and lightbox; controls reachable and >= 40px.
5. All visible strings are translation-ready (`wpmediaverse` domain); JS localized.
6. Anonymous visitors see public media only, at both viewports.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Broken images (naturalWidth 0) | URL resolution / cloud-vs-local serve | `includes/Services/SignedUrlService.php` |
| Horizontal scroll at 390px | missing mobile breakpoint | `assets/css/frontend.css`, block `style.css` |
| Search returns nothing | query-arg filter regression | `includes/Repository/MediaRepository.php::query`, `templates/explore.php` |
| Hardcoded labels | unwrapped strings | `templates/explore.php`, explore JS |
| Private media visible to anon | privacy gate | `includes/Services/PrivacyService.php` |
