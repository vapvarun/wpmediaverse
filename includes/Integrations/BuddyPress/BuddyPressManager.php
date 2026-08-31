<?php
/**
 * BuddyPress integration orchestrator.
 *
 * Conditionally loads sub-integrations based on active BP components.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates all BuddyPress sub-integrations.
 */
class BuddyPressManager {

	/**
	 * Initialize BuddyPress integrations.
	 */
	public function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}

		if ( bp_is_active( 'activity' ) ) {
			( new ActivitySyncIntegration() )->init();
			( new ActivityContentIntegration() )->init();
			( new ActivityFormIntegration() )->init();
			// Viewer-side privacy filter — gates the activity stream by the
			// `_mvs_activity_privacy_level` meta written by ActivitySyncIntegration.
			// Public/Members/Friends/Private end-to-end (1.2.1+).
			( new ActivityPrivacyFilter() )->init();
		}

		// BP notifications integration:
		// MVS owns notifications via NotificationService (canonical store
		// in mvs_notifications). When BP is active, NotificationIntegration
		// injects each MVS event into BP's bp_notifications table so BP's
		// nav bell shows them on every page (not just the MVS dashboard).
		// Dashboard-bell duplication is prevented at render-time — the
		// MVS dashboard bell is suppressed on BP-active sites since BP's
		// nav bell is already global. See render check in
		// templates/partials/dashboard-content.php.
		if ( bp_is_active( 'notifications' ) ) {
			( new NotificationIntegration() )->init();
		}

		// Answer the "does this member have a picture they chose?" question for
		// BP-owned avatars. Free shipped the `mvs_has_custom_avatar` seam in
		// 2.4.0 but nothing hooked it, so a member with a BP avatar was still
		// told to upload one (QA re-verify, Basecamp 10252323883).
		add_filter(
			'mvs_has_custom_avatar',
			static function ( $has, $user_id ) {
				return $has ? $has : self::bp_avatar_was_uploaded( (int) $user_id );
			},
			10,
			2
		);

		// Profile tab is always available (core BP feature).
		( new ProfileTabIntegration() )->init();

		if ( bp_is_active( 'groups' ) ) {
			( new GroupTabIntegration() )->init();
		}
	}

	/**
	 * Whether BuddyPress holds an avatar this member actually uploaded.
	 *
	 * Deliberately NOT `bp_get_user_has_avatar()`. That helper resolves through
	 * `bp_core_fetch_avatar`, so any plugin that generates a per-member avatar
	 * (initials, identicons) makes it answer true for everyone - measured on
	 * mediaverse.local, where it was true for a user created seconds earlier
	 * with no avatar of any kind. Consuming it would mean nobody is ever asked
	 * for a picture again, which is the same bug as never asking the ones who
	 * have one.
	 *
	 * BP writes uploaded avatars to `{avatar upload path}/avatars/{user_id}/`
	 * and nothing else writes there, so the directory is the one answer no
	 * avatar filter can rewrite.
	 *
	 * @since 2.4.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function bp_avatar_was_uploaded( int $user_id ): bool {
		static $memo = array();

		if ( isset( $memo[ $user_id ] ) ) {
			return $memo[ $user_id ];
		}

		if ( $user_id < 1 || ! function_exists( 'bp_core_avatar_upload_path' ) ) {
			return false;
		}

		// No GLOB_BRACE - the constant is undefined on musl (Alpine) builds,
		// where referencing it is a fatal, not a fallback.
		$dir   = trailingslashit( bp_core_avatar_upload_path() ) . 'avatars/' . $user_id;
		$found = false;

		foreach ( ( is_dir( $dir ) ? (array) glob( $dir . '/*.*' ) : array() ) as $file ) {
			if ( preg_match( '/\.(jpe?g|png|gif|webp)$/i', (string) $file ) ) {
				$found = true;
				break;
			}
		}

		$memo[ $user_id ] = $found;

		return $found;
	}
}
