/**
 * Album upload — dropzone + sequential media upload, then attach to the album.
 *
 * Extracted from the inline <script> in templates/album.php (owner-only block).
 * Static config + translatable status strings arrive via the localized
 * `window.mvsAlbumUpload` object (localized at registration in Core\Plugin, NOT
 * from the template — see the note there). The album id is page-specific and
 * travels on `#mvs-album-upload-wrap[data-album-id]`, inside the router region.
 *
 * Dropzone behaviour is delegated to the shared `mvs-dropzone` module, which is
 * the single owner of drag/drop/click wiring for the vanilla uploaders. This
 * file owns only the album-specific upload flow. Do not re-add local drag/drop
 * listeners here — that is what created a third parallel dropzone binder.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var cfg = window.mvsAlbumUpload || {};
	var i18n = cfg.i18n || {};

	function el( id ) {
		return document.getElementById( id );
	}

	function albumId() {
		var wrap = el( 'mvs-album-upload-wrap' );
		return wrap ? parseInt( wrap.getAttribute( 'data-album-id' ), 10 ) || 0 : 0;
	}

	function uploadingLabel( current, total ) {
		return ( i18n.uploadingN || '' ).replace( '%1$d', current ).replace( '%2$d', total );
	}

	// All dropzone wiring (panel toggle, click-to-pick, drag feedback, drop,
	// input change) lives in the shared mvs-dropzone module. This file owns only
	// the album-specific upload flow below.
	if ( ! window.mvsDropzone ) {
		return;
	}
	window.mvsDropzone.register( {
		btn: 'mvs-album-upload-btn',
		wrap: 'mvs-album-upload-wrap',
		dropzone: 'mvs-album-dropzone',
		input: 'mvs-album-file-input',
		status: 'mvs-album-upload-status',
		preview: 'mvs-album-upload-preview',
		cancel: 'mvs-album-upload-cancel',
		onFiles: handleFiles,
	} );

	function handleFiles( files ) {
		if ( ! files.length ) {
			return;
		}
		var previewEl = el( 'mvs-album-upload-preview' );
		if ( ! previewEl ) {
			return;
		}
		files.forEach( function ( file ) {
			if ( ! file.type.match( /^(image|video|audio)\// ) ) {
				return;
			}
			var thumb = document.createElement( 'div' );
			thumb.className = 'mvs-bp-upload-thumb';
			if ( file.type.match( /^image\// ) ) {
				var reader = new FileReader();
				reader.onload = function ( e ) {
					var img = document.createElement( 'img' );
					img.src = e.target.result;
					thumb.appendChild( img );
				};
				reader.readAsDataURL( file );
			} else {
				var label = document.createElement( 'span' );
				label.className = 'mvs-bp-upload-thumb-label';
				label.textContent = file.type.match( /^video\// ) ? '▶ ' + file.name : '♫ ' + file.name;
				thumb.appendChild( label );
			}
			previewEl.appendChild( thumb );
		} );
		uploadAndAddToAlbum( files );
	}

	// Context fields a surface adds to every upload (a BuddyPress group id),
	// from the wrap's data-upload-extra JSON.
	function uploadExtra() {
		var wrap = el( 'mvs-album-upload-wrap' );
		try {
			return JSON.parse( ( wrap && wrap.getAttribute( 'data-upload-extra' ) ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function uploadAndAddToAlbum( files ) {
		var statusEl = el( 'mvs-album-upload-status' );
		var album = albumId();
		if ( ! statusEl || ! album ) {
			return;
		}
		statusEl.style.display = 'block';
		var total = files.length, done = 0, failed = 0;
		var uploadedIds = [];
		var lastError = '';
		var dz = window.mvsDropzone;
		// Mirror the shared-ui modal flag (src/blocks/shared-ui/view.js:878):
		// for ≥2-file album batches, tag each per-file POST so the server
		// suppresses per-media BP activities and emits ONE "uploaded N photos
		// to album X" gallery activity instead. Single-file uploads still
		// produce a normal per-photo activity (no bundling needed).
		var uploadUrl = cfg.restUrl + 'media' + ( total > 1 ? '?album_upload=1' : '' );
		statusEl.textContent = uploadingLabel( 1, total );
		statusEl.className = 'mvs-bp-upload-status';

		function next() {
			if ( done >= total ) {
				if ( ! uploadedIds.length ) {
					// Nothing got in: say why and stay, never a silent "Uploading...".
					dz.showFailure( statusEl, lastError || i18n.uploadFailed || '' );
					return;
				}
				statusEl.textContent = i18n.addingToAlbum || '';
				window.mvsRest.restFetch( cfg.restUrl + 'albums/' + album + '/items', {
					method: 'POST',
					body: { media_ids: uploadedIds },
				} ).then( function () {
					if ( failed ) {
						// Some got in: keep the reason on screen long enough to read.
						dz.showFailure( statusEl, ( i18n.someFailed || '' ).replace( '%1$d', uploadedIds.length ).replace( '%2$d', failed ) + ' ' + lastError );
					} else {
						statusEl.textContent = ( i18n.addedToAlbum || '' ).replace( '%d', uploadedIds.length );
						statusEl.className = 'mvs-bp-upload-status mvs-bp-upload-status--success';
					}
					setTimeout( function () {
						window.location.reload();
					}, failed ? 2500 : 800 );
				} );
				return;
			}
			window.mvsRest.uploadMedia(
				uploadUrl,
				files[ done ],
				{ extra: uploadExtra() },
				// Percent of this file sent: a large video takes a while.
				function ( loaded, bytes ) {
					statusEl.textContent = uploadingLabel( done + 1, total ) + ' ' + ( bytes ? Math.min( 100, Math.round( ( loaded / bytes ) * 100 ) ) : 0 ) + '%';
				}
			).then( function ( r ) {
				var data = r.data;
				if ( r.ok && data && data.id ) {
					uploadedIds.push( data.id );
				} else {
					failed++;
					lastError = dz.failureMessage( r, i18n.uploadFailed );
				}
				done++;
				if ( done < total ) {
					statusEl.textContent = uploadingLabel( done + 1, total );
				}
				next();
			} ).catch( function () {
				failed++;
				lastError = i18n.uploadFailed || '';
				done++;
				next();
			} );
		}
		next();
	}
} )();
