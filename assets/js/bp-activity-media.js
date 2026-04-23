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
		preview.style.display = 'flex';
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
				thumb.alt = 'Media preview';
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
				thumb.alt = 'Media preview';
				thumb.className = 'mvs-activity-media-thumb';
			}

			var removeBtn = document.createElement( 'button' );
			removeBtn.type = 'button';
			removeBtn.className = 'mvs-activity-media-remove';
			removeBtn.textContent = '\u00D7';
			removeBtn.setAttribute( 'aria-label', 'Remove media' );
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
			video.src     = url;
			video.addEventListener( 'loadeddata', function() {
				video.currentTime = Math.min( 1, video.duration * 0.1 || 0 );
			} );
			video.addEventListener( 'seeked', function() {
				var canvas = document.createElement( 'canvas' );
				var w = video.videoWidth  || 320;
				var h = video.videoHeight || 180;
				canvas.width  = 200;
				canvas.height = Math.round( 200 * h / w ) || 113;
				canvas.getContext( '2d' ).drawImage( video, 0, 0, canvas.width, canvas.height );
				URL.revokeObjectURL( url );
				resolve( canvas.toDataURL( 'image/jpeg', 0.7 ) );
			} );
			video.addEventListener( 'error', function() {
				URL.revokeObjectURL( url );
				resolve( '' );
			} );
		} );
	}

	function uploadFile( file, btn ) {
		if ( attachedMedia.length >= maxMedia ) { return Promise.resolve(); }

		var isVideo = file.type.indexOf( 'video/' ) === 0;
		var isAudio = file.type.indexOf( 'audio/' ) === 0;

		var thumbPromise = isVideo ? generateVideoThumb( file ) : Promise.resolve( '' );

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

		var uploadPromise = fetch( restUrl + 'media?context=activity', {
			method: 'POST',
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin',
			body: fd
		} ).then( function( r ) { return r.json(); } );

		return Promise.all( [ thumbPromise, uploadPromise ] ).then( function( results ) {
			var localThumb = results[ 0 ];
			var data       = results[ 1 ];
			if ( data.id ) {
				attachedMedia.push( {
					id:        data.id,
					thumbUrl:  localThumb || data.thumbnail_url || '',
					mediaType: data.media_type || ( isVideo ? 'video' : isAudio ? 'audio' : 'image' )
				} );
			}
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

		// Show uploading state.
		var uploadingText = document.createElement( 'span' );
		uploadingText.className = 'mvs-activity-media-uploading';
		uploadingText.textContent = 'Uploading ' + files.length + ' file' + ( files.length > 1 ? 's' : '' ) + '...';
		ensurePreviewPosition( preview );
		preview.textContent = '';
		preview.appendChild( uploadingText );
		preview.style.display = 'block';
		preview.className = 'mvs-activity-media-preview';

		// Upload all files sequentially.
		var chain = Promise.resolve();
		files.forEach( function( file ) {
			chain = chain.then( function() { return uploadFile( file, btn ); } );
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
		} ).catch( function() {
			preview.textContent = '';
			var errText = document.createElement( 'span' );
			errText.textContent = 'Upload failed. Please try again.';
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
			var title = titleIn.value.trim();
			if ( ! title ) {
				msgEl.textContent = 'Please enter an album name.';
				msgEl.className = 'mvs-bp-album-msg mvs-bp-album-msg-error';
				return;
			}

			saveBtn.disabled = true;
			saveBtn.textContent = 'Creating...';
			msgEl.textContent = '';

			var payload = { title: title, description: descIn.value.trim() };
			var groupIdEl = document.getElementById( 'mvs-bp-group-id' );
			if ( groupIdEl && groupIdEl.value ) {
				payload.group_id = parseInt( groupIdEl.value, 10 );
			}

			fetch( restUrl + 'albums', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				credentials: 'same-origin',
				body: JSON.stringify( payload )
			} )
			.then( function( r ) { return r.json(); } )
			.then( function( data ) {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Create';

				if ( data.id ) {
					// Success — reload to show the new album.
					window.location.reload();
				} else {
					msgEl.textContent = data.message || 'Failed to create album.';
					msgEl.className = 'mvs-bp-album-msg mvs-bp-album-msg-error';
				}
			} )
			.catch( function() {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Create';
				msgEl.textContent = 'Network error. Please try again.';
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
		return fetch( restUrl + path, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce }
		} ).then( function( r ) { return r.json(); } );
	}

	function apiPost( path, body ) {
		return fetch( restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			body: JSON.stringify( body || {} )
		} ).then( function( r ) { return r.json(); } );
	}

	function apiDelete( path ) {
		return fetch( restUrl + path, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce }
		} ).then( function( r ) { return r.json(); } );
	}

	// ── Shared-UI Lightbox Driver for BuddyPress Pages ──────────────
	// Drives the `.mvs-lightbox-overlay` DOM rendered by shared-ui-shell.php
	// using vanilla JS. On Explore/Instagram pages the Interactivity API
	// handles the same DOM; on BP pages this driver takes over.
	( function() {
		var BP_SELECTORS = '#buddypress, .bp-wrap, .activity-content, .activity-inner';

		// State for the shared-ui lightbox driver.
		var suiState = {
			mediaId: 0,
			permalink: '',
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
				fetch( restUrl + 'media/' + mediaId + '/view', {
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce },
					credentials: 'same-origin'
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

			// Author.
			var authorLink   = overlay.querySelector( '.mvs-lightbox-author-link' );
			var authorAvatar = overlay.querySelector( '.mvs-lightbox-author-avatar' );
			var authorName   = overlay.querySelector( '.mvs-lightbox-author strong' );
			if ( authorLink && data.author_url )    { authorLink.href = data.author_url; }
			if ( authorAvatar && data.author_avatar ) { authorAvatar.src = data.author_avatar; }
			if ( authorName )                        { authorName.textContent = data.author_name || ''; }

			// Description.
			var descBlock = overlay.querySelector( '.mvs-lightbox-desc' );
			if ( descBlock ) {
				var descAuthor = descBlock.querySelector( 'strong' );
				var descText   = descBlock.querySelector( 'span' );
				if ( descAuthor ) { descAuthor.textContent = data.author_name || ''; }
				if ( descText )   { descText.textContent = data.description || ''; }
				if ( data.description ) {
					descBlock.removeAttribute( 'hidden' );
				} else {
					descBlock.setAttribute( 'hidden', '' );
				}
			}

			// Permalink on open link.
			suiState.permalink = data.link || data.permalink || '';
			var openLink = overlay.querySelector( '.mvs-lightbox-actions a.mvs-lightbox-action[target="_blank"]' );
			if ( openLink ) { openLink.href = suiState.permalink; }

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

				fetch( restUrl + 'media/' + item.mediaId + '/view', {
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce },
					credentials: 'same-origin'
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
					favBtn.textContent = '\u2665 Favorited';
				} else {
					favBtn.classList.remove( 'active' );
					favBtn.textContent = '\u2661 Favorite';
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
					}
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

		// ── Favorites (event delegation) ──

		document.addEventListener( 'click', function( e ) {
			if ( ! suiState.active ) { return; }
			// Match the first button.mvs-lightbox-action inside .mvs-lightbox-actions.
			var btn = e.target.closest( '.mvs-lightbox-actions button.mvs-lightbox-action' );
			if ( ! btn ) { return; }
			// Skip if it's the share button (contains "Share" text).
			if ( btn.textContent.indexOf( 'Share' ) !== -1 ) { return; }
			if ( ! suiState.mediaId ) { return; }

			var isFav   = btn.classList.contains( 'active' );
			var promise = isFav
				? apiDelete( 'media/' + suiState.mediaId + '/favorite' )
				: apiPost( 'media/' + suiState.mediaId + '/favorite', {} );

			promise.then( function() {
				var newFav = ! isFav;
				if ( newFav ) {
					btn.classList.add( 'active' );
					btn.textContent = '\u2665 Favorited';
				} else {
					btn.classList.remove( 'active' );
					btn.textContent = '\u2661 Favorite';
				}
			} );
		} );

		// ── Share (event delegation) ──

		document.addEventListener( 'click', function( e ) {
			if ( ! suiState.active ) { return; }
			var btn = e.target.closest( '.mvs-lightbox-actions button.mvs-lightbox-action' );
			if ( ! btn ) { return; }
			if ( btn.textContent.indexOf( 'Share' ) === -1 && btn.textContent.indexOf( 'Copied' ) === -1 ) { return; }

			var url = suiState.permalink || window.location.href;
			if ( navigator.share ) {
				navigator.share( { title: 'Media', url: url } );
			} else if ( navigator.clipboard ) {
				navigator.clipboard.writeText( url ).then( function() {
					var original = btn.innerHTML;
					btn.textContent = '\u2713 Copied!';
					setTimeout( function() { btn.innerHTML = original; }, 2000 );
				} );
			}
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

			input.disabled = true;

			apiPost( 'media/' + suiState.mediaId + '/comments', { content: text } ).then( function() {
				input.value = '';
				input.disabled = false;
				loadSharedComments( overlay, suiState.mediaId );
			} ).catch( function() {
				input.disabled = false;
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
