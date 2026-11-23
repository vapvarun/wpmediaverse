<?php
/**
 * Set-based reads and writes on Action Scheduler's tables that its API cannot do fast enough.
 *
 * Called through Core\Jobs only.
 *
 * @package WPMediaVerse
 * @since   2.7.0
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
	 * @since 2.7.0
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
	 * Failed rows of exactly these hooks, last attempted within $days, whose log holds one of the needles, oldest
	 * first, with the hook and arguments so the caller needs no query per row.
	 *
	 * @since 2.7.0
	 *
	 * @param string[] $hooks   Exact hook names.
	 * @param string[] $needles Log message fragments.
	 * @param int      $limit   Rows at most.
	 * @param int      $days    Only rows whose last attempt is newer than this.
	 * @return array<int, array{id: int, hook: string, args: array<int|string, mixed>}>
	 */
	public static function unheard_failed( array $hooks, array $needles, int $limit, int $days ): array {
		global $wpdb;
		$hooks   = array_values( array_filter( array_map( 'strval', $hooks ) ) );
		$needles = array_values( array_filter( array_map( 'strval', $needles ) ) );
		if ( ! $hooks || ! $needles ) {
			return array();
		}
		$in    = implode( ',', array_fill( 0, count( $hooks ), '%s' ) );
		$likes = implode( ' OR ', array_fill( 0, count( $needles ), 'l.message LIKE %s' ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$args  = array_merge( $hooks, array( $since ), array_map( static fn( string $n ): string => '%' . $wpdb->esc_like( $n ) . '%', $needles ), array( $limit ) );

		// Long arguments live in extended_args; args then holds only their hash.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names and placeholder lists built above.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT a.action_id, a.hook, COALESCE( a.extended_args, a.args ) AS args FROM {$wpdb->prefix}actionscheduler_actions a WHERE a.status = 'failed' AND a.hook IN ( {$in} ) AND a.last_attempt_gmt > %s AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}actionscheduler_logs l WHERE l.action_id = a.action_id AND ( {$likes} ) ) ORDER BY a.action_id LIMIT %d", $args ) );

		return array_map(
			static fn( object $row ): array => array(
				'id'   => (int) $row->action_id,
				'hook' => (string) $row->hook,
				'args' => (array) json_decode( (string) $row->args, true ),
			),
			$rows
		);
	}
}
