/**
 * Messages page — scroll the chat section to the top of the viewport on load
 * so the user doesn't have to scroll past the themed header to start chatting.
 * Extracted from the inline <script> in templates/messages.php. Runs once.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	function scrollChatIntoView() {
		var page = document.querySelector( '.mvs-messages-page' );
		if ( ! page ) {
			return;
		}
		var rect = page.getBoundingClientRect();
		if ( rect.top > 4 ) {
			window.scrollTo( 0, window.scrollY + rect.top - 4 );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', scrollChatIntoView );
	} else {
		scrollChatIntoView();
	}
} )();
