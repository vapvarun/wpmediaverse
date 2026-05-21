/**
 * Dismissible callouts — hide-and-remember via localStorage.
 *
 * Replaces the near-identical inline dismiss snippets that were duplicated in
 * explore.php, dashboard-content.php (Free) and the Pro feed layout. Each entry
 * maps a container id + its close-button id to a localStorage key; a missing
 * element is skipped, so the one script is safe to enqueue on any page.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var items = [
		{ el: 'mvs-logged-out-banner', btn: 'mvs-logged-out-banner-close', key: 'mvs_hide_cta' },
		{ el: 'mvs-profile-prompt', btn: 'mvs-profile-prompt-close', key: 'mvs_profile_prompt_dismissed' },
	];

	function stored( key ) {
		try {
			return localStorage.getItem( key ) === '1';
		} catch ( e ) {
			return false;
		}
	}

	function remember( key ) {
		try {
			localStorage.setItem( key, '1' );
		} catch ( e ) {}
	}

	items.forEach( function ( item ) {
		var el = document.getElementById( item.el );
		if ( ! el ) {
			return;
		}
		if ( stored( item.key ) ) {
			el.style.display = 'none';
		}
		var btn = document.getElementById( item.btn );
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				el.style.display = 'none';
				remember( item.key );
			} );
		}
	} );
} )();
