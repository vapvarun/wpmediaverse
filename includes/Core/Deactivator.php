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
	 * One-off background jobs (WP-Cron single events or Action Scheduler). Uninstall clears all of them; deactivate
	 * cancels all but STATE_JOB_HOOKS, whose work can be recomputed or is lost by nature anyway.
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
	 * Jobs that finish an action a member or admin already committed, or carry state nothing else holds (file paths
	 * of deleted media, a deleted user's id, a deleted tag's name). Deactivate leaves them pending, so the routine
	 * update (deactivate, replace, activate) resumes them. Reasons per hook: docs/standards/background-jobs.md,
	 * section 6.
	 */
	public const STATE_JOB_HOOKS = array(
		'mvs_backfill_drive_columns',
		'mvs_close_orphan_reports',
		'mvs_deliver_webhook',
		Jobs::ADOPT_HOOK,
		'mvs_cleanup_media_files',
		'mvs_cloud_repatriate_media',
		'mvs_repair_storage_paths',
		'mvs_tag_merge_batch',
		'mvs_user_deletion_cascade',
		'mvs_user_deletion_purge',
		\WPMediaVerse\Taxonomies\MediaTag::FORGET_HOOK,
		\WPMediaVerse\Services\MediaFileRotator::HOOK,
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
	 * Clear the plugin's scheduled work, every copy whatever its arguments: WP-Cron events, and pending Action
	 * Scheduler rows of Free's own hooks (set-based, so 100,000 rows do not time the deactivation out). Never Pro's
	 * rows, though they share the group. Running, complete and failed rows stay; uninstall.php deletes them.
	 *
	 * @param bool $include_state_jobs False on deactivate: leave STATE_JOB_HOOKS pending. True on uninstall.
	 */
	public static function clear_scheduled( bool $include_state_jobs = true ): void {
		$hooks = array_merge( self::CRON_HOOKS, self::JOB_HOOKS );
		if ( ! $include_state_jobs ) {
			$hooks = array_diff( $hooks, self::STATE_JOB_HOOKS );
			if ( ! doing_action( 'deactivate_' . MVS_PLUGIN_BASENAME ) ) {
				_deprecated_argument( __METHOD__, '2.6.5', esc_html__( 'Before 2.6.5 false cleared only the recurring events; it now also cancels every one-off job except STATE_JOB_HOOKS.', 'wpmediaverse' ) );
			}
		}
		foreach ( $hooks as $hook ) {
			wp_unschedule_hook( $hook );
		}
		Jobs::cancel_pending( $hooks );
	}
}
