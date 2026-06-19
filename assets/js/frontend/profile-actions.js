/**
 * Profile actions — Follow toggle + Message button on profile pages.
 *
 * Extracted from the inline partials/profile-actions-js.php. The follow button
 * carries its own data-* attributes (user id, following state, rest url,
 * nonce); only the two toggle labels arrive via the localized
 * `window.mvsProfileActions.i18n` object so they stay translatable.
 *
 * Nav-aware: init() is idempotent via [data-mvs-wired] on each button and
 * re-runs on mvs:navigated so freshly-swapped profile content is wired after
 * client-side navigation.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var cfg = window.mvsProfileActions || {};
	var i18n = cfg.i18n || {};

	function init() {
		// Follow toggle — wire each unwired button.
		document.querySelectorAll( '.mvs-follow-toggle:not([data-mvs-wired])' ).forEach( function ( fbtn ) {
			fbtn.setAttribute( 'data-mvs-wired', '' );
			fbtn.addEventListener( 'click', function () {
				var userId = fbtn.getAttribute( 'data-user-id' );
				var isFollowing = fbtn.getAttribute( 'data-following' ) === '1';
				var restUrl = fbtn.getAttribute( 'data-rest-url' );
				var nonce = fbtn.getAttribute( 'data-nonce' );
				fbtn.disabled = true;
				window.mvsRest.restFetch( restUrl + 'users/' + userId + '/follow', {
					method: isFollowing ? 'DELETE' : 'POST',
				} )
					.then( function ( r ) {
						return r.data;
					} )
					.then( function ( data ) {
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
						fbtn.disabled = false;
					} );
			} );
		} );

		// Message button — wire each unwired button.
		document.querySelectorAll( '.mvs-message-btn:not([data-mvs-wired])' ).forEach( function ( mbtn ) {
			mbtn.setAttribute( 'data-mvs-wired', '' );
			mbtn.addEventListener( 'click', function () {
				var userId = parseInt( mbtn.getAttribute( 'data-user-id' ), 10 );
				document.dispatchEvent( new CustomEvent( 'mvs-open-conversation', {
					detail: { userId: userId },
				} ) );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	document.addEventListener( 'mvs:navigated', init );
} )();
