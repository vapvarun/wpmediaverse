/**
 * BuddyPress tab media uploader (profile + group tabs). Replaces the two inline
 * <script> blocks that BaseBPTabIntegration formerly emitted. Drives both the
 * plain tab uploader (POST to /media, then reload) and the album uploader (POST
 * each file to /media, then POST the new IDs to /albums/{id}/items).
 *
 * Config comes from window.mvsBpUpload (wp_localize_script):
 *   { restUrl, nonce, extraFields: { group_id: 5 }, i18n: {...} }
 * The album id is read from the album wrap's data-album-id attribute.
 *
 * @package WPMediaVerse
 */
( function () {
	'use strict';

	var cfg = window.mvsBpUpload || {};
	var restUrl = cfg.restUrl;
	var nonce = cfg.nonce;
	var extraFields = cfg.extraFields || {};
	var i18n = cfg.i18n || {};
	if ( ! restUrl ) {
		return;
	}

	function byId( id ) {
		return document.getElementById( id );
	}

	function format( tmpl, map ) {
		var out = tmpl || '';
		Object.keys( map ).forEach( function ( token ) {
			out = out.replace( token, map[ token ] );
		} );
		return out;
	}

	/**
	 * Read the upload-form metadata fields, if this surface renders them.
	 *
	 * The album uploader deliberately has none — the album owns that metadata —
	 * so every lookup is null-guarded rather than assumed present.
	 *
	 * @return {Object} { title, description, tags, privacy }
	 */
	function readMetaFields() {
		function val( id ) {
			var el = document.getElementById( id );
			return el ? String( el.value || '' ).trim() : '';
		}
		return {
			title: val( 'mvs-bp-upload-title' ),
			description: val( 'mvs-bp-upload-description' ),
			tags: val( 'mvs-bp-upload-tags' ),
			privacy: val( 'mvs-bp-upload-privacy' )
		};
	}

	/**
	 * Capture a poster frame from a video File and append it as `thumbnail`.
	 *
	 * Without this, a video uploaded from a BuddyPress tab on a host with no
	 * ffmpeg and no embedded cover atom gets no poster at all and renders as a
	 * blank tile. Mirrors the capture in the Upload Media block and the member
	 * dashboard. Never rejects — a decode failure just uploads without a poster.
	 *
	 * @param {FormData} fd   Upload payload, mutated in place.
	 * @param {File}     file File being uploaded.
	 * @param {Function} done Called when the attempt finishes.
	 */
	function appendVideoPoster( fd, file, done ) {
		if ( ! file || ! file.type || file.type.indexOf( 'video/' ) !== 0 ) {
			done();
			return;
		}
		var video = document.createElement( 'video' );
		var url = URL.createObjectURL( file );
		var settled = false;
		function finish( blob ) {
			if ( settled ) { return; }
			settled = true;
			URL.revokeObjectURL( url );
			if ( blob ) { fd.append( 'thumbnail', blob, 'video-thumb.jpg' ); }
			done();
		}
		var timer = setTimeout( function () { finish( null ); }, 5000 );
		video.preload = 'metadata';
		video.muted = true;
		video.playsInline = true;
		video.addEventListener( 'loadeddata', function () {
			video.currentTime = ( video.duration && video.duration < 1 ) ? 0 : 1;
		} );
		video.addEventListener( 'seeked', function () {
			clearTimeout( timer );
			try {
				var canvas = document.createElement( 'canvas' );
				canvas.width = video.videoWidth || 320;
				canvas.height = video.videoHeight || 180;
				canvas.getContext( '2d' ).drawImage( video, 0, 0, canvas.width, canvas.height );
				canvas.toBlob( function ( b ) { finish( b ); }, 'image/jpeg', 0.7 );
			} catch ( e ) {
				finish( null );
			}
		} );
		video.addEventListener( 'error', function () {
			clearTimeout( timer );
			finish( null );
		} );
		video.src = url;
	}

	function appendExtraFields( fd ) {
		Object.keys( extraFields ).forEach( function ( key ) {
			fd.append( key, extraFields[ key ] );
		} );
	}

	// Register a dropzone with the shared mvs-dropzone module, which is the
	// single owner of panel toggling, click-to-pick, drag feedback, drop and
	// input-change wiring for the vanilla uploaders. This file keeps only the
	// preview rendering + the two upload flows below. Do NOT re-add local
	// drag/drop listeners here — the album uploader briefly grew its own set,
	// which is how three parallel dropzone binders came to exist.
	//
	// Elements are resolved inside onFiles (not captured at register time)
	// because the iAPI router replaces region nodes on client-side navigation.
	function bindForm( opts ) {
		if ( ! window.mvsDropzone ) {
			return;
		}
		window.mvsDropzone.register( {
			btn: opts.btn,
			wrap: opts.wrap,
			dropzone: opts.dropzone,
			input: opts.input,
			status: opts.status,
			preview: opts.preview,
			cancel: opts.cancel,
			onFiles: function ( files ) {
				if ( ! files.length ) {
					return;
				}
				var previewEl = byId( opts.preview );
				var statusEl = byId( opts.status );
				if ( ! previewEl || ! statusEl ) {
					return;
				}
				files.forEach( function ( file ) {
					if ( ! file.type.match( /^image\// ) ) {
						return;
					}
					var reader = new FileReader();
					reader.onload = function ( e ) {
						var thumb = document.createElement( 'div' );
						thumb.className = 'mvs-bp-upload-thumb';
						var img = document.createElement( 'img' );
						img.src = e.target.result;
						img.alt = file.name;
						thumb.appendChild( img );
						if ( opts.showName ) {
							var name = document.createElement( 'span' );
							name.className = 'mvs-bp-upload-thumb-name';
							name.textContent = file.name;
							thumb.appendChild( name );
						}
						previewEl.appendChild( thumb );
					};
					reader.readAsDataURL( file );
				} );
				opts.onFiles( files, statusEl );
			},
		} );
	}

	function startStatus( statusEl, total ) {
		statusEl.style.display = 'block';
		statusEl.className = 'mvs-bp-upload-status';
		statusEl.textContent = format( i18n.uploading, { '%1$d': 1, '%2$d': total } );
	}

	// Plain tab upload: POST each file to /media, then reload.
	function uploadPlain( files, statusEl ) {
		var total = files.length;
		var done = 0;
		var failed = 0;
		var meta = readMetaFields();
		// Tie a multi-file selection together so the BuddyPress activity sync
		// emits ONE carousel item instead of one feed row per file — the same
		// key shape the upload modal and the other blocks send.
		var mediaGroup = total > 1
			? 'grp_' + Date.now() + '_' + Math.random().toString( 36 ).slice( 2, 8 )
			: null;
		startStatus( statusEl, total );

		function next() {
			if ( done >= total ) {
				var uploaded = total - failed;
				statusEl.textContent = format( i18n.uploaded, { '%d': uploaded } );
				statusEl.className = 'mvs-bp-upload-status mvs-bp-upload-status--success';
				setTimeout( function () {
					window.location.reload();
				}, 800 );
				return;
			}
			var fd = new FormData();
			fd.append( 'file', files[ done ] );
			appendExtraFields( fd );
			// Title only when a single file is uploaded — N files must not
			// share one name. The caption travels with every file so a grouped
			// upload keeps it.
			if ( meta.title && total === 1 ) { fd.append( 'title', meta.title ); }
			if ( meta.description ) { fd.append( 'description', meta.description ); }
			if ( meta.tags ) {
				meta.tags.split( ',' ).map( function ( t ) { return t.trim(); } )
					.filter( Boolean )
					.forEach( function ( tag ) { fd.append( 'tags[]', tag ); } );
			}
			if ( meta.privacy ) { fd.append( 'privacy', meta.privacy ); }
			if ( mediaGroup ) {
				fd.append( 'media_group', mediaGroup );
				fd.append( 'group_position', String( done ) );
			}

			appendVideoPoster( fd, files[ done ], function () {
				window.mvsRest.restFetch( restUrl + 'media', {
					method: 'POST',
					body: fd
				} ).then( function ( r ) {
					if ( ! r.ok ) {
						failed++;
					}
					done++;
					if ( done < total ) {
						statusEl.textContent = format( i18n.uploading, { '%1$d': done + 1, '%2$d': total } );
					}
					next();
				} ).catch( function () {
					failed++;
					done++;
					next();
				} );
			} );
		}
		next();
	}

	// Album upload: POST each file to /media, collect IDs, then add to album.
	function uploadToAlbum( files, statusEl, albumId ) {
		var total = files.length;
		var done = 0;
		var uploadedIds = [];
		// Mirror the shared-ui modal flag (src/blocks/shared-ui/view.js:878):
		// for ≥2-file album batches, tag each per-file POST so the server
		// suppresses per-media BP activities and emits ONE "uploaded N photos
		// to album X" gallery activity instead. Single-file uploads still
		// produce a normal per-photo activity (no bundling needed).
		var uploadUrl = restUrl + 'media' + ( total > 1 ? '?album_upload=1' : '' );
		startStatus( statusEl, total );

		function next() {
			if ( done >= total ) {
				if ( uploadedIds.length ) {
					statusEl.textContent = i18n.addingToAlbum || '';
					window.mvsRest.restFetch( restUrl + 'albums/' + albumId + '/items', {
						method: 'POST',
						body: { media_ids: uploadedIds },
					} ).then( function () {
						statusEl.textContent = format( i18n.addedToAlbum, { '%d': uploadedIds.length } );
						statusEl.className = 'mvs-bp-upload-status mvs-bp-upload-status--success';
						setTimeout( function () {
							window.location.reload();
						}, 800 );
					} );
				}
				return;
			}
			var fd = new FormData();
			fd.append( 'file', files[ done ] );
			appendExtraFields( fd );
			// Album metadata belongs to the album, so no title/caption here —
			// but a video still needs a cover frame or it lands posterless on
			// an ffmpeg-less host.
			appendVideoPoster( fd, files[ done ], function () {
				window.mvsRest.restFetch( uploadUrl, {
					method: 'POST',
					body: fd
				} ).then( function ( r ) {
					return r.data;
				} ).then( function ( data ) {
					if ( data && data.id ) {
						uploadedIds.push( data.id );
					}
					done++;
					if ( done < total ) {
						statusEl.textContent = format( i18n.uploading, { '%1$d': done + 1, '%2$d': total } );
					}
					next();
				} ).catch( function () {
					done++;
					next();
				} );
			} );
		}
		next();
	}

	// Plain tab uploader.
	bindForm( {
		btn: 'mvs-bp-upload-btn',
		wrap: 'mvs-bp-upload-wrap',
		dropzone: 'mvs-bp-dropzone',
		input: 'mvs-bp-file-input',
		status: 'mvs-bp-upload-status',
		preview: 'mvs-bp-upload-preview',
		cancel: 'mvs-bp-upload-cancel',
		showName: true,
		onFiles: uploadPlain
	} );

	// Album uploader (single album view).
	var albumWrap = byId( 'mvs-album-upload-wrap' );
	if ( albumWrap ) {
		var albumId = parseInt( albumWrap.getAttribute( 'data-album-id' ), 10 );
		bindForm( {
			btn: 'mvs-album-upload-btn',
			wrap: 'mvs-album-upload-wrap',
			dropzone: 'mvs-album-dropzone',
			input: 'mvs-album-file-input',
			status: 'mvs-album-upload-status',
			preview: 'mvs-album-upload-preview',
			cancel: 'mvs-album-upload-cancel',
			showName: false,
			onFiles: function ( files, statusEl ) {
				uploadToAlbum( files, statusEl, albumId );
			}
		} );
	}
} )();
