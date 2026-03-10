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

	// Use event delegation since Nouveau renders the form dynamically.
	document.addEventListener( 'click', function( e ) {
		var btn = e.target.closest( '#mvs-activity-media-btn' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();

		var fileInput = document.getElementById( 'mvs-activity-media-file' );
		if ( fileInput ) {
			fileInput.click();
		}
	} );

	document.addEventListener( 'change', function( e ) {
		if ( e.target.id !== 'mvs-activity-media-file' ) {
			return;
		}

		var fileInput = e.target;
		var file = fileInput.files[ 0 ];
		if ( ! file ) {
			return;
		}

		var btn = document.getElementById( 'mvs-activity-media-btn' );
		var preview = document.getElementById( 'mvs-activity-media-preview' );
		var hiddenInput = document.getElementById( 'mvs-activity-media-id' );

		if ( ! btn || ! preview || ! hiddenInput ) {
			return;
		}

		btn.disabled = true;
		btn.style.opacity = '0.5';

		// Show uploading state.
		preview.textContent = '';
		var uploadingText = document.createElement( 'span' );
		uploadingText.className = 'mvs-activity-media-uploading';
		uploadingText.textContent = 'Uploading...';
		preview.appendChild( uploadingText );
		preview.style.display = 'block';

		var fd = new FormData();
		fd.append( 'file', file );

		fetch( restUrl + 'media', {
			method: 'POST',
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin',
			body: fd
		} )
		.then( function( r ) {
			return r.json();
		} )
		.then( function( data ) {
			if ( data.id ) {
				hiddenInput.value = data.id;
				var imgUrl = data.thumbnail_url || data.file_url || '';
				if ( imgUrl ) {
					// Build preview using safe DOM methods.
					preview.textContent = '';

					var img = document.createElement( 'img' );
					img.src = imgUrl;
					img.alt = 'Media preview';
					img.className = 'mvs-activity-media-thumb';

					var removeBtn = document.createElement( 'button' );
					removeBtn.type = 'button';
					removeBtn.className = 'mvs-activity-media-remove';
					removeBtn.textContent = '\u00D7';
					removeBtn.addEventListener( 'click', function() {
						preview.style.display = 'none';
						preview.textContent = '';
						hiddenInput.value = '';
					} );

					preview.appendChild( img );
					preview.appendChild( removeBtn );
				} else {
					// No thumbnail but upload succeeded.
					preview.textContent = '';
					var successText = document.createElement( 'span' );
					successText.textContent = 'Media attached!';
					successText.className = 'mvs-activity-media-success';
					preview.appendChild( successText );
				}
			} else {
				preview.textContent = '';
				var errText = document.createElement( 'span' );
				errText.textContent = 'Upload failed. Please try again.';
				errText.className = 'mvs-activity-media-error';
				preview.appendChild( errText );
				setTimeout( function() {
					preview.style.display = 'none';
				}, 3000 );
			}
			btn.disabled = false;
			btn.style.opacity = '1';
		} )
		.catch( function() {
			preview.textContent = '';
			var errText = document.createElement( 'span' );
			errText.textContent = 'Upload failed. Please try again.';
			errText.className = 'mvs-activity-media-error';
			preview.appendChild( errText );
			btn.disabled = false;
			btn.style.opacity = '1';
			setTimeout( function() {
				preview.style.display = 'none';
			}, 3000 );
		} );

		fileInput.value = '';
	} );

	// ── Interactive lightbox for activity media ──
	// Instagram-style: image + social panel (reactions, comments, favorite, share).
	var REACTIONS = {
		like: '\uD83D\uDC4D', love: '\u2764\uFE0F', haha: '\uD83D\uDE02',
		wow: '\uD83D\uDE2E', sad: '\uD83D\uDE22', angry: '\uD83D\uDE21'
	};

	var lbOverlay = null;
	var lbState = { mediaId: 0, permalink: '', userReaction: '', activityId: 0 };

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

		// Left: image.
		var imgWrap = el( 'div', 'mvs-lb-image' );
		var img = el( 'img', 'mvs-lb-img' );
		imgWrap.appendChild( img );

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
			viewBtn: viewBtn, commentsList: commentsList, commentInput: commentInput
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
			if ( e.key === 'Escape' && lbOverlay.classList.contains( 'mvs-lb-open' ) ) {
				closeLb();
			}
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

		// Comment submit.
		commentForm.addEventListener( 'submit', function( e ) {
			e.preventDefault();
			var text = commentInput.value.trim();
			if ( ! text || ! lbState.mediaId ) { return; }
			commentInput.disabled = true;

			var postComment;
			if ( lbState.activityId && bpRestUrl ) {
				// Post as BP activity comment (threaded under the activity item).
				postComment = bpPost( 'activity', {
					primary_item_id: lbState.activityId,
					content: text,
					type: 'activity_comment'
				} );
			} else {
				// Fallback: post as media comment.
				postComment = apiPost( 'media/' + lbState.mediaId + '/comments', { content: text } );
			}
			postComment.then( function() {
				commentInput.value = '';
				commentInput.disabled = false;
				loadComments();
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

	function openLightbox( mediaId, imgSrc, title, permalink, activityId ) {
		buildLightbox();
		var refs = lbOverlay._refs;

		lbState.mediaId = mediaId;
		lbState.permalink = permalink;
		lbState.userReaction = '';
		lbState.activityId = activityId || 0;

		refs.img.src = imgSrc;
		refs.img.alt = title || '';
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

		lbOverlay.classList.add( 'mvs-lb-open' );

		// Load all social data in parallel.
		loadReactions();
		loadComments();

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

	// Extract media ID from permalink: /media/slug/ → lookup via REST, or from data attribute.
	function getMediaIdFromLink( href, callback ) {
		// Try to extract slug from URL pattern /media/{slug}/
		var match = href.match( /\/media\/([^\/]+)\/?$/ );
		if ( ! match ) { callback( 0 ); return; }
		var slug = match[ 1 ];

		apiGet( 'media?slug=' + encodeURIComponent( slug ) + '&per_page=1' ).then( function( data ) {
			if ( Array.isArray( data ) && data.length > 0 ) {
				callback( data[ 0 ].id );
			} else {
				callback( 0 );
			}
		} ).catch( function() { callback( 0 ); } );
	}

	// Delegate: intercept clicks on activity media images.
	// BP strips <div> wrappers from activity content, so we match any link
	// to /media/ that contains an img inside .activity-content.
	document.addEventListener( 'click', function( e ) {
		var link = e.target.closest( '.activity-content a[href*="/media/"]' );
		if ( ! link ) { return; }

		var img = link.querySelector( 'img' );
		if ( ! img ) { return; }

		// Skip emoji images and non-upload images.
		if ( img.classList.contains( 'emoji' ) || img.src.indexOf( 'uploads' ) === -1 ) { return; }

		e.preventDefault();
		e.stopPropagation();

		var fullSrc = img.src.replace( /-\d+x\d+(\.\w+)$/, '$1' );
		var title = img.alt || '';
		var permalink = link.href || '';

		// Extract BP activity ID from closest activity list item.
		var activityItem = link.closest( 'li.activity-item, li[id^="activity-"]' );
		var activityId = 0;
		if ( activityItem ) {
			var idAttr = activityItem.getAttribute( 'id' ) || '';
			var idMatch = idAttr.match( /activity-(\d+)/ );
			if ( idMatch ) {
				activityId = parseInt( idMatch[ 1 ], 10 );
			}
			// Also check data-bp-activity-id attribute.
			if ( ! activityId && activityItem.dataset.bpActivityId ) {
				activityId = parseInt( activityItem.dataset.bpActivityId, 10 );
			}
		}

		getMediaIdFromLink( permalink, function( mediaId ) {
			if ( mediaId ) {
				openLightbox( mediaId, fullSrc, title, permalink, activityId );
			} else {
				// Fallback: navigate to page if we can't resolve the ID.
				window.location.href = permalink;
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

			var hiddenInput = document.getElementById( 'mvs-activity-media-id' );
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
} )();
