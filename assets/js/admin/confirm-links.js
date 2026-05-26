/**
 * Admin: route destructive [data-mvs-confirm] actions through the mvsConfirm
 * modal. mvsConfirm is a hard dependency — if the helper hasn't loaded the
 * action fails closed (no native browser confirm fallback, per
 * admin-ux-rulebook Rule 10). Delegated so it covers dynamically listed
 * rows (All Media, Tags).
 *
 * Handles two surfaces:
 *   - Links (<a data-mvs-confirm="...">): navigate to href on confirm.
 *   - Forms (<form data-mvs-confirm="...">): submit the form on confirm
 *     (replaces inline onsubmit blocks that used to call native confirm).
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	function ask( msg, proceed ) {
		// mvsConfirm is enqueued as a hard dependency wherever this script
		// runs (admin All Media + Tags surfaces). If it's somehow absent
		// fail closed — admin-ux-rulebook Rule 10 bans the native confirm
		// dialog, so we never use it even as a fallback.
		if ( typeof window.mvsConfirm !== 'function' ) {
			return;
		}
		window.mvsConfirm( msg, { tone: 'destructive' } ).then( function ( ok ) {
			if ( ok ) {
				proceed();
			}
		} );
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
