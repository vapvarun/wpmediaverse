/**
 * Admin: route destructive [data-mvs-confirm] links through the mvsConfirm
 * modal instead of a native confirm() dialog. Falls back to window.confirm only
 * if the modal helper hasn't loaded. Delegated so it covers dynamically listed
 * rows (All Media, Tags).
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( '[data-mvs-confirm]' ) : null;
		if ( ! link ) {
			return;
		}
		var msg = link.getAttribute( 'data-mvs-confirm' );
		if ( ! msg || link.dataset.mvsConfirmed === '1' ) {
			return;
		}
		e.preventDefault();

		function proceed() {
			link.dataset.mvsConfirmed = '1';
			var href = link.getAttribute( 'href' );
			if ( href ) {
				window.location.href = href;
			}
		}

		if ( typeof window.mvsConfirm === 'function' ) {
			window.mvsConfirm( msg, { tone: 'destructive' } ).then( function ( ok ) {
				if ( ok ) {
					proceed();
				}
			} );
		} else if ( window.confirm( msg ) ) { // eslint-disable-line no-alert -- defensive fallback when modal helper absent.
			proceed();
		}
	} );
} )();
