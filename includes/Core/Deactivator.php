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
 *
 * Also the one list of scheduled work the plugin owns: uninstall.php requires
 * this file directly and clears the same hooks, so deactivate and uninstall
 * cannot drift apart the way two copies of a list do.
 */
class Deactivator {

	/**
	 * Recurring WP-Cron events. The plugin re-schedules each on boot, so
	 * clearing them on deactivate is safe.
	 *
	 * `mvs_story_cleanup` belongs to Pro since 1.8.1; it stays here so a site
	 * upgraded from before the move does not keep a stray Free-era event.
	 */
	public const CRON_HOOKS = array(
		'mvs_prune_logs',
		'mvs_purge_old_views',
		'mvs_process_account_deletions',
		'mvs_story_cleanup',
	);

	/**
	 * One-off background jobs (WP-Cron single events or Action Scheduler).
	 *
	 * Cleared on uninstall only. Cancelling one on a plain deactivate could
	 * strand work half done - a drive backfill or a file cleanup - that the
	 * plugin resumes when it is switched back on.
	 */
	public const JOB_HOOKS = array(
		'mvs_backfill_drive_columns',
		'mvs_cleanup_media_files',
		'mvs_repair_storage_paths',
		'mvs_ai_process_media',
		'mvs_deliver_webhook',
		'mvs_cloud_sync_media',
		'mvs_cloud_repatriate_media',
	);

	/**
	 * Run deactivation routines.
	 */
	public static function deactivate(): void {
		// flush_rewrite_rules() here would write the rules straight back: this
		// runs after init, while MediaVerse's own rules are still registered, so
		// /media/ kept matching after deactivation. Deleting the option defers
		// the rebuild to the next request, when the plugin is genuinely gone;
		// clearing the version option makes the plugin re-flush on reactivation.
		// Same fix Pro applies to its own rules.
		delete_option( 'mvs_rewrite_version' );
		delete_option( 'rewrite_rules' );

		self::clear_scheduled( false );

		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Clear the plugin's scheduled work.
	 *
	 * @param bool $include_jobs Also cancel one-off jobs (uninstall).
	 */
	public static function clear_scheduled( bool $include_jobs ): void {
		$hooks = $include_jobs ? array_merge( self::CRON_HOOKS, self::JOB_HOOKS ) : self::CRON_HOOKS;

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );

			// ponytail: skipped when no plugin on the site loads Action
			// Scheduler (uninstall runs with MediaVerse inactive); a pending AS
			// row for a gone plugin just fails once and is purged by AS itself.
			if ( $include_jobs && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
			}
		}
	}
}
