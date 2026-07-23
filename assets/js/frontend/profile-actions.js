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
					// Two header markups exist: the free explore profile wraps the
					// counts in .mvs-follows-open buttons (data-list names each
					// count); Pro layout profiles use plain spans in the order
					// media / followers / following. Handle both.
					var stats = document.querySelector( '.mvs-profile-header-stats' );
					var followersEl = document.querySelector( '.mvs-profile-header-stats .mvs-follows-open[data-list="followers"] strong' );
					var followingEl = document.querySelector( '.mvs-profile-header-stats .mvs-follows-open[data-list="following"] strong' );
					if ( ! followersEl && stats ) {
						var spans = stats.querySelectorAll( 'span' );
						followersEl = spans[ 1 ] ? spans[ 1 ].querySelector( 'strong' ) : null;
						followingEl = spans[ 2 ] ? spans[ 2 ].querySelector( 'strong' ) : null;
					}
					if ( followersEl && typeof data.counts.followers !== 'undefined' ) {
						followersEl.textContent = data.counts.followers;
					}
					if ( followingEl && typeof data.counts.following !== 'undefined' ) {
						followingEl.textContent = data.counts.following;
					}
				}
			} )
			.catch( function () {
				fbtn.classList.remove( 'is-loading' );
				fbtn.disabled = false;
			} );
	} );

	// --- Delegated Block toggle: any .mvs-block-toggle click. ---
	// Block/unblock is reachable from the profile; unblocking is one click on
	// the same control, so an accidental block needs no separate undo path.
	document.addEventListener( 'click', function ( e ) {
		var bbtn = e.target.closest( '.mvs-block-toggle' );
		if ( ! bbtn ) {
			return;
		}
		if ( bbtn.classList.contains( 'is-loading' ) ) {
			return;
		}

		var userId = bbtn.getAttribute( 'data-user-id' );
		var isBlocked = bbtn.getAttribute( 'data-blocked' ) === '1';
		var restUrl = bbtn.getAttribute( 'data-rest-url' );

		bbtn.classList.add( 'is-loading' );
		bbtn.disabled = true;

		window.mvsRest.restFetch( restUrl + 'users/' + userId + '/block', {
			method: isBlocked ? 'DELETE' : 'POST',
		} )
			.then( function ( r ) {
				return r.data;
			} )
			.then( function ( data ) {
				bbtn.classList.remove( 'is-loading' );
				bbtn.disabled = false;
				if ( data && data.blocked ) {
					bbtn.setAttribute( 'data-blocked', '1' );
					bbtn.textContent = i18n.unblock || 'Unblock';
					bbtn.classList.add( 'mvs-block-toggle--blocked' );
					bbtn.setAttribute( 'aria-label', i18n.unblockAria || '' );
				} else {
					bbtn.setAttribute( 'data-blocked', '0' );
					bbtn.textContent = i18n.block || 'Block';
					bbtn.classList.remove( 'mvs-block-toggle--blocked' );
					bbtn.setAttribute( 'aria-label', i18n.blockAria || '' );
				}
			} )
			.catch( function () {
				bbtn.classList.remove( 'is-loading' );
				bbtn.disabled = false;
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

	// --- Overflow (⋯) menu: toggle on the trigger, close on outside click. ---
	function closeAllActionMenus() {
		document.querySelectorAll( '.mvs-actions-menu:not([hidden])' ).forEach( function ( m ) {
			m.setAttribute( 'hidden', '' );
			var t = m.parentElement && m.parentElement.querySelector( '.mvs-actions-more' );
			if ( t ) {
				t.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}
	document.addEventListener( 'click', function ( e ) {
		var more = e.target.closest( '.mvs-actions-more' );
		if ( more ) {
			var menu = more.parentElement.querySelector( '.mvs-actions-menu' );
			var willOpen = menu && menu.hasAttribute( 'hidden' );
			closeAllActionMenus();
			if ( willOpen ) {
				menu.removeAttribute( 'hidden' );
				more.setAttribute( 'aria-expanded', 'true' );
			}
			return;
		}
		// Click anywhere outside an open menu closes it.
		if ( ! e.target.closest( '.mvs-actions-menu' ) ) {
			closeAllActionMenus();
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			closeAllActionMenus();
			var dlg = document.querySelector( '.mvs-report-modal:not([hidden])' );
			if ( dlg ) {
				dlg.setAttribute( 'hidden', '' );
			}
		}
	} );

	// --- Report: open dialog from the menu item. ---
	document.addEventListener( 'click', function ( e ) {
		var rtrig = e.target.closest( '.mvs-report-trigger' );
		if ( ! rtrig ) {
			return;
		}
		closeAllActionMenus();
		var dlg = document.querySelector( '.mvs-report-modal' );
		if ( dlg ) {
			dlg.removeAttribute( 'hidden' );
		}
	} );

	// --- Report: cancel / backdrop close. ---
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '.mvs-report-cancel' ) || e.target.classList.contains( 'mvs-report-modal' ) ) {
			var dlg = document.querySelector( '.mvs-report-modal:not([hidden])' );
			if ( dlg ) {
				dlg.setAttribute( 'hidden', '' );
			}
		}
	} );

	// --- Report: submit → POST /users/{id}/report { reason, details }. ---
	document.addEventListener( 'click', function ( e ) {
		var sbtn = e.target.closest( '.mvs-report-submit' );
		if ( ! sbtn || sbtn.classList.contains( 'is-loading' ) ) {
			return;
		}
		var dlg = sbtn.closest( '.mvs-report-modal' );
		if ( ! dlg ) {
			return;
		}
		var userId = dlg.getAttribute( 'data-user-id' );
		var restUrl = dlg.getAttribute( 'data-rest-url' );
		var reason = ( dlg.querySelector( '.mvs-report-reason' ) || {} ).value || 'other';
		var details = ( dlg.querySelector( '.mvs-report-details' ) || {} ).value || '';

		sbtn.classList.add( 'is-loading' );
		sbtn.disabled = true;
		window.mvsRest.restFetch( restUrl + 'users/' + userId + '/report', {
			method: 'POST',
			body: { reason: reason, details: details },
		} )
			.then( function ( r ) {
				sbtn.classList.remove( 'is-loading' );
				sbtn.disabled = false;
				// Any server response (200, or a 4xx "already reported" dedup) means
				// the member is reported from the user's view — show done, never hang.
				if ( r ) {
					// Swap to the success state, then auto-close.
					var form = dlg.querySelector( '.mvs-report-form' );
					var done = dlg.querySelector( '.mvs-report-done' );
					if ( form ) {
						form.setAttribute( 'hidden', '' );
					}
					if ( done ) {
						done.removeAttribute( 'hidden' );
					}
					setTimeout( function () {
						dlg.setAttribute( 'hidden', '' );
						if ( form ) {
							form.removeAttribute( 'hidden' );
						}
						if ( done ) {
							done.setAttribute( 'hidden', '' );
						}
					}, 1600 );
				}
			} )
			.catch( function () {
				sbtn.classList.remove( 'is-loading' );
				sbtn.disabled = false;
			} );
	} );
}() );
