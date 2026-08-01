/**
 * Album audio playlist — sequential track playback for audio-album pages.
 * Extracted from the inline <script> in templates/album.php.
 *
 * Track data is read from the <script type="application/json"
 * class="mvs-playlist-data"> emitted INSIDE the .mvs-playlist container, which
 * lives inside the router region. It used to arrive on a localized
 * window.mvsAlbumPlaylist global, but a template-body wp_localize_script()
 * prints its tag in wp_footer, outside [data-wp-router-region="mvs/main"] — so
 * after a client-side navigation into an audio album the data was stale (or,
 * on a first visit reached by client-nav, absent entirely).
 *
 * NAV-SAFETY: track clicks are delegated on `document`; per-playlist state is
 * (re)initialised on module eval and again on every `mvs:navigated`, matching
 * the pattern in dismissible.js. Nothing holds an element reference across a
 * region swap.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	// Per-album playback state, keyed by album id. Rebuilt on navigation.
	var state = {};

	function playlistScope( el ) {
		return el.closest ? el.closest( '.mvs-playlist' ) : null;
	}

	function albumIdOf( scope ) {
		var id = scope && scope.id ? scope.id.replace( 'mvs-playlist-', '' ) : '';
		return parseInt( id, 10 ) || 0;
	}

	function tracksOf( scope ) {
		var node = scope ? scope.querySelector( '.mvs-playlist-data' ) : null;
		if ( ! node ) {
			return [];
		}
		try {
			var parsed = JSON.parse( node.textContent );
			return Array.isArray( parsed ) ? parsed : [];
		} catch ( e ) {
			return [];
		}
	}

	function label( t ) {
		return ( t.artist ? t.artist + ' — ' : '' ) + t.title;
	}

	function playTrack( scope, idx ) {
		var albumId = albumIdOf( scope );
		var tracks = tracksOf( scope );
		if ( idx < 0 || idx >= tracks.length || ! tracks[ idx ].url ) {
			return;
		}
		var audio = document.getElementById( 'mvs-playlist-audio-' + albumId );
		var nowEl = document.getElementById( 'mvs-playlist-now-' + albumId );
		if ( ! audio || ! nowEl ) {
			return;
		}
		state[ albumId ] = idx;
		audio.src = tracks[ idx ].url;
		audio.type = tracks[ idx ].type;
		audio.play();
		nowEl.textContent = label( tracks[ idx ] );
		scope.querySelectorAll( '.mvs-playlist-track' ).forEach( function ( el, i ) {
			el.classList.toggle( 'mvs-playlist-track--active', i === idx );
		} );
	}

	// --- Delegated track selection. ---
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) {
			return;
		}
		var btn = e.target.closest( '.mvs-playlist-track-btn' );
		if ( ! btn ) {
			return;
		}
		var scope = playlistScope( btn );
		if ( scope ) {
			playTrack( scope, parseInt( btn.getAttribute( 'data-track-index' ), 10 ) );
		}
	} );

	// --- Auto-advance. Delegated via capture: 'ended' does not bubble. ---
	document.addEventListener(
		'ended',
		function ( e ) {
			var audio = e.target;
			if ( ! audio || ! audio.id || audio.id.indexOf( 'mvs-playlist-audio-' ) !== 0 ) {
				return;
			}
			var albumId = parseInt( audio.id.replace( 'mvs-playlist-audio-', '' ), 10 ) || 0;
			var scope = document.getElementById( 'mvs-playlist-' + albumId );
			if ( ! scope ) {
				return;
			}
			var current = typeof state[ albumId ] === 'number' ? state[ albumId ] : -1;
			if ( current + 1 < tracksOf( scope ).length ) {
				playTrack( scope, current + 1 );
			}
		},
		true
	);

	// Prime each playlist with its first track (no autoplay) — idempotent, so
	// it is safe to re-run after every region swap.
	function primeAll() {
		document.querySelectorAll( '.mvs-playlist' ).forEach( function ( scope ) {
			var albumId = albumIdOf( scope );
			var tracks = tracksOf( scope );
			if ( ! albumId || ! tracks.length || ! tracks[ 0 ].url ) {
				return;
			}
			var audio = document.getElementById( 'mvs-playlist-audio-' + albumId );
			var nowEl = document.getElementById( 'mvs-playlist-now-' + albumId );
			if ( ! audio || ! nowEl || audio.src ) {
				return;
			}
			audio.src = tracks[ 0 ].url;
			audio.type = tracks[ 0 ].type;
			nowEl.textContent = label( tracks[ 0 ] );
			var first = scope.querySelector( '.mvs-playlist-track' );
			if ( first ) {
				first.classList.add( 'mvs-playlist-track--active' );
			}
		} );
	}

	primeAll();
	document.addEventListener( 'mvs:navigated', primeAll );
} )();
