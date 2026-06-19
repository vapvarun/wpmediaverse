/**
 * Explore search — media/users tab switch + debounced user search.
 *
 * Extracted from the inline <script> previously duplicated in templates/
 * explore.php (Free) and the Pro feed layouts. Config + translated strings
 * arrive via the localized `window.mvsExploreSearch` object, so no markup or
 * strings live in JS.
 *
 * Nav-safe by DOCUMENT-LEVEL DELEGATION: tab clicks, form submit, and input
 * events are all bound once on `document` at module eval time — immune to
 * iAPI router DOM morphs and region swaps. The active search mode (People /
 * Media) is stored as a dataset attribute on the form element so it survives
 * a DOM swap (a freshly-swapped form always starts in media mode, which is
 * correct server-default behaviour). The debounce timer is a module-level var
 * (shared across navigations, which is safe because it is always cleared
 * before being reset).
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var cfg = window.mvsExploreSearch || {};
	var i18n = cfg.i18n || {};

	// Module-level debounce timer — safe to share; always cleared before reset.
	var debounce;

	// Helper: clear results list.
	function clearResults( results ) {
		while ( results.firstChild ) {
			results.removeChild( results.firstChild );
		}
	}

	// Helper: read the current mode from the form dataset (default: 'media').
	function getMode( form ) {
		return form.dataset.searchMode || 'media';
	}

	// Helper: perform user search and populate results.
	function searchUsers( q, results ) {
		if ( ! q || ! cfg.restUrl ) {
			return;
		}
		window.mvsRest.restFetch( cfg.restUrl + '?q=' + encodeURIComponent( q ) )
			.then( function ( r ) {
				return r.data;
			} )
			.then( function ( users ) {
				clearResults( results );
				if ( ! users.length ) {
					var emptyP = document.createElement( 'p' );
					emptyP.className = 'mvs-user-search-empty';
					emptyP.textContent = i18n.noUsers || '';
					results.appendChild( emptyP );
					results.style.display = 'block';
					return;
				}
				users.forEach( function ( u ) {
					var name = u.display_name || u.name || u.user_login || '';
					var card = document.createElement( 'a' );
					card.href = u.profile_url || '#';
					card.className = 'mvs-user-card';

					var img = document.createElement( 'img' );
					img.src = u.avatar || '';
					img.alt = '';
					img.className = 'mvs-user-card-avatar';
					img.width = 48;
					img.height = 48;
					card.appendChild( img );

					var info = document.createElement( 'div' );
					info.className = 'mvs-user-card-info';
					var nameEl = document.createElement( 'strong' );
					nameEl.textContent = name;
					info.appendChild( nameEl );
					if ( u.media_count !== undefined ) {
						var countEl = document.createElement( 'span' );
						countEl.textContent = u.media_count + ' ' + ( i18n.media || '' );
						info.appendChild( countEl );
					}
					card.appendChild( info );
					results.appendChild( card );
				} );
				results.style.display = 'block';
			} );
	}

	// --- Delegated tab click: any .mvs-search-mode-btn click. ---
	document.addEventListener( 'click', function ( e ) {
		var tab = e.target.closest( '.mvs-search-mode-btn' );
		if ( ! tab ) {
			return;
		}
		var form = document.getElementById( 'mvs-explore-search-form' );
		var input = document.getElementById( 'mvs-search-input' );
		var results = document.getElementById( 'mvs-user-search-results' );
		if ( ! form || ! input || ! results ) {
			return;
		}

		// Deactivate all sibling tabs.
		form.querySelectorAll( '.mvs-search-mode-btn' ).forEach( function ( t ) {
			t.classList.remove( 'active' );
		} );
		tab.classList.add( 'active' );

		// Persist mode on the form element so input/submit handlers can read it.
		var newMode = tab.getAttribute( 'data-search-mode' ) || 'media';
		form.dataset.searchMode = newMode;

		input.placeholder = newMode === 'users' ? ( i18n.searchUsers || '' ) : ( i18n.searchMedia || '' );
		results.style.display = 'none';
		clearResults( results );
	} );

	// --- Delegated form submit: #mvs-explore-search-form submit. ---
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '#mvs-explore-search-form' );
		if ( ! form ) {
			return;
		}
		var input = document.getElementById( 'mvs-search-input' );
		var results = document.getElementById( 'mvs-user-search-results' );
		if ( ! input || ! results ) {
			return;
		}
		if ( getMode( form ) === 'users' ) {
			e.preventDefault();
			searchUsers( input.value.trim(), results );
		}
	} );

	// --- Delegated input: #mvs-search-input typing (debounced user search). ---
	document.addEventListener( 'input', function ( e ) {
		var input = e.target.closest( '#mvs-search-input' );
		if ( ! input ) {
			return;
		}
		var form = document.getElementById( 'mvs-explore-search-form' );
		var results = document.getElementById( 'mvs-user-search-results' );
		if ( ! form || ! results ) {
			return;
		}
		if ( getMode( form ) !== 'users' ) {
			return;
		}
		clearTimeout( debounce );
		debounce = setTimeout( function () {
			var q = input.value.trim();
			if ( q.length >= 2 ) {
				searchUsers( q, results );
			} else {
				results.style.display = 'none';
				clearResults( results );
			}
		}, 300 );
	} );
}() );
