---
journey: explore-browse-mobile
plugin: wpmediaverse
priority: critical
roles: [subscriber, anonymous]
covers: [MV-EXP-001, MV-EXP-002, MV-EXP-003, MV-EXP-004, MV-EXP-005, MV-EXP-006, MV-EXP-007, MV-EXP-008, MV-MED-014, explore-grid, search-filter, single-media, lightbox, mobile-responsive, i18n, touch-target-floor]
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

### 3. Legacy `?s=` search still works on `/media/` (MV-EXP-003)
- **Action**: `playwright_navigate $SITE_URL/media/?s=<known-title-fragment>` directly (no mapped Explore page set).
- **Expect**: identical result set to `?q=<same-fragment>` — `TemplateHelpers::explore_search()` reads `$_GET['mvs_q']`/`q` first, then falls back to `$_GET['s']`, so an old bookmarked `?s=` link still filters the grid rather than triggering WordPress's own site search.
- **On fail**: `includes/Core/TemplateHelpers.php::explore_search()`.

### 4. Filter by category (MV-EXP-005)
- **Action**: click a category link/chip (or navigate `$SITE_URL/media/?mvs_category=<slug>`).
- **Expect**: grid narrows to items assigned that category only; an unknown category slug shows a "not found"/empty state, not the unfiltered grid.
- **On fail**: `templates/explore.php`, `includes/Repository/MediaRepository.php::query()` (`mvs_category` clause).

### 5. Sort control (MV-EXP-006)
- **Action**: change the "Sort by" select to "Oldest", click Apply; repeat with "Most viewed".
- **Expect**: this is a plain GET form submit, not auto-apply on select — changing the dropdown alone does not resort until Apply is clicked. "Oldest" produces `created_at` ascending; "Most viewed" produces `views` descending; the URL reflects `sort`/`order` so it is bookmarkable. Navigating to a tampered `?sort=nonsense` falls back to Newest (`created_at` DESC) rather than erroring — confirmed in `TemplateHelpers::explore_sort()`.
- **On fail**: `includes/Core/TemplateHelpers.php::explore_sort()`.

### 6. Load More pagination (MV-EXP-007)
- **Action**: with more than one page of media, scroll to the bottom and click "Load More" (`.mvs-load-more-btn`) once, then rapidly double-click it.
- **Expect**: the button gains `.is-loading` while its `fetch()` is in flight (no full page reload — check the Network panel shows an XHR/fetch to `mvs/v1/media`, not a document navigation); the next page's items append below the existing grid without duplicates even after the double-click; the button disappears once the last page is reached. Also verify `$SITE_URL/media/page/2/` loads directly with HTTP 200 (server-rendered pagination, not a soft-404).
- **On fail**: `assets/js/frontend/load-more.js`.

### 7. Explore search autocomplete (MV-EXP-008)
- **Action**: in the Explore Feed block's search box, type 1 character, wait, confirm no dropdown/network call; type a 2nd character of a term matching several titles; wait ~300ms.
- **Expect**: no request fires below 2 characters. At 2+ characters, after a 250ms debounce, a dropdown of up to 8 title matches appears (`suggestions.length <= 8`). `ArrowDown`/`ArrowUp` move `suggestionHighlight` (assert the highlighted option gets a visible highlight class, not just internal state); `Enter` navigates to the highlighted suggestion; `Escape` closes the dropdown and clears the highlight. Typing quickly (5+ keystrokes within the debounce window) fires far fewer than 5 requests.
- **On fail**: `src/blocks/explore-feed/view.js` (`suggestions`/`suggestionHighlight` getters/actions).

### 8. Open a single media item
- **Action**: click an image card.
- **Expect**: single-media view (or modal) shows the full image (`naturalWidth > 0`), title, author, and reaction/comment affordances.
- **On fail**: `templates/media-single.php`, `TemplateHelpers::picture_or_img`.

### 9. Lightbox (MV-MED-014)
- **Action**: trigger the lightbox; navigate next/prev.
- **Expect**: lightbox opens, image loads, next/prev work, close works; ESC closes.
- **On fail**: interactivity-API lightbox getters.

### 10. Responsive check — mobile 390px (REQUIRED)
- **Action**: `playwright_resize 390 844`, reload Explore, screenshot; open search, a single item, and the lightbox at 390px, screenshot each.
- **Expect**: `document.documentElement.scrollWidth - window.innerWidth <= 1` on Explore, single view, and lightbox; grid reflows to 1-2 columns; search box + filter tabs reachable without horizontal scroll; nothing clipped.
- **Expect (tap targets)**: **zero** MediaVerse-owned interactive elements measure under the plugin's own floor. Read the floor from the page rather than hardcoding it, and scope to our namespace so a theme's or the admin bar's controls are not counted:

      const floor = parseInt(getComputedStyle(document.documentElement)
        .getPropertyValue('--mvs-touch-min')) || 44;
      const targets = [...document.querySelectorAll('button, [role=button], a[href]')]
        .filter(e => e.offsetParent && !e.classList.contains('screen-reader-text'))
        .filter(e => /(^|\s)mvs-/.test(e.className) || e.closest('[class*="mvs-"]'))
        .filter(e => !e.closest('#wpadminbar, header, .site-header, footer'))
        // Inline text links are exempt (WCAG 2.5.8): an <a> whose accessible name
        // IS its own visible text sits in a text flow and must not be padded to
        // 44px. Icon-only links (empty text, aria-label) are real pointer targets.
        .filter(e => e.tagName !== 'A' || e.textContent.trim() === '');
      const bad = targets.filter(e => { const r = e.getBoundingClientRect();
        return r.width > 0 && r.height > 0 && (r.height < floor || r.width < floor); });
      // bad.length must be 0

  Open the chat panel before measuring — its header buttons and filter tabs live in a
  dialog that is not in the initial layout, and they were the controls this step missed.
- **On fail**: `assets/css/frontend.css` / `assets/css/messaging.css` / block `style.css` missing `@media` rules, or the control not listed in the mobile touch-target block.

  > **Why this step is written this way (2026-09-02).** It used to say "controls
  > >= 40px" — a number that appears nowhere in the plugin. The plugin's own token
  > is `--mvs-touch-min: 44px`, so seven controls (the chat header buttons at 32x32,
  > the four chat filter tabs at 37.5px, `.mvs-bulk-check` at 40x40 and
  > `.mvs-load-more-btn` at 40px tall) sat under the real floor while this journey
  > passed. A threshold hardcoded looser than the standard it is policing is not a
  > check. Read the token; never restate it.
  >
  > The exemption matters as much as the floor. A first cut of this assertion had
  > no inline-text-link filter and flagged four `.mvs-dashboard-card-title` links
  > (257x20) on the FIXED code — a check that fails on correct code gets muted,
  > and a muted check is worse than none. Refining it left exactly one true
  > offender, the 40x40 icon-only profile avatar link, which was fixed in the same
  > pass by growing its hit area rather than scaling the avatar.

### 11. Translation-readiness
- **Action**: grep `templates/explore.php`, `templates/media-single.php`, and the explore/lightbox JS for visible strings.
- **Expect**: all labels ("Search media", "No results", filter names, "by", reaction labels) wrapped in `__()/esc_html__()` with domain `wpmediaverse`; JS strings localized, not inlined.
- **On fail**: the template/JS emitting the literal.

### 12. Anonymous parity
- **Action**: repeat steps 1 + 5 logged-out.
- **Expect**: public media still renders for anonymous visitors at both viewports; private/members items are absent.

## Pass criteria

ALL of the following hold:
1. Explore grid renders public media with no broken images and no console errors.
2. Search and at least one filter narrow the grid; clearing restores it.
3. Single-media view and lightbox open and display the full asset.
4. No horizontal scroll at 390x844 on Explore, single view, and lightbox; **zero** MediaVerse-owned controls under `--mvs-touch-min` read from the page (chat panel opened before measuring).
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
