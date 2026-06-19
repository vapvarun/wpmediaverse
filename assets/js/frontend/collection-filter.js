/**
 * Collection page — client-side title filter for the collection grid.
 * Extracted from the inline <script> in templates/collection.php.
 *
 * Nav-aware: init() is idempotent via [data-mvs-wired] on the search input
 * and re-runs on mvs:navigated so freshly-swapped collection content is wired
 * after client-side navigation.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	function init() {
		var input = document.querySelector( '.mvs-collection-search-input:not([data-mvs-wired])' );
		if ( ! input ) {
			return;
		}
		var grid = document.querySelector( '.mvs-collection-article .mvs-media-grid' );
		if ( ! grid ) {
			return;
		}

		input.setAttribute( 'data-mvs-wired', '' );

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

		// The search is client-side only; block the form from navigating on Enter
		// (replaces the inline onsubmit="return false").
		var form = input.closest( 'form' );
		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				filterItems();
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	document.addEventListener( 'mvs:navigated', init );
} )();
