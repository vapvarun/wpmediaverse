/**
 * Interactivity API store for the lock-overlay block.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

store( 'mvs/lock-overlay', {
	actions: {
		*initiateCheckout() {
			const ctx = getContext();

			if ( ctx.loading ) {
				return;
			}

			ctx.loading = true;
			ctx.message = '';

			try {
				const response = yield fetch(
					`${ window.wpApiSettings?.root || '/wp-json/' }mvs/v1/checkout`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': window.wpApiSettings?.nonce || '',
						},
						body: JSON.stringify( {
							media_id: ctx.mediaId,
						} ),
					}
				);

				const data = yield response.json();

				if ( ! response.ok ) {
					ctx.message = data.message || 'Checkout failed.';
					ctx.loading = false;
					return;
				}

				if ( data.redirect_url ) {
					window.location.href = data.redirect_url;
					return;
				}

				if ( data.granted ) {
					ctx.message = data.message || 'Access granted!';
					ctx.hasAccess = true;
					window.location.reload();
					return;
				}

				ctx.message = data.message || 'Processing...';
			} catch ( err ) {
				ctx.message = 'An error occurred. Please try again.';
			}

			ctx.loading = false;
		},
	},
} );
