/**
 * Interactivity API store for the explore-feed block.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

/**
 * Build the thumbnail node(s) for a Load More media item.
 *
 * Prefers the canonical shared builder (window.mvsCardBuilders.buildThumbnail,
 * the JS mirror of PHP TemplateHelpers::media_thumbnail) so every JS-rendered
 * card — image, video, audio — renders identical markup to the server-rendered
 * grid and always uses thumbnail_url instead of the full-resolution file_url.
 *
 * The explore-feed block can be placed on any page, where mvsCardBuilders is
 * not guaranteed to be enqueued. When it's absent we fall back to a minimal
 * inline mirror that still (a) renders all media types and (b) prefers the
 * thumbnail over the full-res file.
 *
 * @param {Object} item  REST API media object.
 * @return {Array<HTMLElement>} Nodes to append to the link wrapper.
 */
function buildThumbnailNodes( item ) {
	const builders = window.mvsCardBuilders;
	if ( builders && typeof builders.buildThumbnail === 'function' ) {
		return builders.buildThumbnail( item, { alt: item.title || '' } );
	}

	// Fallback mirror of TemplateHelpers::media_thumbnail() type handling.
	const mediaType = item.media_type || '';
	const thumbUrl = item.thumbnail_url || '';
	const fileUrl = item.file_url || '';
	const alt = item.title || '';
	const nodes = [];

	if ( thumbUrl ) {
		const img = document.createElement( 'img' );
		img.className = 'mvs-media-thumb';
		img.src = thumbUrl;
		img.alt = alt;
		img.loading = 'lazy';
		nodes.push( img );
		return nodes;
	}

	if ( 'video' === mediaType && fileUrl ) {
		const video = document.createElement( 'video' );
		video.className = 'mvs-grid-video-preview';
		video.src = fileUrl + '#t=0.1';
		video.preload = 'metadata';
		video.muted = true;
		video.setAttribute( 'playsinline', 'playsinline' );
		video.setAttribute( 'aria-hidden', 'true' );
		nodes.push( video );
		return nodes;
	}

	const placeholderClass =
		'audio' === mediaType
			? 'mvs-grid-item-placeholder mvs-grid-item-placeholder--audio'
			: 'video' === mediaType
				? 'mvs-grid-item-placeholder mvs-grid-item-placeholder--video'
				: 'mvs-grid-item-placeholder mvs-grid-item-placeholder--generic';
	const placeholder = document.createElement( 'div' );
	placeholder.className = placeholderClass;
	nodes.push( placeholder );
	return nodes;
}

// Debounce-state map keyed by IA context so concurrent block instances
// on a single page don't share a timer.
const searchTimers = new WeakMap();

// In-flight AbortController per context so a slow first request can't
// land its results AFTER a fast second request — fixes the search
// autocomplete race where typing "ne" then "new" could leave "ne"
// suggestions visible if the "ne" response arrived second.
const searchAborters = new WeakMap();

store( 'mvs/explore-feed', {
	state: {
		get isMasonry() {
			return getContext().layout === 'masonry';
		},
		get isActiveFilter() {
			const itemCtx = getContext();
			return itemCtx.filterValue === getContext().filter;
		},
		get hasSuggestions() {
			const ctx = getContext();
			return ctx.suggestionsOpen && Array.isArray( ctx.suggestions ) && ctx.suggestions.length > 0;
		},
	},
	actions: {
		setFilter() {
			const itemCtx = getContext();
			const ctx = getContext();
			ctx.filter = itemCtx.filterValue;
			ctx.page = 1;
		},
		handleSearch( event ) {
			const ctx = getContext();
			ctx.search = event.target.value;

			// Reset highlight when the query changes so arrow nav is sane.
			ctx.suggestionHighlight = -1;

			// Debounced fetch of title matches — same /media REST endpoint
			// the loadMore flow uses, just with per_page capped at 8.
			const existing = searchTimers.get( ctx );
			if ( existing ) clearTimeout( existing );

			const query = ( ctx.search || '' ).trim();
			if ( query.length < 2 ) {
				ctx.suggestions = [];
				ctx.suggestionsOpen = false;
				return;
			}

			const timer = setTimeout( async () => {
				// Abort any in-flight request for this context so its late
				// response can't overwrite the current query's results.
				const inflight = searchAborters.get( ctx );
				if ( inflight ) inflight.abort();
				const aborter = new AbortController();
				searchAborters.set( ctx, aborter );

				try {
					const url = new URL( ctx.restUrl );
					url.searchParams.set( 's', query );
					url.searchParams.set( 'per_page', '8' );
					url.searchParams.set( 'page', '1' );
					if ( ctx.filter ) url.searchParams.set( 'media_type', ctx.filter );

					const res = await window.mvsRest.restFetch( url.toString(), {
						signal: aborter.signal,
					} );
					if ( ! res.ok ) return;
					const data = res.data;
					if ( ! Array.isArray( data ) ) return;

					// Drop the result if a newer request superseded us mid-flight
					// (the WeakMap entry was reassigned by a later keystroke).
					if ( searchAborters.get( ctx ) !== aborter ) return;

					ctx.suggestions = data.slice( 0, 8 ).map( ( item ) => ( {
						id: item.id || 0,
						title: item.title || '(untitled)',
						thumb: item.thumbnail_url || '',
						url: item.link || '',
					} ) );
					ctx.suggestionsOpen = true;
				} catch ( err ) {
					// AbortError is expected when a newer keystroke supersedes
					// us. Anything else stays silent — autocomplete is
					// enhancement, not load-blocking.
					if ( err && err.name !== 'AbortError' ) {
						// no-op — autocomplete failures shouldn't surface to UX.
					}
				}
			}, 250 );
			searchTimers.set( ctx, timer );
		},
		handleSearchKeydown( event ) {
			const ctx = getContext();
			if ( ! ctx.suggestionsOpen || ! Array.isArray( ctx.suggestions ) || ctx.suggestions.length === 0 ) {
				if ( event.key === 'Escape' ) {
					ctx.search = '';
					ctx.suggestions = [];
					ctx.suggestionsOpen = false;
				}
				return;
			}

			if ( event.key === 'ArrowDown' ) {
				event.preventDefault();
				ctx.suggestionHighlight = Math.min(
					( ctx.suggestionHighlight ?? -1 ) + 1,
					ctx.suggestions.length - 1
				);
			} else if ( event.key === 'ArrowUp' ) {
				event.preventDefault();
				ctx.suggestionHighlight = Math.max( ( ctx.suggestionHighlight ?? 0 ) - 1, -1 );
			} else if ( event.key === 'Enter' ) {
				const idx = ctx.suggestionHighlight ?? -1;
				if ( idx >= 0 && idx < ctx.suggestions.length ) {
					event.preventDefault();
					const target = ctx.suggestions[ idx ];
					if ( target?.url ) window.location.href = target.url;
				}
			} else if ( event.key === 'Escape' ) {
				event.preventDefault();
				ctx.suggestionsOpen = false;
				ctx.suggestionHighlight = -1;
			}
		},
		selectSuggestion() {
			const itemCtx = getContext();
			if ( itemCtx?.item?.url ) {
				window.location.href = itemCtx.item.url;
			}
		},
		closeSuggestions() {
			const ctx = getContext();
			// Delay so a click on a suggestion still fires before the
			// dropdown unmounts (focusout fires before click).
			setTimeout( () => {
				ctx.suggestionsOpen = false;
				ctx.suggestionHighlight = -1;
			}, 150 );
		},
		async loadMore() {
			const ctx = getContext();
			ctx.page += 1;
			ctx.loading = true;

			try {
				const url = new URL( ctx.restUrl );
				url.searchParams.set( 'page', ctx.page );
				url.searchParams.set( 'per_page', ctx.perPage );
				if ( ctx.filter ) url.searchParams.set( 'media_type', ctx.filter );
				if ( ctx.search ) url.searchParams.set( 'search', ctx.search );

				const res = await window.mvsRest.restFetch( url.toString() );
				if ( ! res.ok ) {
					// Throw so the catch surfaces a retry toast instead of swallowing.
					throw new Error( 'mvs-explore-feed-failed' );
				}
				const data = res.data;
				// X-WP-TotalPages drives infinite scroll; restFetch exposes it via res.headers.
				const total = parseInt( ( res.headers && res.headers.get && res.headers.get( 'X-WP-TotalPages' ) ) || '1', 10 );
				ctx.hasMore = ctx.page < total;

				// Append items to the grid using safe DOM methods. Mirrors the
				// server-rendered card in render.php exactly: a typed grid item
				// wrapping a permalink anchor (with a media-type-aware thumbnail)
				// plus a title overlay. Every media type — image, video, audio —
				// renders here; previously only image/* items were appended so
				// video and audio results from the REST page were silently
				// dropped. Thumbnails come from buildThumbnailNodes (thumbnail_url),
				// never the full-resolution file_url.
				const grid = document.querySelector( '.mvs-explore-feed-block .mvs-media-grid' );
				if ( grid && data.length ) {
					data.forEach( ( item ) => {
						const div = document.createElement( 'div' );
						div.className = 'mvs-grid-item';
						if ( item.media_type ) {
							div.setAttribute( 'data-media-type', item.media_type );
						}

						const anchor = document.createElement( 'a' );
						anchor.href = item.link || '#';
						buildThumbnailNodes( item ).forEach( ( node ) => {
							anchor.appendChild( node );
						} );
						div.appendChild( anchor );

						const overlay = document.createElement( 'div' );
						overlay.className = 'mvs-grid-item-overlay';
						const title = document.createElement( 'span' );
						title.className = 'mvs-grid-item-title';
						title.textContent = item.title || '';
						overlay.appendChild( title );
						div.appendChild( overlay );

						grid.appendChild( div );
					} );
				}
			} catch ( err ) {
				// Surface the failure instead of swallowing it — keep ctx.hasMore so
				// the member can retry rather than seeing a silently dead feed.
				try {
					store( 'mvs/shared-ui' ).actions.showToast( 'Couldn’t load more. Tap to retry.', 'error' );
				} catch ( e ) {}
			}

			ctx.loading = false;
		},
	},
} );
