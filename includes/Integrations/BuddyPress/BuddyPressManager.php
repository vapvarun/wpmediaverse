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
