/**
 * Collection page — client-side title filter for the collection grid.
 * Extracted from the inline <script> in templates/collection.php.
 *
 * Nav-safe by DOCUMENT-LEVEL DELEGATION: input, click (search button), and
 * submit events are bound once on `document` at module eval time — immune to
 * iAPI router DOM morphs and region swaps. Grid items are re-queried from the
 * current DOM on EVERY keystroke (stateless), which also fixes the stale-
 * NodeList bug from the previous init() closure that captured items once at
 * wire time and never refreshed after load-more appended new cards.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	// Filter items re-queried live each call — no stale NodeList.
	function filterItems( input ) {
		var grid = document.querySelector( '.mvs-collection-article .mvs-media-grid' );
		if ( ! grid || ! input ) {
			return;
		}
		var q = input.value.toLowerCase().trim();
		grid.querySelectorAll( '.mvs-grid-item' ).forEach( function ( item ) {
			var title = ( item.getAttribute( 'data-title' ) || '' ).toLowerCase();
			item.style.display = ( ! q || title.indexOf( q ) !== -1 ) ? '' : 'none';
		} );
	}

	// --- Delegated input: .mvs-collection-search-input typing. ---
	document.addEventListener( 'input', function ( e ) {
		var input = e.target.closest( '.mvs-collection-search-input' );
		if ( ! input ) {
			return;
		}
		filterItems( input );
	} );

	// --- Delegated click: .mvs-collection-search-btn button. ---
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.mvs-collection-search-btn' );
		if ( ! btn ) {
			return;
		}
		var form = btn.closest( 'form' );
		var input = form ? form.querySelector( '.mvs-collection-search-input' ) : document.querySelector( '.mvs-collection-search-input' );
		filterItems( input );
	} );

	// --- Delegated submit: block form navigation on Enter key. ---
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		var input = form.querySelector( '.mvs-collection-search-input' );
		if ( ! input ) {
			return;
		}
		// This form is client-side only; prevent page navigation.
		e.preventDefault();
		filterItems( input );
	} );
}() );
