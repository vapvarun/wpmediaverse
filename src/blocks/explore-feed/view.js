/**
 * Interactivity API store for the explore-feed block.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

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

					const res = await fetch( url.toString(), {
						credentials: 'same-origin',
						signal: aborter.signal,
					} );
					if ( ! res.ok ) return;
					const data = await res.json();
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

				const res = await fetch( url.toString() );
				const data = await res.json();
				const total = parseInt( res.headers.get( 'X-WP-TotalPages' ) || '1', 10 );
				ctx.hasMore = ctx.page < total;

				// Append items to the grid using safe DOM methods.
				const grid = document.querySelector( '.mvs-explore-feed-block .mvs-media-grid' );
				if ( grid && data.length ) {
					data.forEach( ( item ) => {
						const div = document.createElement( 'div' );
						div.className = 'mvs-grid-item';
						const fileUrl = item.file_url || item.meta?._mvs_file_url || '';
						const fileType = item.file_type || item.meta?._mvs_file_type || '';

						if ( fileUrl && fileType.startsWith( 'image/' ) ) {
							const img = document.createElement( 'img' );
							img.src = fileUrl;
							img.alt = item.title?.rendered || item.title || '';
							img.loading = 'lazy';
							div.appendChild( img );

							const overlay = document.createElement( 'div' );
							overlay.className = 'mvs-grid-item-overlay';
							const title = document.createElement( 'span' );
							title.className = 'mvs-grid-item-title';
							title.textContent = item.title?.rendered || item.title || '';
							overlay.appendChild( title );
							div.appendChild( overlay );
						}

						grid.appendChild( div );
					} );
				}
			} catch ( err ) {
				// Ignore fetch errors.
			}

			ctx.loading = false;
		},
	},
} );
