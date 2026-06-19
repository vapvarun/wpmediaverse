/**
 * Dismissible callouts — hide-and-remember via localStorage.
 *
 * Replaces the near-identical inline dismiss snippets that were duplicated in
 * explore.php, dashboard-content.php (Free) and the Pro feed layout. Each entry
 * maps a container id + its close-button id to a localStorage key; a missing
 * element is skipped, so the one script is safe to enqueue on any page.
 *
 * Nav-safe by DOCUMENT-LEVEL DELEGATION: the dismiss click is bound once on
 * `document` at module eval time — immune to iAPI router DOM morphs and region
 * swaps.
 *
 * localStorage hide is applied at module eval AND re-applied on every
 * `mvs:navigated` swap — the server re-renders the banner (it cannot read
 * localStorage), so without the re-apply a dismissed banner would reappear
 * after a client-side navigation (a regression vs the old full-load behavior).
 * This re-apply is an idempotent VISIBILITY pass (it only toggles display from
 * localStorage); it adds no listeners, so it cannot double-bind. The dismiss
 * CLICK is handled by a single document-level delegate for the page lifetime.
 *
 * The longer-term improvement is a REST-persisted dismiss flag checked
 * server-side so the banner never renders dismissed in the first place.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	// Map each dismiss button id to its container id and localStorage key.
	var items = [
		{ el: 'mvs-logged-out-banner', btn: 'mvs-logged-out-banner-close', key: 'mvs_hide_cta' },
		{ el: 'mvs-profile-prompt', btn: 'mvs-profile-prompt-close', key: 'mvs_profile_prompt_dismissed' },
	];

	// Build a quick lookup: button-id -> { el, key }.
	var btnMap = {};
	items.forEach( function ( item ) {
		btnMap[ item.btn ] = { el: item.el, key: item.key };
	} );

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

	// Hide any already-dismissed banner. Idempotent visibility pass — safe to
	// re-run on every navigation (sets display only, binds nothing).
	function applyStored() {
		items.forEach( function ( item ) {
			var el = document.getElementById( item.el );
			if ( el && stored( item.key ) ) {
				el.style.display = 'none';
			}
		} );
	}

	// At module eval (script runs deferred / at footer so the DOM is present)
	// and after every client-side region swap (the swapped-in banner is fresh
	// server markup that doesn't know it was dismissed).
	applyStored();
	document.addEventListener( 'mvs:navigated', applyStored );

	// --- Delegated click: any known dismiss button. ---
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[id]' );
		if ( ! btn ) {
			return;
		}
		var mapping = btnMap[ btn.id ];
		if ( ! mapping ) {
			return;
		}
		var el = document.getElementById( mapping.el );
		if ( el ) {
			el.style.display = 'none';
		}
		remember( mapping.key );
	} );
}() );
