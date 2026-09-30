---
journey: explore-redirect-tagcloud-archives
plugin: wpmediaverse
priority: normal
roles: [subscriber, anonymous]
covers: [MV-EXP-009, MV-EXP-010, MV-EXP-011, explore-mapped-page-redirect, tag-cloud, cpt-archives]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "A published WP page available to map as the Explore page"
  - "Several media items tagged with a handful of distinct tags"
  - "At least one public album and one public collection; at least one members-only album"
estimated_runtime_minutes: 6
---

# `/media/` redirects to a mapped Explore page, the tag cloud filters, and album/collection archives list only what a visitor can see

**Why this journey exists**: three independent read-only surfaces that a site owner
relies on to make Explore discoverable and SEO-friendly: the archive-to-page 301
redirect (`mvs_page_explore`), the tag cloud partial, and the `/album/`/`/collection/`
CPT archives. None of the three writes anything, so they are easy to skip — but a
broken redirect creates a duplicate-content SEO problem, and a leaking archive
exposes a members-only album's existence to a logged-out visitor.

## Setup

- Note the current `mvs_page_explore` option value to restore at the end:
  `wp option get mvs_page_explore`.
- Have one published WP page ready to map (its ID as `$PAGE_ID`, permalink as `$PAGE_URL`).

## Steps

### 1. Map the Explore page (MV-EXP-009)
- **Action**: `wp option update mvs_page_explore $PAGE_ID`.
- **Expect**: option saved.

### 2. `/media/` 301s to the mapped page
- **Action**: `curl -sI $SITE_URL/media/`.
- **Expect**: HTTP `301`, `Location:` header is `$PAGE_URL` (or its https-normalized equivalent). This must be a real server-side redirect, verifiable with `curl -I`, not a client-side JS redirect.

### 3. Pagination and query string carry over
- **Action**: `curl -sI "$SITE_URL/media/page/2/"` and `curl -sI "$SITE_URL/media/?q=term"`.
- **Expect**: both 301 to `$PAGE_URL` with `/page/2/` and `?q=term` preserved on the destination Location header (query-string case: `s` is rewritten to `q` if the source used the legacy `?s=` form — verify by requesting `$SITE_URL/media/?s=term` and checking the Location carries `?q=term`, not `?s=term`).

### 4. Singles are never redirected
- **Action**: `curl -sI "$SITE_URL/media/<any-existing-slug>/"`.
- **Expect**: HTTP `200` (or the normal single-media response), never a 301 to the mapped page — only the archive route redirects.

### 5. Unmapping restores the direct archive
- **Action**: `wp option delete mvs_page_explore`; `curl -sI $SITE_URL/media/`.
- **Expect**: HTTP `200`, no redirect, no redirect loop.

### 6. Tag cloud renders and filters (MV-EXP-010)
- **Action**: `playwright_navigate $SITE_URL/media/`; locate the tag cloud partial; click a tag chip.
- **Expect**: tags render as clickable chips sized/weighted by usage; clicking one navigates to `/media/?mvs_tag=<slug>` (or `/media-tag/<slug>/`) and the grid narrows to items carrying that tag (MV-EXP-004 behavior).
- **Action**: on a site/state with zero tags anywhere, reload.
- **Expect**: no tag-cloud markup at all (not an empty box).

### 7. Album archive lists only viewable albums (MV-EXP-011)
- **Action**: `playwright_navigate $SITE_URL/album/` logged out.
- **Expect**: a card grid of the public album(s) only; the members-only album is absent entirely — not a locked/greyed tile, simply not in the DOM.

### 8. Collection archive, same rule
- **Action**: `playwright_navigate $SITE_URL/collection/` logged out.
- **Expect**: same behavior scoped to `mvs_collection` — public collections list, non-public ones absent.

### 9. Empty-state archive
- **Action**: on a site with zero public albums, reload `/album/`.
- **Expect**: a "no albums yet" (or equivalent) empty state — not a blank page.

### 10. Mobile viewport
- **Action**: `playwright_resize 390 844`; reload `/album/` and `/collection/`.
- **Expect**: no horizontal scroll (`document.documentElement.scrollWidth <= window.innerWidth + 1`); card grid reflows.

## Pass criteria

ALL of the following hold:
1. `/media/` (and `page/2/`, `?q=`/`?s=`) 301-redirect to the mapped page when set; singles are never redirected; unmapping restores the direct 200 archive.
2. Tag cloud renders clickable chips and filters the grid; is absent entirely with zero tags.
3. `/album/` and `/collection/` list only what the current viewer can see — a members-only album never appears to a logged-out visitor.
4. Empty state (not a blank page) when nothing public exists.
5. No horizontal scroll at 390x844.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `/media/` stays 200 with the mapped page set | `mvs_redirect_media_archive_to_explore_page` filter disabled, or the page option not read | `includes/Core/TemplateLoader.php::redirect_archive_to_explore_page()` |
| Query string/pagination lost across the redirect | Location-building logic dropped the query args | `includes/Core/TemplateLoader.php::redirect_archive_to_explore_page()` |
| Legacy `?s=` reaches the mapped page unrewritten | the `s` → `q` rewrite on redirect regressed | `includes/Core/TemplateLoader.php` |
| A single is redirected | archive-only guard in `redirect_archive_to_explore_page()` matching too broadly | `includes/Core/TemplateLoader.php` |
| Tag cloud shows an empty box with 0 tags | `templates/partials/explore-tag-cloud.php` missing an empty-state guard | `templates/partials/explore-tag-cloud.php` |
| Members-only album visible to a visitor on `/album/` | CPT archive query missing the privacy clause | `templates/cpt-archive.php`, `includes/Services/PrivacyService.php` |
