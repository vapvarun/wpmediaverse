<?php
/**
 * Set-based writes to Action Scheduler's table that its API cannot do fast enough.
 *
 * Called through Core\Jobs only.
 *
 * @package WPMediaVerse
 * @since   2.6.5
 */

namespace WPMediaVerse\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler rows of MediaVerse hooks.
 */
final class JobRepository {

	/**
	 * Rows one UPDATE cancels.
	 */
	private const CANCEL_BATCH = 10000;

	/**
	 * Mark the PENDING rows of exactly these hooks cancelled, any group, CANCEL_BATCH per UPDATE.
	 *
	 * Running, complete, failed and other hooks' rows are never touched. No "action cancelled" log row is written;
	 * Jobs::prune_history() deletes cancelled rows after FINISHED_RETENTION_DAYS.
	 *
	 * @since 2.6.5
	 *
	 * @param string[] $hooks Exact hook names.
	 * @return int Rows cancelled.
	 */
	public static function cancel_pending( array $hooks ): int {
		global $wpdb;
		$hooks   = array_values( array_filter( array_map( 'strval', $hooks ) ) );
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		if ( ! $hooks || $actions !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) ) ) {
			return 0;
		}

		$in    = implode( ',', array_fill( 0, count( $hooks ), '%s' ) );
		$total = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name and placeholder list built above.
			$done   = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$actions} SET status = 'canceled' WHERE status = 'pending' AND hook IN ( {$in} ) LIMIT %d", array_merge( $hooks, array( self::CANCEL_BATCH ) ) ) );
			$total += $done;
		} while ( self::CANCEL_BATCH === $done );

		return $total;
	}

	/**
	 * Failed rows of exactly these hooks whose log holds one of the needles, oldest first.
	 *
	 * @since 2.6.5
	 *
	 * @param string[] $hooks   Exact hook names.
	 * @param string[] $needles Log message fragments.
	 * @param int      $limit   Rows at most.
	 * @return int[] Action ids.
	 */
	public static function unheard_failed( array $hooks, array $needles, int $limit ): array {
		global $wpdb;
		$hooks   = array_values( array_filter( array_map( 'strval', $hooks ) ) );
		$needles = array_values( array_filter( array_map( 'strval', $needles ) ) );
		if ( ! $hooks || ! $needles ) {
			return array();
		}
		$in    = implode( ',', array_fill( 0, count( $hooks ), '%s' ) );
		$likes = implode( ' OR ', array_fill( 0, count( $needles ), 'l.message LIKE %s' ) );
		$args  = array_merge( $hooks, array_map( static fn( string $n ): string => '%' . $wpdb->esc_like( $n ) . '%', $needles ), array( $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names and placeholder lists built above.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT a.action_id FROM {$wpdb->prefix}actionscheduler_actions a WHERE a.status = 'failed' AND a.hook IN ( {$in} ) AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}actionscheduler_logs l WHERE l.action_id = a.action_id AND ( {$likes} ) ) ORDER BY a.action_id LIMIT %d", $args ) ) );
	}
}
