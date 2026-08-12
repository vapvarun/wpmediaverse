/**
 * Apply-on-change for the shared list toolbar.
 *
 * The toolbar renders an Apply button server-side so a member without
 * JavaScript submits a plain form and gets their sort. This removes it and
 * submits on change instead — a control that already shows what it will do does
 * not also need a button asking you to confirm it.
 *
 * Delegated from the document so a client-side navigation that swaps the region
 * keeps working: a listener bound to the form on first paint would be lost with
 * the markup it was bound to.
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */
( function () {
	'use strict';

	var TOOLBAR = '.mvs-list-toolbar__form';

	/**
	 * Hide the no-JS fallback wherever a toolbar appears.
	 */
	function hideApply() {
		var buttons = document.querySelectorAll( '.mvs-list-toolbar__apply' );

		for ( var i = 0; i < buttons.length; i++ ) {
			buttons[ i ].hidden = true;
		}
	}

	document.addEventListener( 'change', function ( event ) {
		var select = event.target;

		if ( ! select || 'SELECT' !== select.tagName ) {
			return;
		}

		var form = select.closest( TOOLBAR );

		if ( ! form ) {
			return;
		}

		// A page number belongs to the list you were looking at, not to the one
		// a new sort produces: re-sorting and landing on page 4 of a different
		// order shows a member rows they have no way to place. Back to the start.
		var paged = form.querySelector( '[name="paged"], [name="page"]' );

		if ( paged ) {
			paged.remove();
		}

		form.submit();
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', hideApply );
	} else {
		hideApply();
	}

	// Re-hide after an Interactivity-API region swap brings new markup in.
	document.addEventListener( 'wpmediaverse-region-updated', hideApply );
}() );
