/**
 * WPMediaVerse — Single Media Social Interactions
 *
 * Handles reactions, favorites, comments, and share on single media pages.
 * Uses safe DOM methods (createElement/textContent) — no innerHTML with user data.
 *
 * @package WPMediaVerse
 */

( function () {
	'use strict';

	var mediaId = window.mvsMedia ? window.mvsMedia.id : 0;
	var restBase = window.mvsMedia ? window.mvsMedia.restUrl : '';
	var nonce = window.mvsMedia ? window.mvsMedia.nonce : '';
	var isLoggedIn = window.mvsMedia ? window.mvsMedia.isLoggedIn : false;

	if ( ! mediaId || ! restBase ) {
		return;
	}

	var headers = {
		'Content-Type': 'application/json',
		'X-WP-Nonce': nonce,
	};

	/* =====================================================================
	   Reactions
	   ===================================================================== */

	var reactionTypes = {
		like: '\u{1F44D}',
		love: '\u{2764}\u{FE0F}',
		haha: '\u{1F602}',
		wow: '\u{1F62E}',
		sad: '\u{1F622}',
		angry: '\u{1F621}',
	};

	function initReactions() {
		var container = document.querySelector( '.mvs-reactions' );
		if ( ! container ) {
			return;
		}

		fetch( restBase + 'media/' + mediaId + '/reactions', {
			credentials: 'same-origin',
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				renderReactions( container, data );
			} );
	}

	function renderReactions( container, data ) {
		// Clear existing children safely.
		while ( container.firstChild ) {
			container.removeChild( container.firstChild );
		}

		Object.keys( reactionTypes ).forEach( function ( type ) {
			var count =
				data.counts && data.counts[ type ] ? data.counts[ type ] : 0;
			var isActive = data.user_reaction === type;

			var btn = document.createElement( 'button' );
			btn.className = 'mvs-reaction-btn' + ( isActive ? ' active' : '' );
			btn.setAttribute( 'data-type', type );

			var emoji = document.createElement( 'span' );
			emoji.className = 'mvs-reaction-emoji';
			emoji.textContent = reactionTypes[ type ];
			btn.appendChild( emoji );

			var countEl = document.createElement( 'span' );
			countEl.className = 'mvs-count';
			countEl.textContent = count;
			btn.appendChild( countEl );

			btn.addEventListener( 'click', function () {
				if ( ! isLoggedIn ) {
					return;
				}
				handleReactionClick( type, isActive );
			} );

			container.appendChild( btn );
		} );
	}

	function handleReactionClick( type, wasActive ) {
		if ( wasActive ) {
			fetch( restBase + 'media/' + mediaId + '/reactions', {
				method: 'DELETE',
				headers: headers,
				credentials: 'same-origin',
			} ).then( function () {
				initReactions();
			} );
		} else {
			fetch( restBase + 'media/' + mediaId + '/reactions', {
				method: 'POST',
				headers: headers,
				credentials: 'same-origin',
				body: JSON.stringify( { reaction_type: type } ),
			} ).then( function () {
				initReactions();
			} );
		}
	}

	/* =====================================================================
	   Favorite
	   ===================================================================== */

	function initFavorite() {
		var btn = document.querySelector( '.mvs-favorite-btn' );
		if ( ! btn || ! isLoggedIn ) {
			return;
		}

		fetch( restBase + 'me/favorites?per_page=100', {
			headers: headers,
			credentials: 'same-origin',
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				var isFav = Array.isArray( data )
					? data.some( function ( f ) {
							return f.media_id === mediaId;
					  } )
					: false;
				if ( isFav ) {
					btn.classList.add( 'active' );
				}
			} );

		btn.addEventListener( 'click', function () {
			var isFav = btn.classList.contains( 'active' );
			var method = isFav ? 'DELETE' : 'POST';

			fetch( restBase + 'media/' + mediaId + '/favorite', {
				method: method,
				headers: headers,
				credentials: 'same-origin',
			} ).then( function ( r ) {
				if ( r.ok ) {
					btn.classList.toggle( 'active' );
				}
			} );
		} );
	}

	/* =====================================================================
	   Comments
	   ===================================================================== */

	function initComments() {
		var section = document.querySelector( '.mvs-comments-section' );
		if ( ! section ) {
			return;
		}

		loadComments( section );

		var form = section.querySelector( '.mvs-comment-form' );
		if ( form && isLoggedIn ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var textarea = form.querySelector( 'textarea' );
				var content = textarea.value.trim();
				if ( ! content ) {
					return;
				}

				fetch( restBase + 'media/' + mediaId + '/comments', {
					method: 'POST',
					headers: headers,
					credentials: 'same-origin',
					body: JSON.stringify( { content: content } ),
				} )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function () {
						textarea.value = '';
						loadComments( section );
					} );
			} );
		}
	}

	function loadComments( section ) {
		var list = section.querySelector( '.mvs-comment-list' );
		if ( ! list ) {
			return;
		}

		fetch( restBase + 'media/' + mediaId + '/comments?per_page=20', {
			credentials: 'same-origin',
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				// Clear existing children safely.
				while ( list.firstChild ) {
					list.removeChild( list.firstChild );
				}

				if ( ! Array.isArray( data ) || data.length === 0 ) {
					var empty = document.createElement( 'li' );
					empty.className = 'mvs-comment-item mvs-no-comments';
					empty.textContent = 'No comments yet.';
					list.appendChild( empty );
					return;
				}

				data.forEach( function ( c ) {
					var li = document.createElement( 'li' );
					li.className = 'mvs-comment-item';

					var author = document.createElement( 'span' );
					author.className = 'mvs-comment-author';
					author.textContent = c.author_name || 'Anonymous';
					li.appendChild( author );

					var date = document.createElement( 'span' );
					date.className = 'mvs-comment-date';
					date.textContent = new Date( c.date ).toLocaleDateString();
					li.appendChild( date );

					var text = document.createElement( 'div' );
					text.className = 'mvs-comment-text';
					text.textContent = c.content;
					li.appendChild( text );

					list.appendChild( li );
				} );
			} );
	}

	/* =====================================================================
	   View Count & Stats
	   ===================================================================== */

	function initStats() {
		var el = document.querySelector( '.mvs-view-count' );
		if ( ! el ) {
			return;
		}

		// Record a view.
		fetch( restBase + 'media/' + mediaId + '/view', {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
		} );

		// Load stats.
		fetch( restBase + 'media/' + mediaId + '/stats', {
			credentials: 'same-origin',
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				if ( data && data.views !== undefined ) {
					el.textContent = data.views + ' views';
				}
			} );
	}

	/* =====================================================================
	   Share
	   ===================================================================== */

	function initShare() {
		var btn = document.querySelector( '.mvs-share-btn' );
		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var url = window.location.href;
			var title = document.title;

			if ( navigator.share ) {
				navigator.share( { title: title, url: url } );
			} else if ( navigator.clipboard ) {
				navigator.clipboard.writeText( url ).then( function () {
					btn.textContent = '\u2713 Copied!';
					setTimeout( function () {
						btn.textContent = '\u{1F517} Share';
					}, 2000 );
				} );
			}
		} );
	}

	/* =====================================================================
	   Init
	   ===================================================================== */

	document.addEventListener( 'DOMContentLoaded', function () {
		initReactions();
		initFavorite();
		initComments();
		initStats();
		initShare();
	} );
} )();
