<?php
/**
 * Follow service.
 *
 * Manages user follow/unfollow relationships via custom mvs_follows table.
 *
 * @package    WPMediaVerse
 * @subpackage Social
 * @since      1.1.0
 */

namespace WPMediaVerse\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Handles follow/unfollow, follower/following queries, and counts.
 */
class FollowService {

	/**
	 * Follow a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int $follower_id  The user doing the following.
	 * @param int $following_id The user being followed.
	 * @return bool|int Follow row ID on success, false on failure.
	 */
	public function follow( int $follower_id, int $following_id ) {
		if ( $follower_id === $following_id || ! $follower_id || ! $following_id ) {
			return false;
		}

		if ( ! get_userdata( $following_id ) ) {
			return false;
		}

		// Check if either user has blocked the other.
		global $wpdb;
		$blocked = $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_blocks WHERE (blocker_id = %d AND blocked_id = %d) OR (blocker_id = %d AND blocked_id = %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$follower_id,
				$following_id,
				$following_id,
				$follower_id
			)
		);
		if ( $blocked ) {
			return false;
		}

		// Use INSERT IGNORE to handle race conditions — if two concurrent requests
		// both pass the check, only one will succeed; the other silently no-ops.
		$table  = $wpdb->prefix . 'mvs_follows';
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (follower_id, following_id, status, created_at) VALUES (%d, %d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$follower_id,
				$following_id,
				'active',
				current_time( 'mysql', true )
			)
		);

		if ( $result && $wpdb->insert_id ) {
			/**
			 * Fires after a user follows another user.
			 *
			 * @since 1.1.0
			 *
			 * @param int $follower_id  The user doing the following.
			 * @param int $following_id The user being followed.
			 */
			do_action( 'mvs_user_followed', $follower_id, $following_id );

			return $wpdb->insert_id;
		}

		// Already following (INSERT IGNORE no-op) — return true.
		return $this->is_following( $follower_id, $following_id );
	}

	/**
	 * Unfollow a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int $follower_id  The user unfollowing.
	 * @param int $following_id The user being unfollowed.
	 * @return bool True on success.
	 */
	public function unfollow( int $follower_id, int $following_id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_follows',
			array(
				'follower_id'  => $follower_id,
				'following_id' => $following_id,
			),
			array( '%d', '%d' )
		);

		if ( $deleted ) {
			/**
			 * Fires after a user unfollows another user.
			 *
			 * @since 1.1.0
			 *
			 * @param int $follower_id  The user unfollowing.
			 * @param int $following_id The user being unfollowed.
			 */
			do_action( 'mvs_user_unfollowed', $follower_id, $following_id );
		}

		return (bool) $deleted;
	}

	/**
	 * Check if a user is following another.
	 *
	 * @since 1.1.0
	 *
	 * @param int $follower_id  Follower user ID.
	 * @param int $following_id Following user ID.
	 * @return bool
	 */
	public function is_following( int $follower_id, int $following_id ): bool {
		global $wpdb;

		$row = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}mvs_follows WHERE follower_id = %d AND following_id = %d AND status = 'active'",
				$follower_id,
				$following_id
			)
		);

		return (bool) $row;
	}

	/**
	 * Get followers of a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id  User ID.
	 * @param int $per_page Results per page.
	 * @param int $page     Page number.
	 * @return array { followers: array, total: int }
	 */
	public function get_followers( int $user_id, int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_follows WHERE following_id = %d AND status = 'active'",
				$user_id
			)
		);

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT follower_id FROM {$wpdb->prefix}mvs_follows WHERE following_id = %d AND status = 'active' ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);

		return array(
			'users' => array_map( 'intval', $ids ),
			'total' => $total,
		);
	}

	/**
	 * Get users that a user is following.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id  User ID.
	 * @param int $per_page Results per page.
	 * @param int $page     Page number.
	 * @return array { users: array, total: int }
	 */
	public function get_following( int $user_id, int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_follows WHERE follower_id = %d AND status = 'active'",
				$user_id
			)
		);

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT following_id FROM {$wpdb->prefix}mvs_follows WHERE follower_id = %d AND status = 'active' ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);

		return array(
			'users' => array_map( 'intval', $ids ),
			'total' => $total,
		);
	}

	/**
	 * Get follower and following counts for a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @return array { followers: int, following: int }
	 */
	public function get_counts( int $user_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$followers = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_follows WHERE following_id = %d AND status = 'active'",
				$user_id
			)
		);

		$following = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_follows WHERE follower_id = %d AND status = 'active'",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return array(
			'followers' => $followers,
			'following' => $following,
		);
	}

	/**
	 * Get IDs of users that a given user follows (for feed filtering).
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @return int[] Array of followed user IDs.
	 */
	public function get_following_ids( int $user_id ): array {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT following_id FROM {$wpdb->prefix}mvs_follows WHERE follower_id = %d AND status = 'active'",
				$user_id
			)
		);

		return array_map( 'intval', $ids );
	}
}
