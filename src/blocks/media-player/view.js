/**
 * Interactivity API store for the media-player block.
 *
 * Tracks a single view on init and, when the Pro plugin is active, mirrors
 * play/pause/seek/complete events to the Pro analytics endpoint. The render
 * only populates `analyticsUrl` + `sessionId` when Pro is detected, so all
 * analytics actions short-circuit for Free-only installs.
 *
 * @package WPMediaVerse
 */

import { store, getContext } from '@wordpress/interactivity';

/**
 * Fire-and-forget POST to the Pro analytics events endpoint. Swallows network
 * and parsing errors — analytics must never break playback.
 *
 * @param {Object} ctx         Interactivity context.
 * @param {string} eventType   One of play|pause|seek|complete.
 * @param {Event}  domEvent    The DOM media event (carries target.currentTime / duration).
 */
function postAnalyticsEvent( ctx, eventType, domEvent ) {
	if ( ! ctx.analyticsUrl || ! ctx.sessionId ) {
		return;
	}
	const media = domEvent && domEvent.target ? domEvent.target : null;
	const position = media && isFinite( media.currentTime ) ? media.currentTime : 0;
	const duration = media && isFinite( media.duration ) ? media.duration : 0;
	try {
		fetch( ctx.analyticsUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ctx.nonce,
			},
			credentials: 'same-origin',
			body: JSON.stringify( {
				event_type: eventType,
				position,
				duration,
				session_id: ctx.sessionId,
			} ),
		} ).catch( () => {} );
	} catch ( err ) {
		// Non-critical — analytics must never throw into playback.
	}
}

store( 'mvs/media-player', {
	actions: {
		onPlay( event ) {
			const ctx = getContext();
			ctx.playing = true;
			postAnalyticsEvent( ctx, 'play', event );
		},
		onPause( event ) {
			const ctx = getContext();
			ctx.playing = false;
			postAnalyticsEvent( ctx, 'pause', event );
		},
		onSeek( event ) {
			postAnalyticsEvent( getContext(), 'seek', event );
		},
		onComplete( event ) {
			postAnalyticsEvent( getContext(), 'complete', event );
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
