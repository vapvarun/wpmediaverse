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

	/**
	 * Upload-progress callback that adds the percent of the current file sent to
	 * the "Uploading N of M" line, so a large video visibly moves.
	 *
	 * @param {HTMLElement} statusEl Status line.
	 * @param {number}      current  1-based file number.
	 * @param {number}      total    Files in this batch.
	 * @return {Function} ( loaded, bytes ) callback for mvsRest.restFetch.
	 */
	function progressLine( statusEl, current, total ) {
		return function ( loaded, bytes ) {
			var pct = bytes ? Math.min( 100, Math.round( ( loaded / bytes ) * 100 ) ) : 0;
			statusEl.textContent = format( i18n.uploading, { '%1$d': current, '%2$d': total } ) + ' ' + pct + '%';
		};
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
		// The story checkbox is emitted by TemplateHelpers::story_toggle() only
		// when Pro is active and stories are on, so it is often absent.
		var storyBox = document.querySelector( '.mvs-bp-upload-fields .mvs-upload-story-input' );

		return {
			title: val( 'mvs-bp-upload-title' ),
			description: val( 'mvs-bp-upload-description' ),
			tags: val( 'mvs-bp-upload-tags' ),
			privacy: val( 'mvs-bp-upload-privacy' ),
			story: !! ( storyBox && storyBox.checked )
		};
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
		var lastError = '';
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
				if ( ! uploaded ) {
					// Nothing got in: say why and stay (it used to say "0 file(s) uploaded!" in green and reload).
					window.mvsDropzone.showFailure( statusEl, lastError || i18n.uploadFailed || '' );
					return;
				}
				if ( failed ) {
					window.mvsDropzone.showFailure( statusEl, format( i18n.someFailed, { '%1$d': uploaded, '%2$d': failed } ) + ' ' + lastError );
				} else {
					statusEl.textContent = format( i18n.uploaded, { '%d': uploaded } );
					statusEl.className = 'mvs-bp-upload-status mvs-bp-upload-status--success';
				}
				setTimeout( function () {
					window.location.reload();
				}, failed ? 2500 : 800 );
				return;
			}
			// Title only when a single file is uploaded: N files must not share
			// one name. The caption travels with every file so a grouped upload
			// keeps it.
			window.mvsRest.uploadMedia(
				restUrl + 'media',
				files[ done ],
				{
					title: total === 1 ? meta.title : '',
					description: meta.description,
					tags: meta.tags,
					privacy: meta.privacy,
					mediaGroup: mediaGroup,
					groupPosition: done,
					extra: extraFields
				},
				progressLine( statusEl, done + 1, total )
			).then( function ( r ) {
				if ( ! r.ok ) {
					failed++;
					lastError = window.mvsDropzone.failureMessage( r, i18n.uploadFailed );
				} else if ( meta.story && r.data && r.data.id ) {
					// "Also share as a story" (Pro). Non-fatal: the media is
					// uploaded either way.
					window.mvsRest.markAsStory( r.data.id );
				}
				done++;
				if ( done < total ) {
					statusEl.textContent = format( i18n.uploading, { '%1$d': done + 1, '%2$d': total } );
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
	// Album pages: assets/js/frontend/album-upload.js owns uploading into an album.
} )();
