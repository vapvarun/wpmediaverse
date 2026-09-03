/**
 * WPMediaVerse — BuddyPress Activity Media Attachment
 *
 * Works with BP Nouveau (Backbone-rendered form) and Legacy.
 * Elements are rendered by Backbone AFTER textarea focus,
 * so we use event delegation on the document instead of direct binding.
 *
 * @package WPMediaVerse
 */
( function() {
	'use strict';

	// i18n bridge — guard in case wp.i18n is unavailable (graceful English fallback).
	var i18n = ( window.wp && window.wp.i18n ) ? window.wp.i18n : null;
	var __ = i18n ? i18n.__ : function( s ) { return s; };
	var sprintf = i18n ? i18n.sprintf : function( fmt ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( fmt ).replace( /%[sd]/g, function() { return args[ i++ ]; } );
	};

	var restUrl = ( typeof mvsActivityMedia !== 'undefined' ) ? mvsActivityMedia.restUrl : '';
	var bpRestUrl = ( typeof mvsActivityMedia !== 'undefined' ) ? mvsActivityMedia.bpRestUrl : '';
	var nonce = ( typeof mvsActivityMedia !== 'undefined' ) ? mvsActivityMedia.nonce : '';

	if ( ! restUrl || ! nonce ) {
		return;
	}

	var maxMedia = ( typeof mvsActivityMedia !== 'undefined' && mvsActivityMedia.maxMedia ) ? mvsActivityMedia.maxMedia : 6;
	var attachedMedia = []; // Array of { id, thumbUrl }
	var isSubmitting = false; // Flag to prevent orphan cleanup during form submit

	function syncHiddenInput() {
		var hidden = document.getElementById( 'mvs-activity-media-ids' );
		if ( hidden ) {
			hidden.value = attachedMedia.map( function( m ) { return m.id; } ).join( ',' );
		}
	}

	function ensurePreviewPosition( preview ) {
		// Move preview out of the toolbar row so it doesn't push "Post in: Profile" buttons.
		// Place it right before the options/toolbar container for a clean layout.
		if ( preview.dataset.repositioned ) { return; }
		var form = preview.closest( 'form' ) || preview.closest( '#whats-new-form' );
		if ( ! form ) { return; }
		var toolbar = preview.closest( '#whats-new-options' ) ||
						preview.closest( '.activity-form-options' ) ||
						preview.closest( '.whats-new-options' ) ||
						preview.parentElement.parentElement; // fallback: grandparent of btn-wrap
		if ( toolbar && toolbar !== form ) {
			toolbar.parentNode.insertBefore( preview, toolbar );
			preview.dataset.repositioned = '1';
		}
	}

	function renderPreview() {
		var preview = document.getElementById( 'mvs-activity-media-preview' );
		if ( ! preview ) { return; }

		preview.textContent = '';
		if ( ! attachedMedia.length ) {
			preview.style.display = 'none';
			syncHiddenInput();
			return;
		}

		ensurePreviewPosition( preview );
		preview.style.display = '';
		preview.className = 'mvs-activity-media-preview mvs-preview-grid-' + Math.min( attachedMedia.length, 6 );

		attachedMedia.forEach( function( item, idx ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'mvs-preview-item';

			var thumb;
			// Lucide-style inline SVGs (no Unicode play/music chars so WP can't
			// replace them with emoji images + they match the plugin's design).
			var SVG_NS = 'http://www.w3.org/2000/svg';
			function buildLucideIcon( paths, rootAttrs ) {
				var s = document.createElementNS( SVG_NS, 'svg' );
				s.setAttribute( 'width', '32' );
				s.setAttribute( 'height', '32' );
				s.setAttribute( 'viewBox', '0 0 24 24' );
				s.setAttribute( 'aria-hidden', 'true' );
				Object.keys( rootAttrs || {} ).forEach( function( k ) { s.setAttribute( k, rootAttrs[ k ] ); } );
				paths.forEach( function( p ) {
					var el = document.createElementNS( SVG_NS, p.tag );
					Object.keys( p.attrs ).forEach( function( k ) { el.setAttribute( k, p.attrs[ k ] ); } );
					s.appendChild( el );
				} );
				return s;
			}

			if ( 'audio' === item.mediaType ) {
				thumb = document.createElement( 'div' );
				thumb.className = 'mvs-activity-media-thumb mvs-preview-audio';
				thumb.appendChild( buildLucideIcon(
					[
						{ tag: 'path', attrs: { d: 'M9 18V5l12-2v13' } },
						{ tag: 'circle', attrs: { cx: '6', cy: '18', r: '3' } },
						{ tag: 'circle', attrs: { cx: '18', cy: '16', r: '3' } },
					],
					{ fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }
				) );
			} else if ( 'video' === item.mediaType && item.thumbUrl ) {
				thumb = document.createElement( 'img' );
				thumb.src = item.thumbUrl;
				thumb.alt = __( 'Media preview', 'wpmediaverse' );
				thumb.className = 'mvs-activity-media-thumb';
			} else if ( 'video' === item.mediaType ) {
				thumb = document.createElement( 'div' );
				thumb.className = 'mvs-activity-media-thumb mvs-preview-video';
				thumb.appendChild( buildLucideIcon(
					[ { tag: 'path', attrs: { d: 'M6 4l15 8-15 8V4z' } } ],
					{ fill: 'currentColor', stroke: 'none' }
				) );
			} else {
				thumb = document.createElement( 'img' );
				thumb.src = item.thumbUrl;
				thumb.alt = __( 'Media preview', 'wpmediaverse' );
				thumb.className = 'mvs-activity-media-thumb';
			}

			var removeBtn = document.createElement( 'button' );
			removeBtn.type = 'button';
			removeBtn.className = 'mvs-activity-media-remove';
			removeBtn.textContent = '\u00D7';
			removeBtn.setAttribute( 'aria-label', __( 'Remove media', 'wpmediaverse' ) );
			removeBtn.addEventListener( 'click', function() {
				attachedMedia.splice( idx, 1 );
				renderPreview();
			} );

			wrap.appendChild( thumb );
			wrap.appendChild( removeBtn );
			preview.appendChild( wrap );
		} );

		syncHiddenInput();
	}

	function generateVideoThumb( file ) {
		return new Promise( function( resolve ) {
			var video = document.createElement( 'video' );
			var url   = URL.createObjectURL( file );
			video.preload = 'metadata';
			video.muted   = true;
			video.playsInline = true;
			video.src     = url;
			video.addEventListener( 'loadeddata', function() {
				video.currentTime = Math.min( 1, video.duration * 0.1 || 0 );
			} );
			video.addEventListener( 'seeked', function() {
				try {
					var canvas = document.createElement( 'canvas' );
					var w = video.videoWidth  || 320;
					var h = video.videoHeight || 180;
					// Capture at native resolution so the server can resize to
					// large/medium/thumb. The 200px-wide downscale used pre-1.4.1
					// produced a blurry "thumb_large" that Safari/Bing then used
					// as the <video poster> and the result looked broken on the
					// activity stream (card 9910574354).
					canvas.width  = w;
					canvas.height = h;
					canvas.getContext( '2d' ).drawImage( video, 0, 0, canvas.width, canvas.height );
					URL.revokeObjectURL( url );
					resolve( canvas.toDataURL( 'image/jpeg', 0.85 ) );
				} catch ( e ) {
					URL.revokeObjectURL( url );
					resolve( '' );
				}
			} );
			video.addEventListener( 'error', function() {
				URL.revokeObjectURL( url );
				resolve( '' );
			} );
		} );
	}

	// Convert a data:image/jpeg;base64,... URL to a Blob so it can ride the
	// multipart upload as the `thumbnail` field. `MediaController::create_item()`
	// stages this blob into `wpmediaverse/posters/{media_id}.jpg` and feeds it
	// through `UploadService::generate_thumbnails()` — same pipeline as the
	// server-side ffmpeg / cover-atom paths. Safari/Bing now always receive a
	// real `<video poster>` instead of the default-SVG fallback.
	function dataURLtoBlob( dataURL ) {
		if ( ! dataURL || dataURL.indexOf( 'data:' ) !== 0 ) {
			return null;
		}
		try {
			var parts = dataURL.split( ',' );
			var match = parts[ 0 ].match( /:(.*?);/ );
			var mime  = match ? match[ 1 ] : 'image/jpeg';
			var bin   = atob( parts[ 1 ] );
			var bytes = new Uint8Array( bin.length );
			for ( var i = 0; i < bin.length; i++ ) {
				bytes[ i ] = bin.charCodeAt( i );
			}
			return new Blob( [ bytes ], { type: mime } );
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Upload one file and record it in `slots[ index ]`.
	 *
	 * Writes by index rather than pushing because uploads now run three at a
	 * time: push order would be completion order, which would silently reorder
	 * the member's photos in the activity grid. The caller compacts the array
	 * once everything has settled.
	 *
	 * @param {File}   file  File to upload.
	 * @param {Object} btn   Attach-media button (for the disabled state).
	 * @param {Array}  slots Result array, one entry per selected file.
	 * @param {number} index This file's position in the member's selection.
	 * @return {Promise}
	 */
	function uploadFile( file, btn, slots, index ) {
		// The caller already trimmed the selection to the remaining allowance,
		// so this no longer needs to re-check attachedMedia.length — and must
		// not, because with concurrent uploads that count is still 0 for every
		// in-flight file and the guard would either pass for all or none.

		var isVideo = file.type.indexOf( 'video/' ) === 0;
		var isAudio = file.type.indexOf( 'audio/' ) === 0;

		// Serialize thumb capture BEFORE the upload starts so the captured
		// frame can ride the same multipart POST as `thumbnail`. Pre-1.4.1
		// ran these in parallel and the captured frame was discarded —
		// videos with no embedded cover and no ffmpeg on the server ended
		// up with the default-poster SVG instead of a real first frame.
		var thumbPromise = isVideo ? generateVideoThumb( file ) : Promise.resolve( '' );

		return thumbPromise.then( function( localThumb ) {
			var fd = new FormData();
			fd.append( 'file', file );
			fd.append( 'status', 'draft' );

			// Include the user's chosen privacy level when the admin setting
			// exposes the selector. Backend still enforces `mvs_allow_user_privacy`
			// so a forged privacy param is overridden server-side.
			var privacySel = document.getElementById( 'mvs-activity-privacy' );
			if ( privacySel && privacySel.value ) {
				fd.append( 'privacy', privacySel.value );
			}

			if ( isVideo && localThumb ) {
				var blob = dataURLtoBlob( localThumb );
				if ( blob ) {
					fd.append( 'thumbnail', blob, 'video-thumb.jpg' );
				}
			}

			return window.mvsRest.restFetch( restUrl + 'media?context=activity', {
				method: 'POST',
				body: fd
			} ).then( function( r ) {
				var data = r.data || {};
				// restFetch does NOT throw on HTTP 4xx/5xx, so an unsupported
				// type (PDF/document) comes back as a 400 with a WP_Error body
				// that has no `id`. Pre-1.6.0 the handler only checked data.id,
				// so the upload failed SILENTLY with no notice. Throw the
				// server's message so the catch below shows it to the user
				// (audit 2026-06-04, #9962548621).
				if ( ! r.ok || ! data || ! data.id ) {
					throw new Error(
						( data && data.message )
							? data.message
							: __( 'Upload failed. Please try again.', 'wpmediaverse' )
					);
				}
				slots[ index ] = {
					id:        data.id,
					thumbUrl:  localThumb || data.thumbnail_url || '',
					mediaType: data.media_type || ( isVideo ? 'video' : isAudio ? 'audio' : 'image' )
				};
			} );
		} );
	}

	// Cleanup abandoned uploads when user navigates away without posting.
	function cleanupOrphans() {
		if ( isSubmitting || ! attachedMedia.length ) { return; }
		attachedMedia.forEach( function( item ) {
			// Use sendBeacon for reliable delivery on page unload.
			if ( navigator.sendBeacon ) {
				var fd = new FormData();
				fd.append( '_wpnonce', nonce );
				navigator.sendBeacon( restUrl + 'media/' + item.id + '?_method=DELETE', fd );
			}
		} );
		attachedMedia = [];
	}

	window.addEventListener( 'beforeunload', cleanupOrphans );

	// Detect activity form submission (both AJAX and non-AJAX) to prevent orphan cleanup.
	document.addEventListener( 'submit', function( e ) {
		var form = e.target;
		if ( form.id === 'whats-new-form' || form.classList.contains( 'activity-form' ) || form.querySelector( '[name="whats-new"]' ) ) {
			if ( attachedMedia.length ) {
				isSubmitting = true;
			}
		}
	} );

	// jQuery required: BP Nouveau fires jQuery events with no vanilla JS equivalent.
	if ( typeof jQuery !== 'undefined' ) {
		jQuery( document ).on( 'click', '#aw-whats-new-submit', function() {
			if ( attachedMedia.length ) {
				isSubmitting = true;
			}
		} );
	}

	// Use event delegation since Nouveau renders the form dynamically.
	document.addEventListener( 'click', function( e ) {
		var btn = e.target.closest( '#mvs-activity-media-btn' );
		if ( ! btn ) { return; }
		e.preventDefault();
		e.stopPropagation();

		if ( attachedMedia.length >= maxMedia ) { return; }

		var fileInput = document.getElementById( 'mvs-activity-media-file' );
		if ( fileInput ) { fileInput.click(); }
	} );

	document.addEventListener( 'change', function( e ) {
		if ( e.target.id !== 'mvs-activity-media-file' ) { return; }

		var fileInput = e.target;
		var files = Array.prototype.slice.call( fileInput.files );
		if ( ! files.length ) { return; }

		var btn = document.getElementById( 'mvs-activity-media-btn' );
		var preview = document.getElementById( 'mvs-activity-media-preview' );
		if ( ! btn || ! preview ) { return; }

		// Limit total files.
		var remaining = maxMedia - attachedMedia.length;
		files = files.slice( 0, remaining );
		if ( ! files.length ) { return; }

		btn.disabled = true;
		btn.style.opacity = '0.5';

		// Reveal the privacy selector (if admin enabled it) now that the user
		// has chosen files — it stays hidden until there is something to apply
		// the privacy level to.
		var privacySel = document.getElementById( 'mvs-activity-privacy' );
		if ( privacySel ) {
			privacySel.style.display = '';
		}
		// Reveal the tags field too — applied to the attached media at post
		// time (audit 2026-06-04, #9962548621).
		var tagsField = document.getElementById( 'mvs-activity-tags' );
		if ( tagsField ) {
			tagsField.style.display = '';
		}

		// Show uploading state.
		var uploadingText = document.createElement( 'span' );
		uploadingText.className = 'mvs-activity-media-uploading';
		/* translators: %d: number of files being uploaded. */
		uploadingText.textContent = sprintf( __( 'Uploading %d files...', 'wpmediaverse' ), files.length );
		ensurePreviewPosition( preview );
		preview.textContent = '';
		preview.appendChild( uploadingText );
		preview.style.display = 'block';
		preview.className = 'mvs-activity-media-preview';

		// Upload with BOUNDED CONCURRENCY, not one at a time.
		//
		// This was a strict sequential chain, so each file waited for the whole
		// of the previous one and the wall-clock cost was the sum of every
		// upload. Five photos meant five full round trips back to back, and a
		// single slow file stalled everything behind it. Three at a time keeps
		// the connection busy without opening an unbounded number of parallel
		// multipart POSTs, which is its own way to look broken on mobile data.
		//
		// ORDER IS PRESERVED DELIBERATELY. attachedMedia drives the hidden
		// input, the preview and therefore the order the photos appear in the
		// activity grid, so results are written back BY INDEX and compacted
		// afterwards. Pushing them as they land would reorder a member's photos
		// by whichever upload happened to finish first.
		var CONCURRENCY = 3;
		var slots       = new Array( files.length );
		var nextIndex   = 0;

		function runNext() {
			if ( nextIndex >= files.length ) {
				return Promise.resolve();
			}
			var index = nextIndex++;
			return uploadFile( files[ index ], btn, slots, index ).then( runNext );
		}

		var workers = [];
		for ( var w = 0; w < Math.min( CONCURRENCY, files.length ); w++ ) {
			workers.push( runNext() );
		}

		var chain = Promise.all( workers ).then( function() {
			// Append in the member's original order, skipping any that failed.
			slots.forEach( function( item ) {
				if ( item ) {
					attachedMedia.push( item );
				}
			} );
		} );

		chain.then( function() {
			renderPreview();
			btn.disabled = false;
			btn.style.opacity = '1';
			// Update button label with count.
			if ( attachedMedia.length >= maxMedia ) {
				btn.style.opacity = '0.5';
				btn.disabled = true;
			}
		} ).catch( function( err ) {
			preview.textContent = '';
			var errText = document.createElement( 'span' );
			// Show the specific server message (e.g. "PDF uploads are not
			// supported.") instead of a generic line, so an unsupported-type
			// upload tells the user WHY (audit 2026-06-04, #9962548621).
			errText.textContent = ( err && err.message )
				? err.message
				: __( 'Upload failed. Please try again.', 'wpmediaverse' );
			errText.className = 'mvs-activity-media-error';
			preview.appendChild( errText );
			btn.disabled = false;
			btn.style.opacity = '1';
			setTimeout( function() { renderPreview(); }, 3000 );
		} );

		fileInput.value = '';
	} );

	// Reset attachments when activity is posted (BP fires this event).
	// Remove beforeunload handler since media is now attached to the activity.
	if ( typeof jQuery !== 'undefined' ) {
		jQuery( document ).on( 'bp_activity_ajax_post_update', function() {
			isSubmitting = false;
			window.removeEventListener( 'beforeunload', cleanupOrphans );
			attachedMedia = [];
			renderPreview();
			var btn = document.getElementById( 'mvs-activity-media-btn' );
			if ( btn ) { btn.disabled = false; btn.style.opacity = '1'; }
			// Re-add handler for next upload cycle.
			window.addEventListener( 'beforeunload', cleanupOrphans );
		} );
	}

	// ── Wrap multi-image activity posts in a Facebook-style grid ──
	// Shows max 4 visible images. If more exist, last cell gets a "+N" overlay.
	// BP strips <div> via bp_activity_filter_kses(), so we rebuild from bare <a><img></a>.
	var MAX_VISIBLE = 4;

	function wrapActivityMediaGrids() {
		var activities = document.querySelectorAll( '.activity-content .activity-inner' );
		activities.forEach( function( inner ) {
			if ( inner.querySelector( '.mvs-activity-media-grid' ) ) { return; }

			var allLinks = inner.querySelectorAll( 'a[href*="/media/"]' );
			var imgLinks = [];
			allLinks.forEach( function( a ) {
				if ( a.querySelector( 'img:not(.emoji):not(.avatar)' ) ) {
					imgLinks.push( a );
				}
			} );

			if ( imgLinks.length < 2 ) { return; }

			var total = imgLinks.length;
			var visible = Math.min( total, MAX_VISIBLE );
			var grid = document.createElement( 'div' );
			grid.className = 'mvs-activity-media-grid mvs-activity-grid-' + visible;
			grid.dataset.totalImages = total;

			// Store ALL image data (including hidden ones) for lightbox gallery navigation.
			var allImagesData = [];
			imgLinks.forEach( function( a ) {
				var aImg = a.querySelector( 'img' );
				allImagesData.push( {
					href: a.href || '',
					src: aImg ? aImg.src : '',
					alt: aImg ? ( aImg.alt || '' ) : ''
				} );
			} );
			grid.dataset.allImages = JSON.stringify( allImagesData );

			for ( var i = 0; i < visible; i++ ) {
				var wrapper = document.createElement( 'div' );
				wrapper.className = 'mvs-activity-media';
				wrapper.appendChild( imgLinks[ i ].cloneNode( true ) );

				// "+N" overlay on last visible cell if there are hidden images.
				if ( i === visible - 1 && total > MAX_VISIBLE ) {
					var overlay = document.createElement( 'div' );
					overlay.className = 'mvs-activity-media-more';
					overlay.textContent = '+' + ( total - visible + 1 );
					wrapper.appendChild( overlay );
				}

				grid.appendChild( wrapper );
			}

			// Remove all original links.
			imgLinks.forEach( function( a ) { a.remove(); } );

			inner.appendChild( grid );
		} );
	}

	// Run on load and after BP AJAX loads.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function() {
			setTimeout( wrapActivityMediaGrids, 500 );
		} );
	} else {
		setTimeout( wrapActivityMediaGrids, 500 );
	}

	if ( typeof jQuery !== 'undefined' ) {
		jQuery( document ).ajaxComplete( function() {
			setTimeout( wrapActivityMediaGrids, 300 );
		} );
	}

	// After BP posts an activity update, reset our media field —
	// BUT only when BP reports an actual success. BP Nouveau returns HTTP 200
	// for validation failures too (e.g. "Please enter some content") with
	// { success: false, data: ... }. Clearing on those would drop the user's
	// uploaded media and force a re-upload.
	if ( typeof jQuery !== 'undefined' ) {
		jQuery( document ).ajaxSuccess( function( event, xhr, settings ) {
			if ( ! settings.data || typeof settings.data !== 'string' ) {
				return;
			}
			if ( settings.data.indexOf( 'action=post_update' ) === -1 ) {
				return;
			}

			// Distinguish successful post from validation failure.
			var body = xhr.responseJSON;
			if ( ! body && xhr.responseText ) {
				try { body = JSON.parse( xhr.responseText ); } catch ( e ) { body = null; }
			}
			// BP's wp_send_json_success / wp_send_json_error wrap in {success:bool}.
			// Legacy responses may be bare HTML strings — treat as success (no envelope).
			if ( body && body.success === false ) {
				// Validation failed — keep the attached media so the user can
				// fix the error and resubmit. Also re-arm the submit flag so
				// the next submit proceeds normally.
				isSubmitting = false;
				return;
			}

			// Real success — reset closure state so the next upload starts fresh.
			attachedMedia = [];
			isSubmitting = false;

			var hiddenInput = document.getElementById( 'mvs-activity-media-ids' );
			var preview = document.getElementById( 'mvs-activity-media-preview' );
			if ( hiddenInput ) {
				hiddenInput.value = '';
			}
			if ( preview ) {
				preview.style.display = 'none';
				preview.textContent = '';
			}
		} );
	}
	// ── Inline album creation on BP profile albums tab ──
	( function() {
		var createBtn = document.getElementById( 'mvs-bp-create-album-btn' );
		if ( ! createBtn ) { return; }

		var form      = document.getElementById( 'mvs-bp-album-form' );
		var titleIn   = document.getElementById( 'mvs-bp-album-title' );
		var descIn    = document.getElementById( 'mvs-bp-album-desc' );
		var saveBtn   = document.getElementById( 'mvs-bp-album-save' );
		var cancelBtn = document.getElementById( 'mvs-bp-album-cancel' );
		var msgEl     = document.getElementById( 'mvs-bp-album-msg' );

		createBtn.addEventListener( 'click', function() {
			form.style.display = 'block';
			createBtn.style.display = 'none';
			titleIn.focus();
		} );

		cancelBtn.addEventListener( 'click', function() {
			form.style.display = 'none';
			createBtn.style.display = '';
			titleIn.value = '';
			descIn.value = '';
			msgEl.textContent = '';
		} );

		saveBtn.addEventListener( 'click', function() {
			// Read the inputs at click-time, not the init-captured refs. BP
			// Nouveau can re-render the tab body via AJAX after this handler
			// binds, which would leave `titleIn` pointing at a detached node
			// that reads empty — the false "Please enter an album name." bug
			// (Basecamp 10069383195). getElementById always resolves the live
			// node in the current DOM.
			var titleEl = document.getElementById( 'mvs-bp-album-title' );
			var descEl  = document.getElementById( 'mvs-bp-album-desc' );
			var groupEl = document.getElementById( 'mvs-bp-group-id' );

			saveBtn.disabled = true;
			saveBtn.textContent = __( 'Creating...', 'wpmediaverse' );
			msgEl.textContent = '';

			// One shared validate-name + POST path (window.mvsRest.createAlbum)
			// so the message + create logic live in one place across both
			// album-create surfaces.
			window.mvsRest.createAlbum( titleEl ? titleEl.value : '', {
				description: descEl ? descEl.value : '',
				groupId: ( groupEl && groupEl.value ) ? groupEl.value : 0,
			} ).then( function( res ) {
				if ( res.ok ) {
					// Success — reload to show the new album.
					window.location.reload();
					return;
				}
				saveBtn.disabled = false;
				saveBtn.textContent = __( 'Create', 'wpmediaverse' );
				msgEl.textContent = res.message;
				msgEl.className = 'mvs-bp-album-msg mvs-bp-album-msg-error';
			} );
		} );

		// Submit on Enter in title field.
		titleIn.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				saveBtn.click();
			}
		} );
	} )();


	// ── REST API helpers (shared by lightbox driver) ──
	function apiGet( path ) {
		return window.mvsRest.restFetch( restUrl + path )
			.then( function( r ) { return r.data; } );
	}

	function apiPost( path, body ) {
		return window.mvsRest.restFetch( restUrl + path, {
			method: 'POST',
			body: body || {},
		} ).then( function( r ) { return r.data; } );
	}

	function apiDelete( path ) {
		return window.mvsRest.restFetch( restUrl + path, {
			method: 'DELETE',
		} ).then( function( r ) { return r.data; } );
	}

	// ── Shared-UI Lightbox Driver for BuddyPress Pages ──────────────
	// Drives the `.mvs-lightbox-overlay` DOM rendered by shared-ui-frame.php
	// using vanilla JS. On Explore/Instagram pages the Interactivity API
	// handles the same DOM; on BP pages this driver takes over.
	( function() {
		var BP_SELECTORS = '#buddypress, .bp-wrap, .activity-content, .activity-inner';

		// State for the shared-ui lightbox driver.
		var suiState = {
			mediaId: 0,
			permalink: '',
			fileUrl: '',   // Current item's downloadable file URL (for the Download action).
			title: '',     // Current item's title (download filename fallback).
			gallery: [],   // Array of { mediaId, imgSrc }
			galleryIndex: 0,
			active: false  // True while the shared-ui lightbox is driven by this module.
		};

		/**
		 * Check whether the Interactivity API store is managing the lightbox.
		 * If the WP Interactivity API store for mvs/shared-ui exists and has
		 * lightboxVisible set, defer to it.
		 */
		function interactivityApiActive() {
			// Only defer if the Interactivity API lightbox is currently OPEN
			// (not just loaded on the page). On BP pages the IA store may exist
			// but BP clicks should still be handled by this driver.
			try {
				if ( window.wp && wp.interactivity ) {
					var state = wp.interactivity.getElement
						? null  // v6.5+ — can't easily peek at store state
						: null;
					// Check if the overlay is already being driven by IA
					// (it would have data-wp-context set and not have hidden attr).
					var overlay = document.querySelector( '.mvs-lightbox-overlay' );
					if ( overlay && ! overlay.hasAttribute( 'hidden' ) && overlay.hasAttribute( 'data-wp-interactive' ) ) {
						return true; // IA is currently showing the lightbox.
					}
				}
			} catch ( ex ) { /* not available */ }
			return false;
		}

		/**
		 * Check whether the click target is inside a known BP container.
		 */
		function isInBPContext( el ) {
			return !! el.closest( BP_SELECTORS );
		}

		/**
		 * Query the shared-ui overlay element.
		 */
		function getOverlay() {
			// When BP lightbox is active, use the clone; otherwise fall back to original.
			return bpLightbox || document.querySelector( '.mvs-lightbox-overlay' );
		}

		// ── Open ──

		// Clone of the lightbox overlay, detached from the IA container.
		var bpLightbox = null;

		/**
		 * Create a clean clone of .mvs-lightbox-overlay outside the IA container.
		 * This prevents the Interactivity API from re-binding attributes and
		 * intercepting clicks/events on our vanilla-JS-driven lightbox.
		 */
		function createBPLightbox() {
			var original = document.querySelector( '.mvs-lightbox-overlay' );
			if ( ! original ) { return null; }

			// Clone the entire overlay.
			var clone = original.cloneNode( true );
			clone.classList.add( 'mvs-bp-lightbox-clone' );

			// Strip ALL data-wp-* attributes to fully disconnect from IA.
			var allEls = clone.querySelectorAll( '*' );
			allEls.forEach( function( el ) {
				var attrs = Array.from( el.attributes );
				attrs.forEach( function( attr ) {
					if ( attr.name.indexOf( 'data-wp-' ) === 0 ) {
						el.removeAttribute( attr.name );
					}
				} );
			} );
			// Also strip from the clone root.
			var rootAttrs = Array.from( clone.attributes );
			rootAttrs.forEach( function( attr ) {
				if ( attr.name.indexOf( 'data-wp-' ) === 0 ) {
					clone.removeAttribute( attr.name );
				}
			} );

			// Enable post button (IA would have disabled it).
			var postBtn = clone.querySelector( '.mvs-lightbox-comment-post' );
			if ( postBtn ) { postBtn.disabled = false; }

			// Append directly to body (outside IA container).
			document.body.appendChild( clone );
			return clone;
		}

		function removeBPLightbox() {
			if ( bpLightbox && bpLightbox.parentNode ) {
				bpLightbox.parentNode.removeChild( bpLightbox );
			}
			bpLightbox = null;
		}

		function openSharedLightbox( mediaId, gallery, galleryIndex ) {
			// Create a clean clone outside the IA container.
			removeBPLightbox();
			bpLightbox = createBPLightbox();
			if ( ! bpLightbox ) {
				// Fallback: navigate to single media page.
				window.location.href = restUrl.replace( /\/wp-json\/mvs\/v1\/$/, '/media/' + mediaId + '/' );
				return;
			}

			suiState.mediaId = mediaId;
			suiState.gallery = gallery || [];
			suiState.galleryIndex = galleryIndex || 0;
			suiState.active = true;

			var overlay = bpLightbox;

			// Show loading, hide content panels.
			var loading = overlay.querySelector( '.mvs-lightbox-loading' );
			var media   = overlay.querySelector( '.mvs-lightbox-media' );
			var sidebar = overlay.querySelector( '.mvs-lightbox-sidebar' );
			if ( loading ) { loading.removeAttribute( 'hidden' ); }
			if ( media )   { media.setAttribute( 'hidden', '' ); }
			if ( sidebar ) { sidebar.setAttribute( 'hidden', '' ); }

			// Remove hidden from overlay to show it.
			overlay.removeAttribute( 'hidden' );
			document.body.style.overflow = 'hidden';

			// Fetch media data.
			apiGet( 'media/' + mediaId ).then( function( data ) {
				if ( ! data || data.code ) {
					closeSharedLightbox();
					return;
				}
				populateSharedLightbox( overlay, data );

				// Social data fetches in parallel.
				loadSharedReactions( overlay, mediaId );
				loadSharedComments( overlay, mediaId );
				loadSharedStats( overlay, mediaId );
				loadSharedFavorite( overlay, mediaId );

				// Record view.
				window.mvsRest.restFetch( restUrl + 'media/' + mediaId + '/view', {
					method: 'POST',
				} );
			} ).catch( function() {
				closeSharedLightbox();
			} );
		}

		function populateSharedLightbox( overlay, data ) {
			var loading = overlay.querySelector( '.mvs-lightbox-loading' );
			var media   = overlay.querySelector( '.mvs-lightbox-media' );
			var sidebar = overlay.querySelector( '.mvs-lightbox-sidebar' );
			if ( loading ) { loading.setAttribute( 'hidden', '' ); }
			if ( media )   { media.removeAttribute( 'hidden' ); }
			if ( sidebar ) { sidebar.removeAttribute( 'hidden' ); }

			// Media: show the right element (img/video/audio) based on type.
			var img = media ? media.querySelector( 'img' ) : null;
			var vid = media ? media.querySelector( 'video' ) : null;
			var aud = media ? media.querySelector( 'audio' ) : null;
			var isVideo = data.media_type === 'video';
			var isAudio = data.media_type === 'audio';

			if ( img ) {
				if ( isVideo || isAudio ) {
					img.removeAttribute( 'src' );
					img.setAttribute( 'hidden', '' );
				} else {
					img.src = data.thumbnail_url || data.file_url || '';
					img.alt = data.title || '';
					img.removeAttribute( 'hidden' );
				}
			}
			if ( vid ) {
				if ( isVideo ) {
					vid.src = data.file_url || '';
					vid.removeAttribute( 'hidden' );
					vid.load();
				} else {
					vid.removeAttribute( 'src' );
					vid.setAttribute( 'hidden', '' );
				}
			}
			if ( aud ) {
				if ( isAudio ) {
					aud.src = data.file_url || '';
					aud.removeAttribute( 'hidden' );
					aud.load();
				} else {
					aud.removeAttribute( 'src' );
					aud.setAttribute( 'hidden', '' );
				}
			}

			// Author. The cloned lightbox strips `data-wp-*` directives, so
			// we wire the Interactivity contract by hand: plain name from
			// `author_data.name` on the `<strong>` via `textContent` (the
			// legacy `author_name` field still carries badge markup for PHP
			// back-compat and would render as literal `<span>` text here),
			// and trusted decoration HTML from `author_data.badge_html`
			// injected into the sibling badges node. Source is
			// server-controlled (REST → `mvs_user_badge_html` filter); see
			// `shared-ui/view.js` `renderAuthorBadge` for the same pattern
			// on the Interactivity lightbox.
			var authorData   = data.author_data || {};
			var authorPlain  = authorData.name || '';
			var authorBadge  = authorData.badge_html || '';
			var authorLink   = overlay.querySelector( '.mvs-lightbox-author-link' );
			var authorAvatar = overlay.querySelector( '.mvs-lightbox-author-avatar' );
			var authorName   = overlay.querySelector( '.mvs-lightbox-author strong' );
			var authorBadges = overlay.querySelector( '.mvs-lightbox-author-badges' );
			if ( authorLink && data.author_url )      { authorLink.href = data.author_url; }
			if ( authorAvatar && data.author_avatar ) { authorAvatar.src = data.author_avatar; }
			if ( authorName )                         { authorName.textContent = authorPlain; }
			if ( authorBadges ) {
				while ( authorBadges.firstChild ) { authorBadges.removeChild( authorBadges.firstChild ); }
				if ( authorBadge ) {
					var parsed = new DOMParser().parseFromString( authorBadge, 'text/html' );
					var nodes  = Array.prototype.slice.call( parsed.body.childNodes );
					nodes.forEach( function ( n ) { authorBadges.appendChild( n ); } );
				}
			}

			// Description block reuses the author label as a "byline" prefix.
			// Keep it plain (no badge) — badges already render in the author
			// header above; repeating them here would double up.
			var descBlock = overlay.querySelector( '.mvs-lightbox-desc' );
			if ( descBlock ) {
				var descAuthor = descBlock.querySelector( 'strong' );
				var descText   = descBlock.querySelector( 'span' );
				if ( descAuthor ) { descAuthor.textContent = authorPlain; }
				if ( descText )   { descText.textContent = data.description || ''; }
				if ( data.description ) {
					descBlock.removeAttribute( 'hidden' );
				} else {
					descBlock.setAttribute( 'hidden', '' );
				}
			}

			// Permalink on open link.
			suiState.permalink = data.link || data.permalink || '';
			suiState.fileUrl   = data.file_url || '';
			suiState.title     = data.title || '';
			var openLink = overlay.querySelector( '.mvs-lightbox-actions a.mvs-lightbox-action[target="_blank"]' );
			if ( openLink ) { openLink.href = suiState.permalink; }

			// Download button: the clone inherits the original's stripped
			// data-wp-bind--hidden state (stays hidden), so mirror the IA's
			// state.lightboxHideDownload logic here — show it only when the item
			// has a file_url and per-media downloads aren't disabled. Without this
			// the Download action is invisible even though its handler is wired.
			var dlBtn = overlay.querySelector( '.mvs-lightbox-actions button.mvs-lb-download' );
			if ( dlBtn ) {
				if ( suiState.fileUrl && data.allow_download !== false ) {
					dlBtn.removeAttribute( 'hidden' );
				} else {
					dlBtn.setAttribute( 'hidden', '' );
				}
			}

			// Gallery nav.
			updateSharedNav( overlay );
		}

		// ── Close ──

		function closeSharedLightbox() {
			// Pause any playing media before closing.
			if ( bpLightbox ) {
				var vid = bpLightbox.querySelector( 'video' );
				var aud = bpLightbox.querySelector( 'audio' );
				if ( vid ) { vid.pause(); vid.removeAttribute( 'src' ); }
				if ( aud ) { aud.pause(); aud.removeAttribute( 'src' ); }
			}
			// Remove the BP lightbox clone from the DOM.
			removeBPLightbox();
			document.body.style.overflow = '';
			suiState.active = false;
			suiState.mediaId = 0;
			suiState.gallery = [];
			suiState.galleryIndex = 0;
		}

		// ── Gallery Navigation ──

		function updateSharedNav( overlay ) {
			var prevBtn = overlay.querySelector( '.mvs-lightbox-nav--prev' );
			var nextBtn = overlay.querySelector( '.mvs-lightbox-nav--next' );
			var posSpan = overlay.querySelector( '.mvs-lightbox-position span' );
			var hasGallery = suiState.gallery.length > 1;

			if ( prevBtn ) {
				if ( hasGallery && suiState.galleryIndex > 0 ) {
					prevBtn.removeAttribute( 'hidden' );
				} else {
					prevBtn.setAttribute( 'hidden', '' );
				}
			}
			if ( nextBtn ) {
				if ( hasGallery && suiState.galleryIndex < suiState.gallery.length - 1 ) {
					nextBtn.removeAttribute( 'hidden' );
				} else {
					nextBtn.setAttribute( 'hidden', '' );
				}
			}
			if ( posSpan && hasGallery ) {
				posSpan.textContent = ( suiState.galleryIndex + 1 ) + ' / ' + suiState.gallery.length;
			}
		}

		function navigateSharedGallery( direction ) {
			if ( suiState.gallery.length < 2 ) { return; }
			var newIdx = suiState.galleryIndex + direction;
			if ( newIdx < 0 || newIdx >= suiState.gallery.length ) { return; }

			suiState.galleryIndex = newIdx;
			var item = suiState.gallery[ newIdx ];
			suiState.mediaId = item.mediaId;

			var overlay = getOverlay();
			if ( ! overlay ) { return; }

			// Show loading briefly.
			var loading = overlay.querySelector( '.mvs-lightbox-loading' );
			var media   = overlay.querySelector( '.mvs-lightbox-media' );
			if ( loading ) { loading.removeAttribute( 'hidden' ); }
			if ( media )   { media.setAttribute( 'hidden', '' ); }

			apiGet( 'media/' + item.mediaId ).then( function( data ) {
				if ( ! data || data.code ) { return; }
				populateSharedLightbox( overlay, data );
				loadSharedReactions( overlay, item.mediaId );
				loadSharedComments( overlay, item.mediaId );
				loadSharedStats( overlay, item.mediaId );
				loadSharedFavorite( overlay, item.mediaId );

				window.mvsRest.restFetch( restUrl + 'media/' + item.mediaId + '/view', {
					method: 'POST',
				} );
			} );
		}

		// ── Social Data Loaders ──

		function loadSharedReactions( overlay, mediaId ) {
			apiGet( 'media/' + mediaId + '/reactions' ).then( function( data ) {
				if ( ! data ) { return; }
				var buttons = overlay.querySelectorAll( '.mvs-lightbox-reaction[data-reaction]' );
				buttons.forEach( function( btn ) {
					var type     = btn.getAttribute( 'data-reaction' );
					var countEl  = btn.querySelectorAll( 'span' )[ 1 ];
					var count    = ( data.counts && data.counts[ type ] ) || 0;
					var isActive = data.user_reaction === type;

					if ( countEl ) { countEl.textContent = count > 0 ? String( count ) : ''; }
					if ( isActive ) {
						btn.classList.add( 'active' );
					} else {
						btn.classList.remove( 'active' );
					}
				} );
			} );
		}

		function loadSharedComments( overlay, mediaId ) {
			var list = overlay.querySelector( '.mvs-lightbox-comment-list' );
			if ( ! list ) { return; }

			apiGet( 'media/' + mediaId + '/comments?per_page=20' ).then( function( data ) {
				var comments = Array.isArray( data ) ? data : [];

				// Remove any previously injected vanilla comment elements (leave templates alone).
				var existing = list.querySelectorAll( '.mvs-lightbox-comment--vanilla' );
				existing.forEach( function( el ) { el.remove(); } );

				var noComments = list.querySelector( '.mvs-lightbox-no-comments' );
				if ( noComments ) {
					if ( comments.length ) {
						noComments.setAttribute( 'hidden', '' );
					} else {
						noComments.removeAttribute( 'hidden' );
					}
				}

				comments.forEach( function( c ) {
					var item = document.createElement( 'div' );
					item.className = 'mvs-lightbox-comment mvs-lightbox-comment--vanilla';

					// Author link with avatar.
					var link = document.createElement( 'a' );
					link.className = 'mvs-lightbox-comment-author-link';
					link.href = c.author_url || '#';

					if ( c.author_avatar ) {
						var avatar = document.createElement( 'img' );
						avatar.className = 'mvs-lightbox-comment-avatar';
						avatar.src = c.author_avatar;
						avatar.alt = '';
						avatar.width = 24;
						avatar.height = 24;
						link.appendChild( avatar );
					}

					var author = document.createElement( 'strong' );
					author.textContent = c.author_name || 'Anonymous';
					link.appendChild( author );

					var text = document.createElement( 'span' );
					text.textContent = ' ' + ( c.content || '' );

					item.appendChild( link );
					item.appendChild( text );
					list.appendChild( item );
				} );

				list.scrollTop = list.scrollHeight;
			} ).catch( function() { /* silent */ } );
		}

		function loadSharedStats( overlay, mediaId ) {
			apiGet( 'media/' + mediaId + '/stats' ).then( function( data ) {
				var statsSpan = overlay.querySelector( '.mvs-lightbox-stats span' );
				if ( statsSpan && data && data.views !== undefined ) {
					statsSpan.textContent = data.views + ' views';
				}
			} );
		}

		function loadSharedFavorite( overlay, mediaId ) {
			apiGet( 'media/' + mediaId + '/favorite' ).then( function( data ) {
				var favBtn = overlay.querySelector( '.mvs-lightbox-actions button.mvs-lightbox-action' );
				if ( ! favBtn ) { return; }
				var isFav = !! ( data && data.favorited );
				if ( isFav ) {
					favBtn.classList.add( 'active' );
					favBtn.textContent = '\u2665 ' + __( 'Favorited', 'wpmediaverse' );
				} else {
					favBtn.classList.remove( 'active' );
					favBtn.textContent = '\u2661 ' + __( 'Favorite', 'wpmediaverse' );
				}
			} ).catch( function() { /* silent */ } );
		}

		// ── Click Interception (data-mvs-media-id within BP containers) ──

		document.addEventListener( 'click', function( e ) {
			// Strategy 1: element with data-mvs-media-id (new activity format).
			var target = e.target.closest( '[data-mvs-media-id]' );

			// Strategy 2: link to /media/{slug}/ containing an img (old activity format).
			if ( ! target ) {
				var link = e.target.closest( '.activity-content a[href*="/media/"], .activity-inner a[href*="/media/"], #buddypress a[href*="/media/"]' );
				if ( link && link.querySelector( 'img:not(.emoji):not(.avatar)' ) ) {
					target = link;
				}
			}

			if ( ! target ) { return; }

			// Only handle within BP context to avoid conflicting with Interactivity API.
			if ( ! isInBPContext( target ) ) { return; }

			// If Interactivity API is actively managing the lightbox, defer to it.
			if ( interactivityApiActive() ) { return; }

			e.preventDefault();
			e.stopPropagation();

			// Get media ID from attribute or resolve from slug.
			var mediaId = parseInt( target.getAttribute( 'data-mvs-media-id' ), 10 );

			if ( mediaId ) {
				// New format: media ID is known.
				openWithMediaId( mediaId, target );
			} else {
				// Old format: resolve media ID from slug via REST API.
				var href = target.getAttribute( 'href' ) || '';
				var slugMatch = href.match( /\/media\/([^\/]+)\/?$/ );
				if ( ! slugMatch ) { return; }
				var slug = slugMatch[1];

				apiGet( 'media?slug=' + encodeURIComponent( slug ) ).then( function( data ) {
					var resolved = Array.isArray( data ) ? data[0] : data;
					if ( resolved && resolved.id ) {
						openWithMediaId( resolved.id, target );
						return;
					}

					// RESOLVED TO NOTHING IS A FAILURE, NOT A NO-OP. The click was
					// already preventDefault'ed, so returning here left the member
					// clicking a photo and getting absolutely nothing — no
					// lightbox, no navigation, no message (Coding Rule 20).
					//
					// It happens for real: an activity entry outlives the media it
					// embeds, so the slug lookup 200s with an empty array. Every
					// media item in the feed on the reference install was in this
					// state, which is what made the whole feed look dead.
					//
					// Same answer as the .catch below — let the link do what it
					// says. The single-media page then gives the member a real
					// answer, including "this is gone", instead of silence.
					window.location.href = href;
				} ).catch( function() {
					// Fallback: navigate to single page.
					window.location.href = href;
				} );
			}
		} );

		function openWithMediaId( mediaId, target ) {
			// Collect gallery from all media in the same activity item.
			var activityItem = target.closest( 'li.activity-item, li[id^="activity-"], .mvs-activity-media-grid, .activity-content' );
			var gallery = [];
			var clickedIndex = 0;

			if ( activityItem ) {
				// Try data-mvs-media-id first, then links.
				var allMediaEls = activityItem.querySelectorAll( '[data-mvs-media-id]' );
				if ( allMediaEls.length ) {
					allMediaEls.forEach( function( el, idx ) {
						var mid = parseInt( el.getAttribute( 'data-mvs-media-id' ), 10 );
						if ( mid ) {
							gallery.push( { mediaId: mid, imgSrc: '' } );
							if ( mid === mediaId ) { clickedIndex = gallery.length - 1; }
						}
					} );
				}
			}

			if ( ! gallery.length ) {
				gallery = [ { mediaId: mediaId, imgSrc: '' } ];
				clickedIndex = 0;
			}

			openSharedLightbox( mediaId, gallery, clickedIndex );
		}

		// ── Reactions (event delegation) ──

		document.addEventListener( 'click', function( e ) {
			if ( ! suiState.active ) { return; }
			var btn = e.target.closest( '.mvs-lightbox-reaction[data-reaction]' );
			if ( ! btn ) { return; }
			if ( ! suiState.mediaId ) { return; }

			var type     = btn.getAttribute( 'data-reaction' );
			var isActive = btn.classList.contains( 'active' );
			var promise  = isActive
				? apiDelete( 'media/' + suiState.mediaId + '/reactions' )
				: apiPost( 'media/' + suiState.mediaId + '/reactions', { reaction_type: type } );

			promise.then( function() {
				var overlay = getOverlay();
				if ( overlay ) { loadSharedReactions( overlay, suiState.mediaId ); }
			} );
		} );

		// ── Fullscreen (event delegation) ──

		document.addEventListener( 'click', function( e ) {
			if ( ! suiState.active ) { return; }

			// Target the fullscreen button via its STABLE class, for the same
			// reason as the favorite and share handlers below: createBPLightbox()
			// clones the overlay and strips every data-wp-* attribute, so the
			// Interactivity directive `data-wp-on--click="actions.toggleLightboxFullscreen"`
			// is gone by the time the clone is in the DOM. The button rendered,
			// looked live and did nothing on the Activity page while working on
			// Explore, where the original IA overlay is used (Basecamp 10264236711).
			//
			// The favorite button had this exact defect and was fixed the same way
			// (#10077932144); fullscreen was simply never swept with it.
			var btn = e.target.closest( '.mvs-lightbox-fullscreen' );
			if ( ! btn ) { return; }

			var overlay = btn.closest( '.mvs-lightbox-overlay' );
			if ( ! overlay ) { return; }

			e.preventDefault();

			// Mirror what actions.toggleLightboxFullscreen does on the IA side:
			// flip the class the stylesheet keys off, and keep aria-pressed
			// truthful for anyone not looking at the screen.
			var isFull = overlay.classList.toggle( 'mvs-lightbox--fullscreen' );
			btn.setAttribute( 'aria-pressed', isFull ? 'true' : 'false' );
		} );

		// ── Favorites (event delegation) ──

		document.addEventListener( 'click', function( e ) {
			if ( ! suiState.active ) { return; }
			// Target the favorite button via a STABLE class. The BP lightbox is a
			// clone with every data-wp-* attribute stripped (createBPLightbox), so
			// selecting on [data-wp-on--click*=...] never matched and the handler
			// was dead (Basecamp #10077932144). The .mvs-lb-fav class survives the
			// clone and is locale-independent (textContent was translation-broken).
			var btn = e.target.closest( '.mvs-lightbox-actions button.mvs-lb-fav' );
			if ( ! btn ) { return; }
			if ( ! suiState.mediaId ) { return; }

			var isFav   = btn.classList.contains( 'active' );
			var promise = isFav
				? apiDelete( 'media/' + suiState.mediaId + '/favorite' )
				: apiPost( 'media/' + suiState.mediaId + '/favorite', {} );

			promise.then( function() {
				var newFav = ! isFav;
				if ( newFav ) {
					btn.classList.add( 'active' );
					btn.textContent = '\u2665 ' + __( 'Favorited', 'wpmediaverse' );
				} else {
					btn.classList.remove( 'active' );
					btn.textContent = '\u2661 ' + __( 'Favorite', 'wpmediaverse' );
				}
			} );
		} );

		// ── Share (event delegation) ──

		document.addEventListener( 'click', function( e ) {
			if ( ! suiState.active ) { return; }
			// Target the share button via a STABLE class — see the favorite
			// handler above; the cloned lightbox strips data-wp-* so the old
			// [data-wp-on--click*="lightboxShare"] selector was dead (#10077932144).
			var btn = e.target.closest( '.mvs-lightbox-actions button.mvs-lb-share' );
			if ( ! btn ) { return; }

			var url = suiState.permalink || window.location.href;
			if ( navigator.share ) {
				navigator.share( { title: 'Media', url: url } );
			} else if ( navigator.clipboard ) {
				navigator.clipboard.writeText( url ).then( function() {
					var original = btn.innerHTML;
					btn.textContent = '\u2713 ' + __( 'Copied!', 'wpmediaverse' );
					setTimeout( function() { btn.innerHTML = original; }, 2000 );
				} );
			}
		} );

		// ── Save to collection (event delegation) ──
		// The Save button has no BP handler and relied on the IA action
		// lightboxOpenCollections, which is stripped from the clone. Replicate it:
		// announce the media id so Pro's collection-picker.js (listening on document
		// for 'mvs-collections-click') can open the picker (#10077932144).

		document.addEventListener( 'click', function( e ) {
			if ( ! suiState.active ) { return; }
			var btn = e.target.closest( '.mvs-lightbox-actions button.mvs-lb-save' );
			if ( ! btn ) { return; }
			if ( ! suiState.mediaId ) { return; }

			btn.dispatchEvent( new CustomEvent( 'mvs-collections-click', {
				bubbles: true,
				cancelable: false,
				detail: { mediaId: suiState.mediaId }
			} ) );
		} );

		// ── Download (event delegation) ──
		// Also relied on the stripped IA action lightboxDownload. Replicate the
		// browser-native download via a hidden anchor (signed URLs carry the
		// Content-Disposition header from the storage driver) (#10077932144).

		document.addEventListener( 'click', function( e ) {
			if ( ! suiState.active ) { return; }
			var btn = e.target.closest( '.mvs-lightbox-actions button.mvs-lb-download' );
			if ( ! btn ) { return; }
			if ( ! suiState.fileUrl ) { return; }

			var a = document.createElement( 'a' );
			a.href = suiState.fileUrl;
			a.download = suiState.title || 'media';
			a.rel = 'noopener';
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
		} );

		// ── Comment posting ──

		document.addEventListener( 'click', function( e ) {
			if ( ! suiState.active ) { return; }
			var btn = e.target.closest( '.mvs-lightbox-comment-post' );
			if ( ! btn ) { return; }
			postSharedComment();
		} );

		document.addEventListener( 'keydown', function( e ) {
			if ( ! suiState.active ) { return; }
			if ( e.key !== 'Enter' ) { return; }
			var input = e.target.closest( '.mvs-lightbox-comment-input' );
			if ( ! input ) { return; }
			e.preventDefault();
			postSharedComment();
		} );

		function postSharedComment() {
			var overlay = getOverlay();
			if ( ! overlay || ! suiState.mediaId ) { return; }

			var input = overlay.querySelector( '.mvs-lightbox-comment-input' );
			if ( ! input ) { return; }

			var text = ( input.value || '' ).trim();
			if ( ! text ) { return; }

			// Disable the BUTTON, not just the input. The click handler is
			// delegated on document and reads input.value, which is still
			// populated while the request is in flight — so disabling only the
			// input left every extra click posting another identical comment
			// (#10148507863). Guard on the button's own state too, in case the
			// element is re-rendered mid-flight.
			var btn = overlay.querySelector( '.mvs-lightbox-comment-post' );
			if ( btn && btn.disabled ) { return; }
			input.disabled = true;
			if ( btn ) { btn.disabled = true; }

			var release = function() {
				input.disabled = false;
				if ( btn ) { btn.disabled = false; }
			};

			apiPost( 'media/' + suiState.mediaId + '/comments', { content: text } ).then( function() {
				input.value = '';
				release();
				loadSharedComments( overlay, suiState.mediaId );
			} ).catch( function() {
				release();
			} );
		}

		// ── Gallery nav (event delegation) ──

		document.addEventListener( 'click', function( e ) {
			if ( ! suiState.active ) { return; }
			if ( e.target.closest( '.mvs-lightbox-nav--prev' ) ) {
				e.stopPropagation();
				navigateSharedGallery( -1 );
				return;
			}
			if ( e.target.closest( '.mvs-lightbox-nav--next' ) ) {
				e.stopPropagation();
				navigateSharedGallery( 1 );
				return;
			}
		} );

		// ── Close handlers ──

		document.addEventListener( 'click', function( e ) {
			if ( ! suiState.active ) { return; }
			// Close button.
			if ( e.target.closest( '.mvs-lightbox-close' ) ) {
				closeSharedLightbox();
				return;
			}
			// Click on overlay background (not the inner lightbox panel).
			if ( e.target.classList.contains( 'mvs-lightbox-overlay' ) ) {
				closeSharedLightbox();
				return;
			}
		} );

		document.addEventListener( 'keydown', function( e ) {
			if ( ! suiState.active ) { return; }
			if ( e.key === 'Escape' ) {
				closeSharedLightbox();
				return;
			}
			if ( e.key === 'ArrowLeft' ) {
				navigateSharedGallery( -1 );
				return;
			}
			if ( e.key === 'ArrowRight' ) {
				navigateSharedGallery( 1 );
				return;
			}
		} );
	} )();

} )();
