/**
 * WPMediaVerse — Admin Toast Notifications
 *
 * Usage: window.mvsToast( 'Message text', 'success' );
 * Types: success, error, info, warning
 *
 * @package WPMediaVerse
 */
( function() {
	'use strict';

	var container;

	function getContainer() {
		if ( ! container ) {
			container = document.createElement( 'div' );
			container.className = 'mvs-toast-container';
			document.body.appendChild( container );
		}
		return container;
	}

	window.mvsToast = function( message, type ) {
		type = type || 'success';

		var el = document.createElement( 'div' );
		el.className = 'mvs-toast mvs-toast--' + type;
		el.setAttribute( 'role', 'status' );
		el.setAttribute( 'aria-live', 'polite' );
		el.textContent = message;

		getContainer().appendChild( el );

		// Trigger enter animation.
		setTimeout( function() {
			el.classList.add( 'is-visible' );
		}, 10 );

		// Auto-dismiss after 4 seconds.
		setTimeout( function() {
			el.classList.remove( 'is-visible' );
			setTimeout( function() {
				el.remove();
			}, 300 );
		}, 4000 );
	};
} )();
