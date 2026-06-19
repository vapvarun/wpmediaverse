/**
 * Explore search — media/users tab switch + debounced user search.
 *
 * Extracted from the inline <script> previously duplicated in templates/
 * explore.php (Free) and the Pro feed layouts. Config + translated strings
 * arrive via the localized `window.mvsExploreSearch` object, so no markup or
 * strings live in JS.
 *
 * Nav-aware: init() is idempotent via [data-mvs-wired] on the search form and
 * re-runs on mvs:navigated so freshly-swapped explore content is wired after
 * client-side navigation.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var cfg = window.mvsExploreSearch || {};
	var i18n = cfg.i18n || {};

	function init() {
		var form = document.querySelector( '#mvs-explore-search-form:not([data-mvs-wired])' );
		if ( ! form ) {
			return;
		}

		var tabs = document.querySelectorAll( '.mvs-search-mode-btn' );
		var input = document.getElementById( 'mvs-search-input' );
		var results = document.getElementById( 'mvs-user-search-results' );

		if ( ! tabs.length || ! input || ! results ) {
			return;
		}

		// Mark the form wired; tabs/input/results share the same subtree.
		form.setAttribute( 'data-mvs-wired', '' );

		var mode = 'media';
		var debounce;

		function clearResults() {
			while ( results.firstChild ) {
				results.removeChild( results.firstChild );
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				tabs.forEach( function ( t ) {
					t.classList.remove( 'active' );
				} );
				tab.classList.add( 'active' );
				mode = tab.getAttribute( 'data-search-mode' );
				input.placeholder = mode === 'users' ? ( i18n.searchUsers || '' ) : ( i18n.searchMedia || '' );
				results.style.display = 'none';
				clearResults();
			} );
		} );

		form.addEventListener( 'submit', function ( e ) {
			if ( mode === 'users' ) {
				e.preventDefault();
				searchUsers( input.value.trim() );
			}
		} );

		input.addEventListener( 'input', function () {
			if ( mode !== 'users' ) {
				return;
			}
			clearTimeout( debounce );
			debounce = setTimeout( function () {
				if ( input.value.trim().length >= 2 ) {
					searchUsers( input.value.trim() );
				} else {
					results.style.display = 'none';
					clearResults();
				}
			}, 300 );
		} );

		function searchUsers( q ) {
			if ( ! q || ! cfg.restUrl ) {
				return;
			}
			fetch( cfg.restUrl + '?q=' + encodeURIComponent( q ), {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.nonce || '' },
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( users ) {
					clearResults();
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
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	document.addEventListener( 'mvs:navigated', init );
} )();
