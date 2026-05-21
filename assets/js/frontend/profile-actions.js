/**
 * Profile actions — Follow toggle + Message button on profile pages.
 *
 * Extracted from the inline partials/profile-actions-js.php. The follow button
 * carries its own data-* attributes (user id, following state, rest url,
 * nonce); only the two toggle labels arrive via the localized
 * `window.mvsProfileActions.i18n` object so they stay translatable.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var cfg = window.mvsProfileActions || {};
	var i18n = cfg.i18n || {};

	// Follow toggle.
	var fbtn = document.querySelector( '.mvs-follow-toggle' );
	if ( fbtn ) {
		fbtn.addEventListener( 'click', function () {
			var userId = fbtn.getAttribute( 'data-user-id' );
			var isFollowing = fbtn.getAttribute( 'data-following' ) === '1';
			var restUrl = fbtn.getAttribute( 'data-rest-url' );
			var nonce = fbtn.getAttribute( 'data-nonce' );
			fbtn.disabled = true;
			fetch( restUrl + 'users/' + userId + '/follow', {
				method: isFollowing ? 'DELETE' : 'POST',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
			} )
				.then( function ( r ) {
					return r.json();
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
	}

	// Message button — dispatch event; the messaging store handles the rest.
	var mbtn = document.querySelector( '.mvs-message-btn' );
	if ( mbtn ) {
		mbtn.addEventListener( 'click', function () {
			var userId = parseInt( mbtn.getAttribute( 'data-user-id' ), 10 );
			document.dispatchEvent( new CustomEvent( 'mvs-open-conversation', {
				detail: { userId: userId },
			} ) );
		} );
	}
} )();
