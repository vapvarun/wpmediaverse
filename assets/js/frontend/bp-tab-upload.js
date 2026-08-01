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
