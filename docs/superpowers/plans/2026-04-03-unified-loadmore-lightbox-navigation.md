# Unified Load More + Lightbox Navigation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unify all frontend layouts (free explore, Instagram, Pinterest, Flickr, Dribbble, BuddyPress) to use true AJAX "Load More" buttons and full-grid lightbox navigation — click any media item = lightbox, prev/next browses ALL loaded items, Load More appends without page reload.

**Architecture:** A shared `mvs/grid-nav` Interactivity API store (free plugin) manages the grid item registry, Load More fetching, and feeds item IDs to the existing `mvs/shared-ui` lightbox. Each layout template renders a `data-wp-interactive="mvs/grid-nav"` wrapper with context (restUrl, nonce, page, perPage, hasMore). The Load More button calls a store action that fetches the next page via REST, appends server-rendered HTML to the grid using safe DOM insertion (DOMParser), and registers new items in the lightbox registry. The lightbox prev/next is upgraded from gallery-group-only to all-loaded-items.

**Tech Stack:** WordPress Interactivity API, existing `mvs/v1/feed` REST endpoint, PHP template partials, vanilla JS (no jQuery), DOMParser for safe HTML insertion

---

## File Structure

### New Files

| File | Purpose |
|------|---------|
| `wpmediaverse/src/blocks/grid-nav/view.js` | Interactivity API store `mvs/grid-nav` — Load More fetch + grid item registry |
| `wpmediaverse/assets/css/frontend/load-more.css` | Shared Load More button + spinner styles |

### Modified Files

| File | Change |
|------|--------|
| `wpmediaverse/src/blocks/shared-ui/view.js` | Lightbox prev/next reads from grid registry instead of gallery-group-only |
| `wpmediaverse/templates/explore.php` | Replace `paginate_links()` with Load More button + `mvs/grid-nav` wrapper |
| `wpmediaverse/includes/Core/TemplateHelpers.php` | `render_grid_item()` — already has lightbox, no change needed |
| `wpmediaverse-pro/templates/layouts/pinterest/feed.php` | Replace fake Load More link with true AJAX Load More |
| `wpmediaverse-pro/templates/layouts/flickr/feed.php` | Replace fake Load More link with true AJAX Load More |
| `wpmediaverse-pro/templates/layouts/dribbble/feed.php` | Replace fake Load More link with true AJAX Load More |
| `wpmediaverse-pro/templates/layouts/instagram/feed.php` | Replace infinite scroll sentinel with Load More button |
| `wpmediaverse-pro/templates/layouts/instagram/profile.php` | Replace infinite scroll with Load More button |
| `wpmediaverse-pro/templates/layouts/pinterest/profile.php` | Add Load More if missing |
| `wpmediaverse-pro/templates/layouts/flickr/profile.php` | Add Load More if missing |
| `wpmediaverse-pro/templates/layouts/dribbble/profile.php` | Add Load More if missing |
| `wpmediaverse/includes/Integrations/BuddyPressIntegration.php` | Replace `paginate_links()` with Load More on profile/group tabs |
| `wpmediaverse/includes/Core/Plugin.php` | Enqueue `grid-nav` view.js + load-more.css on frontend pages |
| `wpmediaverse/includes/REST/Controller/MediaController.php` | Add `format=rendered` param to feed endpoint |

---

## Task 1: Create the `mvs/grid-nav` Interactivity API Store

**Files:**
- Create: `wpmediaverse/src/blocks/grid-nav/view.js`

This store manages: (1) a registry of all visible media IDs for lightbox prev/next, (2) Load More AJAX fetching.

- [ ] **Step 1: Create the grid-nav store**

```js
/**
 * Interactivity API store: grid navigation.
 *
 * Manages Load More pagination and a flat registry of all loaded media IDs
 * so the lightbox can prev/next through the entire visible grid.
 *
 * Each layout template wraps its grid in a data-wp-interactive="mvs/grid-nav"
 * element with context: { restUrl, nonce, page, perPage, hasMore, loading, endpoint, queryArgs }.
 *
 * @package WPMediaVerse
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * Safely parse an HTML string into DOM nodes using DOMParser.
 *
 * @param {string} html Raw HTML string from trusted server response.
 * @return {NodeList} Parsed child nodes.
 */
function safeParse( html ) {
    const doc = new DOMParser().parseFromString( html, 'text/html' );
    return doc.body.childNodes;
}

const { state, actions } = store( 'mvs/grid-nav', {
    state: {
        /**
         * Flat array of all media IDs currently in the grid, in DOM order.
         * Updated on init and after each Load More append.
         */
        gridItemIds: [],
    },
    actions: {
        /**
         * Load More button click handler.
         * Fetches next page via REST, appends rendered HTML to grid container,
         * updates gridItemIds registry.
         */
        *loadMore() {
            const ctx = getContext();
            if ( ctx.loading || ! ctx.hasMore ) {
                return;
            }

            ctx.loading = true;
            ctx.page += 1;

            try {
                const url = new URL( ctx.restUrl + ( ctx.endpoint || 'feed' ), window.location.origin );
                url.searchParams.set( 'page', ctx.page );
                url.searchParams.set( 'per_page', ctx.perPage );

                // Append any extra query args (tag, category, author, scope, etc.).
                if ( ctx.queryArgs ) {
                    Object.entries( ctx.queryArgs ).forEach( ( [ k, v ] ) => {
                        if ( v ) url.searchParams.set( k, v );
                    } );
                }

                const response = yield fetch( url.toString(), {
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': ctx.nonce },
                } );

                if ( ! response.ok ) {
                    ctx.hasMore = false;
                    ctx.loading = false;
                    return;
                }

                const items = yield response.json();

                if ( ! items.length || items.length < ctx.perPage ) {
                    ctx.hasMore = false;
                }

                // Find the grid container and append new items via safe DOMParser.
                const { ref } = getElement();
                const grid = ref.closest( '[data-mvs-grid]' )
                    ?.querySelector( '[data-mvs-grid-container]' );

                if ( grid && items.length ) {
                    for ( const item of items ) {
                        const nodes = safeParse( item.html );
                        nodes.forEach( ( node ) => {
                            if ( node.nodeType === Node.ELEMENT_NODE ) {
                                grid.appendChild( node.cloneNode( true ) );
                            }
                        } );
                    }

                    // Re-init Interactivity API on new nodes.
                    if ( window.wp?.interactivity?.init ) {
                        window.wp.interactivity.init();
                    }

                    // Rebuild grid item registry.
                    actions.rebuildRegistry();
                }
            } catch {
                ctx.hasMore = false;
            }

            ctx.loading = false;
        },

        /**
         * Scan DOM for all [data-media-id] inside the nearest grid and rebuild
         * the flat ID array used by lightbox prev/next.
         */
        rebuildRegistry() {
            const gridEl = document.querySelector( '[data-mvs-grid-container]' );
            if ( ! gridEl ) return;

            const ids = [];
            gridEl.querySelectorAll( '[data-media-id]' ).forEach( ( el ) => {
                const id = parseInt( el.dataset.mediaId, 10 );
                if ( id && ! ids.includes( id ) ) {
                    ids.push( id );
                }
            } );
            state.gridItemIds = ids;
        },
    },
    callbacks: {
        /**
         * Called on init — builds the initial grid registry from server-rendered items.
         */
        init() {
            // Small delay to ensure DOM is complete.
            requestAnimationFrame( () => {
                actions.rebuildRegistry();
            } );
        },
    },
} );
```

- [ ] **Step 2: Verify the file is syntactically correct**

Run: `node -e "require('fs').readFileSync('src/blocks/grid-nav/view.js','utf8')"` (basic read check)

- [ ] **Step 3: Commit**

```bash
git add src/blocks/grid-nav/view.js
git commit -m "feat: add mvs/grid-nav Interactivity API store for Load More + grid registry"
```

---

## Task 2: Upgrade Lightbox Prev/Next to Browse All Grid Items

**Files:**
- Modify: `wpmediaverse/src/blocks/shared-ui/view.js` (lines 92-170 state, lines 605-648 actions)

Currently `lightboxPrev/Next` only cycles through `lightboxGroupItems` (gallery group members). We upgrade it to cycle through `mvs/grid-nav`'s `gridItemIds` when the current item is NOT a gallery group, or when the gallery group is exhausted.

- [ ] **Step 1: Add grid-aware state getters**

In the `state` section (after line 170), add:

```js
get lightboxHasPrev() {
    // Gallery group nav.
    if ( state.lightboxGroupItems.length > 1 ) return true;
    // Grid nav.
    const gridNav = store( 'mvs/grid-nav' );
    const gridIds = gridNav?.state?.gridItemIds;
    if ( ! gridIds?.length ) return false;
    const idx = gridIds.indexOf( state.lightboxMediaId );
    return idx > 0;
},
get lightboxHasNext() {
    // Gallery group nav.
    if ( state.lightboxGroupItems.length > 1 ) return true;
    // Grid nav.
    const gridNav = store( 'mvs/grid-nav' );
    const gridIds = gridNav?.state?.gridItemIds;
    if ( ! gridIds?.length ) return false;
    const idx = gridIds.indexOf( state.lightboxMediaId );
    return idx >= 0 && idx < gridIds.length - 1;
},
```

- [ ] **Step 2: Upgrade lightboxPrev and lightboxNext actions**

Replace the existing `lightboxPrev()` (lines 605-611) and `lightboxNext()` (lines 612-618) with:

```js
lightboxPrev() {
    // Gallery group takes priority.
    if ( state.lightboxGroupItems.length > 1 ) {
        let idx = state.lightboxCurrentIndex - 1;
        if ( idx < 0 ) idx = state.lightboxGroupItems.length - 1;
        state.lightboxCurrentIndex = idx;
        state.lightboxMediaData = state.lightboxGroupItems[ idx ];
        return;
    }
    // Grid-level navigation.
    const gridNav = store( 'mvs/grid-nav' );
    const gridIds = gridNav?.state?.gridItemIds;
    if ( ! gridIds?.length ) return;
    const currentIdx = gridIds.indexOf( state.lightboxMediaId );
    if ( currentIdx > 0 ) {
        const prevId = gridIds[ currentIdx - 1 ];
        actions.openLightboxById( prevId );
    }
},
lightboxNext() {
    // Gallery group takes priority.
    if ( state.lightboxGroupItems.length > 1 ) {
        let idx = state.lightboxCurrentIndex + 1;
        if ( idx >= state.lightboxGroupItems.length ) idx = 0;
        state.lightboxCurrentIndex = idx;
        state.lightboxMediaData = state.lightboxGroupItems[ idx ];
        return;
    }
    // Grid-level navigation.
    const gridNav = store( 'mvs/grid-nav' );
    const gridIds = gridNav?.state?.gridItemIds;
    if ( ! gridIds?.length ) return;
    const currentIdx = gridIds.indexOf( state.lightboxMediaId );
    if ( currentIdx >= 0 && currentIdx < gridIds.length - 1 ) {
        const nextId = gridIds[ currentIdx + 1 ];
        actions.openLightboxById( nextId );
    }
},
```

- [ ] **Step 3: Add `openLightboxById` helper action**

Add after `openLightbox()` (after line 497):

```js
async openLightboxById( mediaId ) {
    if ( ! mediaId ) return;

    state.lightboxMediaId = mediaId;
    state.lightboxLoading = true;
    state.lightboxGroupItems = [];
    state.lightboxCurrentIndex = 0;

    // Find context from any grid item with this media ID.
    const el = document.querySelector( '[data-media-id="' + mediaId + '"]' );
    const ctxEl = el?.closest( '[data-wp-context]' );
    let restUrl = '/wp-json/mvs/v1/';
    let nonce = '';

    if ( ctxEl ) {
        try {
            const parsed = JSON.parse( ctxEl.dataset.wpContext );
            restUrl = parsed.restUrl || restUrl;
            nonce = parsed.nonce || nonce;
        } catch { /* use defaults */ }
    }

    try {
        const headers = {};
        if ( nonce ) headers[ 'X-WP-Nonce' ] = nonce;
        const res = await fetch( restUrl + 'media/' + mediaId, {
            credentials: 'same-origin',
            headers,
        } );
        const data = await res.json();
        state.lightboxMediaData = data;
        state.lightboxLoading = false;

        if ( data.media_group && data.group_count > 1 ) {
            const groupRes = await fetch( restUrl + 'media/' + mediaId + '/group', {
                credentials: 'same-origin',
                headers,
            } );
            const groupData = await groupRes.json();
            if ( Array.isArray( groupData ) && groupData.length > 1 ) {
                state.lightboxGroupItems = groupData;
                state.lightboxCurrentIndex = 0;
                state.lightboxMediaData = groupData[ 0 ];
            }
        }

        actions.lightboxLoadSocial( { restUrl, nonce }, mediaId, headers );
    } catch {
        state.lightboxLoading = false;
        actions.showToast( 'Failed to load media.', 'error' );
    }
},
```

- [ ] **Step 4: Upgrade keyboard handler for grid nav**

Replace the `handleLightboxKeydown` (lines 635-649) arrow key section:

```js
handleLightboxKeydown( event ) {
    if ( event.key === 'Escape' ) {
        if ( state.uploadModalVisible ) {
            actions.closeUploadModal();
        } else if ( state.lightboxVisible ) {
            actions.closeLightbox();
        }
    } else if ( state.lightboxVisible ) {
        if ( event.key === 'ArrowLeft' && state.lightboxHasPrev ) {
            actions.lightboxPrev();
        } else if ( event.key === 'ArrowRight' && state.lightboxHasNext ) {
            actions.lightboxNext();
        }
    }
},
```

- [ ] **Step 5: Commit**

```bash
git add src/blocks/shared-ui/view.js
git commit -m "feat: lightbox prev/next browses all grid items, not just gallery groups"
```

---

## Task 3: Add REST Endpoint for Rendered Grid Items

**Files:**
- Modify: `wpmediaverse/includes/REST/Controller/MediaController.php`

The Load More JS needs server-rendered HTML for each item (not raw JSON that requires client-side templating per layout). Add a `?format=rendered` param to the existing feed endpoint that returns `{ html: "..." }` per item.

- [ ] **Step 1: Add rendered format support to the feed endpoint**

In the feed endpoint handler, after the normal JSON response is built, add a check:

```php
// At the point where $items array is ready to be returned (after the main query loop):
$format = $request->get_param( 'format' );

if ( 'rendered' === $format ) {
    $layout   = $request->get_param( 'layout' ) ?: 'grid';
    $rendered = array();

    foreach ( $items as $item ) {
        ob_start();
        $media_id = (int) $item['media_id'];
        $stats    = MediaStats::get_stats( $media_id );

        /**
         * Render a single grid item for AJAX append.
         *
         * Pro layouts hook into this to render their own card markup.
         *
         * @param int    $media_id Media ID.
         * @param array  $stats    Stats array (views, reactions, comments).
         * @param string $layout   Layout mode: grid, instagram, pinterest, flickr, dribbble.
         */
        do_action( 'mvs_render_grid_item', $media_id, $stats, $layout );

        $html = ob_get_clean();
        if ( $html ) {
            $rendered[] = array( 'html' => $html );
        }
    }

    return rest_ensure_response( $rendered );
}
```

- [ ] **Step 2: Register the format and layout parameters**

In the endpoint's `get_collection_params()` or route registration args:

```php
'format' => array(
    'description' => __( 'Response format. "rendered" returns server-rendered HTML per item.', 'wpmediaverse' ),
    'type'        => 'string',
    'enum'        => array( 'json', 'rendered' ),
    'default'     => 'json',
),
'layout' => array(
    'description' => __( 'Layout mode for rendered format.', 'wpmediaverse' ),
    'type'        => 'string',
    'enum'        => array( 'grid', 'instagram', 'pinterest', 'flickr', 'dribbble' ),
    'default'     => 'grid',
),
```

- [ ] **Step 3: Hook the free plugin's default grid item renderer**

In `wpmediaverse/includes/Core/Plugin.php`:

```php
add_action( 'mvs_render_grid_item', function ( int $media_id, array $stats, string $layout ) {
    if ( 'grid' === $layout ) {
        \WPMediaVerse\Core\TemplateHelpers::render_grid_item( $media_id, $stats, array( 'show_author' => true ) );
    }
}, 10, 3 );
```

- [ ] **Step 4: Commit**

```bash
git add includes/REST/Controller/MediaController.php includes/Core/Plugin.php
git commit -m "feat: add format=rendered to feed REST endpoint for AJAX Load More"
```

---

## Task 4: Create Load More CSS

**Files:**
- Create: `wpmediaverse/assets/css/frontend/load-more.css`

- [ ] **Step 1: Write the shared Load More styles**

```css
/**
 * Load More button — shared across all layouts.
 *
 * @package WPMediaVerse
 */

.mvs-load-more {
    text-align: center;
    padding: 24px 0;
}

.mvs-load-more-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 40px;
    padding: 0 24px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #1e1e1e;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s, border-color 0.15s;
}

.mvs-load-more-btn:hover {
    background: #f6f7f7;
    border-color: #c3c4c7;
    color: #1e1e1e;
    text-decoration: none;
}

.mvs-load-more-btn[disabled] {
    opacity: 0.5;
    cursor: not-allowed;
}

.mvs-load-more-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid #c3c4c7;
    border-top-color: #2271b1;
    border-radius: 50%;
    animation: mvs-spin 0.6s linear infinite;
}

@keyframes mvs-spin {
    to { transform: rotate(360deg); }
}

.mvs-load-more-end {
    text-align: center;
    padding: 24px 0;
    color: #50575e;
    font-size: 14px;
}

@media (prefers-reduced-motion: reduce) {
    .mvs-load-more-spinner {
        animation: none;
        opacity: 0.5;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/frontend/load-more.css
git commit -m "feat: add shared Load More button CSS"
```

---

## Task 5: Update Free Plugin Explore Page

**Files:**
- Modify: `wpmediaverse/templates/explore.php` (lines ~430-462)

Replace `paginate_links()` with true AJAX Load More using `mvs/grid-nav` store.

- [ ] **Step 1: Wrap the grid in the mvs/grid-nav interactive wrapper**

Replace the grid + pagination section. The items loop stays the same but gets wrapped:

```php
<?php
$grid_nav_ctx = array(
    'restUrl'   => esc_url_raw( rest_url( 'mvs/v1/' ) ),
    'nonce'     => wp_create_nonce( 'wp_rest' ),
    'page'      => $paged,
    'perPage'   => $per_page,
    'hasMore'   => ( $paged < $max_pages ),
    'loading'   => false,
    'endpoint'  => 'feed',
    'queryArgs' => array(
        'scope'    => 'public',
        'tag'      => get_query_var( 'mvs_tag', '' ),
        'category' => get_query_var( 'mvs_category', '' ),
        's'        => get_query_var( 's', '' ),
        'format'   => 'rendered',
        'layout'   => 'grid',
    ),
);
?>
<div data-wp-interactive="mvs/grid-nav"
    <?php echo wp_interactivity_data_wp_context( $grid_nav_ctx ); ?>
    data-wp-init="callbacks.init"
    data-mvs-grid>

    <div class="mvs-grid mvs-grid--3" data-mvs-grid-container>
        <?php
        foreach ( $media_items as $item ) :
            $item_id  = (int) $item['media_id'];
            $my_stats = $stats_data[ $item_id ] ?? array();
            \WPMediaVerse\Core\TemplateHelpers::render_grid_item(
                $item_id,
                $my_stats,
                array( 'show_author' => true )
            );
        endforeach;
        ?>
    </div>

    <?php if ( $max_pages > 1 ) : ?>
        <div class="mvs-load-more" data-wp-bind--hidden="!context.hasMore">
            <button type="button"
                class="mvs-load-more-btn"
                data-wp-on--click="actions.loadMore"
                data-wp-bind--disabled="context.loading">
                <span data-wp-bind--hidden="context.loading">
                    <?php esc_html_e( 'Load More', 'wpmediaverse' ); ?>
                </span>
                <span data-wp-bind--hidden="!context.loading" class="mvs-load-more-spinner"></span>
            </button>
        </div>
        <p class="mvs-load-more-end" data-wp-bind--hidden="context.hasMore">
            <?php esc_html_e( "You're all caught up!", 'wpmediaverse' ); ?>
        </p>
    <?php endif; ?>
</div>
```

- [ ] **Step 2: Remove the old `paginate_links()` block**

Delete the old pagination markup that used `paginate_links()`.

- [ ] **Step 3: Commit**

```bash
git add templates/explore.php
git commit -m "feat: replace paginate_links with true AJAX Load More on explore page"
```

---

## Task 6: Update Pro Pinterest Feed

**Files:**
- Modify: `wpmediaverse-pro/templates/layouts/pinterest/feed.php`

- [ ] **Step 1: Wrap grid in mvs/grid-nav + replace fake Load More**

Add `mvs/grid-nav` context wrapper around the grid. Add `data-mvs-grid` to the outer wrapper and `data-mvs-grid-container` to `.mvs-pinterest-feed`. Replace the `<a href>` Load More link (lines 245-254) with the standard button pattern. Context `queryArgs` must include `'format' => 'rendered', 'layout' => 'pinterest'`.

- [ ] **Step 2: Hook the Pinterest card renderer in pro plugin**

```php
add_action( 'mvs_render_grid_item', function ( int $media_id, array $stats, string $layout ) {
    if ( 'pinterest' === $layout ) {
        \WPMediaVersePro\Layouts\Pinterest::render_card( $media_id, $stats );
    }
}, 10, 3 );
```

- [ ] **Step 3: Extract Pinterest card into a reusable render method**

Create a static `render_card( $media_id, $stats )` method that outputs the single `.mvs-pinterest-card` div (the markup currently inside the foreach loop in feed.php lines 186-241). This is called by both the template loop and the REST rendered hook.

- [ ] **Step 4: Commit**

```bash
git add templates/layouts/pinterest/feed.php
git commit -m "feat: true AJAX Load More on Pinterest feed"
```

---

## Task 7: Update Pro Flickr Feed

**Files:**
- Modify: `wpmediaverse-pro/templates/layouts/flickr/feed.php`

Same pattern as Task 6.

- [ ] **Step 1: Add mvs/grid-nav wrapper + replace Load More**

Wrap `.mvs-flickr-feed` in `mvs/grid-nav` interactive context, add `data-mvs-grid-container`. Replace `<a href>` with button. Context `queryArgs` includes `'format' => 'rendered', 'layout' => 'flickr'`.

- [ ] **Step 2: Hook Flickr card renderer + extract render method**

```php
add_action( 'mvs_render_grid_item', function ( int $media_id, array $stats, string $layout ) {
    if ( 'flickr' === $layout ) {
        \WPMediaVersePro\Layouts\Flickr::render_card( $media_id, $stats );
    }
}, 10, 3 );
```

Extract `.mvs-flickr-item` from the foreach loop into a static `render_card()` method.

- [ ] **Step 3: Commit**

```bash
git add templates/layouts/flickr/feed.php
git commit -m "feat: true AJAX Load More on Flickr feed"
```

---

## Task 8: Update Pro Dribbble Feed

**Files:**
- Modify: `wpmediaverse-pro/templates/layouts/dribbble/feed.php`

Same pattern as Tasks 6-7.

- [ ] **Step 1: Add mvs/grid-nav wrapper + replace Load More**

Same pattern. Context `queryArgs` includes `'format' => 'rendered', 'layout' => 'dribbble'`.

- [ ] **Step 2: Hook Dribbble card renderer + extract render method**

- [ ] **Step 3: Commit**

```bash
git add templates/layouts/dribbble/feed.php
git commit -m "feat: true AJAX Load More on Dribbble feed"
```

---

## Task 9: Convert Instagram Feed from Infinite Scroll to Load More

**Files:**
- Modify: `wpmediaverse-pro/templates/layouts/instagram/feed.php` (lines 74-82)
- Modify: `wpmediaverse-pro/templates/layouts/instagram/profile.php`
- Modify: `wpmediaverse-pro/assets/js/instagram-feed.js` (lines 452-466)

Instagram currently uses an Intersection Observer sentinel for infinite scroll. Replace with the same Load More button pattern.

- [ ] **Step 1: Replace the sentinel div with Load More button in feed.php**

Replace lines 74-82 (sentinel block) with the standard Load More button markup.

- [ ] **Step 2: Remove the sentinel observer from instagram-feed.js**

Remove or comment out the `observeSentinel()` callback (lines 452-466). The `loadMore()` action stays but is triggered by button click via the shared `mvs/grid-nav` store. Alternatively, integrate Instagram with the shared store by having it use `format=rendered&layout=instagram` with a server-side card renderer.

- [ ] **Step 3: Hook Instagram card renderer**

```php
add_action( 'mvs_render_grid_item', function ( int $media_id, array $stats, string $layout ) {
    if ( 'instagram' === $layout ) {
        \WPMediaVersePro\Layouts\Instagram::render_card( $media_id, $stats );
    }
}, 10, 3 );
```

- [ ] **Step 4: Apply same pattern to instagram/profile.php**

- [ ] **Step 5: Commit**

```bash
git add templates/layouts/instagram/feed.php templates/layouts/instagram/profile.php assets/js/instagram-feed.js
git commit -m "feat: replace Instagram infinite scroll with Load More button"
```

---

## Task 10: Update BuddyPress Profile and Group Media Tabs

**Files:**
- Modify: `wpmediaverse/includes/Integrations/BuddyPressIntegration.php`

Replace `paginate_links()` with Load More button on profile media tab (~line 839-851) and group media tab.

- [ ] **Step 1: Replace paginate_links with Load More on profile tab**

After the media grid output, replace `paginate_links()` with:

```php
<?php if ( $max_pages > 1 ) : ?>
    <?php
    $bp_grid_ctx = array(
        'restUrl'   => esc_url_raw( rest_url( 'mvs/v1/' ) ),
        'nonce'     => wp_create_nonce( 'wp_rest' ),
        'page'      => $paged,
        'perPage'   => $per_page,
        'hasMore'   => ( $paged < $max_pages ),
        'loading'   => false,
        'endpoint'  => 'feed',
        'queryArgs' => array(
            'author' => $displayed_user_id,
            'format' => 'rendered',
            'layout' => 'grid',
        ),
    );
    ?>
    <div data-wp-interactive="mvs/grid-nav"
        <?php echo wp_interactivity_data_wp_context( $bp_grid_ctx ); ?>>
        <div class="mvs-load-more" data-wp-bind--hidden="!context.hasMore">
            <button type="button" class="mvs-load-more-btn"
                data-wp-on--click="actions.loadMore"
                data-wp-bind--disabled="context.loading">
                <span data-wp-bind--hidden="context.loading">
                    <?php esc_html_e( 'Load More', 'wpmediaverse' ); ?>
                </span>
                <span data-wp-bind--hidden="!context.loading" class="mvs-load-more-spinner"></span>
            </button>
        </div>
    </div>
<?php endif; ?>
```

- [ ] **Step 2: Same for group media tab**

- [ ] **Step 3: Commit**

```bash
git add includes/Integrations/BuddyPressIntegration.php
git commit -m "feat: replace paginate_links with Load More on BP profile/group media tabs"
```

---

## Task 11: Enqueue Grid Nav Assets

**Files:**
- Modify: `wpmediaverse/includes/Core/Plugin.php`

- [ ] **Step 1: Register and enqueue the grid-nav view module**

```php
wp_enqueue_script_module(
    'mvs-grid-nav',
    MVS_PLUGIN_URL . 'build/blocks/grid-nav/view.js',
    array( '@wordpress/interactivity' ),
    MVS_VERSION
);
```

- [ ] **Step 2: Enqueue load-more.css**

```php
wp_enqueue_style(
    'mvs-load-more',
    MVS_PLUGIN_URL . 'assets/css/frontend/load-more.css',
    array(),
    MVS_VERSION
);
```

- [ ] **Step 3: Build the grid-nav module**

Run: `npm run build` to compile `src/blocks/grid-nav/view.js` into `build/blocks/grid-nav/view.js`.

- [ ] **Step 4: Commit**

```bash
git add includes/Core/Plugin.php build/blocks/grid-nav/
git commit -m "feat: enqueue grid-nav store and load-more CSS on frontend"
```

---

## Task 12: Update Pro Profile Templates

**Files:**
- Modify: `wpmediaverse-pro/templates/layouts/pinterest/profile.php`
- Modify: `wpmediaverse-pro/templates/layouts/flickr/profile.php`
- Modify: `wpmediaverse-pro/templates/layouts/dribbble/profile.php`

Each profile template needs the same Load More treatment as its feed template.

- [ ] **Step 1: Add Load More to Pinterest profile**
- [ ] **Step 2: Add Load More to Flickr profile**
- [ ] **Step 3: Add Load More to Dribbble profile**
- [ ] **Step 4: Commit**

```bash
git add templates/layouts/*/profile.php
git commit -m "feat: add Load More to all pro profile layout templates"
```

---

## Task 13: Unify Per-Page Values

**Files:**
- Modify: All templates that hardcode per_page

Currently per_page values are scattered: 10, 12, 18, 20, 24. Unify to use the setting.

- [ ] **Step 1: All templates read from `get_option( 'mvs_items_per_page', 12 )`**

Replace hardcoded values:
- Instagram feed.php: `10` to `absint( get_option( 'mvs_items_per_page', 12 ) )`
- Instagram profile.php: `12` to same
- Flickr feed.php: `24` to same
- BuddyPressIntegration.php profile: `18` to same
- BuddyPressIntegration.php group: `18` to same

- [ ] **Step 2: Commit**

```bash
git commit -am "fix: unify per_page to use mvs_items_per_page setting everywhere"
```

---

## Task 14: Verify Click = Lightbox Everywhere

All layouts already use `openLightbox` on click. This task is verification only.

- [ ] **Step 1: Confirm all layouts open lightbox on click**

Run a grep to confirm every layout has `data-wp-on--click="actions.openLightbox"`:

```bash
grep -r "openLightbox" wpmediaverse/templates/ wpmediaverse/includes/Core/TemplateHelpers.php wpmediaverse-pro/templates/
```

Expected: every feed/profile template has the lightbox click handler.

- [ ] **Step 2: No commit needed if all pass**

---

## Task 15: Browser Verification

- [ ] **Step 1: Test free explore page Load More**

Navigate to `http://mediaverse.local/media/`. Click Load More. Verify items append without page reload. Click an item — verify lightbox opens. Use arrow keys — verify prev/next navigates through all loaded items.

- [ ] **Step 2: Test each pro layout**

Switch to Pinterest, Flickr, Dribbble, Instagram layouts. For each:
- Verify Load More button appears and works
- Verify items append to the grid
- Verify lightbox opens on click
- Verify prev/next browses all loaded items

- [ ] **Step 3: Test BuddyPress tabs**

Navigate to a user profile media tab. Verify Load More works. Verify lightbox.

- [ ] **Step 4: Test single page URL**

Visit `http://mediaverse.local/media/{slug}/` directly. Verify single page renders properly (SEO/share landing page). Verify social features work.

- [ ] **Step 5: Test keyboard navigation**

In lightbox: Escape closes, Left/Right arrows navigate all grid items.
