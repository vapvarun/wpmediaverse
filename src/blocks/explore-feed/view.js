/**
 * Interactivity API store for the explore-feed block.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

store( 'mvs/explore-feed', {
	state: {
		get isMasonry() {
			return getContext().layout === 'masonry';
		},
		get isActiveFilter() {
			const itemCtx = getContext();
			return itemCtx.filterValue === getContext().filter;
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
