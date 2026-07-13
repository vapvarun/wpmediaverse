<?php
/**
 * Report and block service.
 *
 * Handles content/user reporting and user blocking for safety.
 *
 * @package    WPMediaVerse
 * @subpackage Social
 * @since      1.1.0
 */

namespace WPMediaVerse\Social;

defined( 'ABSPATH' ) || exit;


/**
 * Report media/users, block users, admin report queue.
 */
class ReportService {

	/**
	 * Valid report reasons.
	 *
	 * @var string[]
	 */
	const REASONS = array(
		'spam',
		'harassment',
		'nudity',
		'violence',
		'copyright',
		'misinformation',
		'other',
	);

	/**
	 * Whether members may file reports on this site.
	 *
	 * Single source of truth for the report write path. Every caller (REST,
	 * service, templates) must gate on this rather than re-deriving the state,
	 * so the button a member sees and the endpoint it posts to can never
	 * disagree.
	 *
	 * Defaults to ON. Reporting used to default to OFF behind a filter no
	 * shipped code ever set, which meant every install — Free and Pro alike —
	 * refused every report with a 403 while Pro still rendered an (always
	 * empty) User Reports queue. A UGC platform whose members cannot report
	 * abuse is not shippable, and Apple App Store guideline 1.2 requires a
	 * working report mechanism, so the default is now on and site owners opt
	 * *out* via Settings, not in via a mu-plugin.
	 *
	 * The `mvs_reports_enabled` filter is preserved and still wins, so sites
	 * that already force it either way keep their behaviour.
	 *
	 * @since 2.1.0
	 *
	 * @return bool True when reports may be filed.
	 */
	public static function reports_enabled(): bool {
		$enabled = (bool) get_option( 'mvs_enable_reports', true );

		/** This filter is documented in templates/media-single.php */
		return (bool) apply_filters( 'mvs_reports_enabled', $enabled );
	}

	/**
	 * Report a media item or user.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $reporter_id Reporter user ID.
	 * @param string $target_type 'media' or 'user'.
	 * @param int    $target_id   Target media/user ID.
	 * @param string $reason      Report reason.
	 * @param string $details     Optional details.
	 * @return int|false Report ID or false.
	 */
	public function report( int $reporter_id, string $target_type, int $target_id, string $reason, string $details = '' ) {
		// Guards every caller, not just REST, so a disabled site can never
		// collect reports nobody will read.
		if ( ! self::reports_enabled() ) {
			return false;
		}

		if ( ! in_array( $target_type, array( 'media', 'user' ), true ) ) {
			return false;
		}

		if ( ! in_array( $reason, self::REASONS, true ) ) {
			return false;
		}

		// Don't report yourself.
		if ( 'user' === $target_type && $reporter_id === $target_id ) {
			return false;
		}

		// Check for duplicate report.
		if ( $this->has_reported( $reporter_id, $target_type, $target_id ) ) {
			return false;
		}

		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_reports',
			array(
				'reporter_id' => $reporter_id,
				'target_type' => $target_type,
				'target_id'   => $target_id,
				'reason'      => $reason,
				'details'     => sanitize_textarea_field( $details ),
				'status'      => 'pending',
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $wpdb->insert_id ) {
			return false;
		}

		$report_id = $wpdb->insert_id;

		// Auto-hide media if report threshold reached.
		if ( 'media' === $target_type ) {
			$threshold = (int) get_option( 'mvs_report_auto_hide_threshold', 3 );
			if ( $threshold > 0 ) {
				$count = $this->get_report_count( $target_type, $target_id );
				if ( $count >= $threshold ) {
					\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $target_id, 'moderation_status', 'flagged' );
				}
			}
		}

		/**
		 * Fires after a report is submitted.
		 *
		 * @since 1.1.0
		 *
		 * @param int    $report_id   Report ID.
		 * @param int    $reporter_id Reporter user ID.
		 * @param string $target_type Target type.
		 * @param int    $target_id   Target ID.
		 * @param string $reason      Report reason.
		 */
		do_action( 'mvs_report_submitted', $report_id, $reporter_id, $target_type, $target_id, $reason );

		return $report_id;
	}

	/**
	 * Check if user already reported this target.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $reporter_id Reporter.
	 * @param string $target_type Target type.
	 * @param int    $target_id   Target ID.
	 * @return bool
	 */
	public function has_reported( int $reporter_id, string $target_type, int $target_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}mvs_reports WHERE reporter_id = %d AND target_type = %s AND target_id = %d",
				$reporter_id,
				$target_type,
				$target_id
			)
		);
	}

	/**
	 * Get report count for a target.
	 *
	 * @param string $target_type Target type.
	 * @param int    $target_id   Target ID.
	 * @return int
	 */
	public function get_report_count( string $target_type, int $target_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_reports WHERE target_type = %s AND target_id = %d AND status = 'pending'",
				$target_type,
				$target_id
			)
		);
	}

	/**
	 * Count reports by overall status (pending/resolved/dismissed).
	 *
	 * Used by Pro's moderation admin to render queue badges. Sister of
	 * get_report_count() which is scoped to one target.
	 *
	 * @since 1.3.0
	 *
	 * @param string $status Status value to count.
	 * @return int
	 */
	public function count_by_status( string $status ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_reports WHERE status = %s",
				$status
			)
		);
	}

	/**
	 * List reports for a status, newest first, one page at a time.
	 *
	 * The read path for every reports queue (Free's Member Reports screen and
	 * Pro's richer User Reports screen). Paginated on purpose: an abuse queue on
	 * a busy community is one of the few tables that grows without bound, and a
	 * moderator opening an unbounded SELECT on 50k rows is how the admin dies.
	 * `status` is indexed, so the WHERE + LIMIT stays cheap at any size.
	 *
	 * Pair with count_by_status() for the total.
	 *
	 * @since 2.1.0
	 *
	 * @param string $status   Status to list ('pending', 'resolved', 'dismissed').
	 * @param int    $per_page Rows per page. Clamped to 1..100.
	 * @param int    $offset   Row offset.
	 * @return array<int, object> Report rows, newest first.
	 */
	public function list_reports( string $status, int $per_page = 20, int $offset = 0 ): array {
		global $wpdb;

		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = max( 0, $offset );

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, reporter_id, target_type, target_id, reason, details, status, created_at
				 FROM {$wpdb->prefix}mvs_reports
				 WHERE status = %s
				 ORDER BY created_at DESC, id DESC
				 LIMIT %d OFFSET %d",
				$status,
				$per_page,
				$offset
			)
		);
	}

	/**
	 * Update a report's status (resolved / dismissed / pending).
	 *
	 * @since 1.3.0
	 *
	 * @param int    $report_id Report row id.
	 * @param string $status    New status.
	 * @return bool True when one row was updated.
	 */
	public function update_status( int $report_id, string $status ): bool {
		global $wpdb;

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_reports',
			array( 'status' => $status ),
			array( 'id' => $report_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Block a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int $blocker_id User doing the blocking.
	 * @param int $blocked_id User being blocked.
	 * @return bool
	 */
	public function block_user( int $blocker_id, int $blocked_id ): bool {
		if ( $blocker_id === $blocked_id || ! $blocker_id || ! $blocked_id ) {
			return false;
		}

		if ( $this->is_blocked( $blocker_id, $blocked_id ) ) {
			return true;
		}

		global $wpdb;

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_blocks',
			array(
				'blocker_id' => $blocker_id,
				'blocked_id' => $blocked_id,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s' )
		);

		if ( $result ) {
			/**
			 * Fires after a user is blocked.
			 *
			 * @since 1.1.0
			 *
			 * @param int $blocker_id User doing the blocking.
			 * @param int $blocked_id User being blocked.
			 */
			do_action( 'mvs_user_blocked', $blocker_id, $blocked_id );
		}

		return (bool) $result;
	}

	/**
	 * Unblock a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int $blocker_id User doing the unblocking.
	 * @param int $blocked_id User being unblocked.
	 * @return bool
	 */
	public function unblock_user( int $blocker_id, int $blocked_id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_blocks',
			array(
				'blocker_id' => $blocker_id,
				'blocked_id' => $blocked_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Check if a user is blocked by another.
	 *
	 * @since 1.1.0
	 *
	 * @param int $blocker_id Potential blocker.
	 * @param int $blocked_id Potentially blocked user.
	 * @return bool
	 */
	public function is_blocked( int $blocker_id, int $blocked_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}mvs_blocks WHERE blocker_id = %d AND blocked_id = %d",
				$blocker_id,
				$blocked_id
			)
		);
	}

	/**
	 * Check if either user has blocked the other (bidirectional).
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_a First user.
	 * @param int $user_b Second user.
	 * @return bool True if either has blocked the other.
	 */
	public function is_blocked_either_way( int $user_a, int $user_b ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}mvs_blocks WHERE (blocker_id = %d AND blocked_id = %d) OR (blocker_id = %d AND blocked_id = %d) LIMIT 1",
				$user_a,
				$user_b,
				$user_b,
				$user_a
			)
		);
	}

	/**
	 * Get list of blocked user IDs for a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @return int[] Blocked user IDs.
	 */
	public function get_blocked_ids( int $user_id ): array {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT blocked_id FROM {$wpdb->prefix}mvs_blocks WHERE blocker_id = %d",
				$user_id
			)
		);

		return array_map( 'intval', $ids );
	}
}
