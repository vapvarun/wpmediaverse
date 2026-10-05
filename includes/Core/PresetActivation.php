<?php
/**
 * One-time activation of the free preset licence key, with backoff.
 *
 * The free plugin activates a shared preset key so update downloads work. The
 * old hook posted to the store on every admin_init until the reply was 'valid':
 * any error, timeout, 403 or final "invalid" reply meant another POST on the
 * next admin request, and admin_init also fires on admin-ajax.php, so Heartbeat
 * repeated it every few minutes. One site sent 933 requests in 23 hours.
 *
 * Now it runs on real admin page loads only, waits RETRY_AFTER after any reply
 * the store did not answer, and treats every real store answer as final.
 *
 * @package WPMediaVerse
 * @since   2.6.1
 */

declare( strict_types=1 );

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded preset-key activation.
 */
final class PresetActivation {

	/** Store reply once known: 1 when valid, else the store's licence status. */
	public const OPT_DONE = 'wpmediaverse_preset_activated';

	/** Set while waiting after an unanswered attempt. */
	public const RETRY_TRANSIENT = 'wpmediaverse_preset_retry';

	/** Wait this long after a network error, 403 or non-JSON reply. */
	public const RETRY_AFTER = 12 * HOUR_IN_SECONDS;

	/** The baked-in free preset key. */
	private const PRESET_KEY = 'wbcomfree7a9c2e5d1f8b4c6a3e0d9b2f7c1a8e44';

	/** EDD item id for MediaVerse Free. */
	private const ITEM_ID = 1660826;

	/**
	 * Hook the attempt onto admin page loads.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_activate' ) );
	}

	/**
	 * Try the activation when it is due: not done, not waiting, and a real
	 * wp-admin page load (never admin-ajax/Heartbeat or cron).
	 *
	 * @return void
	 */
	public static function maybe_activate(): void {
		if ( get_option( self::OPT_DONE ) || wp_doing_ajax() || wp_doing_cron() || get_transient( self::RETRY_TRANSIENT ) ) {
			return;
		}

		update_option( 'wpmediaverse_license_key', self::PRESET_KEY, false );

		$response = wp_remote_post(
			'https://wbcomdesigns.com',
			array(
				'timeout' => 5,
				'body'    => array(
					'edd_action' => 'activate_license',
					'license'    => self::PRESET_KEY,
					'item_id'    => self::ITEM_ID,
					'url'        => home_url(),
				),
			)
		);

		$body   = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$status = is_array( $body ) ? (string) ( $body['license'] ?? '' ) : '';

		// No store answer (network error, firewall 403, HTML, 5xx): wait, then retry.
		if ( '' === $status ) {
			set_transient( self::RETRY_TRANSIENT, 1, self::RETRY_AFTER );
			return;
		}

		// A store answer is final: 'valid', or invalid/disabled/expired/
		// no_activations_left, which retrying cannot change.
		update_option( self::OPT_DONE, 'valid' === $status ? 1 : $status, false );
	}
}
