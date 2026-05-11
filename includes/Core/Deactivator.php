<?php
/**
 * Plugin deactivator.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin deactivation handler.
 */
class Deactivator {

	/**
	 * Run deactivation routines.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();

		// Unschedule plugin-owned cron jobs so deactivated sites don't keep
		// firing them (WP fires scheduled callbacks even for deactivated
		// plugins if the plugin re-activates briefly during the day).
		wp_clear_scheduled_hook( 'mvs_prune_logs' );
		wp_clear_scheduled_hook( 'mvs_purge_old_views' );

		// Clean transients.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
