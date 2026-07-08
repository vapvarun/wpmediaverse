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
- Test user: `journey_subscriber` (autologin via `?autologin=journey_subscriber`); also run key steps logged-out. Create the fixture idempotently if missing: `wp user get journey_subscriber || wp user create journey_subscriber journey_subscriber@example.test --role=subscriber --user_pass=journey-pass`.
- **Run as a subscriber or anonymous — NOT as a moderator/admin.** A `moderate_mvs_media` viewer gets the `privacy=any` Explore scope (`templates/explore.php`), which intentionally surfaces *other users' private* items. Those render only inside that moderator's own session, so the broken-image assertion below is only meaningful for a non-moderator viewer. Running this journey as admin produces false "broken image" failures.
- **Judge images inside the logged-in browser, never by re-fetching `/serve` out of band.** A private item's signed `/serve` URL correctly returns 403 to any request that does not carry the viewer's session cookie. Assert `naturalWidth` on the rendered `<img>` in the Playwright page (which carries the cookie); do not `curl` the `src` without the session.

## Steps

### 1. Load Explore (desktop, as subscriber)
- **Action**: `playwright_resize 1280 800` then `playwright_navigate $SITE_URL/explore-media/?autologin=journey_subscriber`
- **Expect**: grid renders >= 6 cards; every rendered `<img>` (evaluated in the page) has `naturalWidth > 0` (no broken images); no console errors. The subscriber's `privacy=visible` scope shows public + members + own only, so no un-viewable private items appear.
- **On fail**: `templates/explore.php`, `includes/Repository/MediaRepository.php::query`, `includes/Services/SignedUrlService.php`. (If a single private item shows broken, confirm the run is NOT as a moderator — see Setup.)

### 2. Search / filter
- **Action**: type a known title fragment in the Explore search box; click a category/type filter tab.
- **Expect**: grid updates to matching items; clearing search restores the full grid.
- **On fail**: explore search JS + `mvs_explore_query_args` filter path.

### 3. Open a single media item
- **Action**: click an image card.
- **Expect**: single-media view (or modal) shows the full image (`naturalWidth > 0`), title, author, and reaction/comment affordances.
- **On fail**: `templates/media-single.php`, `TemplateHelpers::picture_or_img`.

### 4. Lightbox
- **Action**: trigger the lightbox; navigate next/prev.
- **Expect**: lightbox opens, image loads, next/prev work, close works; ESC closes.
- **On fail**: interactivity-API lightbox getters.

### 5. Responsive check — mobile 390px (REQUIRED)
- **Action**: `playwright_resize 390 844`, reload Explore, screenshot; open search, a single item, and the lightbox at 390px, screenshot each.
- **Expect**: `document.documentElement.scrollWidth - window.innerWidth <= 1` on Explore, single view, and lightbox; grid reflows to 1-2 columns; search box + filter tabs reachable without horizontal scroll; lightbox controls >= 40px and not clipped; cards' author/tap targets usable.
- **On fail**: `assets/css/frontend.css` / block `style.css` missing `@media (max-width:640px)` rules.

### 6. Translation-readiness
- **Action**: grep `templates/explore.php`, `templates/media-single.php`, and the explore/lightbox JS for visible strings.
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
