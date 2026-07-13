<?php
/**
 * Member-initiated account deletion.
 *
 * @package WPMediaVerse
 * @since   2.1.0
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

use WP_Application_Passwords;
use WP_Error;
use WP_User;

/**
 * Lets a member delete their own account, from the app or the web.
 *
 * Apple App Store guideline 5.1.1(v) requires any app that lets you create an
 * account to let you start deleting it from inside the app. Until 2.1.0 the only
 * erasure path was WordPress's admin-mediated Tools -> Erase Personal Data flow,
 * which a member cannot complete themselves. That made the mobile app
 * unshippable.
 *
 * Two design decisions worth stating, because both look like extra work and
 * both are load-bearing:
 *
 * 1. **The account password is required, even though the caller authenticated
 *    with an Application Password.** An app password is a long-lived bearer
 *    token sitting on a phone. If a stolen one could irreversibly destroy the
 *    account and every photo in it, the token becomes a weapon. Re-auth with the
 *    real password is a genuine second factor against exactly that.
 *
 * 2. **Deletion is scheduled, not immediate (14 days by default).** The most
 *    common support ticket after any account deletion is "I didn't mean it, give
 *    my photos back" — and after a hard delete there is nothing to give back.
 *    The grace window also defends the account-takeover case: on request we
 *    revoke every application password and destroy every session, so a thief
 *    holding a stolen token is locked out and cannot cancel, while the real
 *    owner can. Apple permits a disclosed, automatic, time-bounded delay; what
 *    it forbids is deactivation-only, or making the member email support.
 *
 * During the grace window the member is suspended via the `mvs_suspended` meta
 * that RestGate already enforces, so they cannot post or interact while leaving.
 * Reads still work, so they can export their data before the cutoff.
 *
 * Execution calls wp_delete_user(), which fires core's `deleted_user` and runs
 * the existing UserDeletionService cascade unchanged. No purge logic is
 * duplicated here.
 */
class AccountDeletionService {

	/**
	 * User meta holding the scheduled deletion timestamp.
	 */
	const META_SCHEDULED = 'mvs_deletion_scheduled_at';

	/**
	 * Cron hook that sweeps due deletions.
	 */
	const CRON_HOOK = 'mvs_process_account_deletions';

	/**
	 * Default grace period, in days.
	 */
	const DEFAULT_GRACE_DAYS = 14;

	/**
	 * Hook the cron sweep.
	 */
	public function init(): void {
		add_action( self::CRON_HOOK, array( $this, 'process_due' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Grace period in days. 0 deletes immediately.
	 *
	 * @return int
	 */
	public function grace_days(): int {
		/**
		 * Filter the account-deletion grace period, in days.
		 *
		 * Return 0 to delete immediately on request.
		 *
		 * @since 2.1.0
		 *
		 * @param int $days Grace period. Default 14.
		 */
		return max( 0, (int) apply_filters( 'mvs_account_deletion_grace_days', self::DEFAULT_GRACE_DAYS ) );
	}

	/**
	 * Whether a deletion is already scheduled for a member.
	 *
	 * @param int $user_id Member.
	 * @return int Unix timestamp of the scheduled deletion, or 0.
	 */
	public function scheduled_at( int $user_id ): int {
		return (int) get_user_meta( $user_id, self::META_SCHEDULED, true );
	}

	/**
	 * Request deletion of one's own account.
	 *
	 * @param int    $user_id  Member deleting themselves.
	 * @param string $password Their account password.
	 * @return array|WP_Error Status payload, or an error.
	 */
	public function request( int $user_id, string $password ) {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'mvs_user_not_found',
				__( 'Account not found.', 'wpmediaverse' ),
				array( 'status' => 404 )
			);
		}

		// Never let the last administrator delete themselves from the app and
		// lock the site owner out of their own community. A reviewer testing on
		// a demo site is very often the admin, so this message has to be human.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'mvs_delete_forbidden',
				__( 'Administrators cannot delete their own account here. Ask another administrator, or remove your admin role first.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->password_ok( $user, $password ) ) {
			return new WP_Error(
				'mvs_invalid_password',
				__( 'That password is not correct.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}

		$grace = $this->grace_days();

		if ( 0 === $grace ) {
			$this->execute( $user_id );

			return array(
				'status'  => 'deleted',
				'message' => __( 'Your account and content have been permanently deleted.', 'wpmediaverse' ),
			);
		}

		$when = time() + ( $grace * DAY_IN_SECONDS );

		update_user_meta( $user_id, self::META_SCHEDULED, $when );

		// Suspend for the duration. RestGate already enforces `mvs_suspended` on
		// every write, so this needs no new enforcement code — it is the reason
		// the suspension gate exists.
		update_user_meta( $user_id, 'mvs_suspended', 1 );

		// Lock out anyone holding a credential, including a thief. The owner can
		// still cancel by signing in with their password, which the thief lacks.
		$this->revoke_credentials( $user_id );

		/**
		 * Fires when a member schedules their own account deletion.
		 *
		 * @since 2.1.0
		 *
		 * @param int $user_id      Member.
		 * @param int $scheduled_at Unix timestamp deletion will run.
		 */
		do_action( 'mvs_account_deletion_requested', $user_id, $when );

		return array(
			'status'        => 'scheduled',
			'scheduled_for' => gmdate( 'c', $when ),
			'grace_days'    => $grace,
			'message'       => sprintf(
				/* translators: %s: date the account will be deleted. */
				__( 'Your account will be permanently deleted on %s. Sign in before then to cancel.', 'wpmediaverse' ),
				date_i18n( get_option( 'date_format' ), $when )
			),
		);
	}

	/**
	 * Cancel a pending deletion.
	 *
	 * @param int $user_id Member.
	 * @return array|WP_Error
	 */
	public function cancel( int $user_id ) {
		if ( 0 === $this->scheduled_at( $user_id ) ) {
			return new WP_Error(
				'mvs_no_pending_deletion',
				__( 'No deletion is scheduled for this account.', 'wpmediaverse' ),
				array( 'status' => 404 )
			);
		}

		delete_user_meta( $user_id, self::META_SCHEDULED );
		delete_user_meta( $user_id, 'mvs_suspended' );

		/**
		 * Fires when a member cancels their pending account deletion.
		 *
		 * @since 2.1.0
		 *
		 * @param int $user_id Member.
		 */
		do_action( 'mvs_account_deletion_cancelled', $user_id );

		return array(
			'status'  => 'active',
			'message' => __( 'Your account is no longer scheduled for deletion.', 'wpmediaverse' ),
		);
	}

	/**
	 * Delete the account now.
	 *
	 * Defers every purge to core's `deleted_user`, which UserDeletionService
	 * already listens to. Nothing is duplicated here.
	 *
	 * @param int $user_id Member.
	 */
	public function execute( int $user_id ): void {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		/**
		 * Fires immediately before a member's account is destroyed.
		 *
		 * @since 2.1.0
		 *
		 * @param int $user_id Member.
		 */
		do_action( 'mvs_account_deletion_executing', $user_id );

		// A member of several sites must not lose their identity network-wide
		// because they left one community.
		if ( is_multisite() && count( get_blogs_of_user( $user_id ) ) > 1 ) {
			remove_user_from_blog( $user_id, get_current_blog_id() );
			return;
		}

		wp_delete_user( $user_id );
	}

	/**
	 * Cron sweep: delete every account whose grace period has expired.
	 */
	public function process_due(): void {
		$due = get_users(
			array(
				'meta_key'     => self::META_SCHEDULED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed meta lookup, bounded by number=100.
				'meta_compare' => 'EXISTS',
				'number'       => 100,
				'fields'       => 'ID',
			)
		);

		$now = time();

		foreach ( $due as $user_id ) {
			$when = $this->scheduled_at( (int) $user_id );

			if ( $when > 0 && $when <= $now ) {
				$this->execute( (int) $user_id );
			}
		}
	}

	/**
	 * Verify the member's account password.
	 *
	 * Accounts with no usable password (SSO / social login) cannot be asked for
	 * one. Those sites opt out via the filter, and the type-to-confirm step in
	 * the client is the confirmation.
	 *
	 * @param WP_User $user     Member.
	 * @param string  $password Supplied password.
	 * @return bool
	 */
	private function password_ok( WP_User $user, string $password ): bool {
		/**
		 * Filter whether account deletion requires the account password.
		 *
		 * @since 2.1.0
		 *
		 * @param bool $required Whether a password is required. Default true.
		 * @param int  $user_id  Member.
		 */
		$required = (bool) apply_filters( 'mvs_account_deletion_password_required', true, $user->ID );

		if ( ! $required || '' === $user->user_pass ) {
			return true;
		}

		return wp_check_password( $password, $user->user_pass, $user->ID );
	}

	/**
	 * Revoke every credential the member holds.
	 *
	 * @param int $user_id Member.
	 */
	private function revoke_credentials( int $user_id ): void {
		if ( class_exists( WP_Application_Passwords::class ) ) {
			WP_Application_Passwords::delete_all_application_passwords( $user_id );
		}

		$sessions = \WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();
	}
}
