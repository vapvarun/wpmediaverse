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
	 * Only Free's own: `mvs_story_cleanup` is Pro's (Pro's Lifecycle clears it),
	 * and listing it here made deactivating or deleting Free stop Pro's sweep.
	 */
	public const CRON_HOOKS = array(
		'mvs_prune_logs',
		'mvs_purge_old_views',
		'mvs_process_account_deletions',
		// A switched-off plugin asks the store nothing; activation re-arms it.
		PresetActivation::HOOK,
	);

	/**
	 * One-off background jobs (WP-Cron single events or Action Scheduler).
	 *
	 * Cancelled on deactivate as well as uninstall: with the plugin off nothing
	 * listens for them, and Action Scheduler loaded by another plugin would mark
	 * them complete unrun. Work that was pending does not carry across a
	 * deactivation; the recovery paths are listed in
	 * docs/standards/background-jobs.md.
	 */
	public const JOB_HOOKS = array(
		'mvs_backfill_drive_columns',
		'mvs_cleanup_media_files',
		'mvs_repair_storage_paths',
		'mvs_ai_process_media',
		'mvs_deliver_webhook',
		'mvs_cloud_sync_media',
		'mvs_cloud_repatriate_media',
		'mvs_user_deletion_cascade',
		'mvs_user_deletion_purge',
		'mvs_tag_merge_batch',
		'mvs_close_orphan_reports',
		\WPMediaVerse\Messaging\ChatFiles::HOOK,
		\WPMediaVerse\Taxonomies\MediaTag::FORGET_HOOK,
		\WPMediaVerse\Services\MediaFileRotator::HOOK,
		\WPMediaVerse\Services\MediaFileRotator::LEGACY_HOOK,
		Jobs::ADOPT_HOOK,
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

		self::clear_scheduled();

		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Clear the plugin's scheduled work, every copy whatever its arguments: WP-Cron events, and pending Action
	 * Scheduler rows of Free's own hooks (set-based, so 100,000 rows do not time the deactivation out). Never Pro's
	 * rows, though they share the group. Running, complete and failed rows stay; uninstall.php deletes them.
	 *
	 * @param bool $include_jobs Ignored since 2.6.5: deactivate and uninstall both cancel one-off jobs.
	 */
	public static function clear_scheduled( bool $include_jobs = true ): void {
		unset( $include_jobs );
		$hooks = array_merge( self::CRON_HOOKS, self::JOB_HOOKS );
		foreach ( $hooks as $hook ) {
			wp_unschedule_hook( $hook );
		}
		Jobs::cancel_pending( $hooks );
	}
}
