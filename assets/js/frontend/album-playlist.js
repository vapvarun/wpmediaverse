/**
 * Album audio playlist — sequential track playback for audio-album pages.
 * Extracted from the inline <script> in templates/album.php. Track data +
 * album id arrive via the localized `window.mvsAlbumPlaylist` object.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var cfg = window.mvsAlbumPlaylist || {};
	var tracks = cfg.tracks || [];
	var albumId = cfg.albumId;

	var audio = document.getElementById( 'mvs-playlist-audio-' + albumId );
	var nowEl = document.getElementById( 'mvs-playlist-now-' + albumId );
	if ( ! audio || ! nowEl ) {
		return;
	}
	var trackEls = document.querySelectorAll( '#mvs-playlist-' + albumId + ' .mvs-playlist-track' );
	var current = -1;

	function label( t ) {
		return ( t.artist ? t.artist + ' — ' : '' ) + t.title;
	}

	function playTrack( idx ) {
		if ( idx < 0 || idx >= tracks.length || ! tracks[ idx ].url ) {
			return;
		}
		current = idx;
		audio.src = tracks[ idx ].url;
		audio.type = tracks[ idx ].type;
		audio.play();
		nowEl.textContent = label( tracks[ idx ] );
		trackEls.forEach( function ( el, i ) {
			el.classList.toggle( 'mvs-playlist-track--active', i === idx );
		} );
	}

	audio.addEventListener( 'ended', function () {
		if ( current + 1 < tracks.length ) {
			playTrack( current + 1 );
		}
	} );

	document.querySelectorAll( '#mvs-playlist-' + albumId + ' .mvs-playlist-track-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			playTrack( parseInt( btn.getAttribute( 'data-track-index' ), 10 ) );
		} );
	} );

	if ( tracks.length > 0 && tracks[ 0 ].url ) {
		audio.src = tracks[ 0 ].url;
		audio.type = tracks[ 0 ].type;
		nowEl.textContent = label( tracks[ 0 ] );
		if ( trackEls[ 0 ] ) {
			trackEls[ 0 ].classList.add( 'mvs-playlist-track--active' );
		}
	}
} )();
