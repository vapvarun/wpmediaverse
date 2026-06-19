/**
 * Profile actions — Follow toggle + Message button on profile pages.
 *
 * Extracted from the inline partials/profile-actions-js.php. The follow button
 * carries its own data-* attributes (user id, following state, rest url,
 * nonce); only the two toggle labels arrive via the localized
 * `window.mvsProfileActions.i18n` object so they stay translatable.
 *
 * Nav-safe by DOCUMENT-LEVEL DELEGATION: both handlers are bound once on
 * `document` at module eval time — immune to iAPI router DOM morphs, region
 * swaps, and timing races. In-flight state lives on the button element
 * (is-loading class) rather than a closure, so a freshly-swapped button
 * starts clean.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var cfg = window.mvsProfileActions || {};
	var i18n = cfg.i18n || {};

	// --- Delegated Follow toggle: any .mvs-follow-toggle click. ---
	document.addEventListener( 'click', function ( e ) {
		var fbtn = e.target.closest( '.mvs-follow-toggle' );
		if ( ! fbtn ) {
			return;
		}
		// Prevent double-fire while the request is in flight.
		if ( fbtn.classList.contains( 'is-loading' ) ) {
			return;
		}

		var userId = fbtn.getAttribute( 'data-user-id' );
		var isFollowing = fbtn.getAttribute( 'data-following' ) === '1';
		var restUrl = fbtn.getAttribute( 'data-rest-url' );

		fbtn.classList.add( 'is-loading' );
		fbtn.disabled = true;

		window.mvsRest.restFetch( restUrl + 'users/' + userId + '/follow', {
			method: isFollowing ? 'DELETE' : 'POST',
		} )
			.then( function ( r ) {
				return r.data;
			} )
			.then( function ( data ) {
				fbtn.classList.remove( 'is-loading' );
				fbtn.disabled = false;
				if ( data.following ) {
					fbtn.setAttribute( 'data-following', '1' );
					fbtn.textContent = i18n.following || '';
					fbtn.classList.add( 'mvs-follow-toggle--following' );
					fbtn.classList.remove( 'mvs-btn--primary' );
				} else {
					fbtn.setAttribute( 'data-following', '0' );
					fbtn.textContent = i18n.follow || '';
					fbtn.classList.remove( 'mvs-follow-toggle--following' );
					fbtn.classList.add( 'mvs-btn--primary' );
				}
				if ( data.counts ) {
					var stats = document.querySelector( '.mvs-profile-header-stats' );
					if ( stats ) {
						var spans = stats.querySelectorAll( 'span' );
						if ( spans[ 1 ] ) {
							var strong = spans[ 1 ].querySelector( 'strong' );
							if ( strong ) {
								strong.textContent = data.counts.followers;
							}
						}
					}
				}
			} )
			.catch( function () {
				fbtn.classList.remove( 'is-loading' );
				fbtn.disabled = false;
			} );
	} );

	// --- Delegated Message button: any .mvs-message-btn click. ---
	document.addEventListener( 'click', function ( e ) {
		var mbtn = e.target.closest( '.mvs-message-btn' );
		if ( ! mbtn ) {
			return;
		}
		var userId = parseInt( mbtn.getAttribute( 'data-user-id' ), 10 );
		document.dispatchEvent( new CustomEvent( 'mvs-open-conversation', {
			detail: { userId: userId },
		} ) );
	} );
}() );
