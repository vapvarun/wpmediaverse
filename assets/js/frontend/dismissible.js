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
	// `remote` is the key POST /mvs/v1/me/dismiss understands. An entry with one
	// is remembered on the SERVER, so the banner never renders again and cannot
	// flash. Entries without one are localStorage-only, which is all a logged-out
	// visitor can have.
	var items = [
		{ el: 'mvs-logged-out-banner', btn: 'mvs-logged-out-banner-close', key: 'mvs_hide_cta' },
		{ el: 'mvs-profile-prompt', btn: 'mvs-profile-prompt-close', key: 'mvs_profile_prompt_dismissed', remote: 'profile_prompt' },
	];

	/**
	 * Persist a dismissal for the signed-in member.
	 *
	 * Fire-and-forget: the banner is already gone from the page, so a failed
	 * request must not undo that or interrupt anybody. localStorage still holds
	 * it for this browser either way.
	 *
	 * @param {string} remoteKey Key the endpoint understands.
	 */
	function persist( remoteKey ) {
		// Through the shared client, not a raw fetch. `restFetch()` resolves the
		// base URL and the nonce, and refreshes the nonce once on
		// `403 rest_cookie_invalid_nonce` — which is exactly what a long-open
		// dashboard tab hits. A hand-rolled fetch here silently failed that way.
		if ( ! remoteKey || ! window.mvsRest || ! window.mvsRest.restFetch ) {
			return;
		}

		try {
			// Fire-and-forget: the banner is already gone from this page, so a
			// failed request must not undo that or interrupt anybody.
			// localStorage still holds it for this browser either way.
			// A PLAIN OBJECT, not a JSON string: `restFetch()` encodes plain
			// objects itself and sets `Content-Type: application/json` while it
			// does. Handing it a pre-stringified body sent it without that
			// header, so the route saw no `key` at all and answered 400.
			window.mvsRest.restFetch( '/me/dismiss', {
				method: 'POST',
				body: { key: remoteKey }
			} ).catch( function () {} );
		} catch ( e ) {}
	}

	// Build a quick lookup: button-id -> { el, key }.
	var btnMap = {};
	items.forEach( function ( item ) {
		btnMap[ item.btn ] = { el: item.el, key: item.key, remote: item.remote };
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
		persist( mapping.remote );
	} );
}() );
