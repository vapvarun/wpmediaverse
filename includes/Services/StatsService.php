<?php
/**
 * Stats service.
 *
 * Tracks views, downloads, and aggregates media statistics.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

use WPMediaVerse\Core\MediaTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks views, downloads, and aggregates media statistics.
 */
class StatsService {

	/**
	 * Get stats for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return array|null Stats array or null if not found.
	 */
	public function get_for_media( int $media_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT views, downloads, reactions, comments, shares, updated_at FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
				$media_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'media_id'   => $media_id,
			'views'      => (int) $row['views'],
			'downloads'  => (int) $row['downloads'],
			'reactions'  => (int) $row['reactions'],
			'comments'   => (int) $row['comments'],
			'shares'     => (int) $row['shares'],
			'updated_at' => $row['updated_at'],
		);
	}

	/**
	 * Get aggregated stats for a user's media.
	 *
	 * @param int $user_id User ID.
	 * @return array Aggregated stats.
	 */
	public function get_for_user( int $user_id ): array {
		global $wpdb;

		// `total_media` must count media (Coding Rule 13). Documents get their own
		// counters when the document library ships; folding them in here would
		// silently change every existing member's headline number on upgrade.
		//
		// Untouched on purpose: this query has NO status filter, so trashed items
		// still count toward a member's totals. That is a real discrepancy against
		// every other count in the plugin, but correcting it changes a number
		// members already see, which is a behaviour change and needs its own
		// decision rather than a quiet ride-along on a type fix.
		list( $mvs_stats_type_sql, $mvs_stats_type_params ) = MediaTypes::in_clause( MediaTypes::MEDIA_LIBRARY, 'i.media_type' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_media,
					COALESCE(SUM(s.views), 0) as total_views,
					COALESCE(SUM(s.downloads), 0) as total_downloads,
					COALESCE(SUM(s.reactions), 0) as total_reactions,
					COALESCE(SUM(s.comments), 0) as total_comments,
					COALESCE(SUM(s.shares), 0) as total_shares
				FROM {$wpdb->prefix}mvs_media_index i
				INNER JOIN {$wpdb->prefix}mvs_media_stats s ON i.media_id = s.media_id
				WHERE i.post_author = %d AND {$mvs_stats_type_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				...$mvs_stats_type_params
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return array(
				'total_media'     => 0,
				'total_views'     => 0,
				'total_downloads' => 0,
				'total_reactions' => 0,
				'total_comments'  => 0,
				'total_shares'    => 0,
			);
		}

		return array(
			'total_media'     => (int) $row['total_media'],
			'total_views'     => (int) $row['total_views'],
			'total_downloads' => (int) $row['total_downloads'],
			'total_reactions' => (int) $row['total_reactions'],
			'total_comments'  => (int) $row['total_comments'],
			'total_shares'    => (int) $row['total_shares'],
		);
	}

	/**
	 * Prune old view records older than the given number of days.
	 *
	 * Keeps aggregate stats in mvs_media_stats intact.
	 *
	 * @param int $days_old Number of days to retain.
	 * @return int Number of rows deleted.
	 */
	public function prune_views( int $days_old = 90 ): int {
		global $wpdb;

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}mvs_media_views WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days_old
			)
		);
	}

	/**
	 * Record a download event.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID (0 for anonymous).
	 */
	public function record_download( int $media_id, int $user_id = 0 ): void {
		global $wpdb;

		$ip_hash = hash( 'sha256', $this->get_client_ip() . wp_salt() );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_views',
			array(
				'media_id'   => $media_id,
				'user_id'    => $user_id ? $user_id : null,
				'ip_hash'    => $ip_hash,
				'event_type' => 'download',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		// Increment download count.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mvs_media_stats SET downloads = downloads + 1, updated_at = %s WHERE media_id = %d",
				current_time( 'mysql', true ),
				$media_id
			)
		);
	}

	/**
	 * Get client IP.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '127.0.0.1';
	}
}
