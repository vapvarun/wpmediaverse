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

	// Capture the first usable video frame so the server-side poster pipeline
	// always has a real image to feed `UploadService::generate_thumbnails()`.
	// Without this, videos uploaded on hosts without ffmpeg + without an
	// embedded cover atom render the default-SVG poster in Safari/Bing
	// (card 9910574354).
	function generateVideoThumb( file ) {
		return new Promise( function ( resolve ) {
			var video = document.createElement( 'video' );
			var url = URL.createObjectURL( file );
			video.preload = 'metadata';
			video.muted = true;
			video.playsInline = true;
			video.src = url;
			video.addEventListener( 'loadeddata', function () {
				video.currentTime = Math.min( 1, video.duration * 0.1 || 0 );
			} );
			video.addEventListener( 'seeked', function () {
				try {
					var canvas = document.createElement( 'canvas' );
					canvas.width = video.videoWidth || 320;
					canvas.height = video.videoHeight || 180;
					canvas.getContext( '2d' ).drawImage( video, 0, 0, canvas.width, canvas.height );
					URL.revokeObjectURL( url );
					resolve( canvas.toDataURL( 'image/jpeg', 0.85 ) );
				} catch ( e ) {
					URL.revokeObjectURL( url );
					resolve( '' );
				}
			} );
			video.addEventListener( 'error', function () {
				URL.revokeObjectURL( url );
				resolve( '' );
			} );
		} );
	}

	function dataURLtoBlob( dataURL ) {
		if ( ! dataURL || dataURL.indexOf( 'data:' ) !== 0 ) {
			return null;
		}
		try {
			var parts = dataURL.split( ',' );
			var match = parts[ 0 ].match( /:(.*?);/ );
			var mime = match ? match[ 1 ] : 'image/jpeg';
			var bin = atob( parts[ 1 ] );
			var bytes = new Uint8Array( bin.length );
			for ( var i = 0; i < bin.length; i++ ) {
				bytes[ i ] = bin.charCodeAt( i );
			}
			return new Blob( [ bytes ], { type: mime } );
		} catch ( e ) {
			return null;
		}
	}

	function uploadAndAddToAlbum( files ) {
		var statusEl = el( 'mvs-album-upload-status' );
		var album = albumId();
		if ( ! statusEl || ! album ) {
			return;
		}
		statusEl.style.display = 'block';
		var total = files.length, done = 0;
		var uploadedIds = [];
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
				if ( uploadedIds.length ) {
					statusEl.textContent = i18n.addingToAlbum || '';
					window.mvsRest.restFetch( cfg.restUrl + 'albums/' + album + '/items', {
						method: 'POST',
						body: { media_ids: uploadedIds },
					} ).then( function () {
						statusEl.textContent = ( i18n.addedToAlbum || '' ).replace( '%d', uploadedIds.length );
						statusEl.className = 'mvs-bp-upload-status mvs-bp-upload-status--success';
						setTimeout( function () {
							window.location.reload();
						}, 800 );
					} );
				}
				return;
			}
			var file = files[ done ];
			var thumbPromise = file.type.indexOf( 'video/' ) === 0
				? generateVideoThumb( file )
				: Promise.resolve( '' );

			thumbPromise.then( function ( localThumb ) {
				var fd = new FormData();
				fd.append( 'file', file );
				if ( localThumb ) {
					var blob = dataURLtoBlob( localThumb );
					if ( blob ) {
						fd.append( 'thumbnail', blob, 'video-thumb.jpg' );
					}
				}
				return window.mvsRest.restFetch( uploadUrl, {
					method: 'POST',
					body: fd,
				} );
			} ).then( function ( r ) {
				var data = r.data;
				if ( data && data.id ) {
					uploadedIds.push( data.id );
				}
				done++;
				if ( done < total ) {
					statusEl.textContent = uploadingLabel( done + 1, total );
				}
				next();
			} ).catch( function () {
				done++;
				next();
			} );
		}
		next();
	}
} )();
