/**
 * Interactivity API store for the media-player block.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

store( 'mvs/media-player', {
	actions: {
		onPlay() {
			const ctx = getContext();
			ctx.playing = true;
		},
		onPause() {
			const ctx = getContext();
			ctx.playing = false;
		},
		async trackView() {
			const ctx = getContext();
			if ( ! ctx.restUrl ) return;
			try {
				await fetch( ctx.restUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': ctx.nonce,
					},
				} );
			} catch ( err ) {
				// Non-critical — silently ignore.
			}
		},
	},
} );
