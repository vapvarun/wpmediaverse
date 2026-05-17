<?php
/**
 * Opt-in telemetry service — counter-only, local-only, anonymous.
 *
 * Purpose: turn "we are guessing which code paths customers actually use"
 * into "we have 30 days of counter data per hook/route/CLI command across
 * N sites." Without this, every cleanup decision in 1.2.4+ is speculation.
 *
 * Hard guarantees:
 *
 *   1. DISABLED by default. capture() is a no-op when
 *      get_option('mvs_telemetry_enabled') is falsy. Admin opts in
 *      explicitly via the Storage tab setting.
 *   2. NO transmission. Counters live in a single wp_options row on the
 *      customer's site. We never POST anything anywhere.
 *   3. NO PII. Captured events are short event-name strings + integer
 *      counters. No user IDs, URLs, file paths, IPs, or content.
 *   4. CARDINALITY-BOUNDED. The counter map is capped at 500 distinct
 *      event names — over the cap, new events are dropped silently
 *      (and we log a single warning). Protects wp_options from a
 *      runaway instrumentation bug.
 *   5. Counters are integer-only. capture('event', $context) increments
 *      by 1; $context is informational (logged at debug level when the
 *      site enables WP_DEBUG_LOG), not persisted.
 *
 * What this PR ships: the SERVICE + the SETTING. No callsites yet —
 * instrumentation lands incrementally as 1.2.4+ PRs add capture() calls
 * at the hooks/routes/commands we want to measure. By the time a customer
 * site has 30 days of data, the answers to "is hook X still used?" are
 * mechanical.
 *
 * @package WPMediaVerse
 * @since   1.2.3
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Telemetry service — opt-in, counter-only, local-only.
 */
class TelemetryService {

	public const SETTING_KEY     = 'mvs_telemetry_enabled';
	public const COUNTERS_OPTION = 'mvs_telemetry_counters';
	public const SINCE_OPTION    = 'mvs_telemetry_since';
	public const MAX_EVENTS      = 500;

	/**
	 * In-process cache — keeps capture() a single get_option lookup per
	 * request even on a site that calls it 100+ times.
	 *
	 * @var bool|null
	 */
	private static $enabled_cache = null;

	/**
	 * Record one occurrence of an event.
	 *
	 * No-op when telemetry is disabled (default). Cheap when enabled —
	 * one in-process counter increment per call; the wp_options write
	 * happens at shutdown so we never block a request.
	 *
	 * @param string $event   Short event name (e.g. 'mvs_media_uploaded',
	 *                        'rest:GET /media', 'cli:optimize-bulk').
	 *                        Must be ASCII + underscore + colon + slash;
	 *                        sanitized to a-z0-9_:/ via sanitize_key-style.
	 * @param array  $context Optional informational context (not stored).
	 *                        Logged to debug.log when WP_DEBUG_LOG=true.
	 * @return void
	 */
	public static function capture( string $event, array $context = array() ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$key = self::sanitize_event_key( $event );
		if ( '' === $key ) {
			return;
		}

		self::$pending[ $key ] = ( self::$pending[ $key ] ?? 0 ) + 1;

		// Lazy-register the shutdown flush so a request with zero capture()
		// calls pays nothing.
		if ( ! self::$flush_registered ) {
			add_action( 'shutdown', array( self::class, 'flush_to_storage' ), 99 );
			self::$flush_registered = true;
		}

		// Informational only — log context when debug is on.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && ! empty( $context ) ) {
			// Intentionally minimal — no objects, no full arrays.
			$ctx_summary = array_filter(
				$context,
				static function ( $v ) {
					return is_scalar( $v );
				}
			);
			if ( ! empty( $ctx_summary ) ) {
				LoggerService::info(
					'telemetry',
					'capture: ' . $key,
					$ctx_summary
				);
			}
		}
	}

	/**
	 * Whether telemetry is opted into.
	 *
	 * Cached per-request; re-reading on every capture() call would dominate
	 * hot-path cost on instrumented hooks.
	 */
	public static function is_enabled(): bool {
		if ( null !== self::$enabled_cache ) {
			return self::$enabled_cache;
		}
		self::$enabled_cache = (bool) get_option( self::SETTING_KEY, false );

		// First-enable bookkeeping: record when the customer opted in so
		// the admin view can show "data covers X days".
		if ( self::$enabled_cache && ! get_option( self::SINCE_OPTION ) ) {
			update_option( self::SINCE_OPTION, gmdate( 'Y-m-d\TH:i:s\Z' ), false );
		}

		return self::$enabled_cache;
	}

	/**
	 * Read the persisted counter map.
	 *
	 * @return array<string,int> Map of event_name => count.
	 */
	public static function get_counters(): array {
		$raw = get_option( self::COUNTERS_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Get the date telemetry was first enabled.
	 *
	 * @return string ISO timestamp or '' when never enabled.
	 */
	public static function get_collecting_since(): string {
		return (string) get_option( self::SINCE_OPTION, '' );
	}

	/**
	 * Reset all counters and the "since" date. Called from admin only.
	 *
	 * Caller must verify capability + nonce before invoking.
	 */
	public static function reset(): void {
		delete_option( self::COUNTERS_OPTION );
		delete_option( self::SINCE_OPTION );
		self::$pending          = array();
		self::$flush_registered = false;
		self::$enabled_cache    = null;
	}

	/**
	 * Pending counter increments for this request, flushed at shutdown.
	 *
	 * @var array<string,int>
	 */
	private static $pending = array();

	/**
	 * Whether we've already registered the shutdown handler.
	 *
	 * @var bool
	 */
	private static $flush_registered = false;

	/**
	 * Flush in-process counters to wp_options. Hooked at shutdown@99.
	 *
	 * Single get_option + single update_option per request, regardless of
	 * how many capture() calls fired. Bounded by MAX_EVENTS — once the
	 * persisted map has that many keys, new keys are dropped.
	 *
	 * Public only so the shutdown hook can reach it; do not call directly
	 * outside the class.
	 */
	public static function flush_to_storage(): void {
		if ( empty( self::$pending ) ) {
			return;
		}

		$current = self::get_counters();
		$dropped = 0;
		foreach ( self::$pending as $event => $delta ) {
			if ( isset( $current[ $event ] ) ) {
				$current[ $event ] += $delta;
			} elseif ( count( $current ) < self::MAX_EVENTS ) {
				$current[ $event ] = $delta;
			} else {
				++$dropped;
			}
		}

		// `autoload=no` — telemetry data is read on admin pages only, not
		// every page load. Keeping it out of autoload protects sites with
		// thousands of distinct event names from inflating every request.
		update_option( self::COUNTERS_OPTION, $current, false );

		if ( $dropped > 0 ) {
			LoggerService::warning(
				'telemetry',
				sprintf( 'Counter cap (%d) reached; dropped %d new event keys', self::MAX_EVENTS, $dropped ),
				array(
					'cap'     => self::MAX_EVENTS,
					'dropped' => $dropped,
				)
			);
		}

		self::$pending = array();
	}

	/**
	 * Normalize an event name to a safe persisted key.
	 *
	 * Allowed character class: a-z, 0-9, underscore, colon, slash, hyphen.
	 * Length capped at 80 chars. Returning '' signals "do not record."
	 */
	private static function sanitize_event_key( string $event ): string {
		$lower = strtolower( $event );
		$safe  = preg_replace( '/[^a-z0-9_:\/\-]/', '', $lower );
		if ( null === $safe || '' === $safe ) {
			return '';
		}
		if ( strlen( $safe ) > 80 ) {
			$safe = substr( $safe, 0, 80 );
		}
		return $safe;
	}
}
