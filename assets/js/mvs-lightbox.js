/**
 * WPMediaVerse — Grid Lightbox
 *
 * Instagram-style lightbox (reactions, comments, favorites, share, gallery nav)
 * for all MVS media grids: explore, dashboard, BuddyPress profile/group tabs.
 *
 * Triggered by clicks on .mvs-grid-item[data-media-id] .mvs-grid-item-link.
 * Gallery navigation uses sibling grid items within the same .mvs-media-grid.
 *
 * @package WPMediaVerse
 */
( function() {
	'use strict';

	var cfg        = ( typeof mvsLightboxData !== 'undefined' ) ? mvsLightboxData : {};
	var restUrl    = cfg.restUrl   || '';
	var nonce      = cfg.nonce     || '';
	var isLoggedIn = !! cfg.isLoggedIn;

	if ( ! restUrl || ! nonce ) {
		return;
	}

	var REACTIONS = {
		like: '\uD83D\uDC4D', love: '\u2764\uFE0F', haha: '\uD83D\uDE02',
		wow:  '\uD83D\uDE2E', sad:  '\uD83D\uDE22', angry: '\uD83D\uDE21'
	};

	var lbOverlay    = null;
	var lbState      = { mediaId: 0, permalink: '', userReaction: '' };
	var lbGallery    = [];
	var lbGalleryIdx = 0;

	/* ── DOM helpers ── */
	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls )  { node.className   = cls; }
		if ( text ) { node.textContent = text; }
		return node;
	}

	/* ── REST helpers ── */
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

	/* ── Build lightbox DOM (once) ── */
	function buildLightbox() {
		if ( lbOverlay ) { return; }

		lbOverlay = el( 'div', 'mvs-lb' );

		var container = el( 'div', 'mvs-lb-container' );

		var closeBtn = el( 'button', 'mvs-lb-close', '\u00D7' );
		closeBtn.type = 'button';
		closeBtn.setAttribute( 'aria-label', 'Close lightbox' );

		var imgWrap = el( 'div', 'mvs-lb-image' );
		var img     = el( 'img', 'mvs-lb-img' );
		img.alt = '';

		var videoEl = document.createElement( 'video' );
		videoEl.className = 'mvs-lb-video';
		videoEl.controls  = true;
		videoEl.style.cssText = 'display:none;max-width:100%;max-height:100%;width:auto;height:auto;';

		var audioWrap = el( 'div', 'mvs-lb-audio-wrap' );
		audioWrap.style.cssText = 'display:none;flex-direction:column;align-items:center;justify-content:center;padding:24px;gap:16px;';
		var audioIcon = el( 'span', '', '\u266B' );
		audioIcon.style.cssText = 'font-size:4em;color:#aaa;';
		var audioEl = document.createElement( 'audio' );
		audioEl.className = 'mvs-lb-audio';
		audioEl.controls  = true;
		audioEl.style.cssText = 'width:100%;max-width:400px;';
		audioWrap.appendChild( audioIcon );
		audioWrap.appendChild( audioEl );

		var prevBtn = el( 'button', 'mvs-lb-nav mvs-lb-prev', '\u2039' );
		prevBtn.type = 'button';
		prevBtn.setAttribute( 'aria-label', 'Previous image' );
		var nextBtn = el( 'button', 'mvs-lb-nav mvs-lb-next', '\u203A' );
		nextBtn.type = 'button';
		nextBtn.setAttribute( 'aria-label', 'Next image' );
		imgWrap.appendChild( prevBtn );
		imgWrap.appendChild( img );
		imgWrap.appendChild( videoEl );
		imgWrap.appendChild( audioWrap );
		imgWrap.appendChild( nextBtn );

		var panel        = el( 'div', 'mvs-lb-panel' );
		var header       = el( 'div', 'mvs-lb-header' );
		var titleEl      = el( 'h3',  'mvs-lb-title' );
		var statsEl      = el( 'div', 'mvs-lb-stats' );
		header.appendChild( titleEl );
		header.appendChild( statsEl );

		var reactionsBar = el( 'div', 'mvs-lb-reactions' );

		var actionsRow = el( 'div', 'mvs-lb-actions' );
		var favBtn     = el( 'button', 'mvs-lb-fav-btn', '\u2661 Favorite' );
		favBtn.type = 'button';
		var shareBtn   = el( 'button', 'mvs-lb-share-btn', '\uD83D\uDD17 Share' );
		shareBtn.type = 'button';
		var viewBtn    = el( 'a', 'mvs-lb-view-btn', '\u2197 Open' );
		actionsRow.appendChild( favBtn );
		actionsRow.appendChild( shareBtn );
		actionsRow.appendChild( viewBtn );

		var commentsSection = el( 'div', 'mvs-lb-comments' );
		var commentsList    = el( 'div', 'mvs-lb-comments-list' );
		var commentForm     = el( 'form', 'mvs-lb-comment-form' );
		var commentInput    = el( 'input', 'mvs-lb-comment-input' );
		commentInput.type = 'text';
		commentInput.placeholder = 'Add a comment\u2026';
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

		lbOverlay._refs = {
			img: img, imgWrap: imgWrap, videoEl: videoEl, audioEl: audioEl, audioWrap: audioWrap,
			titleEl: titleEl, statsEl: statsEl,
			reactionsBar: reactionsBar, favBtn: favBtn, shareBtn: shareBtn,
			viewBtn: viewBtn, commentsList: commentsList, commentInput: commentInput,
			prevBtn: prevBtn, nextBtn: nextBtn
		};

		/* Close handlers */
		function closeLb() {
			lbOverlay.classList.remove( 'mvs-lb-open' );
			img.src = '';
			videoEl.pause(); videoEl.src = '';
			audioEl.pause(); audioEl.src = '';
			lbState.mediaId = 0;
			document.body.style.overflow = '';
		}

		closeBtn.addEventListener( 'click', closeLb );
		lbOverlay.addEventListener( 'click', function( e ) {
			if ( e.target === lbOverlay ) { closeLb(); }
		} );
		document.addEventListener( 'keydown', function( e ) {
			if ( ! lbOverlay.classList.contains( 'mvs-lb-open' ) ) { return; }
			if ( 'Escape'     === e.key ) { closeLb(); }
			if ( 'ArrowLeft'  === e.key ) { navigate( -1 ); }
			if ( 'ArrowRight' === e.key ) { navigate( 1 ); }
		} );
		prevBtn.addEventListener( 'click', function( e ) { e.stopPropagation(); navigate( -1 ); } );
		nextBtn.addEventListener( 'click', function( e ) { e.stopPropagation(); navigate( 1 ); } );

		/* Favorite toggle */
		favBtn.addEventListener( 'click', function() {
			if ( ! lbState.mediaId || ! isLoggedIn ) { return; }
			var isFav  = '1' === favBtn.dataset.fav;
			var method = isFav
				? apiDelete( 'media/' + lbState.mediaId + '/favorite' )
				: apiPost(   'media/' + lbState.mediaId + '/favorite', {} );
			method.then( function() {
				favBtn.dataset.fav  = isFav ? '0' : '1';
				favBtn.textContent  = isFav ? '\u2661 Favorite' : '\u2665 Favorited';
				favBtn.classList.toggle( 'mvs-lb-fav-active', ! isFav );
			} );
		} );

		/* Share */
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

		/* Comment submit */
		commentForm.addEventListener( 'submit', function( e ) {
			e.preventDefault();
			if ( ! isLoggedIn || ! lbState.mediaId ) { return; }
			var text = commentInput.value.trim();
			if ( ! text ) { return; }
			commentInput.disabled = true;
			apiPost( 'media/' + lbState.mediaId + '/comments', { content: text } )
				.then( function() {
					commentInput.value    = '';
					commentInput.disabled = false;
					loadComments( lbState.mediaId );
				} );
		} );
	}

	/* ── Nav visibility ── */
	function updateNav() {
		if ( ! lbOverlay ) { return; }
		var refs = lbOverlay._refs;
		refs.prevBtn.style.display = ( lbGallery.length > 1 && lbGalleryIdx > 0 ) ? '' : 'none';
		refs.nextBtn.style.display = ( lbGallery.length > 1 && lbGalleryIdx < lbGallery.length - 1 ) ? '' : 'none';
	}

	/* ── Gallery navigation ── */
	function navigate( dir ) {
		var newIdx = lbGalleryIdx + dir;
		if ( newIdx < 0 || newIdx >= lbGallery.length ) { return; }
		lbGalleryIdx = newIdx;
		switchItem( lbGallery[ newIdx ] );
	}

	function switchItem( item ) {
		var refs       = lbOverlay._refs;
		lbState.mediaId   = item.mediaId;
		lbState.permalink = item.permalink;

		showMedia( refs, item );
		refs.titleEl.textContent = item.title || '';
		refs.viewBtn.href        = item.permalink;
		refs.statsEl.textContent = '';
		refs.commentsList.textContent = '';
		refs.commentInput.value  = '';
		refs.favBtn.textContent  = '\u2661 Favorite';
		refs.favBtn.dataset.fav  = '0';
		refs.favBtn.classList.remove( 'mvs-lb-fav-active' );

		updateNav();
		loadSocialData( item.mediaId );
	}

	/* ── Social data ── */
	function loadSocialData( mediaId ) {
		var refs = lbOverlay._refs;

		apiGet( 'media/' + mediaId ).then( function( data ) {
			if ( data && data.file_url && lbState.mediaId === mediaId ) {
				var curItem = lbGallery[ lbGalleryIdx ];
				var curType = curItem ? ( curItem.mediaType || 'image' ) : 'image';
				if ( 'image' === curType ) {
					refs.img.src = data.file_url;
				}
			}
		} );

		loadReactions( mediaId );
		loadComments( mediaId );

		apiGet( 'media/' + mediaId + '/stats' ).then( function( data ) {
			if ( data && lbState.mediaId === mediaId ) {
				refs.statsEl.textContent =
					( data.views || 0 ) + ' views \u00B7 ' + ( data.downloads || 0 ) + ' downloads';
			}
		} );

		if ( isLoggedIn ) {
			apiGet( 'me/favorites?per_page=100' ).then( function( data ) {
				if ( lbState.mediaId !== mediaId ) { return; }
				if ( Array.isArray( data ) ) {
					var isFav = data.some( function( f ) { return f.media_id === mediaId; } );
					refs.favBtn.dataset.fav = isFav ? '1' : '0';
					refs.favBtn.textContent = isFav ? '\u2665 Favorited' : '\u2661 Favorite';
					refs.favBtn.classList.toggle( 'mvs-lb-fav-active', isFav );
				}
			} );
		}

		fetch( restUrl + 'media/' + mediaId + '/view', {
			method: 'POST',
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin'
		} );
	}

	function loadReactions( mediaId ) {
		apiGet( 'media/' + mediaId + '/reactions' ).then( renderReactions );
	}

	function renderReactions( data ) {
		var bar = lbOverlay._refs.reactionsBar;
		bar.textContent = '';
		Object.keys( REACTIONS ).forEach( function( type ) {
			var count    = ( data.counts && data.counts[ type ] ) || 0;
			var isActive = data.user_reaction === type;
			var btn      = el( 'button', 'mvs-lb-reaction' + ( isActive ? ' mvs-lb-reaction-active' : '' ) );
			btn.type = 'button';
			btn.dataset.type = type;
			btn.appendChild( el( 'span', 'mvs-lb-reaction-emoji', REACTIONS[ type ] ) );
			btn.appendChild( el( 'span', 'mvs-lb-reaction-count', count > 0 ? String( count ) : '' ) );
			btn.addEventListener( 'click', function() {
				if ( ! lbState.mediaId || ! isLoggedIn ) { return; }
				var wasActive = lbState.userReaction === type;
				( wasActive
					? apiDelete( 'media/' + lbState.mediaId + '/reactions' )
					: apiPost(   'media/' + lbState.mediaId + '/reactions', { reaction_type: type } ) )
					.then( function() { loadReactions( lbState.mediaId ); } );
			} );
			bar.appendChild( btn );
		} );
		lbState.userReaction = data.user_reaction || '';
	}

	function loadComments( mediaId ) {
		apiGet( 'media/' + mediaId + '/comments?per_page=50' )
			.then( function( data ) { renderComments( Array.isArray( data ) ? data : [] ); } )
			.catch( function() { renderComments( [] ); } );
	}

	function renderComments( comments ) {
		var list = lbOverlay._refs.commentsList;
		list.textContent = '';
		if ( ! comments.length ) {
			list.appendChild( el( 'p', 'mvs-lb-no-comments', 'No comments yet. Be the first!' ) );
			return;
		}
		comments.forEach( function( c ) {
			var item = el( 'div', 'mvs-lb-comment' );
			item.appendChild( el( 'strong', 'mvs-lb-comment-author', c.author_name || 'Anonymous' ) );
			item.appendChild( el( 'span',   'mvs-lb-comment-text',   ' ' + c.content ) );
			item.appendChild( el( 'span',   'mvs-lb-comment-date',
				new Date( c.date ).toLocaleDateString() ) );
			list.appendChild( item );
		} );
		list.scrollTop = list.scrollHeight;
	}

	/* ── Open lightbox ── */
	/* ── Show correct media type (image / video / audio) inside the lightbox ── */
	function showMedia( refs, item ) {
		var type = item.mediaType || 'image';
		refs.img.style.display       = 'none';
		refs.videoEl.style.display   = 'none';
		refs.audioWrap.style.display = 'none';
		if ( refs.videoEl.src ) { refs.videoEl.pause(); refs.videoEl.src = ''; }
		if ( refs.audioEl.src ) { refs.audioEl.pause(); refs.audioEl.src = ''; }
		if ( 'video' === type ) {
			refs.videoEl.src         = item.mediaSrc || item.imgSrc || '';
			refs.videoEl.style.display = '';
		} else if ( 'audio' === type ) {
			refs.audioEl.src         = item.mediaSrc || '';
			refs.audioWrap.style.display = 'flex';
		} else {
			refs.img.src             = item.imgSrc || '';
			refs.img.alt             = item.title  || '';
			refs.img.style.display   = '';
		}
	}

	function openLightbox( mediaId, imgSrc, title, permalink, gallery, galleryIdx ) {
		buildLightbox();
		var refs = lbOverlay._refs;

		lbState.mediaId      = mediaId;
		lbState.permalink    = permalink;
		lbState.userReaction = '';
		lbGallery            = gallery    || [];
		lbGalleryIdx         = galleryIdx || 0;

		showMedia( refs, lbGallery[ lbGalleryIdx ] );
		refs.titleEl.textContent  = title || '';
		refs.viewBtn.href         = permalink;
		refs.statsEl.textContent  = '';
		refs.reactionsBar.textContent = '';
		refs.commentsList.textContent = '';
		refs.commentInput.value   = '';
		refs.commentInput.disabled = false;
		refs.favBtn.textContent   = '\u2661 Favorite';
		refs.favBtn.dataset.fav   = '0';
		refs.favBtn.classList.remove( 'mvs-lb-fav-active' );

		updateNav();
		lbOverlay.classList.add( 'mvs-lb-open' );
		document.body.style.overflow = 'hidden';

		loadSocialData( mediaId );
	}

	/* ── Grid item click delegate ── */
	document.addEventListener( 'click', function( e ) {
		var link = e.target.closest( '.mvs-grid-item-link' );
		if ( ! link ) { return; }

		var gridItem = link.closest( '.mvs-grid-item[data-media-id]' );
		if ( ! gridItem ) { return; }

		// Only intercept images; videos/docs navigate to their single page.
		var mediaType = gridItem.dataset.mediaType || 'image';
		if ( 'image' !== mediaType ) { return; }

		var mediaId = parseInt( gridItem.dataset.mediaId, 10 );
		if ( ! mediaId ) { return; }

		e.preventDefault();
		e.stopPropagation();

		var imgEl     = link.querySelector( 'img' );
		var imgSrc    = imgEl ? imgEl.src : '';
		var title     = imgEl ? ( imgEl.alt || '' ) : '';
		var permalink = link.href || '';

		// Build gallery from image items in the same grid container.
		var gridContainer = link.closest( '.mvs-media-grid' );
		var gallery  = [];
		var clickIdx = 0;

		if ( gridContainer ) {
			var allItems = gridContainer.querySelectorAll( '.mvs-grid-item[data-media-id]' );
			allItems.forEach( function( gi ) {
				if ( ( gi.dataset.mediaType || 'image' ) !== 'image' ) { return; }
				var mid   = parseInt( gi.dataset.mediaId, 10 );
				var aLink = gi.querySelector( '.mvs-grid-item-link' );
				var aImg  = aLink ? aLink.querySelector( 'img' ) : null;
				gallery.push( {
					mediaId:   mid,
					imgSrc:    aImg  ? aImg.src  : '',
					title:     aImg  ? ( aImg.alt || '' ) : '',
					permalink: aLink ? aLink.href : ''
				} );
				if ( mid === mediaId ) { clickIdx = gallery.length - 1; }
			} );
		}

		if ( ! gallery.length ) {
			gallery = [ { mediaId: mediaId, imgSrc: imgSrc, title: title, permalink: permalink } ];
		}

		openLightbox( mediaId, imgSrc, title, permalink, gallery, clickIdx );
	} );

	/* ── Activity stream media click delegate ── */
	/* Handles .mvs-activity-media[data-mvs-media-id] — both native MVS activities  */
	/* and transformed legacy rtMedia/MediaPress/BuddyBoss activities.               */
	document.addEventListener( 'click', function( e ) {
		var link = e.target.closest( 'a' );
		if ( ! link ) { return; }

		var wrapper = link.closest( '.mvs-activity-media[data-mvs-media-id]' );
		if ( ! wrapper ) { return; }

		// Skip document type; open images, videos, and audio in the lightbox.
		if ( wrapper.classList.contains( 'mvs-activity-media--document' ) ) { return; }

		var mediaId = parseInt( wrapper.dataset.mvsMediaId, 10 );
		if ( ! mediaId ) { return; }

		e.preventDefault();
		e.stopPropagation();

		/* Resolve media type and sources for the clicked item. */
		function wrapperToItem( w ) {
			var mid      = parseInt( w.dataset.mvsMediaId, 10 );
			var aEl      = w.querySelector( 'a' );
			var aImg     = aEl ? aEl.querySelector( 'img' ) : null;
			var mediaSrc = w.dataset.mvsSrc || '';
			var mType    = 'image';
			if ( w.classList.contains( 'mvs-activity-media--video' ) )    { mType = 'video'; }
			if ( w.classList.contains( 'mvs-activity-media--audio' ) )    { mType = 'audio'; }
			return {
				mediaId:   mid,
				mediaType: mType,
				mediaSrc:  mediaSrc,
				imgSrc:    aImg ? aImg.src : ( 'image' === mType ? ( aEl ? aEl.href : '' ) : '' ),
				title:     aImg ? ( aImg.alt || '' ) : ( aEl ? ( aEl.textContent.trim() || '' ) : '' ),
				permalink: ( aEl && aEl.dataset.mvsPermalink ) ? aEl.dataset.mvsPermalink : ( aEl ? aEl.href : '' )
			};
		}

		var clickedItem = wrapperToItem( wrapper );

		// Build gallery from all wrappers (image + video + audio) in the same grid.
		var gridContainer = wrapper.closest( '.mvs-activity-media-grid' );
		var gallery  = [];
		var clickIdx = 0;

		if ( gridContainer ) {
			var allWrappers = gridContainer.querySelectorAll( '.mvs-activity-media[data-mvs-media-id]' );
			allWrappers.forEach( function( w ) {
				if ( w.classList.contains( 'mvs-activity-media--document' ) ) { return; }
				var item = wrapperToItem( w );
				gallery.push( item );
				if ( item.mediaId === mediaId ) { clickIdx = gallery.length - 1; }
			} );
		}

		if ( ! gallery.length ) {
			gallery = [ clickedItem ];
		}

		openLightbox( mediaId, clickedItem.imgSrc, clickedItem.title, clickedItem.permalink, gallery, clickIdx );
	} );

} )();
