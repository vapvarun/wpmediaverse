/**
 * Interactivity API store for the media-grid block.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

store( 'mvs/media-grid', {
	actions: {
		openLightbox( event ) {
			event.stopPropagation();
			const itemCtx = getContext();
			// Walk up to find the block-level context.
			const blockEl = event.target.closest( '[data-wp-interactive="mvs/media-grid"]' );
			if ( blockEl && itemCtx.url ) {
				const ctx = getContext();
				ctx.lightboxOpen = true;
				ctx.lightboxUrl = itemCtx.url;
				ctx.lightboxTitle = itemCtx.title || '';
				document.body.style.overflow = 'hidden';
			}
		},
		closeLightbox() {
			const ctx = getContext();
			ctx.lightboxOpen = false;
			ctx.lightboxUrl = '';
			ctx.lightboxTitle = '';
			document.body.style.overflow = '';
		},
		handleLightboxKey( event ) {
			if ( event.key === 'Escape' ) {
				const ctx = getContext();
				ctx.lightboxOpen = false;
				ctx.lightboxUrl = '';
				document.body.style.overflow = '';
			}
		},
	},
} );
