/**
 * Collection page — client-side title filter for the collection grid.
 * Extracted from the inline <script> in templates/collection.php.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var input = document.querySelector( '.mvs-collection-search-input' );
	if ( ! input ) {
		return;
	}
	var grid = document.querySelector( '.mvs-collection-article .mvs-media-grid' );
	if ( ! grid ) {
		return;
	}
	var items = grid.querySelectorAll( '.mvs-grid-item' );

	function filterItems() {
		var q = input.value.toLowerCase().trim();
		items.forEach( function ( item ) {
			var title = ( item.getAttribute( 'data-title' ) || '' ).toLowerCase();
			item.style.display = ( ! q || title.indexOf( q ) !== -1 ) ? '' : 'none';
		} );
	}

	input.addEventListener( 'input', filterItems );
	var btn = document.querySelector( '.mvs-collection-search-btn' );
	if ( btn ) {
		btn.addEventListener( 'click', filterItems );
	}
} )();
