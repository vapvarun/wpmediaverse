/**
 * syncEvents(): mark the store actions that need the event synchronously.
 *
 * From WordPress 6.8 the Interactivity API hands store actions their event
 * after yielding to the browser, so event.preventDefault() and
 * event.stopPropagation() must run in an action wrapped with withSyncEvent(),
 * or they stop working in a later release. Wrapping by hand is a list to keep
 * up to date; this wraps, by rule, every action whose own code calls one of
 * those methods, and leaves the rest async. On WordPress before 6.8 there is
 * no withSyncEvent and every action already gets its event synchronously, so
 * the actions are returned unchanged.
 *
 * Usage: store( 'ns', { actions: syncEvents( { ... } ) } ).
 *
 * @package WPMediaVerse
 */
import * as iapi from '@wordpress/interactivity';

const SYNC_CALL = /\.\s*(?:preventDefault|stopPropagation|stopImmediatePropagation)\s*\(/;

/**
 * Wrap the actions that call a synchronous event method.
 *
 * @param {Object} actions Store actions (nested groups are handled too).
 * @return {Object} The same actions, those that need it wrapped.
 */
export function syncEvents( actions ) {
	const wrap = 'function' === typeof iapi.withSyncEvent ? iapi.withSyncEvent : null;
	if ( ! wrap || ! actions || 'object' !== typeof actions ) {
		return actions;
	}
	const out = {};
	Object.keys( actions ).forEach( ( key ) => {
		const value = actions[ key ];
		if ( 'function' === typeof value ) {
			out[ key ] = SYNC_CALL.test( Function.prototype.toString.call( value ) ) ? wrap( value ) : value;
		} else if ( value && 'object' === typeof value && ! Array.isArray( value ) ) {
			out[ key ] = syncEvents( value );
		} else {
			out[ key ] = value;
		}
	} );
	return out;
}
