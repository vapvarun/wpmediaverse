/**
 * Album cover setter — owner-only "Set as cover" buttons.
 * Extracted from the inline <script> in templates/album.php. Static config +
 * labels arrive via the localized `window.mvsAlbumCover` object (localized at
 * registration in Core\Plugin, not from the template). The album id is
 * page-specific and is read from the enclosing [data-album-id] element, which
 * lives inside the router region. Errors surface on the button label (no
 * native alert).
 *
 * NAV-SAFETY: the click is delegated on `document` and the button, label and
 * album id are all resolved at dispatch time. The previous
 * querySelectorAll().forEach() bound at module-eval time, so every button was
 * inert after the iAPI router swapped the region on a client-side navigation.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var cfg = window.mvsAlbumCover || {};
	var i18n = cfg.i18n || {};

	document.addEventListener( 'click', function ( ev ) {
		if ( ! ev.target.closest ) {
			return;
		}
		var btn = ev.target.closest( '.mvs-album-set-cover' );
		if ( ! btn ) {
			return;
		}
		var labelEl = btn.querySelector( '.mvs-album-set-cover__label' );
		var scope = btn.closest( '[data-album-id]' );
		var albumId = scope ? parseInt( scope.getAttribute( 'data-album-id' ), 10 ) || 0 : 0;
		{
			ev.preventDefault();
			ev.stopPropagation();
			var mediaId = btn.getAttribute( 'data-media-id' );
			if ( ! mediaId || ! albumId ) {
				return;
			}
			btn.disabled = true;
			if ( labelEl ) {
				labelEl.textContent = i18n.saving || '';
			}
			window.mvsRest.restFetch( cfg.restUrl + 'albums/' + albumId + '/cover', {
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
		}
	} );
} )();
