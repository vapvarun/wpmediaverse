<?php
/**
 * Shared REST permission guards.
 *
 * @package WPMediaVerse
 * @since   1.9.0
 */

namespace WPMediaVerse\REST;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WPMediaVerse\Core\Plugin;

/**
 * Cross-controller permission helpers.
 *
 * Application Passwords are minted by WordPress core and bypass any plugin
 * login gate, so block/ban enforcement on writes must live in the REST
 * permission callback — not only in the service/data layer. This guard is the
 * single place that decision is made, reused by every write route between two
 * members (comment, reaction, follow, ...).
 */
final class RestGuards {

	/**
	 * Deny a between-members write when either side has blocked the other.
	 *
	 * @since 1.9.0
	 *
	 * @param int $actor  Acting user ID (current user).
	 * @param int $target Target user ID (content owner / followed user).
	 * @return WP_Error|null WP_Error (403) when blocked, otherwise null.
	 */
	public static function deny_if_blocked( int $actor, int $target ): ?WP_Error {
		if ( $actor <= 0 || $target <= 0 || $actor === $target ) {
			return null;
		}

		$reports = Plugin::container()->get( 'reports' );
		if ( $reports->is_blocked_either_way( $actor, $target ) ) {
			return new WP_Error(
				'mvs_blocked',
				__( 'This action is unavailable between you and this member.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}

		return null;
	}
}
