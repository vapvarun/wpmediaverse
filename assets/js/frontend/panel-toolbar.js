/**
 * Apply-on-change for `TemplateHelpers::render_panel_toolbar()`.
 *
 * That helper is the one toolbar: the document drive, the dashboard panels,
 * Explore Media and Explore Documents all render through it. Each emits an
 * Apply button server-side so a member without JavaScript submits a plain form
 * and gets their sort. This removes it and submits on change instead — a
 * control that already shows what it will do does not also need a button asking
 * you to confirm it.
 *
 * Retargeting this at the helper's own class rather than a second one is what
 * lets the drive drop its Apply too: the drive's comment said it "submits
 * rather than applying on change, because with JavaScript off there is nothing
 * to apply on", which is right about the no-JS case and was being paid for by
 * everybody else.
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

	var TOOLBAR = 'form.mvs-panel-toolbar';

	/**
	 * Hide the no-JS fallback wherever a toolbar appears.
	 */
	function hideApply() {
		var buttons = document.querySelectorAll( 'form.mvs-panel-toolbar [type="submit"]' );

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
		var paged = form.querySelector( '[name="paged"], [name="page"], [name="doc_page"]' );

		if ( paged ) {
			paged.remove();
		}

		// Empty fields are disabled rather than submitted, so a sort change
		// produces `?sort=title&order=desc` and not
		// `?q=&doc_type=&sort=title&order=desc`. The hidden inputs exist to carry
		// a search or a filter ACROSS the change; when there is none to carry,
		// naming it in the URL states a filter the member never set. Disabling
		// (not removing) keeps the field for the next change on this page.
		// Selects are included: a filter sitting on its "All types" option has an
		// empty value and means "no filter", so naming it in the URL is the same
		// overstatement as an empty search term. `sort` and `order` always carry
		// a value and are untouched by this.
		var carried = form.querySelectorAll( 'input[type="hidden"], input[type="search"], input[type="text"], select' );

		for ( var i = 0; i < carried.length; i++ ) {
			if ( '' === carried[ i ].value ) {
				carried[ i ].disabled = true;
			}
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
