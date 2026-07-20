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
 * single place that decision is made.
 *
 * These helpers are applied to every write route by RestGate, which consults a
 * declarative route map at dispatch time. Do not hand-wire them into new
 * controllers; classify the route in RestGate::map() instead, and the coverage
 * test will hold you to it.
 */
final class RestGuards {

	/**
	 * Per-request memo of block lookups, keyed "actor:target".
	 *
	 * A write-heavy request can consult the gate several times. The block table
	 * cannot change mid-request in a way that should flip a decision, so caching
	 * keeps the gate effectively free.
	 *
	 * @var array<string, bool>
	 */
	private static array $block_cache = array();

	/**
	 * Per-request memo of suspension lookups, keyed by user ID.
	 *
	 * @var array<int, bool>
	 */
	private static array $suspended_cache = array();

	/**
	 * Deny a between-members write when either side has blocked the other.
	 *
	 * Accepts several targets (a group invite, a battle with an opponent) and
	 * denies if *any* of them is blocked either way — you cannot pull someone
	 * who blocked you into a shared surface by naming several people at once.
	 *
	 * @since 1.9.0
	 *
	 * @param int       $actor  Acting user ID (current user).
	 * @param int|int[] $target Target user ID(s).
	 * @return WP_Error|null WP_Error (403) when blocked, otherwise null.
	 */
	public static function deny_if_blocked( int $actor, $target ): ?WP_Error {
		if ( $actor <= 0 ) {
			return null;
		}

		foreach ( (array) $target as $one ) {
			$one = (int) $one;

			if ( $one <= 0 || $one === $actor ) {
				continue;
			}

			$key = $actor . ':' . $one;

			if ( ! isset( self::$block_cache[ $key ] ) ) {
				/** @var \WPMediaVerse\Social\ReportService $reports */
				$reports                   = Plugin::container()->get( 'reports' );
				self::$block_cache[ $key ] = $reports->is_blocked_either_way( $actor, $one );
			}

			if ( self::$block_cache[ $key ] ) {
				return new WP_Error(
					'mvs_blocked',
					__( 'This action is unavailable between you and this member.', 'wpmediaverse' ),
					array( 'status' => 403 )
				);
			}
		}

		return null;
	}

	/**
	 * Deny any write by a suspended / spam-flagged member.
	 *
	 * This is the gap Application Passwords open. Core's
	 * wp_authenticate_application_password() does not run the `authenticate`
	 * filter chain, so wp_authenticate_spam_check() never fires for an App
	 * Password request: a multisite-spammed user, or a BuddyPress-marked
	 * spammer, keeps a fully working credential and can write to every route.
	 * A login gate cannot close this. Only a REST-side check can.
	 *
	 * Sources of truth, in order:
	 *  - `mvs_suspended` user meta (this plugin's own flag; unset everywhere on
	 *    upgrade, so no existing member is locked out by installing 2.1.0)
	 *  - multisite spam flag
	 *  - BuddyPress spammer flag
	 *  - the `mvs_user_is_suspended` filter, for third-party moderation plugins
	 *
	 * @since 2.1.0
	 *
	 * @param int $actor Acting user ID.
	 * @return WP_Error|null WP_Error (403) when suspended, otherwise null.
	 */
	public static function deny_if_suspended( int $actor ): ?WP_Error {
		if ( $actor <= 0 ) {
			return null;
		}

		if ( ! isset( self::$suspended_cache[ $actor ] ) ) {
			$suspended = (bool) get_user_meta( $actor, 'mvs_suspended', true );

			if ( ! $suspended && is_multisite() && function_exists( 'is_user_spammy' ) ) {
				// is_user_spammy() treats a non-WP_User arg as a login STRING, so
				// passing the int ID looked up a user whose login is that number
				// (never matches) and the multisite spam check silently no-opped.
				// Pass the WP_User so it actually enforces.
				$actor_user = get_userdata( $actor );
				$suspended  = $actor_user ? is_user_spammy( $actor_user ) : false;
			}

			if ( ! $suspended && function_exists( 'bp_is_user_spammer' ) ) {
				$suspended = (bool) bp_is_user_spammer( $actor );
			}

			/**
			 * Filter whether a member is suspended and barred from every write.
			 *
			 * The seam for third-party moderation/suspension plugins.
			 *
			 * @since 2.1.0
			 *
			 * @param bool $suspended Whether the member is suspended.
			 * @param int  $user_id   Member being checked.
			 */
			self::$suspended_cache[ $actor ] = (bool) apply_filters( 'mvs_user_is_suspended', $suspended, $actor );
		}

		if ( self::$suspended_cache[ $actor ] ) {
			return new WP_Error(
				'mvs_account_suspended',
				__( 'Your account is suspended, so you cannot post or interact right now.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}

		return null;
	}

	/**
	 * Drop the per-request memo caches.
	 *
	 * Static caches survive the DB rollback WP_UnitTestCase performs between
	 * tests, so a primed entry would go stale and leak across cases. Tests call
	 * this in set_up().
	 *
	 * @since 2.1.0
	 */
	public static function flush_cache(): void {
		self::$block_cache     = array();
		self::$suspended_cache = array();
	}
}
