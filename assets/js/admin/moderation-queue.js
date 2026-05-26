/**
 * Moderation queue admin: bulk select-all, live selected-count, and a submit
 * guard that blocks an empty bulk action. Replaces the former inline <script>.
 * Row checkboxes live outside the bulk form (joined via form=""), so selections
 * are queried against the document, not the form node.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var selectAll = document.getElementById( 'mvs-select-all' );
	var form = document.getElementById( 'mvs-moderation-bulk-form' );
	var countWrap = document.getElementById( 'mvs-bulk-count' );
	var countEl = document.getElementById( 'mvs-selected-count' );
	if ( ! selectAll || ! form || ! countWrap || ! countEl ) {
		return;
	}

	function rowBoxes() {
		return document.querySelectorAll( '.mvs-bulk-cb' );
	}

	function updateCount() {
		var checked = document.querySelectorAll( '.mvs-bulk-cb:checked' );
		countEl.textContent = checked.length;
		countWrap.classList.toggle( 'mvs-hidden', checked.length === 0 );
	}

	selectAll.addEventListener( 'change', function () {
		rowBoxes().forEach( function ( cb ) {
			cb.checked = selectAll.checked;
		} );
		updateCount();
	} );

	document.addEventListener( 'change', function ( e ) {
		if ( e.target.classList && e.target.classList.contains( 'mvs-bulk-cb' ) ) {
			updateCount();
		}
	} );

	form.addEventListener( 'submit', function ( e ) {
		var action = document.getElementById( 'mvs-bulk-action-select' ).value;
		var checked = document.querySelectorAll( '.mvs-bulk-cb:checked' );
		if ( ! action || checked.length === 0 ) {
			e.preventDefault();
		}
	} );
} )();
