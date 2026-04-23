/**
 * Layout-specific JS card builders for Load More.
 *
 * Each function takes a REST API media JSON object and returns a DOM element
 * matching the corresponding PHP template's card HTML structure.
 *
 * ALL builders use safe DOM methods (createElement, textContent, setAttribute)
 * — NO innerHTML.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */
( function () {
	'use strict';

	/* ── Helpers ─────────────────────────────────────────────────────────── */

	/**
	 * Shorthand for createElement.
	 *
	 * @param {string} tag
	 * @param {string} [className]
	 * @param {Object} [attrs]
	 * @return {HTMLElement}
	 */
	function el( tag, className, attrs ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( key ) {
				node.setAttribute( key, attrs[ key ] );
			} );
		}
		return node;
	}

	/**
	 * Create an SVG element with the given markup path.
	 *
	 * @param {string}  width
	 * @param {string}  height
	 * @param {string}  viewBox
	 * @param {Array}   children  Array of { tag, attrs } objects
	 * @param {Object}  [rootAttrs]
	 * @return {SVGElement}
	 */
	function svg( width, height, viewBox, children, rootAttrs ) {
		var ns = 'http://www.w3.org/2000/svg';
		var s = document.createElementNS( ns, 'svg' );
		s.setAttribute( 'width', width );
		s.setAttribute( 'height', height );
		s.setAttribute( 'viewBox', viewBox );
		s.setAttribute( 'aria-hidden', 'true' );
		if ( rootAttrs ) {
			Object.keys( rootAttrs ).forEach( function ( key ) {
				s.setAttribute( key, rootAttrs[ key ] );
			} );
		}
		children.forEach( function ( child ) {
			var c = document.createElementNS( ns, child.tag );
			if ( child.attrs ) {
				Object.keys( child.attrs ).forEach( function ( key ) {
					c.setAttribute( key, child.attrs[ key ] );
				} );
			}
			s.appendChild( c );
		} );
		return s;
	}

	/**
	 * Get the media ID from a REST item, handling both `id` and `media_id`.
	 *
	 * @param {Object} item
	 * @return {number}
	 */
	function getId( item ) {
		return item.media_id || item.id;
	}

	/**
	 * Format a number with locale separators (mimics number_format_i18n).
	 *
	 * @param {number} n
	 * @return {string}
	 */
	function fmtNum( n ) {
		return ( typeof n === 'number' ) ? n.toLocaleString() : '0';
	}

	/* ── Heart SVG (filled, 12x12) for Pinterest stats ───────────────── */

	function heartSvg12() {
		return svg( '12', '12', '0 0 24 24', [
			{
				tag: 'path',
				attrs: {
					d: 'M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z',
					fill: 'currentColor',
				},
			},
		] );
	}

	/* ── Comment SVG (12x12) for Pinterest stats ─────────────────────── */

	function commentSvg12() {
		return svg( '12', '12', '0 0 24 24', [
			{
				tag: 'path',
				attrs: {
					d: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
				},
			},
		], { fill: 'none', stroke: 'currentColor', 'stroke-width': '2' } );
	}

	/* ── Dribbble heart SVG (outline) ────────────────────────────────── */

	function dribbbleHeartSvg() {
		var ns = 'http://www.w3.org/2000/svg';
		var s = document.createElementNS( ns, 'svg' );
		s.setAttribute( 'xmlns', ns );
		s.setAttribute( 'viewBox', '0 0 24 24' );
		s.setAttribute( 'fill', 'none' );
		s.setAttribute( 'stroke', 'currentColor' );
		s.setAttribute( 'stroke-width', '2' );
		s.setAttribute( 'stroke-linecap', 'round' );
		s.setAttribute( 'stroke-linejoin', 'round' );
		s.setAttribute( 'aria-hidden', 'true' );
		var path = document.createElementNS( ns, 'path' );
		path.setAttribute( 'd', 'M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z' );
		s.appendChild( path );
		return s;
	}

	/* ── Dribbble eye SVG (outline) ──────────────────────────────────── */

	function dribbbleEyeSvg() {
		var ns = 'http://www.w3.org/2000/svg';
		var s = document.createElementNS( ns, 'svg' );
		s.setAttribute( 'xmlns', ns );
		s.setAttribute( 'viewBox', '0 0 24 24' );
		s.setAttribute( 'fill', 'none' );
		s.setAttribute( 'stroke', 'currentColor' );
		s.setAttribute( 'stroke-width', '2' );
		s.setAttribute( 'stroke-linecap', 'round' );
		s.setAttribute( 'stroke-linejoin', 'round' );
		s.setAttribute( 'aria-hidden', 'true' );
		var path = document.createElementNS( ns, 'path' );
		path.setAttribute( 'd', 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z' );
		s.appendChild( path );
		var circle = document.createElementNS( ns, 'circle' );
		circle.setAttribute( 'cx', '12' );
		circle.setAttribute( 'cy', '12' );
		circle.setAttribute( 'r', '3' );
		s.appendChild( circle );
		return s;
	}

	/* ── Flickr eye SVG (filled) ─────────────────────────────────────── */

	function flickrEyeSvg() {
		var ns = 'http://www.w3.org/2000/svg';
		var s = document.createElementNS( ns, 'svg' );
		s.setAttribute( 'xmlns', ns );
		s.setAttribute( 'viewBox', '0 0 24 24' );
		s.setAttribute( 'aria-hidden', 'true' );
		s.setAttribute( 'focusable', 'false' );
		var path = document.createElementNS( ns, 'path' );
		path.setAttribute( 'd', 'M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5C21.27 7.61 17 4.5 12 4.5zm0 12.5c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z' );
		s.appendChild( path );
		return s;
	}

	/* ── Flickr play SVG ─────────────────────────────────────────────── */

	function flickrPlaySvg() {
		var ns = 'http://www.w3.org/2000/svg';
		var s = document.createElementNS( ns, 'svg' );
		s.setAttribute( 'xmlns', ns );
		s.setAttribute( 'viewBox', '0 0 24 24' );
		s.setAttribute( 'aria-hidden', 'true' );
		s.setAttribute( 'focusable', 'false' );
		var path = document.createElementNS( ns, 'path' );
		path.setAttribute( 'd', 'M8 5v14l11-7z' );
		s.appendChild( path );
		return s;
	}

	/* ====================================================================
	 * 1. FREE GRID — matches TemplateHelpers::render_grid_item()
	 * ==================================================================== */

	/**
	 * Build a free grid card.
	 *
	 * Structure:
	 *   div.mvs-grid-item[data-media-id][data-media-type]
	 *     a.mvs-grid-item-link
	 *       img (thumbnail)
	 *       span.mvs-gallery-badge  (if gallery)
	 *       div.mvs-grid-item-overlay
	 *         div.mvs-grid-item-stats
	 *           span.mvs-grid-stat  (views / reactions / comments)
	 *     div.mvs-grid-item-info
	 *       img.mvs-grid-avatar
	 *       span.mvs-grid-item-author
	 *
	 * @param {Object} item  REST API media object.
	 * @return {HTMLElement}
	 */
	function grid( item ) {
		var mediaId   = getId( item );
		var title     = item.title || '';
		var thumbUrl  = item.thumbnail_url || '';
		var link      = item.link || '#';
		var mediaType = item.media_type || 'image';
		var stats     = item.stats || {};
		var author    = item.author_data || {};
		var isGallery = !! item.media_group;
		var groupCnt  = item.group_count || 0;

		var rootClass = 'mvs-grid-item' + ( isGallery ? ' mvs-grid-item--gallery' : '' );
		var root = el( 'div', rootClass, {
			'data-media-id': mediaId,
			'data-media-type': mediaType,
		} );

		// Owner-only per-item actions (delete). The container only renders
		// when the REST response marks `can_edit` true — the server is the
		// source of truth, the UI just surfaces what the user is allowed to
		// do. CSS hides the actions by default and fades them in on hover.
		if ( item.can_edit ) {
			var actions = el( 'div', 'mvs-grid-item-actions' );
			var delBtn = el( 'button', 'mvs-grid-item-action mvs-grid-item-action--danger mvs-media-delete-btn', {
				type: 'button',
				'data-media-id': mediaId,
				'aria-label': 'Delete media',
				title: 'Delete media',
			} );
			var delIcon = el( 'i', '', { 'data-lucide': 'trash-2' } );
			delIcon.setAttribute( 'aria-hidden', 'true' );
			delBtn.appendChild( delIcon );
			actions.appendChild( delBtn );
			root.appendChild( actions );
		}

		// Link wrapping image + overlay.
		var anchor = el( 'a', 'mvs-grid-item-link', { href: link } );

		// Thumbnail image.
		if ( thumbUrl ) {
			var img = el( 'img', '', {
				src: thumbUrl,
				alt: title,
				loading: 'lazy',
			} );
			anchor.appendChild( img );
		}

		// Gallery badge.
		if ( isGallery && groupCnt > 1 ) {
			var badge = el( 'span', 'mvs-gallery-badge', {
				title: groupCnt + ' photos',
			} );
			var dashicon = el( 'span', 'dashicons dashicons-images-alt2' );
			badge.appendChild( dashicon );
			badge.appendChild( document.createTextNode( ' ' + groupCnt ) );
			anchor.appendChild( badge );
		}

		// Stats overlay.
		var overlay = el( 'div', 'mvs-grid-item-overlay' );
		var statsWrap = el( 'div', 'mvs-grid-item-stats' );

		if ( stats.views ) {
			var viewStat = el( 'span', 'mvs-grid-stat' );
			viewStat.textContent = '\uD83D\uDC41\uFE0F ' + fmtNum( stats.views );
			statsWrap.appendChild( viewStat );
		}

		var reactStat = el( 'span', 'mvs-grid-stat' );
		reactStat.textContent = '\u2764\uFE0F ' + fmtNum( stats.reactions || 0 );
		statsWrap.appendChild( reactStat );

		var commentStat = el( 'span', 'mvs-grid-stat' );
		commentStat.textContent = '\uD83D\uDCAC ' + fmtNum( stats.comments || 0 );
		statsWrap.appendChild( commentStat );

		overlay.appendChild( statsWrap );
		anchor.appendChild( overlay );

		root.appendChild( anchor );

		// Author info row.
		if ( author.name ) {
			var info = el( 'div', 'mvs-grid-item-info' );

			if ( author.avatar ) {
				var avatar = el( 'img', 'mvs-grid-avatar', {
					src: author.avatar,
					alt: '',
					width: '24',
					height: '24',
				} );
				info.appendChild( avatar );
			}

			var authorSpan = el( 'span', 'mvs-grid-item-author' );
			authorSpan.textContent = author.name;
			info.appendChild( authorSpan );

			root.appendChild( info );
		}

		return root;
	}

	/* ====================================================================
	 * 2. PINTEREST — matches pinterest/feed.php card markup
	 * ==================================================================== */

	/**
	 * Build a Pinterest-layout card.
	 *
	 * Structure:
	 *   div.mvs-pinterest-card[data-media-id][role=button][tabindex=0]
	 *     div.mvs-pinterest-card__img-wrap
	 *       img
	 *     div.mvs-pinterest-card__body
	 *       p.mvs-pinterest-card__title
	 *       p.mvs-pinterest-card__desc
	 *       div.mvs-pinterest-card__footer
	 *         a.mvs-pinterest-card__author (stopPropagation click)
	 *           img.mvs-pinterest-card__author-avatar
	 *           span.mvs-pinterest-card__author-name
	 *         div.mvs-pinterest-card__stats
	 *           span.mvs-pinterest-card__stat  (heart svg + count)
	 *           span.mvs-pinterest-card__stat  (comment svg + count)
	 *
	 * @param {Object} item  REST API media object.
	 * @return {HTMLElement}
	 */
	function pinterest( item ) {
		var mediaId  = getId( item );
		var title    = item.title || '';
		var desc     = item.description || '';
		var thumbUrl = item.thumbnail_url || '';
		var stats    = item.stats || {};
		var author   = item.author_data || {};

		var root = el( 'div', 'mvs-pinterest-card', {
			'data-media-id': mediaId,
			role: 'button',
			tabindex: '0',
			'aria-label': title,
		} );

		// Image wrap.
		if ( thumbUrl ) {
			var imgWrap = el( 'div', 'mvs-pinterest-card__img-wrap' );
			var img = el( 'img', '', {
				src: thumbUrl,
				alt: title,
				loading: 'lazy',
			} );
			imgWrap.appendChild( img );
			root.appendChild( imgWrap );
		} else {
			var placeholder = el( 'div', 'mvs-pinterest-card__img-wrap mvs-pinterest-card__img-placeholder' );
			var camSpan = el( 'span', '', { 'aria-hidden': 'true' } );
			camSpan.textContent = '\uD83D\uDCF7';
			placeholder.appendChild( camSpan );
			root.appendChild( placeholder );
		}

		// Body.
		var body = el( 'div', 'mvs-pinterest-card__body' );

		if ( title ) {
			var titleP = el( 'p', 'mvs-pinterest-card__title' );
			titleP.textContent = title;
			body.appendChild( titleP );
		}

		if ( desc ) {
			var descP = el( 'p', 'mvs-pinterest-card__desc' );
			// Truncate to ~120 chars like the PHP $desc_preview.
			descP.textContent = desc.length > 120 ? desc.substring( 0, 120 ) + '\u2026' : desc;
			body.appendChild( descP );
		}

		// Footer: author + stats.
		var footer = el( 'div', 'mvs-pinterest-card__footer' );

		// Author link — stopPropagation so clicking doesn't open lightbox.
		if ( author.name ) {
			var authorLink = el( 'a', 'mvs-pinterest-card__author', {
				href: author.profile_url || '#',
				'aria-label': author.name,
			} );
			authorLink.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
			} );

			if ( author.avatar ) {
				var authorAvatar = el( 'img', 'mvs-pinterest-card__author-avatar', {
					src: author.avatar,
					alt: '',
					width: '24',
					height: '24',
				} );
				authorLink.appendChild( authorAvatar );
			}

			var authorName = el( 'span', 'mvs-pinterest-card__author-name' );
			authorName.textContent = author.name;
			authorLink.appendChild( authorName );
			footer.appendChild( authorLink );
		}

		// Stats.
		var statsDiv = el( 'div', 'mvs-pinterest-card__stats' );

		var likeStat = el( 'span', 'mvs-pinterest-card__stat' );
		likeStat.appendChild( heartSvg12() );
		likeStat.appendChild( document.createTextNode( '\n' + fmtNum( stats.reactions || 0 ) ) );
		statsDiv.appendChild( likeStat );

		var commentStatEl = el( 'span', 'mvs-pinterest-card__stat' );
		commentStatEl.appendChild( commentSvg12() );
		commentStatEl.appendChild( document.createTextNode( '\n' + fmtNum( stats.comments || 0 ) ) );
		statsDiv.appendChild( commentStatEl );

		footer.appendChild( statsDiv );
		body.appendChild( footer );
		root.appendChild( body );

		return root;
	}

	/* ====================================================================
	 * 3. FLICKR — matches flickr/feed.php card markup
	 * ==================================================================== */

	/**
	 * Build a Flickr-layout card.
	 *
	 * Structure:
	 *   div.mvs-flickr-item[data-media-id][style="flex-grow: ratio"]
	 *     img  (or div.mvs-flickr-item__placeholder)
	 *     span.mvs-flickr-item__video-badge  (if video)
	 *     div.mvs-flickr-item__overlay
	 *     div.mvs-flickr-item__info
	 *       span.mvs-flickr-item__title
	 *       div.mvs-flickr-item__meta
	 *         span.mvs-flickr-item__author
	 *         span.mvs-flickr-item__views (svg + count)
	 *     a.mvs-flickr-item__link
	 *
	 * @param {Object} item  REST API media object.
	 * @return {HTMLElement}
	 */
	function flickr( item ) {
		var mediaId   = getId( item );
		var title     = item.title || '';
		var thumbUrl  = item.thumbnail_url || '';
		var link      = item.link || '#';
		var mediaType = item.media_type || 'image';
		var stats     = item.stats || {};
		var author    = item.author_data || {};
		var w         = item.width || 4;
		var h         = item.height || 3;
		var ratio     = ( w / h ).toFixed( 2 );
		var isVideo   = mediaType === 'video';

		var rootClass = 'mvs-flickr-item' + ( isVideo ? ' mvs-flickr-item--video' : '' );
		var root = el( 'div', rootClass, {
			'data-media-id': mediaId,
		} );
		root.style.flexGrow = ratio;

		// Image.
		if ( thumbUrl ) {
			var img = el( 'img', '', {
				src: thumbUrl,
				alt: title,
				loading: 'lazy',
				decoding: 'async',
			} );
			root.appendChild( img );
		} else {
			root.appendChild( el( 'div', 'mvs-flickr-item__placeholder' ) );
		}

		// Video badge.
		if ( isVideo ) {
			var badge = el( 'span', 'mvs-flickr-item__video-badge', {
				'aria-label': 'Video',
			} );
			badge.appendChild( flickrPlaySvg() );
			root.appendChild( badge );
		}

		// Gradient overlay.
		root.appendChild( el( 'div', 'mvs-flickr-item__overlay', { 'aria-hidden': 'true' } ) );

		// Info section.
		var info = el( 'div', 'mvs-flickr-item__info', { 'aria-hidden': 'true' } );

		if ( title ) {
			var titleSpan = el( 'span', 'mvs-flickr-item__title' );
			titleSpan.textContent = title;
			info.appendChild( titleSpan );
		}

		var meta = el( 'div', 'mvs-flickr-item__meta' );

		if ( author.name ) {
			var authorSpan = el( 'span', 'mvs-flickr-item__author' );
			authorSpan.textContent = author.name;
			meta.appendChild( authorSpan );
		}

		if ( stats.views && stats.views > 0 ) {
			var viewsSpan = el( 'span', 'mvs-flickr-item__views' );
			viewsSpan.appendChild( flickrEyeSvg() );
			viewsSpan.appendChild( document.createTextNode( '\n' + fmtNum( stats.views ) ) );
			meta.appendChild( viewsSpan );
		}

		info.appendChild( meta );
		root.appendChild( info );

		// Clickable link.
		var anchor = el( 'a', 'mvs-flickr-item__link', {
			href: link,
			'aria-label': title || 'View media',
		} );
		root.appendChild( anchor );

		return root;
	}

	/* ====================================================================
	 * 4. DRIBBBLE — matches dribbble/feed.php card markup
	 * ==================================================================== */

	/**
	 * Build a Dribbble-layout card.
	 *
	 * Structure:
	 *   article.mvs-dribbble-card[data-media-id]
	 *     a.mvs-dribbble-card__image
	 *       img  (or div.mvs-dribbble-card__placeholder)
	 *       span.mvs-dribbble-card__play-badge  (if video)
	 *       div.mvs-dribbble-card__overlay
	 *         p.mvs-dribbble-card__overlay-title
	 *     footer.mvs-dribbble-card__footer
	 *       a.mvs-dribbble-card__author
	 *         img.mvs-dribbble-card__author-avatar
	 *         span.mvs-dribbble-card__author-name
	 *       div.mvs-dribbble-card__stats
	 *         span.mvs-dribbble-card__stat (heart svg + count)
	 *         span.mvs-dribbble-card__stat (eye svg + count)
	 *
	 * @param {Object} item  REST API media object.
	 * @return {HTMLElement}
	 */
	function dribbble( item ) {
		var mediaId   = getId( item );
		var title     = item.title || '';
		var thumbUrl  = item.thumbnail_url || '';
		var link      = item.link || '#';
		var mediaType = item.media_type || 'image';
		var stats     = item.stats || {};
		var author    = item.author_data || {};

		var root = el( 'article', 'mvs-dribbble-card', {
			'data-media-id': mediaId,
		} );

		// Image link.
		var imageLink = el( 'a', 'mvs-dribbble-card__image', {
			href: link,
			'aria-label': title,
		} );

		if ( thumbUrl ) {
			var img = el( 'img', '', {
				src: thumbUrl,
				alt: title,
				loading: 'lazy',
			} );
			imageLink.appendChild( img );

			if ( mediaType === 'video' ) {
				var playBadge = el( 'span', 'mvs-dribbble-card__play-badge', {
					'aria-hidden': 'true',
				} );
				playBadge.textContent = '\u25B6';
				imageLink.appendChild( playBadge );
			}
		} else {
			var placeholder = el( 'div', 'mvs-dribbble-card__placeholder', {
				'aria-hidden': 'true',
			} );
			var icon = el( 'span' );
			if ( mediaType === 'video' ) {
				icon.textContent = '\u25B6';
			} else if ( mediaType === 'audio' ) {
				icon.textContent = '\u266B';
			} else {
				icon.className = 'dashicons dashicons-media-default';
			}
			placeholder.appendChild( icon );
			imageLink.appendChild( placeholder );
		}

		// Overlay.
		var overlay = el( 'div', 'mvs-dribbble-card__overlay', { 'aria-hidden': 'true' } );
		if ( title ) {
			var overlayTitle = el( 'p', 'mvs-dribbble-card__overlay-title' );
			overlayTitle.textContent = title;
			overlay.appendChild( overlayTitle );
		}
		imageLink.appendChild( overlay );

		root.appendChild( imageLink );

		// Footer.
		var footer = el( 'footer', 'mvs-dribbble-card__footer' );

		if ( author.name ) {
			var authorLink = el( 'a', 'mvs-dribbble-card__author', {
				href: author.profile_url || '#',
			} );

			if ( author.avatar ) {
				var avatar = el( 'img', 'mvs-dribbble-card__author-avatar', {
					src: author.avatar,
					alt: author.name,
					width: '28',
					height: '28',
				} );
				authorLink.appendChild( avatar );
			}

			var nameSpan = el( 'span', 'mvs-dribbble-card__author-name' );
			nameSpan.textContent = author.name;
			authorLink.appendChild( nameSpan );
			footer.appendChild( authorLink );
		}

		// Stats.
		var statsDiv = el( 'div', 'mvs-dribbble-card__stats', {
			'aria-label': 'Stats',
		} );

		var likeStat = el( 'span', 'mvs-dribbble-card__stat', {
			title: 'Likes',
		} );
		likeStat.appendChild( dribbbleHeartSvg() );
		likeStat.appendChild( document.createTextNode( '\n' + fmtNum( stats.reactions || 0 ) ) );
		statsDiv.appendChild( likeStat );

		var viewStat = el( 'span', 'mvs-dribbble-card__stat', {
			title: 'Views',
		} );
		viewStat.appendChild( dribbbleEyeSvg() );
		viewStat.appendChild( document.createTextNode( '\n' + fmtNum( stats.views || 0 ) ) );
		statsDiv.appendChild( viewStat );

		footer.appendChild( statsDiv );
		root.appendChild( footer );

		return root;
	}

	/* ====================================================================
	 * 5. INSTAGRAM (simplified) — matches instagram/partials/feed-card.php
	 * ==================================================================== */

	/**
	 * Build a simplified Instagram-layout card for Load More.
	 *
	 * The full Instagram card is very complex (Interactivity API bindings,
	 * follow/like/favorite state, comment forms, gallery carousels, etc.).
	 * This builder produces a simplified version with:
	 *   - Header (avatar + author name)
	 *   - Image
	 *   - Stats footer (likes + comments)
	 *   - Caption
	 * And adds class `mvs-ig-card--loadmore` to distinguish from
	 * PHP-rendered cards.
	 *
	 * Structure:
	 *   article.mvs-ig-card.mvs-ig-card--loadmore[data-media-id]
	 *     div.mvs-ig-card-header
	 *       a.mvs-ig-card-author
	 *         img.mvs-ig-card-avatar
	 *         strong.mvs-ig-card-username
	 *     div.mvs-ig-card-media
	 *       img.mvs-ig-card-img
	 *     div.mvs-ig-actions
	 *       div.mvs-ig-actions-left
	 *         button.mvs-ig-action-btn (heart outline)
	 *         a.mvs-ig-action-btn (comment link)
	 *       div.mvs-ig-actions-right
	 *         button.mvs-ig-action-btn (bookmark outline)
	 *     div.mvs-ig-likes
	 *       strong (like count)
	 *     div.mvs-ig-caption
	 *       strong.mvs-ig-caption-author
	 *       span.mvs-ig-caption-text
	 *
	 * @param {Object} item  REST API media object.
	 * @return {HTMLElement}
	 */
	function instagram( item ) {
		var mediaId  = getId( item );
		var title    = item.title || '';
		var desc     = item.description || '';
		var thumbUrl = item.thumbnail_url || item.file_url || '';
		var link     = item.link || '#';
		var stats    = item.stats || {};
		var author   = item.author_data || {};

		var root = el( 'article', 'mvs-ig-card mvs-ig-card--loadmore', {
			'data-media-id': mediaId,
		} );

		// ── Header ──────────────────────────────────────────────────────
		var header = el( 'div', 'mvs-ig-card-header' );
		var authorLink = el( 'a', 'mvs-ig-card-author', {
			href: author.profile_url || '#',
		} );

		if ( author.avatar ) {
			var avatar = el( 'img', 'mvs-ig-card-avatar', {
				src: author.avatar,
				alt: author.name || '',
				width: '32',
				height: '32',
			} );
			authorLink.appendChild( avatar );
		}

		var username = document.createElement( 'strong' );
		username.className = 'mvs-ig-card-username';
		username.textContent = author.name || '';
		authorLink.appendChild( username );
		header.appendChild( authorLink );
		root.appendChild( header );

		// ── Media area ──────────────────────────────────────────────────
		var mediaWrap = el( 'div', 'mvs-ig-card-media' );

		if ( thumbUrl ) {
			var img = el( 'img', 'mvs-ig-card-img', {
				src: thumbUrl,
				alt: title,
				loading: 'lazy',
			} );
			mediaWrap.appendChild( img );
		}

		root.appendChild( mediaWrap );

		// ── Action bar ──────────────────────────────────────────────────
		var actions = el( 'div', 'mvs-ig-actions' );

		var actionsLeft = el( 'div', 'mvs-ig-actions-left' );

		// Heart button (outline).
		var heartBtn = el( 'button', 'mvs-ig-action-btn', { type: 'button' } );
		heartBtn.appendChild( svg( '24', '24', '0 0 24 24', [
			{
				tag: 'path',
				attrs: {
					d: 'M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z',
				},
			},
		], { fill: 'none', stroke: 'currentColor', 'stroke-width': '2' } ) );
		actionsLeft.appendChild( heartBtn );

		// Comment link.
		var commentLink = el( 'a', 'mvs-ig-action-btn', { href: link + '#comments' } );
		commentLink.appendChild( svg( '24', '24', '0 0 24 24', [
			{
				tag: 'path',
				attrs: {
					d: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
				},
			},
		], { fill: 'none', stroke: 'currentColor', 'stroke-width': '2' } ) );
		actionsLeft.appendChild( commentLink );

		actions.appendChild( actionsLeft );

		// Bookmark button (outline).
		var actionsRight = el( 'div', 'mvs-ig-actions-right' );
		var bookmarkBtn = el( 'button', 'mvs-ig-action-btn', { type: 'button' } );
		bookmarkBtn.appendChild( svg( '24', '24', '0 0 24 24', [
			{
				tag: 'path',
				attrs: {
					d: 'M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z',
				},
			},
		], { fill: 'none', stroke: 'currentColor', 'stroke-width': '2' } ) );
		actionsRight.appendChild( bookmarkBtn );
		actions.appendChild( actionsRight );

		root.appendChild( actions );

		// ── Like count ──────────────────────────────────────────────────
		var likes = stats.reactions || 0;
		if ( likes > 0 ) {
			var likesDiv = el( 'div', 'mvs-ig-likes' );
			var likesStrong = document.createElement( 'strong' );
			likesStrong.textContent = fmtNum( likes ) + ' like' + ( likes !== 1 ? 's' : '' );
			likesDiv.appendChild( likesStrong );
			root.appendChild( likesDiv );
		}

		// ── Caption ─────────────────────────────────────────────────────
		if ( author.name || desc ) {
			var caption = el( 'div', 'mvs-ig-caption' );

			if ( author.name ) {
				var captionAuthor = document.createElement( 'strong' );
				captionAuthor.className = 'mvs-ig-caption-author';
				captionAuthor.textContent = author.name;
				caption.appendChild( captionAuthor );
			}

			if ( desc ) {
				var space = document.createTextNode( ' ' );
				caption.appendChild( space );
				var captionText = el( 'span', 'mvs-ig-caption-text' );
				captionText.textContent = desc;
				caption.appendChild( captionText );
			}

			root.appendChild( caption );
		}

		return root;
	}

	/* ── Export ───────────────────────────────────────────────────────── */

	window.mvsCardBuilders = {
		grid: grid,
		pinterest: pinterest,
		flickr: flickr,
		dribbble: dribbble,
		instagram: instagram,
	};

} )();
