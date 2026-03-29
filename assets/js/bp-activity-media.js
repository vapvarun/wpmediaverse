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

	var maxMedia = ( typeof mvsActivityMedia !== 'undefined' && mvsActivityMedia.maxMedia ) ? mvsActivityMedia.maxMedia : 5;
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
		preview.className = 'mvs-activity-media-preview mvs-preview-grid-' + Math.min( attachedMedia.length, 5 );

		attachedMedia.forEach( function( item, idx ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'mvs-preview-item';

			var thumb;
			if ( 'audio' === item.mediaType ) {
				thumb = document.createElement( 'div' );
				thumb.className = 'mvs-activity-media-thumb mvs-preview-audio';
				thumb.innerHTML = '<span style="font-size:2em;">♫</span>';
			} else if ( 'video' === item.mediaType && item.thumbUrl ) {
				thumb = document.createElement( 'img' );
				thumb.src = item.thumbUrl;
				thumb.alt = 'Media preview';
				thumb.className = 'mvs-activity-media-thumb';
			} else if ( 'video' === item.mediaType ) {
				thumb = document.createElement( 'div' );
				thumb.className = 'mvs-activity-media-thumb mvs-preview-video';
				thumb.innerHTML = '<span style="font-size:2em;">▶</span>';
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

	// Also intercept the BP Nouveau AJAX post button click.
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

	// ── Interactive lightbox for activity media ──
	// Instagram-style: image + social panel (reactions, comments, favorite, share).
	var REACTIONS = {
		like: '\uD83D\uDC4D', love: '\u2764\uFE0F', haha: '\uD83D\uDE02',
		wow: '\uD83D\uDE2E', sad: '\uD83D\uDE22', angry: '\uD83D\uDE21'
	};

	var lbOverlay = null;
	var lbState = { mediaId: 0, permalink: '', userReaction: '', activityId: 0 };
	// Gallery navigation state: array of { mediaId, imgSrc, title, permalink }.
	var lbGallery = [];
	var lbGalleryIndex = 0;

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) { node.className = cls; }
		if ( text ) { node.textContent = text; }
		return node;
	}

	function apiGet( path ) {
		return fetch( restUrl + path, {
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin'
		} ).then( function( r ) { return r.json(); } );
	}

	function apiPost( path, body ) {
		return fetch( restUrl + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			credentials: 'same-origin',
			body: JSON.stringify( body )
		} ).then( function( r ) { return r.json(); } );
	}

	function apiDelete( path ) {
		return fetch( restUrl + path, {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin'
		} );
	}

	function bpGet( path ) {
		return fetch( bpRestUrl + path, {
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin'
		} ).then( function( r ) { return r.json(); } );
	}

	function bpPost( path, body ) {
		return fetch( bpRestUrl + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			credentials: 'same-origin',
			body: JSON.stringify( body )
		} ).then( function( r ) { return r.json(); } );
	}

	function buildLightbox() {
		if ( lbOverlay ) { return; }

		lbOverlay = el( 'div', 'mvs-lb' );

		var container = el( 'div', 'mvs-lb-container' );

		// Close button.
		var closeBtn = el( 'button', 'mvs-lb-close', '\u00D7' );
		closeBtn.type = 'button';
		closeBtn.setAttribute( 'aria-label', 'Close' );

		// Left: image with prev/next arrows.
		var imgWrap = el( 'div', 'mvs-lb-image' );
		var img = el( 'img', 'mvs-lb-img' );
		var prevBtn = el( 'button', 'mvs-lb-nav mvs-lb-prev', '\u2039' );
		prevBtn.type = 'button';
		prevBtn.setAttribute( 'aria-label', 'Previous' );
		var nextBtn = el( 'button', 'mvs-lb-nav mvs-lb-next', '\u203A' );
		nextBtn.type = 'button';
		nextBtn.setAttribute( 'aria-label', 'Next' );
		imgWrap.appendChild( prevBtn );
		imgWrap.appendChild( img );
		imgWrap.appendChild( nextBtn );

		// Right: social panel.
		var panel = el( 'div', 'mvs-lb-panel' );

		// Title + stats row.
		var header = el( 'div', 'mvs-lb-header' );
		var titleEl = el( 'h3', 'mvs-lb-title' );
		var statsEl = el( 'div', 'mvs-lb-stats' );
		header.appendChild( titleEl );
		header.appendChild( statsEl );

		// Reactions bar.
		var reactionsBar = el( 'div', 'mvs-lb-reactions' );

		// Action buttons row.
		var actionsRow = el( 'div', 'mvs-lb-actions' );
		var favBtn = el( 'button', 'mvs-lb-fav-btn', '\u2661 Favorite' );
		favBtn.type = 'button';
		var shareBtn = el( 'button', 'mvs-lb-share-btn', '\uD83D\uDD17 Share' );
		shareBtn.type = 'button';
		var viewBtn = el( 'a', 'mvs-lb-view-btn', '\u2197 Open' );
		actionsRow.appendChild( favBtn );
		actionsRow.appendChild( shareBtn );
		actionsRow.appendChild( viewBtn );

		// Comments section.
		var commentsSection = el( 'div', 'mvs-lb-comments' );
		var commentsList = el( 'div', 'mvs-lb-comments-list' );
		var commentForm = el( 'form', 'mvs-lb-comment-form' );
		var commentInput = el( 'input', 'mvs-lb-comment-input' );
		commentInput.type = 'text';
		commentInput.placeholder = 'Add a comment...';
		commentInput.setAttribute( 'autocomplete', 'off' );
		var commentSubmit = el( 'button', 'mvs-lb-comment-submit', 'Post' );
		commentSubmit.type = 'submit';
		commentForm.appendChild( commentInput );
		commentForm.appendChild( commentSubmit );
		commentsSection.appendChild( commentsList );
		commentsSection.appendChild( commentForm );

		panel.appendChild( header );
		panel.appendChild( reactionsBar );
		panel.appendChild( actionsRow );
		panel.appendChild( commentsSection );

		container.appendChild( closeBtn );
		container.appendChild( imgWrap );
		container.appendChild( panel );
		lbOverlay.appendChild( container );
		document.body.appendChild( lbOverlay );

		// Store refs.
		lbOverlay._refs = {
			img: img, titleEl: titleEl, statsEl: statsEl,
			reactionsBar: reactionsBar, favBtn: favBtn, shareBtn: shareBtn,
			viewBtn: viewBtn, commentsList: commentsList, commentInput: commentInput,
			prevBtn: prevBtn, nextBtn: nextBtn, imgWrap: imgWrap
		};

		// Close handlers.
		function closeLb() {
			lbOverlay.classList.remove( 'mvs-lb-open' );
			img.src = '';
			lbState.mediaId = 0;
		}

		closeBtn.addEventListener( 'click', closeLb );
		lbOverlay.addEventListener( 'click', function( e ) {
			if ( e.target === lbOverlay ) { closeLb(); }
		} );
		document.addEventListener( 'keydown', function( e ) {
			if ( ! lbOverlay.classList.contains( 'mvs-lb-open' ) ) { return; }
			if ( e.key === 'Escape' ) { closeLb(); }
			if ( e.key === 'ArrowLeft' ) { navigateGallery( -1 ); }
			if ( e.key === 'ArrowRight' ) { navigateGallery( 1 ); }
		} );

		// Prev / Next button clicks.
		prevBtn.addEventListener( 'click', function( e ) {
			e.stopPropagation();
			navigateGallery( -1 );
		} );
		nextBtn.addEventListener( 'click', function( e ) {
			e.stopPropagation();
			navigateGallery( 1 );
		} );

		// Favorite toggle.
		favBtn.addEventListener( 'click', function() {
			if ( ! lbState.mediaId ) { return; }
			var isFav = favBtn.dataset.fav === '1';
			var method = isFav ? apiDelete : function( p ) { return apiPost( p, {} ); };
			method( 'media/' + lbState.mediaId + '/favorite' ).then( function() {
				favBtn.dataset.fav = isFav ? '0' : '1';
				favBtn.textContent = isFav ? '\u2661 Favorite' : '\u2665 Favorited';
				favBtn.classList.toggle( 'mvs-lb-fav-active', ! isFav );
			} );
		} );

		// Share.
		shareBtn.addEventListener( 'click', function() {
			var url = lbState.permalink || window.location.href;
			if ( navigator.share ) {
				navigator.share( { title: titleEl.textContent, url: url } );
			} else if ( navigator.clipboard ) {
				navigator.clipboard.writeText( url );
				shareBtn.textContent = '\u2713 Copied!';
				setTimeout( function() { shareBtn.textContent = '\uD83D\uDD17 Share'; }, 2000 );
			}
		} );

		// Comment submit — posts image-specific MVS comment.
		// PHP hooks handle threading under the parent BP activity via bp_activity_new_comment().
		commentForm.addEventListener( 'submit', function( e ) {
			e.preventDefault();
			var text = commentInput.value.trim();
			if ( ! text || ! lbState.mediaId ) { return; }
			commentInput.disabled = true;

			// Pass from_activity so PHP threads under the parent activity
			// instead of creating a standalone "commented on" activity entry.
			var commentPayload = { content: text };
			if ( lbState.activityId ) {
				commentPayload.from_activity = lbState.activityId;
			}

			apiPost( 'media/' + lbState.mediaId + '/comments', commentPayload ).then( function() {
				commentInput.value = '';
				commentInput.disabled = false;
				loadMediaComments( lbState.mediaId );
			} );
		} );
	}

	function renderReactions( data ) {
		var bar = lbOverlay._refs.reactionsBar;
		bar.textContent = '';

		Object.keys( REACTIONS ).forEach( function( type ) {
			var count = ( data.counts && data.counts[ type ] ) || 0;
			var isActive = data.user_reaction === type;

			var btn = el( 'button', 'mvs-lb-reaction' + ( isActive ? ' mvs-lb-reaction-active' : '' ) );
			btn.type = 'button';
			btn.dataset.type = type;

			var emoji = el( 'span', 'mvs-lb-reaction-emoji', REACTIONS[ type ] );
			var countSpan = el( 'span', 'mvs-lb-reaction-count', count > 0 ? String( count ) : '' );
			btn.appendChild( emoji );
			btn.appendChild( countSpan );

			btn.addEventListener( 'click', function() {
				if ( ! lbState.mediaId ) { return; }
				var wasActive = lbState.userReaction === type;
				var promise = wasActive
					? apiDelete( 'media/' + lbState.mediaId + '/reactions' )
					: apiPost( 'media/' + lbState.mediaId + '/reactions', { reaction_type: type } );
				promise.then( function() { loadReactions(); } );
			} );

			bar.appendChild( btn );
		} );

		lbState.userReaction = data.user_reaction || '';
	}

	function renderComments( comments ) {
		var list = lbOverlay._refs.commentsList;
		list.textContent = '';

		if ( ! comments || ! comments.length ) {
			var empty = el( 'p', 'mvs-lb-no-comments', 'No comments yet. Be the first!' );
			list.appendChild( empty );
			return;
		}

		comments.forEach( function( c ) {
			var item = el( 'div', 'mvs-lb-comment' );
			var author = el( 'strong', 'mvs-lb-comment-author', c.author_name || 'Anonymous' );
			var text = el( 'span', 'mvs-lb-comment-text', ' ' + c.content );
			var date = el( 'span', 'mvs-lb-comment-date', new Date( c.date ).toLocaleDateString() );
			item.appendChild( author );
			item.appendChild( text );
			item.appendChild( date );
			list.appendChild( item );
		} );

		list.scrollTop = list.scrollHeight;
	}

	function loadReactions() {
		apiGet( 'media/' + lbState.mediaId + '/reactions' ).then( renderReactions );
	}

	function extractNameFromTitle( title ) {
		var m = title && title.match( />([^<]+)</ );
		return m ? m[ 1 ] : 'User';
	}

	// Load comments for a specific media item (always uses MVS REST endpoint).
	function loadMediaComments( mediaId ) {
		apiGet( 'media/' + mediaId + '/comments?per_page=50' ).then( function( data ) {
			renderComments( Array.isArray( data ) ? data : [] );
		} ).catch( function() {
			renderComments( [] );
		} );
	}

	function loadComments() {
		if ( lbState.activityId && bpRestUrl ) {
			// Fetch parent activity with threaded comments included.
			bpGet( 'activity?include=' + lbState.activityId + '&display_comments=threaded' )
				.then( function( data ) {
					var comments = [];
					var activity = Array.isArray( data ) ? data[ 0 ] : data;
					var rawComments = ( activity && activity.comments ) || [];
					if ( Array.isArray( rawComments ) ) {
						rawComments.forEach( function( c ) {
							var content = '';
							if ( c.content && c.content.rendered ) {
								content = c.content.rendered.replace( /<[^>]+>/g, '' ).trim();
							} else if ( typeof c.content === 'string' ) {
								content = c.content;
							}
							comments.push( {
								author_name: extractNameFromTitle( c.title ),
								content: content,
								date: c.date || ''
							} );
						} );
					}
					renderComments( comments );
				} )
				.catch( function() {
					// Fallback to media comments if BP endpoint fails.
					apiGet( 'media/' + lbState.mediaId + '/comments?per_page=50' ).then( function( d ) {
						renderComments( Array.isArray( d ) ? d : [] );
					} );
				} );
		} else {
			apiGet( 'media/' + lbState.mediaId + '/comments?per_page=50' ).then( function( data ) {
				renderComments( Array.isArray( data ) ? data : [] );
			} );
		}
	}

	function updateNavButtons() {
		if ( ! lbOverlay || ! lbOverlay._refs ) { return; }
		var refs = lbOverlay._refs;
		var hasGallery = lbGallery.length > 1;
		refs.prevBtn.style.display = hasGallery && lbGalleryIndex > 0 ? '' : 'none';
		refs.nextBtn.style.display = hasGallery && lbGalleryIndex < lbGallery.length - 1 ? '' : 'none';
	}

	function navigateGallery( direction ) {
		if ( lbGallery.length < 2 ) { return; }
		var newIndex = lbGalleryIndex + direction;
		if ( newIndex < 0 || newIndex >= lbGallery.length ) { return; }

		lbGalleryIndex = newIndex;
		var item = lbGallery[ newIndex ];

		// If we already have the mediaId, switch immediately.
		if ( item.mediaId ) {
			switchToGalleryItem( item );
		} else {
			// Resolve media ID from permalink first.
			getMediaIdFromLink( item.permalink, item.imgSrc, function( resolvedId, resolvedLink ) {
				item.mediaId = resolvedId;
				if ( resolvedLink ) { item.permalink = resolvedLink; }
				switchToGalleryItem( item );
			} );
		}
	}

	function switchToGalleryItem( item ) {
		var refs = lbOverlay._refs;
		lbState.mediaId = item.mediaId;
		lbState.permalink = item.permalink;
		lbState.userReaction = '';

		// Clear comments and reactions immediately for fresh load.
		refs.commentsList.textContent = '';
		refs.reactionsBar.textContent = '';

		// Animate image transition.
		refs.img.style.opacity = '0';
		setTimeout( function() {
			refs.img.src = item.imgSrc;
			refs.img.alt = item.title || '';
			refs.img.style.opacity = '1';
		}, 120 );

		refs.titleEl.textContent = item.title || '';
		refs.statsEl.textContent = '';
		refs.viewBtn.href = item.permalink;
		refs.favBtn.textContent = '\u2661 Favorite';
		refs.favBtn.dataset.fav = '0';
		refs.favBtn.classList.remove( 'mvs-lb-fav-active' );
		refs.commentInput.value = '';

		updateNavButtons();

		// Reload social data for new image (always use media-specific comments).
		if ( item.mediaId ) {
			// Fetch full-size image URL from REST.
			apiGet( 'media/' + item.mediaId ).then( function( data ) {
				if ( data && data.file_url ) {
					refs.img.src = data.file_url;
				}
			} );
			loadReactions();
			loadMediaComments( item.mediaId );
			apiGet( 'media/' + item.mediaId + '/stats' ).then( function( data ) {
				if ( data && data.views !== undefined ) {
					refs.statsEl.textContent = data.views + ' views \u00B7 ' + ( data.downloads || 0 ) + ' downloads';
				}
			} );
			apiGet( 'me/favorites?per_page=100' ).then( function( data ) {
				if ( Array.isArray( data ) ) {
					var isFav = data.some( function( f ) { return f.media_id === item.mediaId; } );
					refs.favBtn.dataset.fav = isFav ? '1' : '0';
					refs.favBtn.textContent = isFav ? '\u2665 Favorited' : '\u2661 Favorite';
					refs.favBtn.classList.toggle( 'mvs-lb-fav-active', isFav );
				}
			} );
			fetch( restUrl + 'media/' + item.mediaId + '/view', {
				method: 'POST',
				headers: { 'X-WP-Nonce': nonce },
				credentials: 'same-origin'
			} );
		}
	}

	function openLightbox( mediaId, imgSrc, title, permalink, activityId, gallery, galleryIndex ) {
		buildLightbox();
		var refs = lbOverlay._refs;

		lbState.mediaId = mediaId;
		lbState.permalink = permalink;
		lbState.userReaction = '';
		lbState.activityId = activityId || 0;

		// Set gallery for prev/next navigation.
		lbGallery = gallery || [];
		lbGalleryIndex = galleryIndex || 0;

		refs.img.src = imgSrc;
		refs.img.alt = title || '';
		refs.img.style.opacity = '1';
		refs.img.style.transition = 'opacity 0.12s ease';
		refs.titleEl.textContent = title || '';
		refs.statsEl.textContent = '';
		refs.reactionsBar.textContent = '';
		refs.commentsList.textContent = '';
		refs.commentInput.value = '';
		refs.commentInput.disabled = false;
		refs.favBtn.textContent = '\u2661 Favorite';
		refs.favBtn.dataset.fav = '0';
		refs.favBtn.classList.remove( 'mvs-lb-fav-active' );
		refs.viewBtn.href = permalink;

		updateNavButtons();
		lbOverlay.classList.add( 'mvs-lb-open' );

		// Fetch full-size image URL from REST (replaces thumbnail with original).
		apiGet( 'media/' + mediaId ).then( function( data ) {
			if ( data && data.file_url ) {
				refs.img.src = data.file_url;
			}
		} );

		// Load all social data in parallel (always image-specific comments).
		loadReactions();
		loadMediaComments( mediaId );

		// Stats.
		apiGet( 'media/' + mediaId + '/stats' ).then( function( data ) {
			if ( data && data.views !== undefined ) {
				refs.statsEl.textContent = data.views + ' views \u00B7 ' + ( data.downloads || 0 ) + ' downloads';
			}
		} );

		// Favorite status.
		apiGet( 'me/favorites?per_page=100' ).then( function( data ) {
			if ( Array.isArray( data ) ) {
				var isFav = data.some( function( f ) { return f.media_id === mediaId; } );
				refs.favBtn.dataset.fav = isFav ? '1' : '0';
				refs.favBtn.textContent = isFav ? '\u2665 Favorited' : '\u2661 Favorite';
				refs.favBtn.classList.toggle( 'mvs-lb-fav-active', isFav );
			}
		} );

		// Record view.
		fetch( restUrl + 'media/' + mediaId + '/view', {
			method: 'POST',
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin'
		} );
	}

	// Extract media ID from permalink or image src.
	function getMediaIdFromLink( href, imgSrc, callback ) {
		// Try to extract slug from URL pattern /media/{slug}/
		var match = href.match( /\/media\/([^\/]+)\/?$/ );
		if ( match ) {
			apiGet( 'media?slug=' + encodeURIComponent( match[ 1 ] ) + '&per_page=1' ).then( function( data ) {
				if ( Array.isArray( data ) && data.length > 0 ) {
					callback( data[ 0 ].id, data[ 0 ].link || href );
				} else {
					callback( 0, href );
				}
			} ).catch( function() { callback( 0, href ); } );
			return;
		}

		// Fallback: no slug in URL (draft/archive link). Search by filename from img src.
		if ( imgSrc ) {
			var fnMatch = imgSrc.match( /\/([^\/]+?)(?:-\d+x\d+)?(\.\w+)$/ );
			if ( fnMatch ) {
				var searchTerm = fnMatch[ 1 ];
				apiGet( 'media?search=' + encodeURIComponent( searchTerm ) + '&per_page=1' ).then( function( data ) {
					if ( Array.isArray( data ) && data.length > 0 ) {
						callback( data[ 0 ].id, data[ 0 ].link || href );
					} else {
						callback( 0, href );
					}
				} ).catch( function() { callback( 0, href ); } );
				return;
			}
		}

		callback( 0, href );
	}

	// Handle clicks on "+N more" overlay — open lightbox at that position.
	document.addEventListener( 'click', function( e ) {
		var overlay = e.target.closest( '.mvs-activity-media-more' );
		if ( ! overlay ) { return; }
		e.preventDefault();
		e.stopPropagation();
		// Find the link inside the same cell and trigger its click logic.
		var cell = overlay.parentElement;
		var link = cell ? cell.querySelector( 'a[href*="/media/"]' ) : null;
		if ( link ) { link.click(); }
	} );

	// Delegate: intercept clicks on activity media images.
	// BP strips <div> wrappers from activity content, so we match any link
	// to /media/ that contains an img inside .activity-content.
	document.addEventListener( 'click', function( e ) {
		var link = e.target.closest( '.activity-content a[href*="/media/"]' );
		if ( ! link ) { return; }

		var img = link.querySelector( 'img' );
		if ( ! img ) { return; }

		if ( img.classList.contains( 'emoji' ) || img.src.indexOf( 'uploads' ) === -1 ) { return; }

		e.preventDefault();
		e.stopPropagation();

		var fullSrc = img.src; // Use actual src; full-size fetched from REST after ID resolve.
		var title = img.alt || '';
		var permalink = link.href || '';

		// Extract BP activity ID.
		var activityItem = link.closest( 'li.activity-item, li[id^="activity-"]' );
		var activityId = 0;
		if ( activityItem ) {
			var idAttr = activityItem.getAttribute( 'id' ) || '';
			var idMatch = idAttr.match( /activity-(\d+)/ );
			if ( idMatch ) { activityId = parseInt( idMatch[ 1 ], 10 ); }
			if ( ! activityId && activityItem.dataset.bpActivityId ) {
				activityId = parseInt( activityItem.dataset.bpActivityId, 10 );
			}
		}

		// Build gallery from all media images in the same activity.
		// Use data-all-images attribute (includes hidden images behind "+N" overlay).
		var gallery = [];
		var clickedIndex = 0;
		var grid = link.closest( '.mvs-activity-media-grid' );

		if ( grid && grid.dataset.allImages ) {
			try {
				var allImgs = JSON.parse( grid.dataset.allImages );
				allImgs.forEach( function( item, idx ) {
					var itemSrc = item.src;
					gallery.push( {
						mediaId: 0,
						imgSrc: itemSrc,
						title: item.alt || '',
						permalink: item.href || ''
					} );
					if ( item.href === link.href ) { clickedIndex = idx; }
				} );
			} catch ( e ) { /* ignore parse errors */ }
		}

		// Fallback: build from visible DOM links.
		if ( ! gallery.length ) {
			var container = grid ||
							( activityItem && activityItem.querySelector( '.activity-inner' ) );
			if ( container ) {
				var allLinks = container.querySelectorAll( 'a[href*="/media/"]' );
				allLinks.forEach( function( a, idx ) {
					var aImg = a.querySelector( 'img:not(.emoji):not(.avatar)' );
					if ( ! aImg ) { return; }
					var itemSrc = aImg.src;
					gallery.push( {
						mediaId: 0,
						imgSrc: itemSrc,
						title: aImg.alt || '',
						permalink: a.href || ''
					} );
					if ( a === link ) { clickedIndex = gallery.length - 1; }
				} );
			}
		}

		getMediaIdFromLink( permalink, fullSrc, function( mediaId, resolvedLink ) {
			if ( resolvedLink && resolvedLink !== permalink ) {
				permalink = resolvedLink;
			}
			if ( mediaId ) {
				// Set mediaId on the clicked gallery item.
				if ( gallery.length > 0 && gallery[ clickedIndex ] ) {
					gallery[ clickedIndex ].mediaId = mediaId;
				}
				openLightbox( mediaId, fullSrc, title, permalink, activityId, gallery, clickedIndex );
			} else {
				// Only navigate if we have a real single-media permalink.
				if ( permalink.match( /\/media\/[^\/]+\/?$/ ) ) {
					window.location.href = permalink;
				}
			}
		} );
	} );

	// After BP posts an activity update, reset our media field.
	if ( typeof jQuery !== 'undefined' ) {
		jQuery( document ).ajaxSuccess( function( event, xhr, settings ) {
			if ( ! settings.data || typeof settings.data !== 'string' ) {
				return;
			}
			if ( settings.data.indexOf( 'action=post_update' ) === -1 ) {
				return;
			}

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


	// ── Activity media click handler ─────────────────────────────────
	// Click on activity media navigates to /media/{slug}/ single page.
	// That page has full social UX (reactions, comments, favorites).
	// No separate lightbox needed — the single page IS the detail view.
	document.addEventListener( 'click', function( e ) {
		var link = e.target.closest( '.activity-content a' );
		if ( ! link ) return;
		var img = link.querySelector( 'img' );
		if ( ! img || img.classList.contains( 'avatar' ) || img.classList.contains( 'emoji' ) ) return;

		var href = link.getAttribute( 'href' ) || '';

		// Raw file URL (old posts) — redirect to media permalink.
		if ( href.indexOf( '/uploads/wpmediaverse/' ) !== -1 ) {
			var permalink = link.getAttribute( 'data-mvs-permalink' );
			if ( permalink ) {
				e.preventDefault();
				window.location.href = permalink;
			}
		}
		// Links to /media/{slug}/ — normal navigation to single page.
	} );
} )();
