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

		// BP notifications integration intentionally NOT registered.
		//
		// MVS owns notifications via NotificationService + the bell on the
		// dashboard surface. The previous BP integration mirrored every
		// MVS notification into bp_notifications, which produced two
		// surfaces showing the same items (BP nav bell + MVS dashboard
		// bell on dashboard pages) and added a maintenance surface (a
		// TYPE_TO_BP_ACTION map per new notification type).
		//
		// Right long-term shape (deferred to 1.2.1): wire the same
		// notification source with BP — either by injecting MVS rows
		// into bp_notifications_get_notifications_for_user at read time
		// (single store, BP renders it), or by mounting the MVS bell
		// globally in the WP admin bar / theme header so BP-active sites
		// see notifications everywhere through the MVS surface itself.
		// Either way: ONE source, ONE write per event, multiple render
		// surfaces — never the dual-write pattern this commit removed.

		// Profile tab is always available (core BP feature).
		( new ProfileTabIntegration() )->init();

		if ( bp_is_active( 'groups' ) ) {
			( new GroupTabIntegration() )->init();
		}
	}
}
