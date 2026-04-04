# Event-Delegated Load More + Lightbox Navigation — Design Spec

## Problem

WPMediaVerse has 5 frontend layout modes (free grid, Instagram, Pinterest, Flickr, Dribbble) plus BuddyPress profile/group tabs. Each uses different pagination (page links, infinite scroll, fake Load More links) and different click behavior (some open lightbox, some navigate to single page). Users don't know what to expect.

## Goal

Unified, consistent frontend: click any media = lightbox opens, Load More appends items without page reload, prev/next browses all loaded items. Same behavior in every layout, every context.

## Architecture: Event Delegation + JSON REST

### Why NOT Interactivity API for Load More

WordPress Interactivity API hydrates directives once on page load. Dynamically appended HTML has dead directives. This is a framework limitation with no supported workaround. Any solution that appends HTML and expects `data-wp-on--click` to work on new nodes is fundamentally broken.

### The Correct Approach

- **Initial render:** PHP templates (unchanged) — good for SEO
- **Load More:** Vanilla JS fetches JSON from REST API, builds card DOM via layout-specific JS builder functions, appends to grid
- **Click handling:** One delegated `addEventListener('click')` on the grid container — catches clicks on ALL items (PHP-rendered and JS-appended)
- **Lightbox bridge:** Delegated handler reads `data-media-id` from clicked element, calls `store('mvs/shared-ui').actions.openLightbox` programmatically
- **Prev/next:** Load-more module maintains `window.mvsGridRegistry = [id1, id2, ...]` array. Lightbox reads it for navigation. Stops at boundaries (no auto-fetch).

## Components

### 1. `assets/js/frontend/load-more.js` (NEW — vanilla JS module)

**Responsibilities:**
- Discover grid container (`[data-mvs-grid-container]`) and Load More button on page
- Read config from button's `data-*` attributes: restUrl, nonce, page, perPage, hasMore, endpoint, layout, and filter params (tag, category, search, scope, author, groupCovers)
- On button click: fetch JSON from REST, call correct card builder, append nodes, update registry
- Maintain `window.mvsGridRegistry` (flat array of all media IDs in DOM order)
- Attach delegated click handler on grid container for lightbox
- Show/hide spinner during fetch, show "all caught up" when done

**Config via data attributes on the button (set by PHP):**
```html
<button class="mvs-load-more-btn"
    data-rest-url="/wp-json/mvs/v1/"
    data-nonce="abc123"
    data-page="1"
    data-per-page="12"
    data-has-more="true"
    data-endpoint="media"
    data-layout="pinterest"
    data-tag=""
    data-category=""
    data-search=""
    data-scope="public"
    data-author=""
    data-group-covers="true">
    Load More
</button>
```

No Interactivity API. No `data-wp-interactive`. Pure vanilla JS with `addEventListener`.

**Delegated click handler:**
```js
gridContainer.addEventListener('click', (e) => {
    const card = e.target.closest('[data-media-id]');
    if (!card) return;
    e.preventDefault();
    const mediaId = parseInt(card.dataset.mediaId, 10);
    // Bridge to Interactivity API lightbox
    const sharedUI = wp.interactivity.store('mvs/shared-ui');
    sharedUI.actions.openLightboxById(mediaId);
});
```

**Registry:**
```js
function rebuildRegistry() {
    const ids = [];
    gridContainer.querySelectorAll('[data-media-id]').forEach(el => {
        ids.push(parseInt(el.dataset.mediaId, 10));
    });
    window.mvsGridRegistry = ids;
}
```

### 2. `assets/js/frontend/card-builders.js` (NEW — vanilla JS module)

**5 builder functions, each returns a DOM element:**

```js
window.mvsCardBuilders = {
    grid(item)      { /* returns .mvs-grid-item element */ },
    pinterest(item) { /* returns .mvs-pinterest-card element */ },
    flickr(item)    { /* returns .mvs-flickr-item element */ },
    dribbble(item)  { /* returns .mvs-dribbble-card element */ },
    instagram(item) { /* returns .mvs-ig-card element */ },
};
```

**Input:** JSON media object from REST API:
```json
{
    "media_id": 123,
    "title": "Sunset Beach",
    "thumbnail_url": "https://..../thumb.jpg",
    "file_url": "https://..../full.jpg",
    "media_type": "image",
    "link": "/media/sunset-beach/",
    "width": 1200,
    "height": 800,
    "author": 5,
    "author_data": {
        "name": "John",
        "avatar": "https://...",
        "profile_url": "/media/@john/"
    },
    "stats": {
        "views": 42,
        "reactions": 5,
        "comments": 3
    },
    "media_group": null,
    "group_count": 0
}
```

**All builders use safe DOM methods only:** `createElement`, `textContent`, `setAttribute`, `classList.add`. No innerHTML. No insertAdjacentHTML.

**All builders set `data-media-id`** on the root element.

**Each builder matches its PHP template's HTML structure exactly** — same class names, same nesting, same data attributes. This ensures the layout's existing CSS applies identically.

### 3. `src/blocks/shared-ui/view.js` (MODIFY — Interactivity API)

**Add to state:**
```js
get lightboxHasPrev() {
    if (state.lightboxGroupItems.length > 1) return true;
    const gridIds = window.mvsGridRegistry || [];
    return gridIds.indexOf(state.lightboxMediaId) > 0;
},
get lightboxHasNext() {
    if (state.lightboxGroupItems.length > 1) return true;
    const gridIds = window.mvsGridRegistry || [];
    const idx = gridIds.indexOf(state.lightboxMediaId);
    return idx >= 0 && idx < gridIds.length - 1;
},
```

**Add action:**
```js
async openLightboxById(mediaId) { ... }
// Same as openLightbox but takes mediaId directly instead of reading from getContext()
```

**Upgrade lightboxPrev/lightboxNext:**
- Gallery group: cycle within group (existing behavior)
- No gallery group: read `window.mvsGridRegistry`, navigate to adjacent ID via `openLightboxById`

**Upgrade handleLightboxKeydown:**
- Use `lightboxHasPrev`/`lightboxHasNext` getters instead of checking `lightboxGroupItems.length > 1`

**Add noop action** (needed by Pinterest author link `data-wp-on--click.stop`):
```js
noop() {},
```

### 4. `includes/REST/Controller/MediaController.php` (MODIFY)

**Add filter params to `get_collection_params()`:**
- `tag` (string, sanitize_text_field) — filter by mvs_tag slug
- `category` (string, sanitize_text_field) — filter by mvs_category slug
- `s` (string, sanitize_text_field) — LIKE search on title + description
- `scope` (string, enum: public/all) — privacy filtering
- `group_covers` (boolean) — exclude non-cover gallery items

**Implement WHERE clause logic in `get_items()`** matching the SQL patterns in explore.php (tag JOIN, category JOIN, LIKE search, privacy filter, group cover subquery).

**Include in `prepare_item_for_response()`:**
- `stats` object: `{ views, reactions, comments }` from `mvs_media_stats` table
- `author_data` object: `{ name, avatar, profile_url }` — verify present
- `width` and `height` from media metadata — needed by Flickr card builder

### 5. PHP Template Changes (ALL feed/profile templates)

**For every template that shows a media grid, make these 3 changes:**

**a) Add `data-media-id` to every card element:**

Free grid (`TemplateHelpers::render_grid_item`):
```php
// Already outputs data-media-id via $data_attrs — VERIFY this works
```

Pro layouts (pinterest/feed.php, flickr/feed.php, dribbble/feed.php, instagram/partials/feed-card.php):
```php
// Add data-media-id="<?php echo absint( $media_id ); ?>" to the card root element
```

**b) Add `data-mvs-grid-container` to the grid div**

**c) Replace pagination with Load More button:**
```php
<?php if ( $max_pages > 1 ) : ?>
    <div class="mvs-load-more">
        <button type="button" class="mvs-load-more-btn"
            data-rest-url="<?php echo esc_attr( rest_url( 'mvs/v1/' ) ); ?>"
            data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
            data-page="<?php echo esc_attr( $paged ); ?>"
            data-per-page="<?php echo esc_attr( $per_page ); ?>"
            data-has-more="true"
            data-endpoint="media"
            data-layout="<?php echo esc_attr( $layout_name ); ?>"
            data-tag="<?php echo esc_attr( $tag_filter ); ?>"
            data-category="<?php echo esc_attr( $cat_filter ); ?>"
            data-search="<?php echo esc_attr( $search ); ?>"
            data-scope="public"
            data-group-covers="true">
            <?php esc_html_e( 'Load More', 'wpmediaverse' ); ?>
        </button>
    </div>
    <p class="mvs-load-more-end" hidden>
        <?php esc_html_e( "You're all caught up!", 'wpmediaverse' ); ?>
    </p>
<?php endif; ?>
```

### 6. `assets/css/frontend/load-more.css` (NEW)

Shared styles for Load More button, spinner, end message. Includes `prefers-reduced-motion` and mobile responsive breakpoint.

### 7. `includes/Core/Plugin.php` (MODIFY)

Enqueue on frontend MVS pages:
- `assets/js/frontend/card-builders.js` (script module, no deps)
- `assets/js/frontend/load-more.js` (script module, depends on card-builders)
- `assets/css/frontend/load-more.css` (style)

These are vanilla JS modules — NOT Interactivity API stores. Enqueued via `wp_enqueue_script_module()` from source (they use standard ES module `import` that WordPress module system handles).

### 8. `includes/Integrations/BuddyPressIntegration.php` (MODIFY)

Replace `paginate_links()` on profile and group media tabs with the same Load More button markup. Add `data-mvs-grid-container` to grid divs. Add `data-author` to button for user filtering.

### 9. `templates/explore.php` (MODIFY — bug fix)

Add `AND m.moderation_status = 'approved'` to the WHERE clause to match REST endpoint behavior.

### 10. Per-page unification

All templates use `absint( get_option( 'mvs_items_per_page', 12 ) )` instead of hardcoded values.

## What Does NOT Change

- `media-single.php` — single media page untouched
- `media-social/view.js` — reactions, comments, favorites on single page untouched
- `shared-ui` lightbox HTML template — untouched
- Album pages — keep existing pagination
- Lightbox social features — untouched
- BuddyPress album tabs — keep existing pagination

## Files Summary

### New (3 files)
| File | Type |
|------|------|
| `assets/js/frontend/load-more.js` | Vanilla JS module |
| `assets/js/frontend/card-builders.js` | Vanilla JS module |
| `assets/css/frontend/load-more.css` | CSS |

### Modified (free plugin — 5 files)
| File | Change |
|------|--------|
| `src/blocks/shared-ui/view.js` | Add lightboxHasPrev/HasNext, openLightboxById, noop, upgrade prev/next/keyboard |
| `includes/REST/Controller/MediaController.php` | Add filter params + stats/author/dimensions in response |
| `includes/Core/Plugin.php` | Enqueue load-more.js, card-builders.js, load-more.css |
| `templates/explore.php` | Replace pagination, add data attrs, fix moderation_status |
| `includes/Integrations/BuddyPressIntegration.php` | Replace pagination on media tabs |

### Modified (pro plugin — 9 files)
| File | Change |
|------|--------|
| `templates/layouts/pinterest/feed.php` | Add data-media-id, data-mvs-grid-container, Load More button |
| `templates/layouts/pinterest/profile.php` | Same |
| `templates/layouts/flickr/feed.php` | Same |
| `templates/layouts/flickr/profile.php` | Same |
| `templates/layouts/dribbble/feed.php` | Same |
| `templates/layouts/dribbble/profile.php` | Same |
| `templates/layouts/instagram/feed.php` | Replace sentinel with Load More, add data attrs |
| `templates/layouts/instagram/profile.php` | Same |
| `templates/layouts/instagram/partials/feed-card.php` | Add data-media-id |

## Verification Plan

1. **Free explore page** — Load More appends grid cards, click opens lightbox, prev/next works, keyboard works
2. **Each pro layout** — Load More appends layout-correct cards (Pinterest masonry, Flickr justified, Dribbble shots, Instagram feed cards)
3. **Filtered page** (tag, category, search) — Load More respects active filters
4. **Single media page** — ALL actions work (reactions, comments, favorite, share, report, edit, delete) — regression test
5. **BuddyPress profile media tab** — Load More works
6. **BuddyPress group media tab** — Load More works
7. **Lightbox prev/next** — navigates all loaded items, stops at boundary
8. **Keyboard** — Left/Right in lightbox, Escape to close
9. **Pro inactive** — free plugin works standalone with grid layout
10. **Mobile** (390px viewport) — Load More button, lightbox responsive
