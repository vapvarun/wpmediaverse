/**
 * Admin: route destructive [data-mvs-confirm] actions through the mvsConfirm
 * modal instead of a native confirm() dialog. Falls back to window.confirm only
 * if the modal helper hasn't loaded. Delegated so it covers dynamically listed
 * rows (All Media, Tags).
 *
 * Handles two surfaces:
 *   - Links (<a data-mvs-confirm="...">): navigate to href on confirm.
 *   - Forms (<form data-mvs-confirm="...">): submit the form on confirm
 *     (replaces inline onsubmit="return confirm(...)").
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	function ask( msg, proceed ) {
		if ( typeof window.mvsConfirm === 'function' ) {
			window.mvsConfirm( msg, { tone: 'destructive' } ).then( function ( ok ) {
				if ( ok ) {
					proceed();
				}
			} );
		} else if ( window.confirm( msg ) ) { // eslint-disable-line no-alert -- defensive fallback when modal helper absent.
			proceed();
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( 'a[data-mvs-confirm]' ) : null;
		if ( ! link ) {
			return;
		}
		var msg = link.getAttribute( 'data-mvs-confirm' );
		if ( ! msg || link.dataset.mvsConfirmed === '1' ) {
			return;
		}
		e.preventDefault();
		ask( msg, function () {
			link.dataset.mvsConfirmed = '1';
			var href = link.getAttribute( 'href' );
			if ( href ) {
				window.location.href = href;
			}
		} );
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest ? e.target.closest( 'form[data-mvs-confirm]' ) : null;
		if ( ! form ) {
			return;
		}
		var msg = form.getAttribute( 'data-mvs-confirm' );
		if ( ! msg || form.dataset.mvsConfirmed === '1' ) {
			return;
		}
		e.preventDefault();
		ask( msg, function () {
			form.dataset.mvsConfirmed = '1';
			form.submit();
		} );
	} );
} )();
