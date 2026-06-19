/**
 * Interactivity API store: explore page tag cloud.
 *
 * Replaces assets/js/mvs-explore.js (52 lines).
 * Server-rendered grid + WP pagination remain untouched (SEO).
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

store( 'mvs/explore', {
	state: {
		tags: [],
		loaded: false,
	},
	actions: {},
	callbacks: {
		async init() {
			const ctx = getContext();
			if ( ! ctx.restUrl ) {
				return;
			}
			try {
				const res = await window.mvsRest.restFetch(
					ctx.restUrl + 'tags/cloud?limit=20'
				);
				const data = res.data;
				if ( Array.isArray( data ) ) {
					ctx.tags = data.map( ( tag ) => ( {
						name: tag.name || '',
						slug: tag.slug || '',
						href: ctx.archiveUrl +
							( ctx.archiveUrl.indexOf( '?' ) !== -1 ? '&' : '?' ) +
							'mvs_tag=' + encodeURIComponent( tag.slug || '' ),
						active: ctx.activeTag === ( tag.slug || '' ),
					} ) );
				}
			} catch {
				// Silently fail.
			}
			ctx.loaded = true;
		},
	},
} );
