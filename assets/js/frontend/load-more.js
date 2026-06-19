/**
 * Load More button handler + delegated lightbox click.
 *
 * Pure vanilla JS — no Interactivity API, no jQuery.
 * Reads config from data attributes on the button element.
 * Appends cards built by window.mvsCardBuilders[layout].
 * Maintains window.mvsGridRegistry for lightbox prev/next.
 *
 * Nav-aware: init() is idempotent via [data-mvs-wired] guards on the
 * grid container and load-more button, and re-runs on mvs:navigated so
 * freshly-swapped region content is wired after client-side navigation.
 *
 * @package WPMediaVerse
 */

( function () {
	'use strict';

	function init() {
		// Wire each unwired grid container (delegated lightbox click + registry).
		document.querySelectorAll( '[data-mvs-grid-container]:not([data-mvs-wired])' ).forEach( function ( gridContainer ) {
			gridContainer.setAttribute( 'data-mvs-wired', '' );

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

			// Delegated click handler: any [data-media-id] click opens lightbox.
			gridContainer.addEventListener( 'click', function ( e ) {
				var card = e.target.closest( '[data-media-id]' );
				if ( ! card ) return;

				// Don't intercept clicks on links that should navigate (author links, etc.)
				// Only intercept clicks on the card itself or its primary media link.
				var clickedLink = e.target.closest( 'a' );
				if ( clickedLink && clickedLink !== card ) {
					// Check if this is a media link (grid-item-link, flickr-item__link, dribbble-card__image)
					// or a navigation link (author link, etc.)
					var mediaLinkClasses = [
						'mvs-grid-item-link',
						'mvs-flickr-item__link',
						'mvs-dribbble-card__image',
					];
					var isMediaLink = mediaLinkClasses.some( function ( cls ) {
						return clickedLink.classList.contains( cls );
					} );
					if ( ! isMediaLink ) {
						// This is an author link or other navigation — let it through.
						return;
					}
				}

				e.preventDefault();

				var mediaId = parseInt( card.dataset.mediaId, 10 );
				if ( ! mediaId ) return;

				// Bridge to Interactivity API lightbox via global function exposed by shared-ui store.
				if ( window.mvsOpenLightbox ) {
					window.mvsOpenLightbox( mediaId );
				}
			} );
		} );

		// Wire each unwired load-more button (independent of grid container selector).
		document.querySelectorAll( '.mvs-load-more-btn:not([data-mvs-wired])' ).forEach( function ( loadMoreBtn ) {
			loadMoreBtn.setAttribute( 'data-mvs-wired', '' );

			var loadMoreWrap = loadMoreBtn.closest( '.mvs-load-more' );
			var endMessage = document.querySelector( '.mvs-load-more-end' );

			// gridContainer may be anywhere on the page — look it up at click time
			// so a deferred swap (region containing both) is handled correctly.
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

				var gridContainer = document.querySelector( '[data-mvs-grid-container]' );

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

						if ( builder && gridContainer ) {
							items.forEach( function ( item ) {
								var node = builder( item, gridContainer );
								if ( node ) {
									gridContainer.appendChild( node );
								}
							} );
						}

						// Re-render any Lucide placeholders added by builder (i[data-lucide]).
						if ( window.lucide && typeof window.lucide.createIcons === 'function' ) {
							window.lucide.createIcons();
						}

						// Rebuild registry in case the grid container was swapped.
						if ( gridContainer ) {
							var ids = [];
							gridContainer.querySelectorAll( '[data-media-id]' ).forEach( function ( el ) {
								var id = parseInt( el.dataset.mediaId, 10 );
								if ( id && ids.indexOf( id ) === -1 ) {
									ids.push( id );
								}
							} );
							window.mvsGridRegistry = ids;
						}

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
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	document.addEventListener( 'mvs:navigated', init );
} )();
