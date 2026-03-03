<?php
/**
 * REST API rate limiter.
 *
 * Uses transients for per-user/IP rate limiting on write endpoints.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\REST;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * REST API rate limiter using transients.
 */
class RateLimiter {

	/**
	 * Default requests per window.
	 */
	const DEFAULT_LIMIT = 60;

	/**
	 * Default window in seconds.
	 */
	const DEFAULT_WINDOW = 60;

	/**
	 * Check if the current request is rate limited.
	 *
	 * @param string $action  Action identifier (e.g. 'media_create').
	 * @param int    $limit   Max requests per window.
	 * @param int    $window  Window in seconds.
	 * @return true|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public static function check( string $action, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW ) {
		$key = self::get_key( $action );

		$data = get_transient( $key );

		if ( false === $data ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		// Reset if window expired.
		if ( ( time() - $data['start'] ) >= $window ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		++$data['count'];

		if ( $data['count'] > $limit ) {
			$retry_after = $window - ( time() - $data['start'] );
			return new WP_Error(
				'mvs_rate_limited',
				__( 'Too many requests. Please try again later.', 'wpmediaverse' ),
				array(
					'status'      => 429,
					'retry_after' => max( 1, $retry_after ),
				)
			);
		}

		set_transient( $key, $data, $window );

		return true;
	}

	/**
	 * Build a transient key from action + user/IP.
	 *
	 * @param string $action Action identifier.
	 * @return string
	 */
	private static function get_key( string $action ): string {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$identifier = 'u' . $user_id;
		} else {
			$identifier = 'ip' . md5( self::get_client_ip() );
		}
		return 'mvs_rl_' . $action . '_' . $identifier;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private static function get_client_ip(): string {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '127.0.0.1';
	}
}
