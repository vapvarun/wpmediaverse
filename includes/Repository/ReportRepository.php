<?php
/**
 * Queries against `mvs_reports` that live outside ReportService.
 *
 * ReportService already holds the report queries it grew with (coding Rule 11 caps how many raw
 * queries a service may carry), so new report reads go here, in the repository layer, where SQL
 * against this table is reviewable in one directory.
 *
 * @package WPMediaVerse
 * @since   2.6.5
 */

namespace WPMediaVerse\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Report reads.
 */
class ReportRepository {

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
}
