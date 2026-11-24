<?php
/**
 * Queries against `mvs_reports` that live outside ReportService.
 *
 * ReportService already holds the report queries it grew with (coding Rule 11 caps how many raw
 * queries a service may carry), so new report reads go here, in the repository layer, where SQL
 * against this table is reviewable in one directory.
 *
 * @package WPMediaVerse
 * @since   2.7.0
 */

namespace WPMediaVerse\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Report reads.
 */
class ReportRepository {

	/**
	 * Action Scheduler hook of the orphan-report sweep.
	 *
	 * @since 2.7.0
	 */
	public const SWEEP_HOOK = 'mvs_close_orphan_reports';

	/**
	 * Reports one orphan-sweep batch looks at.
	 *
	 * @since 2.7.0
	 */
	public const SWEEP_BATCH = 500;

	/**
	 * Batches the sweep runs inline when no scheduler takes the next one. Both schedulers refusing means a broken
	 * site, and one request must not loop over every report.
	 */
	private const SWEEP_INLINE_MAX_BATCHES = 20;

	/**
	 * Ids of the pending reports filed against one target.
	 *
	 * @param string $target_type Target type (media, album, comment...).
	 * @param int    $target_id   Target id.
	 * @return int[] Report ids, oldest first.
	 */
	public function pending_ids_for_target( string $target_type, int $target_id ): array {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}mvs_reports WHERE target_type = %s AND target_id = %d AND status = 'pending' ORDER BY id ASC",
				$target_type,
				$target_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * A media item was deleted: close its pending reports.
	 *
	 * @param int $media_id Media id.
	 */
	public function on_media_deleted( $media_id ): void {
		$this->close_pending_silently( 'media', (int) $media_id );
	}

	/**
	 * Close every pending report against a target without announcing it.
	 *
	 * For a target that no longer exists: there is nothing left to act on, so its reports must stop
	 * counting in the moderation queue. A plain status change, not ReportService::update_status(),
	 * so no 'report resolved' event fires and reporters are not notified.
	 *
	 * @param string $target_type Target type (media, album, comment...).
	 * @param int    $target_id   Target id.
	 * @return int Reports closed.
	 */
	public function close_pending_silently( string $target_type, int $target_id ): int {
		global $wpdb;

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mvs_reports SET status = 'resolved', details = CONCAT( COALESCE( details, '' ), %s ) WHERE target_type = %s AND target_id = %d AND status = 'pending'",
				self::removed_note( $target_type ),
				$target_type,
				$target_id
			)
		);
	}

	/**
	 * The audit line added to a report closed because its target is gone, so "resolved" is not read as a moderator decision.
	 *
	 * Stored in the site language at the time of closing (it is a record, not interface text).
	 *
	 * @since 2.7.0
	 *
	 * @param string $target_type Target type.
	 * @return string
	 */
	private static function removed_note( string $target_type ): string {
		return "\n\n" . ( 'media' === $target_type
			? __( 'Closed (media removed)', 'wpmediaverse' )
			: __( 'Closed (reported item removed)', 'wpmediaverse' ) );
	}

	/**
	 * Close one batch of pending media reports whose media no longer exists (MediaRepository::existing_ids()).
	 *
	 * Media targets only: reports on albums, comments or members are left for a moderator.
	 *
	 * For sites that deleted media before reports were closed on delete. Same silent close as
	 * close_pending_silently(): rows kept for the audit trail, reporters not notified.
	 *
	 * @since 2.7.0
	 *
	 * @param int $after_id Report id to continue after (keyset cursor).
	 * @param int $limit    Batch size.
	 * @return array{scanned:int, closed:int, last_id:int} Rows looked at, rows closed, last report id looked at.
	 */
	public function close_orphan_media_reports( int $after_id, int $limit = self::SWEEP_BATCH ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, target_id FROM {$wpdb->prefix}mvs_reports WHERE id > %d AND target_type = 'media' AND status = 'pending' ORDER BY id ASC LIMIT %d",
				$after_id,
				$limit
			)
		);

		$live    = array_flip( ( new MediaRepository() )->existing_ids( wp_list_pluck( $rows, 'target_id' ) ) );
		$orphans = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $live[ (int) $row->target_id ] ) ) {
				$orphans[] = (int) $row->id;
			}
		}

		$closed = 0;
		if ( $orphans ) {
			$closed = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}mvs_reports SET status = 'resolved', details = CONCAT( COALESCE( details, '' ), %s ) WHERE status = 'pending' AND id IN (" . implode( ',', $orphans ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- ids are ints from the query above.
					self::removed_note( 'media' )
				)
			);
		}

		return array(
			'scanned' => count( $rows ),
			'closed'  => $closed,
			'last_id' => $rows ? (int) end( $rows )->id : $after_id,
		);
	}

	/**
	 * Start the sweep: one queued job, or inline batches when no scheduler takes it.
	 *
	 * Any copy, pending or running, means a chain is already under way (its continuations queue unguarded with the
	 * next cursor), so this checks is_queued() for any arguments rather than async_once() for the first cursor.
	 *
	 * @since 2.7.0
	 */
	public static function queue_sweep(): void {
		if ( \WPMediaVerse\Core\Jobs::is_queued( self::SWEEP_HOOK ) ) {
			return;
		}
		if ( ! \WPMediaVerse\Core\Jobs::async( self::SWEEP_HOOK, array( 0 ) ) ) {
			self::sweep_orphans( 0 );
		}
	}

	/**
	 * Action Scheduler job: close one batch, then queue the next while batches come back full.
	 *
	 * When no scheduler takes the next batch, up to SWEEP_INLINE_MAX_BATCHES run inline and the rest stay open
	 * for a moderator; a later queue_sweep() closes nothing twice.
	 *
	 * @since 2.7.0
	 *
	 * @param int $after_id Report id to continue after.
	 */
	public static function sweep_orphans( $after_id = 0 ): void {
		$repo  = new self();
		$batch = $repo->close_orphan_media_reports( (int) $after_id );

		for ( $inline = 0; self::SWEEP_BATCH === $batch['scanned'] && $inline < self::SWEEP_INLINE_MAX_BATCHES; $inline++ ) {
			if ( \WPMediaVerse\Core\Jobs::async( self::SWEEP_HOOK, array( $batch['last_id'] ) ) ) {
				return;
			}
			$batch = $repo->close_orphan_media_reports( $batch['last_id'] );
		}
	}
}
