/**
 * Album upload — dropzone + sequential media upload, then attach to the album.
 *
 * Extracted from the inline <script> in templates/album.php (owner-only block).
 * Config + translatable status strings arrive via the localized
 * `window.mvsAlbumUpload` object, so no data or strings live in JS.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var cfg = window.mvsAlbumUpload || {};
	var i18n = cfg.i18n || {};

	var uploadBtn = document.getElementById( 'mvs-album-upload-btn' );
	var uploadWrap = document.getElementById( 'mvs-album-upload-wrap' );
	var dropzone = document.getElementById( 'mvs-album-dropzone' );
	var fileInput = document.getElementById( 'mvs-album-file-input' );
	var statusEl = document.getElementById( 'mvs-album-upload-status' );
	var previewEl = document.getElementById( 'mvs-album-upload-preview' );
	var cancelBtn = document.getElementById( 'mvs-album-upload-cancel' );

	if ( ! uploadBtn || ! uploadWrap || ! dropzone || ! fileInput || ! statusEl || ! previewEl || ! cancelBtn ) {
		return;
	}

	function uploadingLabel( current, total ) {
		return ( i18n.uploadingN || '' ).replace( '%1$d', current ).replace( '%2$d', total );
	}

	uploadBtn.addEventListener( 'click', function () {
		uploadWrap.style.display = 'block';
		uploadBtn.style.display = 'none';
	} );
	cancelBtn.addEventListener( 'click', function () {
		uploadWrap.style.display = 'none';
		uploadBtn.style.display = '';
		previewEl.textContent = '';
		statusEl.style.display = 'none';
	} );

	var clicking = false;
	dropzone.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		if ( clicking ) {
			return;
		}
		clicking = true;
		fileInput.click();
		setTimeout( function () {
			clicking = false;
		}, 100 );
	} );
	dropzone.addEventListener( 'dragover', function ( e ) {
		e.preventDefault();
		dropzone.classList.add( 'mvs-bp-dropzone--active' );
	} );
	dropzone.addEventListener( 'dragleave', function () {
		dropzone.classList.remove( 'mvs-bp-dropzone--active' );
	} );
	dropzone.addEventListener( 'drop', function ( e ) {
		e.preventDefault();
		dropzone.classList.remove( 'mvs-bp-dropzone--active' );
		handleFiles( Array.from( e.dataTransfer.files ) );
	} );
	fileInput.addEventListener( 'change', function () {
		handleFiles( Array.from( fileInput.files ) );
		fileInput.value = '';
	} );

	function handleFiles( files ) {
		if ( ! files.length ) {
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
					fetch( cfg.restUrl + 'albums/' + cfg.albumId + '/items', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
						credentials: 'same-origin',
						body: JSON.stringify( { media_ids: uploadedIds } ),
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
				return fetch( uploadUrl, {
					method: 'POST',
					headers: { 'X-WP-Nonce': cfg.nonce },
					credentials: 'same-origin',
					body: fd,
				} );
			} ).then( function ( r ) {
				return r.json();
			} ).then( function ( data ) {
				if ( data.id ) {
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
