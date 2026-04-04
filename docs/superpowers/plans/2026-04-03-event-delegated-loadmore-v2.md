# Event-Delegated Load More + Lightbox Navigation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unified Load More (event-delegated, JSON REST, JS card builders) + full-grid lightbox prev/next across all 5 layout modes + BuddyPress, with zero Interactivity API hydration issues.

**Architecture:** PHP renders initial page (SEO). Load More button fetches JSON from REST, JS card builders create DOM, event delegation handles clicks on all items (initial + appended). Lightbox bridge via `window.mvsGridRegistry`. No Interactivity API for dynamic content.

**Tech Stack:** Vanilla JS (ES modules), WordPress REST API, WordPress Interactivity API (lightbox only, server-rendered content only), PHP templates

**Free plugin:** `/Users/varundubey/Local Sites/mediaverse/app/public/wp-content/plugins/wpmediaverse/`
**Pro plugin:** `/Users/varundubey/Local Sites/mediaverse/app/public/wp-content/plugins/wpmediaverse-pro/`

---

## Task 1: Fix shared-ui enqueue — build path + rebuild all blocks

The `@mvs/shared-ui` script module is loaded from `src/` (Plugin.php:1045) which has bare ES imports that browsers can't resolve. This breaks all Interactivity API features (lightbox, toast, upload modal) on pages that load shared-ui via Plugin.php. Must fix BEFORE any other work.

**Files:**
- Modify: `wpmediaverse/includes/Core/Plugin.php:1043-1052`

- [ ] **Step 1: Update shared-ui enqueue to use build path**

At Plugin.php line 1043-1052, change:

```php
wp_enqueue_script_module(
    '@mvs/shared-ui',
    MVS_PLUGIN_URL . 'src/blocks/shared-ui/view.js',
    array(
        array(
            'id'     => '@wordpress/interactivity',
            'import' => 'static',
        ),
    ),
    MVS_VERSION
);
```

To:

```php
$mvs_shared_ui_asset = file_exists( MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php' )
    ? require MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php'
    : array( 'dependencies' => array(), 'version' => MVS_VERSION );
wp_enqueue_script_module(
    '@mvs/shared-ui',
    MVS_PLUGIN_URL . 'build/blocks/shared-ui/view.js',
    $mvs_shared_ui_asset['dependencies'],
    $mvs_shared_ui_asset['version']
);
```

- [ ] **Step 2: Run npm build to ensure all blocks are compiled**

```bash
cd wpmediaverse && npm run build
```

Verify `build/blocks/shared-ui/view.js` timestamp is fresh.

- [ ] **Step 3: Verify single media page works**

Visit `/media/any-slug/` — reactions, comments, favorites, share, report should all work. This confirms the Interactivity API is no longer broken.

- [ ] **Step 4: Commit**

```bash
git add includes/Core/Plugin.php build/blocks/
git commit -m "fix: load shared-ui from build/ path to fix Interactivity API on all pages"
```

---

## Task 2: Add missing REST filter params + stats to response

The REST endpoint `GET /mvs/v1/media` is missing `tag`, `category`, `s` (search), `scope`, and `group_covers` params. Load More needs these so filtered pages get correct results. Also, stats (views, reactions, comments) are missing from the response — card builders need them.

**Files:**
- Modify: `wpmediaverse/includes/REST/Controller/MediaController.php`

- [ ] **Step 1: Add filter params to get_collection_params()**

In the `get_collection_params()` method (line 944), add these params to the return array before the closing `);`:

```php
'tag' => array(
    'type'              => 'string',
    'sanitize_callback' => 'sanitize_text_field',
),
'category' => array(
    'type'              => 'string',
    'sanitize_callback' => 'sanitize_text_field',
),
's' => array(
    'type'              => 'string',
    'sanitize_callback' => 'sanitize_text_field',
),
'scope' => array(
    'type'              => 'string',
    'enum'              => array( 'public', 'all' ),
    'default'           => 'all',
    'sanitize_callback' => 'sanitize_text_field',
),
'group_covers' => array(
    'type'              => 'boolean',
    'default'           => false,
    'sanitize_callback' => 'rest_sanitize_boolean',
),
```

- [ ] **Step 2: Implement tag filter in get_items()**

After the existing `$author` check (line 256), add:

```php
// Tag filter (by slug).
$tag_slug = $request->get_param( 'tag' );
$join_sql = '';
if ( $tag_slug ) {
    $tag_term = get_term_by( 'slug', $tag_slug, 'mvs_tag' );
    if ( $tag_term ) {
        $join_sql = $wpdb->prepare(
            " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = {$wpdb->prefix}mvs_media_index.media_id AND tr.term_taxonomy_id = %d",
            $tag_term->term_taxonomy_id
        );
    }
}

// Category filter (by slug).
$cat_slug = $request->get_param( 'category' );
$cat_join_sql = '';
if ( $cat_slug ) {
    $cat_term = get_term_by( 'slug', $cat_slug, 'mvs_category' );
    if ( $cat_term ) {
        $cat_join_sql = $wpdb->prepare(
            " INNER JOIN {$wpdb->term_relationships} cr ON cr.object_id = {$wpdb->prefix}mvs_media_index.media_id AND cr.term_taxonomy_id = %d",
            $cat_term->term_taxonomy_id
        );
    }
}

// Search filter.
$search = $request->get_param( 's' );
if ( $search ) {
    $like     = '%' . $wpdb->esc_like( $search ) . '%';
    $where[]  = '(title LIKE %s OR description LIKE %s)';
    $params[] = $like;
    $params[] = $like;
}

// Scope filter — if 'public', force public-only regardless of login.
$scope = $request->get_param( 'scope' );
if ( 'public' === $scope ) {
    $where[]  = 'privacy = %s';
    $params[] = 'public';
}

// Group covers — exclude non-cover gallery items.
$group_covers = $request->get_param( 'group_covers' );
if ( $group_covers ) {
    $where[] = "(media_id NOT IN (
        SELECT mm.media_id FROM {$wpdb->prefix}mvs_media_meta mm
        WHERE mm.meta_key = 'media_group' AND mm.media_id != (
            SELECT mm2.media_id FROM {$wpdb->prefix}mvs_media_meta mm2
            WHERE mm2.meta_key = 'media_group' AND mm2.meta_value = mm.meta_value
            ORDER BY mm2.media_id ASC LIMIT 1
        )
    ))";
}
```

Then update the SQL queries (count and data) to include `$join_sql` and `$cat_join_sql` in the FROM clause. The existing code at lines 328-361 uses `{$wpdb->prefix}mvs_media_index` — add the JOINs after it.

- [ ] **Step 3: Add stats to prepare_item_for_response()**

After line 922 (width/height), add:

```php
// Include engagement stats for card builders.
$stats_row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->prepare(
        "SELECT views, reactions, comments FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
        $media_id
    ),
    ARRAY_A
);
$data['stats'] = array(
    'views'     => (int) ( $stats_row['views'] ?? 0 ),
    'reactions' => (int) ( $stats_row['reactions'] ?? 0 ),
    'comments'  => (int) ( $stats_row['comments'] ?? 0 ),
);
```

- [ ] **Step 4: Run php -l and commit**

```bash
php -l includes/REST/Controller/MediaController.php
git add includes/REST/Controller/MediaController.php
git commit -m "feat: add tag/category/search/scope/group_covers filters + stats to media REST endpoint"
```

---

## Task 3: Create Load More CSS

**Files:**
- Create: `wpmediaverse/assets/css/frontend/load-more.css`

- [ ] **Step 1: Create the directory and CSS file**

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
}

.mvs-load-more-btn.is-loading {
	opacity: 0.7;
	cursor: wait;
}

.mvs-load-more-spinner {
	display: none;
	width: 16px;
	height: 16px;
	border: 2px solid #c3c4c7;
	border-top-color: #2271b1;
	border-radius: 50%;
	animation: mvs-spin 0.6s linear infinite;
}

.mvs-load-more-btn.is-loading .mvs-load-more-spinner {
	display: inline-block;
}

.mvs-load-more-btn.is-loading .mvs-load-more-label {
	display: none;
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

@media (max-width: 640px) {
	.mvs-load-more-btn {
		padding: 0 16px;
		height: 36px;
		font-size: 13px;
	}
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
mkdir -p assets/css/frontend
git add assets/css/frontend/load-more.css
git commit -m "feat: add shared Load More button CSS"
```

---

## Task 4: Create JS card builders

Each layout needs a JS function that takes a JSON media object and returns a DOM element matching the PHP template's HTML exactly.

**Files:**
- Create: `wpmediaverse/assets/js/frontend/card-builders.js`

- [ ] **Step 1: Create the card builders module**

```js
/**
 * Layout-specific card builder functions for Load More.
 *
 * Each function takes a media JSON object (from REST API) and returns
 * a DOM element matching the PHP template's HTML structure exactly.
 * All use safe DOM methods — no innerHTML.
 *
 * @package WPMediaVerse
 */

/**
 * Helper: create element with class(es).
 */
function el( tag, className ) {
	const node = document.createElement( tag );
	if ( className ) node.className = className;
	return node;
}

/**
 * Helper: create img element.
 */
function img( src, alt, cls ) {
	const i = el( 'img', cls || '' );
	i.src = src;
	i.alt = alt || '';
	i.loading = 'lazy';
	return i;
}

/**
 * Helper: format number with locale.
 */
function fmt( n ) {
	return ( n || 0 ).toLocaleString();
}

/**
 * Free plugin grid card — matches TemplateHelpers::render_grid_item() output.
 *
 * Structure:
 *   div.mvs-grid-item[data-media-id]
 *     a.mvs-grid-item-link
 *       img OR div.mvs-grid-thumb-placeholder
 *       div.mvs-grid-item-overlay > div.mvs-grid-item-stats > spans
 *     div.mvs-grid-item-info (avatar + author name)
 */
function buildGridCard( item ) {
	const card = el( 'div', 'mvs-grid-item' );
	card.dataset.mediaId = item.media_id || item.id;

	const link = el( 'a', 'mvs-grid-item-link' );
	link.href = item.link || '#';

	// Thumbnail.
	if ( item.thumbnail_url ) {
		link.appendChild( img( item.thumbnail_url, item.title, 'mvs-grid-thumb' ) );
	} else {
		const placeholder = el( 'div', 'mvs-grid-thumb-placeholder' );
		placeholder.textContent = '\uD83D\uDCF7';
		link.appendChild( placeholder );
	}

	// Gallery badge.
	if ( item.media_group && item.group_count > 1 ) {
		const badge = el( 'span', 'mvs-gallery-badge' );
		badge.title = item.group_count + ' photos';
		badge.textContent = '\uD83D\uDDBC\uFE0F ' + item.group_count;
		link.appendChild( badge );
	}

	// Stats overlay.
	const overlay = el( 'div', 'mvs-grid-item-overlay' );
	const stats = el( 'div', 'mvs-grid-item-stats' );
	const s = item.stats || {};

	if ( s.views ) {
		const v = el( 'span', 'mvs-grid-stat' );
		v.textContent = '\uD83D\uDC41\uFE0F ' + fmt( s.views );
		stats.appendChild( v );
	}
	const r = el( 'span', 'mvs-grid-stat' );
	r.textContent = '\u2764\uFE0F ' + fmt( s.reactions );
	stats.appendChild( r );

	const c = el( 'span', 'mvs-grid-stat' );
	c.textContent = '\uD83D\uDCAC ' + fmt( s.comments );
	stats.appendChild( c );

	overlay.appendChild( stats );
	link.appendChild( overlay );
	card.appendChild( link );

	// Author info.
	if ( item.author_data ) {
		const info = el( 'div', 'mvs-grid-item-info' );
		if ( item.author_data.avatar ) {
			const av = img( item.author_data.avatar, '', 'mvs-grid-avatar' );
			av.width = 24;
			av.height = 24;
			info.appendChild( av );
		}
		const name = el( 'span', 'mvs-grid-item-author' );
		name.textContent = item.author_data.name || '';
		info.appendChild( name );
		card.appendChild( info );
	}

	return card;
}

/**
 * Pinterest masonry card — matches pinterest/feed.php card markup.
 */
function buildPinterestCard( item ) {
	const card = el( 'div', 'mvs-pinterest-card' );
	card.dataset.mediaId = item.media_id || item.id;
	card.setAttribute( 'role', 'button' );
	card.tabIndex = 0;
	card.setAttribute( 'aria-label', item.title || 'Media' );

	// Image.
	const imgWrap = el( 'div', 'mvs-pinterest-card__img-wrap' );
	if ( item.thumbnail_url ) {
		imgWrap.appendChild( img( item.thumbnail_url, item.title ) );
	} else {
		imgWrap.classList.add( 'mvs-pinterest-card__img-placeholder' );
		const cam = el( 'span' );
		cam.setAttribute( 'aria-hidden', 'true' );
		cam.textContent = '\uD83D\uDCF7';
		imgWrap.appendChild( cam );
	}
	card.appendChild( imgWrap );

	// Body.
	const body = el( 'div', 'mvs-pinterest-card__body' );

	if ( item.title ) {
		const title = el( 'p', 'mvs-pinterest-card__title' );
		title.textContent = item.title;
		body.appendChild( title );
	}

	if ( item.description ) {
		const desc = el( 'p', 'mvs-pinterest-card__desc' );
		desc.textContent = item.description.length > 80
			? item.description.substring( 0, 80 ) + '...'
			: item.description;
		body.appendChild( desc );
	}

	// Footer with author + stats.
	const footer = el( 'div', 'mvs-pinterest-card__footer' );

	if ( item.author_data ) {
		const authorLink = el( 'a', 'mvs-pinterest-card__author' );
		authorLink.href = item.author_data.profile_url || '#';
		authorLink.setAttribute( 'aria-label', item.author_data.name || '' );
		// Stop click from bubbling to card (which opens lightbox).
		authorLink.addEventListener( 'click', ( e ) => e.stopPropagation() );

		if ( item.author_data.avatar ) {
			const av = img( item.author_data.avatar, '', 'mvs-pinterest-card__author-avatar' );
			av.width = 24;
			av.height = 24;
			authorLink.appendChild( av );
		}
		const aName = el( 'span', 'mvs-pinterest-card__author-name' );
		aName.textContent = item.author_data.name || '';
		authorLink.appendChild( aName );
		footer.appendChild( authorLink );
	}

	const statsDiv = el( 'div', 'mvs-pinterest-card__stats' );
	const s = item.stats || {};

	const likeStat = el( 'span', 'mvs-pinterest-card__stat' );
	likeStat.textContent = '\u2764 ' + fmt( s.reactions );
	statsDiv.appendChild( likeStat );

	const commentStat = el( 'span', 'mvs-pinterest-card__stat' );
	commentStat.textContent = '\uD83D\uDCAC ' + fmt( s.comments );
	statsDiv.appendChild( commentStat );

	footer.appendChild( statsDiv );
	body.appendChild( footer );
	card.appendChild( body );

	return card;
}

/**
 * Flickr justified item — matches flickr/feed.php card markup.
 * Uses flex-grow based on aspect ratio.
 */
function buildFlickrCard( item ) {
	const w = item.width || 4;
	const h = item.height || 3;
	const ratio = Math.round( ( w / h ) * 10000 ) / 10000;

	const wrap = el( 'div', 'mvs-flickr-item' );
	wrap.dataset.mediaId = item.media_id || item.id;
	wrap.style.flexGrow = ratio;

	const link = el( 'a', 'mvs-flickr-item__link' );
	link.href = item.link || '#';

	if ( item.thumbnail_url ) {
		const imgEl = img( item.thumbnail_url, item.title, 'mvs-flickr-item__image' );
		link.appendChild( imgEl );
	}

	// Video badge.
	if ( item.media_type === 'video' ) {
		const badge = el( 'span', 'mvs-flickr-item__play-badge' );
		badge.textContent = '\u25B6';
		link.appendChild( badge );
	}

	// Info overlay.
	const overlay = el( 'div', 'mvs-flickr-item__info' );
	if ( item.title ) {
		const title = el( 'span', 'mvs-flickr-item__title' );
		title.textContent = item.title;
		overlay.appendChild( title );
	}
	link.appendChild( overlay );

	wrap.appendChild( link );
	return wrap;
}

/**
 * Dribbble shot card — matches dribbble/feed.php card markup.
 */
function buildDribbbleCard( item ) {
	const card = el( 'div', 'mvs-dribbble-card' );
	card.dataset.mediaId = item.media_id || item.id;

	// Image wrapper + link.
	const imgLink = el( 'a', 'mvs-dribbble-card__image' );
	imgLink.href = item.link || '#';

	if ( item.thumbnail_url ) {
		imgLink.appendChild( img( item.thumbnail_url, item.title ) );
	}

	if ( item.media_type === 'video' ) {
		const badge = el( 'span', 'mvs-dribbble-card__play-badge' );
		badge.textContent = '\u25B6';
		imgLink.appendChild( badge );
	}

	card.appendChild( imgLink );

	// Footer: author + stats.
	const footer = el( 'div', 'mvs-dribbble-card__footer' );

	if ( item.author_data ) {
		const author = el( 'a', 'mvs-dribbble-card__author' );
		author.href = item.author_data.profile_url || '#';

		if ( item.author_data.avatar ) {
			const av = img( item.author_data.avatar, '', 'mvs-dribbble-card__avatar' );
			av.width = 24;
			av.height = 24;
			author.appendChild( av );
		}
		const name = el( 'span', 'mvs-dribbble-card__name' );
		name.textContent = item.author_data.name || '';
		author.appendChild( name );
		footer.appendChild( author );
	}

	const statsDiv = el( 'div', 'mvs-dribbble-card__stats' );
	const s = item.stats || {};

	const likeStat = el( 'span', 'mvs-dribbble-card__stat' );
	likeStat.textContent = '\u2764 ' + fmt( s.reactions );
	statsDiv.appendChild( likeStat );

	const viewStat = el( 'span', 'mvs-dribbble-card__stat' );
	viewStat.textContent = '\uD83D\uDC41 ' + fmt( s.views );
	statsDiv.appendChild( viewStat );

	footer.appendChild( statsDiv );
	card.appendChild( footer );

	return card;
}

/**
 * Instagram feed card — simplified version for Load More.
 * Full Instagram card (with follow, double-tap, gallery carousel) is too complex
 * for client-side rendering. This builds a simpler card that visually matches
 * the grid but opens the lightbox for full interaction.
 */
function buildInstagramCard( item ) {
	const card = el( 'div', 'mvs-ig-card mvs-ig-card--loadmore' );
	card.dataset.mediaId = item.media_id || item.id;

	// Author header.
	const header = el( 'div', 'mvs-ig-card-header' );
	if ( item.author_data ) {
		const authorLink = el( 'a', 'mvs-ig-card-author' );
		authorLink.href = item.author_data.profile_url || '#';
		authorLink.addEventListener( 'click', ( e ) => e.stopPropagation() );

		if ( item.author_data.avatar ) {
			const av = img( item.author_data.avatar, '', 'mvs-ig-card-avatar' );
			av.width = 32;
			av.height = 32;
			authorLink.appendChild( av );
		}
		const name = el( 'span', 'mvs-ig-card-username' );
		name.textContent = item.author_data.name || '';
		authorLink.appendChild( name );
		header.appendChild( authorLink );
	}
	card.appendChild( header );

	// Image.
	const imgWrap = el( 'div', 'mvs-ig-card-image' );
	if ( item.thumbnail_url ) {
		imgWrap.appendChild( img( item.thumbnail_url, item.title ) );
	}
	card.appendChild( imgWrap );

	// Stats footer.
	const footer = el( 'div', 'mvs-ig-card-footer' );
	const s = item.stats || {};

	const likes = el( 'span', 'mvs-ig-card-likes' );
	likes.textContent = fmt( s.reactions ) + ' likes';
	footer.appendChild( likes );

	if ( item.title ) {
		const caption = el( 'p', 'mvs-ig-card-caption' );
		const strong = el( 'strong' );
		strong.textContent = ( item.author_data?.name || '' ) + ' ';
		caption.appendChild( strong );
		caption.appendChild( document.createTextNode( item.title ) );
		footer.appendChild( caption );
	}

	card.appendChild( footer );
	return card;
}

// Export as global so load-more.js can access.
window.mvsCardBuilders = {
	grid: buildGridCard,
	pinterest: buildPinterestCard,
	flickr: buildFlickrCard,
	dribbble: buildDribbbleCard,
	instagram: buildInstagramCard,
};
```

- [ ] **Step 2: Commit**

```bash
mkdir -p assets/js/frontend
git add assets/js/frontend/card-builders.js
git commit -m "feat: add layout-specific JS card builders for Load More"
```

---

## Task 5: Create Load More JS module

The core Load More engine: button handler, REST fetch, card builder dispatch, grid registry, delegated click → lightbox bridge.

**Files:**
- Create: `wpmediaverse/assets/js/frontend/load-more.js`

- [ ] **Step 1: Create the load-more module**

```js
/**
 * Load More button handler + delegated lightbox click.
 *
 * Pure vanilla JS — no Interactivity API.
 * Reads config from data attributes on the button element.
 * Appends cards built by window.mvsCardBuilders[layout].
 * Maintains window.mvsGridRegistry for lightbox prev/next.
 *
 * @package WPMediaVerse
 */

( function () {
	'use strict';

	var gridContainer = document.querySelector( '[data-mvs-grid-container]' );
	var loadMoreBtn = document.querySelector( '.mvs-load-more-btn' );
	var loadMoreWrap = document.querySelector( '.mvs-load-more' );
	var endMessage = document.querySelector( '.mvs-load-more-end' );

	if ( ! gridContainer ) return;

	// --- Registry: flat array of all visible media IDs ---

	function rebuildRegistry() {
		var ids = [];
		gridContainer.querySelectorAll( '[data-media-id]' ).forEach( function ( el ) {
			var id = parseInt( el.dataset.mediaId, 10 );
			if ( id && ids.indexOf( id ) === -1 ) {
				ids.push( id );
			}
		} );
		window.mvsGridRegistry = ids;
	}

	// Build initial registry from server-rendered items.
	rebuildRegistry();

	// --- Delegated click handler: any [data-media-id] click opens lightbox ---

	gridContainer.addEventListener( 'click', function ( e ) {
		var card = e.target.closest( '[data-media-id]' );
		if ( ! card ) return;

		// Don't intercept clicks on links inside cards (author links, etc.)
		// unless the link itself is the card wrapper (like grid items).
		var clickedLink = e.target.closest( 'a' );
		if ( clickedLink && clickedLink !== card && ! clickedLink.classList.contains( 'mvs-grid-item-link' )
			&& ! clickedLink.classList.contains( 'mvs-flickr-item__link' )
			&& ! clickedLink.classList.contains( 'mvs-dribbble-card__image' ) ) {
			// This is an author link or other non-media link — let it navigate.
			return;
		}

		e.preventDefault();

		var mediaId = parseInt( card.dataset.mediaId, 10 );
		if ( ! mediaId ) return;

		// Bridge to Interactivity API lightbox.
		if ( window.wp && window.wp.interactivity ) {
			var sharedUI = window.wp.interactivity.store( 'mvs/shared-ui' );
			if ( sharedUI && sharedUI.actions && sharedUI.actions.openLightboxById ) {
				sharedUI.actions.openLightboxById( mediaId );
			}
		}
	} );

	// --- Load More button handler ---

	if ( ! loadMoreBtn ) return;

	var config = {
		restUrl: loadMoreBtn.dataset.restUrl || '/wp-json/mvs/v1/',
		nonce: loadMoreBtn.dataset.nonce || '',
		page: parseInt( loadMoreBtn.dataset.page, 10 ) || 1,
		perPage: parseInt( loadMoreBtn.dataset.perPage, 10 ) || 12,
		endpoint: loadMoreBtn.dataset.endpoint || 'media',
		layout: loadMoreBtn.dataset.layout || 'grid',
		tag: loadMoreBtn.dataset.tag || '',
		category: loadMoreBtn.dataset.category || '',
		search: loadMoreBtn.dataset.search || '',
		scope: loadMoreBtn.dataset.scope || '',
		author: loadMoreBtn.dataset.author || '',
		groupCovers: loadMoreBtn.dataset.groupCovers === 'true',
	};
	var loading = false;

	loadMoreBtn.addEventListener( 'click', function () {
		if ( loading ) return;
		loading = true;
		config.page += 1;

		loadMoreBtn.classList.add( 'is-loading' );

		var url = new URL( config.restUrl + config.endpoint, window.location.origin );
		url.searchParams.set( 'page', config.page );
		url.searchParams.set( 'per_page', config.perPage );
		if ( config.tag ) url.searchParams.set( 'tag', config.tag );
		if ( config.category ) url.searchParams.set( 'category', config.category );
		if ( config.search ) url.searchParams.set( 's', config.search );
		if ( config.scope ) url.searchParams.set( 'scope', config.scope );
		if ( config.author ) url.searchParams.set( 'author', config.author );
		if ( config.groupCovers ) url.searchParams.set( 'group_covers', '1' );

		var headers = {};
		if ( config.nonce ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}

		fetch( url.toString(), {
			credentials: 'same-origin',
			headers: headers,
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					showEnd();
					return [];
				}
				return response.json();
			} )
			.then( function ( items ) {
				if ( ! items || ! items.length ) {
					showEnd();
					return;
				}

				var builder = window.mvsCardBuilders && window.mvsCardBuilders[ config.layout ];
				if ( ! builder ) {
					builder = window.mvsCardBuilders && window.mvsCardBuilders.grid;
				}

				if ( builder ) {
					items.forEach( function ( item ) {
						var node = builder( item );
						if ( node ) {
							gridContainer.appendChild( node );
						}
					} );
				}

				rebuildRegistry();

				if ( items.length < config.perPage ) {
					showEnd();
				}

				loading = false;
				loadMoreBtn.classList.remove( 'is-loading' );
			} )
			.catch( function () {
				showEnd();
			} );
	} );

	function showEnd() {
		loading = false;
		if ( loadMoreWrap ) loadMoreWrap.style.display = 'none';
		if ( endMessage ) endMessage.removeAttribute( 'hidden' );
		loadMoreBtn.classList.remove( 'is-loading' );
	}
} )();
```

- [ ] **Step 2: Commit**

```bash
git add assets/js/frontend/load-more.js
git commit -m "feat: add Load More JS engine with event delegation + lightbox bridge"
```

---

## Task 6: Upgrade shared-ui lightbox for grid navigation

Add `openLightboxById`, `lightboxHasPrev/HasNext` getters, noop action. Upgrade prev/next and keyboard handler.

**Files:**
- Modify: `wpmediaverse/src/blocks/shared-ui/view.js`

- [ ] **Step 1: Add getters after lightboxPositionText (line 170)**

```js
get lightboxHasPrev() {
    if ( state.lightboxGroupItems.length > 1 ) return true;
    const gridIds = window.mvsGridRegistry || [];
    const idx = gridIds.indexOf( state.lightboxMediaId );
    return idx > 0;
},
get lightboxHasNext() {
    if ( state.lightboxGroupItems.length > 1 ) return true;
    const gridIds = window.mvsGridRegistry || [];
    const idx = gridIds.indexOf( state.lightboxMediaId );
    return idx >= 0 && idx < gridIds.length - 1;
},
```

- [ ] **Step 2: Add openLightboxById action after openLightbox (after line 497)**

```js
async openLightboxById( mediaId ) {
    if ( ! mediaId ) return;

    state.lightboxMediaId = mediaId;
    state.lightboxVisible = true;
    state.lightboxLoading = true;
    state.lightboxCommentText = '';
    state.lightboxGroupItems = [];
    state.lightboxCurrentIndex = 0;
    document.body.style.overflow = 'hidden';

    // Find REST URL + nonce from any existing Interactivity context on the page.
    let restUrl = '/wp-json/mvs/v1/';
    let nonce = '';
    const ctxEl = document.querySelector( '[data-wp-interactive="mvs/shared-ui"][data-wp-context]' );
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

- [ ] **Step 3: Add noop action (anywhere in actions)**

```js
noop() {},
```

- [ ] **Step 4: Replace lightboxPrev (line 605-611)**

```js
lightboxPrev() {
    if ( state.lightboxGroupItems.length > 1 ) {
        let idx = state.lightboxCurrentIndex - 1;
        if ( idx < 0 ) idx = state.lightboxGroupItems.length - 1;
        state.lightboxCurrentIndex = idx;
        state.lightboxMediaData = state.lightboxGroupItems[ idx ];
        return;
    }
    const gridIds = window.mvsGridRegistry || [];
    const currentIdx = gridIds.indexOf( state.lightboxMediaId );
    if ( currentIdx > 0 ) {
        actions.openLightboxById( gridIds[ currentIdx - 1 ] );
    }
},
```

- [ ] **Step 5: Replace lightboxNext (line 612-618)**

```js
lightboxNext() {
    if ( state.lightboxGroupItems.length > 1 ) {
        let idx = state.lightboxCurrentIndex + 1;
        if ( idx >= state.lightboxGroupItems.length ) idx = 0;
        state.lightboxCurrentIndex = idx;
        state.lightboxMediaData = state.lightboxGroupItems[ idx ];
        return;
    }
    const gridIds = window.mvsGridRegistry || [];
    const currentIdx = gridIds.indexOf( state.lightboxMediaId );
    if ( currentIdx >= 0 && currentIdx < gridIds.length - 1 ) {
        actions.openLightboxById( gridIds[ currentIdx + 1 ] );
    }
},
```

- [ ] **Step 6: Replace handleLightboxKeydown arrow section (line 642-648)**

```js
} else if ( state.lightboxVisible ) {
    if ( event.key === 'ArrowLeft' && state.lightboxHasPrev ) {
        actions.lightboxPrev();
    } else if ( event.key === 'ArrowRight' && state.lightboxHasNext ) {
        actions.lightboxNext();
    }
}
```

- [ ] **Step 7: Run npm build and commit**

```bash
npm run build
git add src/blocks/shared-ui/view.js build/blocks/
git commit -m "feat: add grid-aware lightbox prev/next + openLightboxById + noop action"
```

---

## Task 7: Enqueue Load More assets in Plugin.php

**Files:**
- Modify: `wpmediaverse/includes/Core/Plugin.php`

- [ ] **Step 1: Add enqueue calls inside the frontend assets block**

In `enqueue_frontend_assets()`, inside the `if ( $is_mvs || $is_archive || ... )` block (around line 732-738), add after the existing `mvs-frontend` style enqueue:

```php
wp_enqueue_style(
    'mvs-load-more',
    MVS_PLUGIN_URL . 'assets/css/frontend/load-more.css',
    array(),
    MVS_VERSION
);

wp_enqueue_script(
    'mvs-card-builders',
    MVS_PLUGIN_URL . 'assets/js/frontend/card-builders.js',
    array(),
    MVS_VERSION,
    true
);

wp_enqueue_script(
    'mvs-load-more',
    MVS_PLUGIN_URL . 'assets/js/frontend/load-more.js',
    array( 'mvs-card-builders' ),
    MVS_VERSION,
    true
);
```

- [ ] **Step 2: Commit**

```bash
git add includes/Core/Plugin.php
git commit -m "feat: enqueue load-more JS, card-builders JS, and load-more CSS on frontend"
```

---

## Task 8: Update free explore.php template

Replace `paginate_links()` with Load More button. Add `data-media-id` to grid items. Add `data-mvs-grid-container`. Fix moderation_status filter.

**Files:**
- Modify: `wpmediaverse/templates/explore.php`
- Modify: `wpmediaverse/includes/Core/TemplateHelpers.php`

- [ ] **Step 1: Add data-media-id to TemplateHelpers::render_grid_item()**

At line 259, the grid item div already outputs `$data_str` which includes `data-media-id` (set at line 223: `$data_attrs['media-id'] = $media_id`). Verify this outputs `data-media-id="123"`. If it uses `data-media-id`, we're good. If the format is different, fix it.

- [ ] **Step 2: Add data-mvs-grid-container to explore.php grid div**

Find `<div class="mvs-media-grid mvs-cols-3 mvs-feed">` (line ~393) and add the attribute:

```php
<div class="mvs-media-grid mvs-cols-3 mvs-feed" data-mvs-grid-container>
```

- [ ] **Step 3: Replace paginate_links with Load More button**

Find the pagination block (lines ~446-463) and replace with:

```php
<?php if ( $max_pages > 1 ) : ?>
    <div class="mvs-load-more">
        <button type="button" class="mvs-load-more-btn"
            data-rest-url="<?php echo esc_attr( rest_url( 'mvs/v1/' ) ); ?>"
            data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
            data-page="<?php echo esc_attr( $paged ); ?>"
            data-per-page="<?php echo esc_attr( $per_page ); ?>"
            data-endpoint="media"
            data-layout="grid"
            data-tag="<?php echo esc_attr( get_query_var( 'mvs_tag', '' ) ); ?>"
            data-category="<?php echo esc_attr( get_query_var( 'mvs_category', '' ) ); ?>"
            data-search="<?php echo esc_attr( get_query_var( 's', '' ) ); ?>"
            data-scope="public"
            data-group-covers="true">
            <span class="mvs-load-more-label"><?php esc_html_e( 'Load More', 'wpmediaverse' ); ?></span>
            <span class="mvs-load-more-spinner"></span>
        </button>
    </div>
    <p class="mvs-load-more-end" hidden>
        <?php esc_html_e( "You're all caught up!", 'wpmediaverse' ); ?>
    </p>
<?php endif; ?>
```

- [ ] **Step 4: Fix moderation_status in WHERE clause**

Find the WHERE clause (around line 282) and ensure it includes `AND m.moderation_status = 'approved'`. If missing, add it.

- [ ] **Step 5: Commit**

```bash
git add templates/explore.php includes/Core/TemplateHelpers.php
git commit -m "feat: Load More button on explore page + moderation_status fix"
```

---

## Task 9: Update all pro feed templates

Add `data-media-id` to every card, `data-mvs-grid-container` to grids, replace pagination with Load More button.

**Files:**
- Modify: `wpmediaverse-pro/templates/layouts/pinterest/feed.php`
- Modify: `wpmediaverse-pro/templates/layouts/flickr/feed.php`
- Modify: `wpmediaverse-pro/templates/layouts/dribbble/feed.php`
- Modify: `wpmediaverse-pro/templates/layouts/instagram/feed.php`
- Modify: `wpmediaverse-pro/templates/layouts/instagram/partials/feed-card.php`

For each:

- [ ] **Step 1: Add `data-media-id` to the card root element in each template**

Pinterest (line 186): Add `data-media-id="<?php echo absint( $media_id ); ?>"` to `.mvs-pinterest-card` div
Flickr (line 199): Add to `.mvs-flickr-item` div
Dribbble: Add to `.mvs-dribbble-card` div
Instagram feed-card.php: Add to the card root element

- [ ] **Step 2: Add `data-mvs-grid-container` to each grid div**

Pinterest: `.mvs-pinterest-feed`
Flickr: `.mvs-flickr-feed`
Dribbble: `.mvs-dribbble-feed`
Instagram: `.mvs-ig-feed-cards`

- [ ] **Step 3: Replace pagination in each feed template**

Use the same Load More button pattern as explore.php, but with the correct `data-layout` value for each (pinterest, flickr, dribbble, instagram).

For Instagram specifically: replace the infinite scroll sentinel (lines 74-82) with the Load More button.

- [ ] **Step 4: Update Instagram per_page**

Change `$per_page = 10;` (line 19) to `$per_page = absint( get_option( 'mvs_items_per_page', 12 ) );`

- [ ] **Step 5: Commit**

```bash
git add templates/layouts/
git commit -m "feat: Load More + data-media-id on all pro feed templates"
```

---

## Task 10: Update all pro profile templates

Same pattern as Task 9 but for profile pages.

**Files:**
- Modify: `wpmediaverse-pro/templates/layouts/pinterest/profile.php`
- Modify: `wpmediaverse-pro/templates/layouts/flickr/profile.php`
- Modify: `wpmediaverse-pro/templates/layouts/dribbble/profile.php`
- Modify: `wpmediaverse-pro/templates/layouts/instagram/profile.php`

For each: add `data-media-id`, `data-mvs-grid-container`, replace pagination with Load More button (including `data-author` for user filtering). Unify per_page.

- [ ] **Step 1: Apply changes to all 4 profile templates**
- [ ] **Step 2: Commit**

```bash
git add templates/layouts/
git commit -m "feat: Load More + data-media-id on all pro profile templates"
```

---

## Task 11: Update BuddyPress media tabs

**Files:**
- Modify: `wpmediaverse/includes/Integrations/BuddyPressIntegration.php`

- [ ] **Step 1: Replace paginate_links on profile media tab**

Find the profile media pagination and replace with Load More button markup. Add `data-mvs-grid-container` to the media grid div. Add `data-author` to the button. Unify per_page from 18 to `get_option( 'mvs_items_per_page', 12 )`.

- [ ] **Step 2: Replace paginate_links on group media tab**

Same pattern. Use `data-author=""` but add `data-group-id` if the REST endpoint supports it (or use author filtering).

- [ ] **Step 3: Commit**

```bash
git add includes/Integrations/BuddyPressIntegration.php
git commit -m "feat: Load More on BuddyPress profile and group media tabs"
```

---

## Task 12: Unify per_page across all templates

**Files:**
- Grep and fix all remaining hardcoded per_page values

- [ ] **Step 1: Search and replace**

All templates should use: `$per_page = absint( get_option( 'mvs_items_per_page', 12 ) );`

Check: Instagram feed (10), Flickr feed (24), BP profile (18), BP group (18), Instagram profile (12 hardcoded context).

- [ ] **Step 2: Commit**

```bash
git commit -am "fix: unify per_page to use mvs_items_per_page setting everywhere"
```

---

## Task 13: Full build + browser verification

- [ ] **Step 1: Rebuild all blocks**

```bash
cd wpmediaverse && npm run build
```

- [ ] **Step 2: Test free explore page**

Visit `/media/`. Verify: Load More button visible, click appends grid cards, click any item opens lightbox, arrow keys navigate all loaded items, Escape closes.

- [ ] **Step 3: Test each pro layout**

Switch layout to Pinterest, Flickr, Dribbble, Instagram. For each: Load More appends correct layout cards, lightbox works.

- [ ] **Step 4: Test filtered page**

Visit `/media/?mvs_tag=nature`. Load More should only return items with that tag.

- [ ] **Step 5: Test single media page (regression)**

Visit `/media/any-slug/`. ALL actions must work: reactions, comments, favorite, share, report, edit, delete.

- [ ] **Step 6: Test BuddyPress tabs**

Profile media tab + group media tab: Load More works.

- [ ] **Step 7: Test with Pro inactive**

Deactivate Pro plugin. Free explore page should work with grid layout Load More.

- [ ] **Step 8: Final commit**

```bash
git commit -am "chore: rebuild all blocks for v1.2.0"
```
