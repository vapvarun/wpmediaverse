/**
 * Album cover setter — owner-only "Set as cover" buttons.
 * Extracted from the inline <script> in templates/album.php. Config + labels
 * arrive via the localized `window.mvsAlbumCover` object. Errors surface on the
 * button label (no native alert).
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var cfg = window.mvsAlbumCover || {};
	var i18n = cfg.i18n || {};

	document.querySelectorAll( '.mvs-album-set-cover' ).forEach( function ( btn ) {
		var labelEl = btn.querySelector( '.mvs-album-set-cover__label' );
		btn.addEventListener( 'click', function ( ev ) {
			ev.preventDefault();
			ev.stopPropagation();
			var mediaId = btn.getAttribute( 'data-media-id' );
			if ( ! mediaId ) {
				return;
			}
			btn.disabled = true;
			if ( labelEl ) {
				labelEl.textContent = i18n.saving || '';
			}
			window.mvsRest.restFetch( cfg.restUrl + 'albums/' + cfg.albumId + '/cover', {
				method: 'PUT',
				body: { media_id: parseInt( mediaId, 10 ) },
			} ).then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( ( r.data && r.data.message ) || ( i18n.error || '' ) );
				}
				return r.data;
			} ).then( function () {
				window.location.reload();
			} ).catch( function ( err ) {
				btn.disabled = false;
				// Non-blocking error feedback on the button label; restores after 3s.
				var msg = ( err && err.message ) || i18n.error || '';
				if ( labelEl ) {
					labelEl.textContent = msg;
					setTimeout( function () {
						labelEl.textContent = i18n.setAsCover || '';
					}, 3000 );
				} else {
					btn.setAttribute( 'title', msg );
				}
			} );
		} );
	} );
} )();
