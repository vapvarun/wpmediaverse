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
					update_post_meta( $target_id, '_mvs_moderation_status', 'flagged' );
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
