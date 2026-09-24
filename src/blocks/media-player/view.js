/**
 * Interactivity API store for the media-player block.
 *
 * Tracks a single view on init and, when the Pro plugin is active, mirrors
 * play/pause/seek/complete events to the Pro analytics endpoint. The render
 * only populates `analyticsUrl` + `sessionId` when Pro is detected, so all
 * analytics actions short-circuit for Free-only installs. Also drives resume
 * playback (Pro, signed-in members, videos over 2 minutes) — the render only
 * populates `resumeUrl` under the same conditions, so all resume actions
 * short-circuit otherwise.
 *
 * i18n: this is a script MODULE, so window.wp.i18n.__() is English-locked
 * here. The "Resumed at" chip prefix is PHP-translated and injected into
 * interactivity state by TemplateHelpers::media_player_i18n_state() via
 * wp_interactivity_state(); read as `state.i18n.resumedAtPrefix` with an
 * English fallback.
 *
 * @package WPMediaVerse
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

// A video shorter than this never offers resume — card requirement "only for
// videos over 2 minutes".
const RESUME_MIN_DURATION = 120;
// Ignore a saved/observed position this close to the start — nothing to resume.
const RESUME_MIN_POSITION = 5;
// "Not nearly finished" — matches the server's own auto-clear threshold in
// ResumeService, kept in lockstep here so the chip never offers a position
// the server has already dropped.
const RESUME_NEAR_END_RATIO = 0.95;
// Throttle floor between position saves fired from timeupdate.
const RESUME_SAVE_THROTTLE_MS = 15000;
// How long the "Resumed at ..." chip stays up before auto-hiding.
const RESUME_CHIP_HIDE_MS = 6000;

// Per-instance resume bookkeeping that must NOT be reactive context (save
// throttling, the auto-hide timer, a one-time "already checked" flag).
// WeakMap keyed by the Interactivity context object mirrors the pattern in
// explore-feed's view.js (searchTimers).
const resumeMeta = new WeakMap();

// Players that have an eligible resumeUrl, tracked so a page/tab unload can
// flush the current position for whichever of them is mid-playback.
// ponytail: entries are never removed on unmount; negligible for the normal
// case of one or a few players per page — revisit if client-side navigation
// starts accumulating many detached instances in one session.
const activeResumePlayers = new Set();

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
		window.mvsRest.restFetch( ctx.analyticsUrl, {
			method: 'POST',
			body: {
				event_type: eventType,
				position,
				duration,
				session_id: ctx.sessionId,
			},
		} ).catch( () => {} );
	} catch ( err ) {
		// Non-critical — analytics must never throw into playback.
	}
}

/**
 * Format a seconds offset as "M:SS", or "H:MM:SS" once it reaches an hour.
 *
 * @param {number} seconds
 * @return {string}
 */
function formatResumeTime( seconds ) {
	const total = Math.max( 0, Math.floor( seconds ) );
	const hours = Math.floor( total / 3600 );
	const minutes = Math.floor( ( total % 3600 ) / 60 );
	const secs = total % 60;
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return hours > 0
		? `${ hours }:${ pad( minutes ) }:${ pad( secs ) }`
		: `${ minutes }:${ pad( secs ) }`;
}

/**
 * Build the chip's accessible label, e.g. "Resumed at 7:30".
 *
 * @param {Object} state   Store state (carries the PHP-seeded i18n prefix).
 * @param {number} seconds Resume position in seconds.
 * @return {string}
 */
function buildResumeLabel( state, seconds ) {
	const prefix = ( state.i18n && state.i18n.resumedAtPrefix ) || 'Resumed at';
	return `${ prefix } ${ formatResumeTime( seconds ) }`;
}

/**
 * True when `media` is long enough and far enough along to be worth saving —
 * shared by the pause, timeupdate and unload save paths.
 *
 * @param {HTMLMediaElement} media
 * @return {boolean}
 */
function isResumeSaveWorthy( media ) {
	return !! media
		&& isFinite( media.duration ) && media.duration > RESUME_MIN_DURATION
		&& isFinite( media.currentTime ) && media.currentTime > RESUME_MIN_POSITION;
}

/**
 * Fire-and-forget POST of the current position. Never blocks playback.
 *
 * @param {Object} ctx      Interactivity context.
 * @param {number} position Seconds.
 */
function saveResumePosition( ctx, position ) {
	if ( ! ctx.resumeUrl ) {
		return;
	}
	try {
		window.mvsRest.restFetch( ctx.resumeUrl, {
			method: 'POST',
			body: { position },
		} ).catch( () => {} );
	} catch ( err ) {
		// Non-critical — resume saves must never throw into playback.
	}
}

/**
 * Fire-and-forget DELETE of the saved position (video ended / start over).
 *
 * @param {Object} ctx Interactivity context.
 */
function clearResumePosition( ctx ) {
	if ( ! ctx.resumeUrl ) {
		return;
	}
	try {
		window.mvsRest.restFetch( ctx.resumeUrl, { method: 'DELETE' } ).catch( () => {} );
	} catch ( err ) {
		// Non-critical.
	}
}

/**
 * Hide the "Resumed at ..." chip and clear any pending auto-hide timer.
 *
 * @param {Object} ctx Interactivity context.
 */
function hideResumeChip( ctx ) {
	ctx.resumeShown = false;
	const meta = resumeMeta.get( ctx );
	if ( meta && meta.hideTimer ) {
		clearTimeout( meta.hideTimer );
		meta.hideTimer = null;
	}
}

/**
 * Show the chip with a formatted label and schedule its auto-hide.
 *
 * @param {Object} state   Store state.
 * @param {Object} ctx     Interactivity context.
 * @param {number} seconds Resume position in seconds.
 */
function showResumeChip( state, ctx, seconds ) {
	ctx.resumeLabel = buildResumeLabel( state, seconds );
	ctx.resumeShown = true;
	const meta = resumeMeta.get( ctx ) || {};
	if ( meta.hideTimer ) {
		clearTimeout( meta.hideTimer );
	}
	meta.hideTimer = setTimeout( () => {
		ctx.resumeShown = false;
	}, RESUME_CHIP_HIDE_MS );
	resumeMeta.set( ctx, meta );
}

/**
 * Synchronous-as-possible save used on pagehide / tab-hide, when a normal
 * fetch() may be cancelled before it lands. `restFetch()` doesn't expose
 * `keepalive`, so this bypasses it for a plain fetch carrying the same nonce
 * the store already has in context.
 *
 * @param {Object} ctx      Interactivity context.
 * @param {number} position Seconds.
 */
function keepaliveSaveResumePosition( ctx, position ) {
	if ( ! ctx.resumeUrl ) {
		return;
	}
	try {
		fetch( ctx.resumeUrl, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ctx.nonce || '',
			},
			body: JSON.stringify( { position } ),
		} ).catch( () => {} );
	} catch ( err ) {
		// Non-critical — best effort only.
	}
}

/**
 * On pagehide / tab-hide, flush the position for every tracked player that's
 * currently worth saving. Never blocks unload.
 */
function flushResumeOnHide() {
	activeResumePlayers.forEach( ( { ctx, media } ) => {
		if ( isResumeSaveWorthy( media ) ) {
			keepaliveSaveResumePosition( ctx, media.currentTime );
		}
	} );
}

if ( typeof document !== 'undefined' ) {
	window.addEventListener( 'pagehide', flushResumeOnHide );
	document.addEventListener( 'visibilitychange', () => {
		if ( document.visibilityState === 'hidden' ) {
			flushResumeOnHide();
		}
	} );
}

/**
 * Once a video's duration is known: register it for the unload flush and, for
 * a qualifying video, fetch the saved position and jump to it. Runs once per
 * player instance (from loadedmetadata, or from init when the metadata was
 * already loaded before the store hydrated - preload="metadata" often wins
 * that race, and the event is then never seen).
 *
 * @param {Object}           ctx   Interactivity context.
 * @param {HTMLMediaElement} media The <video>.
 */
async function applyResume( ctx, media ) {
	if ( ! ctx.resumeUrl ) {
		return;
	}
	const meta = resumeMeta.get( ctx ) || {};
	if ( meta.checked ) {
		return;
	}
	meta.checked = true;
	resumeMeta.set( ctx, meta );

	if ( ! media || ! isFinite( media.duration ) || media.duration <= RESUME_MIN_DURATION ) {
		return;
	}

	activeResumePlayers.add( { ctx, media } );

	try {
		const res = await window.mvsRest.restFetch( ctx.resumeUrl );
		if ( ! res.ok || ! res.data ) {
			return;
		}
		const position = Number( res.data.position );
		if ( ! isFinite( position ) || position <= RESUME_MIN_POSITION ) {
			return;
		}
		if ( position >= media.duration * RESUME_NEAR_END_RATIO ) {
			return;
		}
		media.currentTime = position;
		showResumeChip( state, ctx, position );
	} catch ( err ) {
		// Non-critical — never blocks playback.
	}
}

const { state } = store( 'mvs/media-player', {
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
			if ( isResumeSaveWorthy( event && event.target ) ) {
				saveResumePosition( ctx, event.target.currentTime );
			}
		},
		onSeek( event ) {
			postAnalyticsEvent( getContext(), 'seek', event );
		},
		onComplete( event ) {
			postAnalyticsEvent( getContext(), 'complete', event );
			const ctx = getContext();
			clearResumePosition( ctx );
			hideResumeChip( ctx );
		},
		/**
		 * Once the video's duration is known: register it for the unload
		 * flush and, for a qualifying video, fetch the saved position and
		 * jump to it. Runs once per player instance.
		 *
		 * @param {Event} event `loadedmetadata` — target is the <video>.
		 */
		onLoadedMetadata( event ) {
			applyResume( getContext(), event.target );
		},
		// data-wp-init on the <video>: catch metadata that loaded before hydration.
		initResume() {
			const { ref } = getElement();
			if ( ref && ref.readyState >= 1 ) {
				applyResume( getContext(), ref );
			}
		},
		/**
		 * Throttled position save fired from `timeupdate` while playing.
		 *
		 * @param {Event} event `timeupdate` — target is the <video>.
		 */
		onTimeUpdate( event ) {
			const ctx = getContext();
			if ( ! ctx.resumeUrl || ! ctx.playing ) {
				return;
			}
			const media = event.target;
			if ( ! isResumeSaveWorthy( media ) ) {
				return;
			}
			const meta = resumeMeta.get( ctx ) || {};
			const now = Date.now();
			if ( meta.lastSaveMs && now - meta.lastSaveMs < RESUME_SAVE_THROTTLE_MS ) {
				return;
			}
			meta.lastSaveMs = now;
			resumeMeta.set( ctx, meta );
			saveResumePosition( ctx, media.currentTime );
		},
		/**
		 * "Start over" — reset playback to 0, clear the saved position, hide
		 * the chip.
		 */
		onResumeStartOver() {
			const ctx = getContext();
			const root = getElement().ref.closest( '[data-wp-interactive]' );
			const media = root ? root.querySelector( 'video' ) : null;
			if ( media ) {
				media.currentTime = 0;
			}
			clearResumePosition( ctx );
			hideResumeChip( ctx );
		},
		async trackView() {
			const ctx = getContext();
			if ( ! ctx.restUrl ) return;
			try {
				await window.mvsRest.restFetch( ctx.restUrl, {
					method: 'POST',
				} );
			} catch ( err ) {
				// Non-critical — silently ignore.
			}
		},
	},
} );
