/**
 * Load More button handler + delegated lightbox click.
 *
 * Pure vanilla JS — no Interactivity API, no jQuery.
 * Reads config from data attributes on the button element.
 * Appends cards built by window.mvsCardBuilders[layout].
 * Maintains window.mvsGridRegistry for lightbox prev/next.
 *
 * Nav-safe by DOCUMENT-LEVEL DELEGATION: both the grid lightbox click and the
 * Load More click are bound once on `document`, so they survive client-side
 * region swaps with zero re-wiring. An earlier version re-wired per-element on
 * `mvs:navigated` guarded by a `data-mvs-wired` attribute — but the iAPI router
 * morphs/reuses region nodes, leaving that attribute and the click listener in
 * an inconsistent state (race: sometimes the swapped button was left dead,
 * sometimes double-bound). Delegation removes the element-identity dependency
 * entirely. Per-button state (page cursor, loading) lives on the button's own
 * dataset/class so a freshly-swapped button starts clean from its server values.
 *
 * @package WPMediaVerse
 */

( function () {
	'use strict';

	// Rebuild the flat media-id registry the lightbox uses for prev/next from
	// whatever grid container is on the page right now (current or swapped-in).
	function rebuildRegistry( gridContainer ) {
		if ( ! gridContainer ) {
			return;
		}
		var ids = [];
		gridContainer.querySelectorAll( '[data-media-id]' ).forEach( function ( el ) {
			var id = parseInt( el.dataset.mediaId, 10 );
			if ( id && ids.indexOf( id ) === -1 ) {
				ids.push( id );
			}
		} );
		window.mvsGridRegistry = ids;
	}

	// --- Delegated lightbox open: any [data-media-id] click inside a grid. ---
	document.addEventListener( 'click', function ( e ) {
		// If the IAPI navigate action on #mvs-app already claimed this click
		// (client-nav is enabled and the link targets an internal route), it will
		// have called event.preventDefault() before bubbling reaches document.
		// Stand down — the page is already navigating; opening the lightbox on
		// top of a navigation would stack the overlay on the wrong page.
		if ( e.defaultPrevented ) {
			return;
		}

		var card = e.target.closest( '[data-media-id]' );
		if ( ! card ) {
			return;
		}
		var gridContainer = card.closest( '[data-mvs-grid-container]' );
		if ( ! gridContainer ) {
			return;
		}

		// Don't intercept clicks on links that should navigate (author links,
		// etc.). Only intercept the card itself or its primary media link.
		var clickedLink = e.target.closest( 'a' );
		if ( clickedLink && clickedLink !== card ) {
			var mediaLinkClasses = [
				'mvs-grid-item-link',
				'mvs-flickr-item__link',
				'mvs-dribbble-card__image',
			];
			var isMediaLink = mediaLinkClasses.some( function ( cls ) {
				return clickedLink.classList.contains( cls );
			} );
			if ( ! isMediaLink ) {
				return; // author / navigation link — let it through.
			}
		}

		// Interactive control BUTTONS inside a card (like, comment, bookmark,
		// share, mute, follow, carousel, expand, etc.) run their own action — the
		// generic lightbox-open must not swallow their clicks (the expand button
		// even opens the lightbox via its own action). Match only <button>, NOT
		// any [data-wp-on--click] ancestor: the whole app is wrapped in
		// #mvs-app[data-wp-on--click="actions.navigate"] (client-nav), so matching
		// that ancestor bailed on every click and the lightbox never opened.
		// (Bugs #10014549774; regression guard #10019404777.)
		if ( e.target.closest( 'button' ) ) {
			return;
		}

		e.preventDefault();

		var mediaId = parseInt( card.dataset.mediaId, 10 );
		if ( ! mediaId ) {
			return;
		}

		// Registry reflects the CURRENT grid (handles appended + swapped items).
		rebuildRegistry( gridContainer );

		// Bridge to the Interactivity API lightbox via the global exposed by the
		// shared-ui store.
		if ( window.mvsOpenLightbox ) {
			window.mvsOpenLightbox( mediaId );
		}
	} );

	// --- Delegated Load More: any .mvs-load-more-btn click. ---
	document.addEventListener( 'click', function ( e ) {
		var loadMoreBtn = e.target.closest( '.mvs-load-more-btn' );
		if ( ! loadMoreBtn ) {
			return;
		}
		if ( loadMoreBtn.classList.contains( 'is-loading' ) ) {
			return;
		}

		var loadMoreWrap = loadMoreBtn.closest( '.mvs-load-more' );
		var endMessage   = document.querySelector( '.mvs-load-more-end' );

		var config = {
			restUrl: loadMoreBtn.dataset.restUrl || '/wp-json/mvs/v1/',
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

		// Page cursor lives on the button's dataset so it persists across clicks
		// AND a freshly-swapped button (which carries only its server data-page)
		// resumes correctly. dataset.currentPage tracks our running position.
		var page = parseInt( loadMoreBtn.dataset.currentPage || loadMoreBtn.dataset.page, 10 ) || 1;
		page += 1;
		loadMoreBtn.dataset.currentPage = String( page );

		loadMoreBtn.classList.add( 'is-loading' );

		function showEnd() {
			if ( loadMoreWrap ) {
				loadMoreWrap.style.display = 'none';
			}
			if ( endMessage ) {
				endMessage.removeAttribute( 'hidden' );
			}
			loadMoreBtn.classList.remove( 'is-loading' );
		}

		// A network/server error is NOT end-of-feed. Keep the button visible and
		// clickable (clicking retries the same page) and tell the user — never
		// call showEnd() on error, which would falsely read "You're all caught up".
		function showError() {
			loadMoreBtn.classList.remove( 'is-loading' );
			var msg = ( window.wp && window.wp.i18n )
				? window.wp.i18n.__( 'Couldn’t load more. Tap to retry.', 'wpmediaverse' )
				: 'Couldn’t load more. Tap to retry.';
			if ( typeof window.mvsToast === 'function' ) {
				window.mvsToast( msg, 'error' );
			}
		}

		var gridContainer = document.querySelector( '[data-mvs-grid-container]' );

		var url = new URL( config.restUrl + config.endpoint, window.location.origin );
		url.searchParams.set( 'page', page );
		url.searchParams.set( 'per_page', config.perPage );
		if ( config.tag ) url.searchParams.set( 'tag', config.tag );
		if ( config.category ) url.searchParams.set( 'category', config.category );
		if ( config.search ) url.searchParams.set( 's', config.search );
		if ( config.scope ) url.searchParams.set( 'scope', config.scope );
		if ( config.author ) url.searchParams.set( 'author', config.author );
		if ( config.groupCovers ) url.searchParams.set( 'group_covers', '1' );

		window.mvsRest.restFetch( url.toString() )
			.then( function ( response ) {
				if ( ! response.ok ) {
					// Throw so the .catch below shows the retry state — returning
					// [] here would fall through to showEnd() = false "all caught up".
					throw new Error( 'mvs-load-more-failed' );
				}
				return response.data;
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

				// Re-render any Lucide placeholders the builder added.
				if ( window.lucide && typeof window.lucide.createIcons === 'function' ) {
					window.lucide.createIcons();
				}

				// Keep the lightbox prev/next registry current.
				rebuildRegistry( gridContainer );

				if ( items.length < config.perPage ) {
					showEnd();
				}

				loadMoreBtn.classList.remove( 'is-loading' );
			} )
			.catch( function () {
				showError();
			} );
	} );
}() );
