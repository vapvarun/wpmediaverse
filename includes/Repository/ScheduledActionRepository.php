<?php
/**
 * Clean-up of MediaVerse's own finished Action Scheduler rows.
 *
 * Action Scheduler's site-wide retention (30 days) belongs to every plugin on the site, so MediaVerse never
 * changes it. Instead the daily log prune also deletes MediaVerse's completed actions, and their log rows first,
 * once they are older than a week. Ownership is the hook name: every Free and Pro hook starts with mvs_ (the same
 * rule uninstall uses), while groups vary by service.
 *
 * @package WPMediaVerse
 * @since   2.6.5
 */

namespace WPMediaVerse\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Finished-job pruning.
 */
class ScheduledActionRepository {

	/**
	 * Rows per delete batch.
	 */
	private const BATCH = 200;

	/**
	 * Batches per run: bounds one daily run at 20,000 rows; anything left goes on the next day.
	 */
	private const MAX_BATCHES = 100;

	/**
	 * Delete our completed actions older than the retention window, with their log rows. Pending, running and
	 * failed rows are never touched here.
	 *
	 * @return int Actions deleted.
	 */
	public static function prune_completed(): int {
		global $wpdb;

		/**
		 * Filters how long MediaVerse keeps its own completed Action Scheduler rows.
		 *
		 * @since 2.6.5
		 *
		 * @param int $seconds Age after which a completed MediaVerse action is deleted. Default one week.
		 */
		$keep = max( HOUR_IN_SECONDS, (int) apply_filters( 'mvs_completed_actions_retention', WEEK_IN_SECONDS ) );

		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';
		if ( $actions !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $keep );
		$total  = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		for ( $i = 0; $i < self::MAX_BATCHES; $i++ ) {
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT action_id FROM {$actions} WHERE status = 'complete' AND hook LIKE %s AND last_attempt_gmt < %s LIMIT %d",
						$wpdb->esc_like( 'mvs_' ) . '%',
						$cutoff,
						self::BATCH
					)
				)
			);
			if ( ! $ids ) {
				break;
			}
			$list = implode( ',', $ids );
			$wpdb->query( "DELETE FROM {$logs} WHERE action_id IN ( {$list} )" );
			$wpdb->query( "DELETE FROM {$actions} WHERE action_id IN ( {$list} )" );
			$total += count( $ids );
			if ( count( $ids ) < self::BATCH ) {
				break;
			}
		}
		// phpcs:enable

		return $total;
	}
}
