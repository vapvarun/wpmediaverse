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

		// Clean transients.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
