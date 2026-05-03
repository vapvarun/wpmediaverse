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

		// Profile tab is always available (core BP feature).
		( new ProfileTabIntegration() )->init();

		if ( bp_is_active( 'groups' ) ) {
			( new GroupTabIntegration() )->init();
		}
	}
}
