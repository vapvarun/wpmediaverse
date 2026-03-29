/**
 * Interactivity API store for the media-grid block.
 *
 * NOTE: Lightbox is handled by the shared-ui store (mvs/shared-ui).
 * Grid items use data-wp-interactive="mvs/shared-ui" with
 * data-wp-on--click="actions.openLightbox" and a context
 * containing mediaId, restUrl, nonce.
 *
 * This block store is kept minimal — only block-specific behaviors
 * (filter toggles, layout switches, etc.) belong here.
 *
 * @package WPMediaVerse
 */

import { store } from '@wordpress/interactivity';

store( 'mvs/media-grid', {
	// Block-specific actions (not lightbox — that's in shared-ui).
	actions: {},
} );
