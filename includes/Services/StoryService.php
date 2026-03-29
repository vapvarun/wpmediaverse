<?php
/**
 * Story service.
 *
 * Handles time-limited media (stories) that expire after a set duration.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Manages time-limited media stories.
 */
class StoryService {

	/**
	 * Default story duration in hours.
	 *
	 * @var int
	 */
	const DEFAULT_DURATION_HOURS = 24;

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	const CLEANUP_HOOK = 'mvs_story_cleanup';

	/**
	 * Initialize story hooks.
	 */
	public function init(): void {
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_expired' ) );

		// Schedule cleanup if not already scheduled.
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Mark a media item as a story with an expiration time.
	 *
	 * @param int $media_id      Media post ID.
	 * @param int $duration_hours Duration in hours (default 24).
	 * @return string Expiration datetime (UTC).
	 */
	public function create( int $media_id, int $duration_hours = self::DEFAULT_DURATION_HOURS ): string {
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $duration_hours * HOUR_IN_SECONDS ) );

		MediaMeta::set( $media_id, 'is_story', '1' );
		MediaMeta::set( $media_id, 'story_expires_at', $expires_at );

		/**
		 * Fires after a story is created.
		 *
		 * @param int    $media_id   Media post ID.
		 * @param string $expires_at Expiration datetime (UTC).
		 */
		do_action( 'mvs_story_created', $media_id, $expires_at );

		return $expires_at;
	}

	/**
	 * Check if a media item is an active story.
	 *
	 * @param int $media_id Media post ID.
	 * @return bool
	 */
	public function is_active( int $media_id ): bool {
		$is_story = MediaMeta::get( $media_id, 'is_story' );
		if ( '1' !== $is_story ) {
			return false;
		}

		$expires_at = MediaMeta::get( $media_id, 'story_expires_at' );
		if ( ! $expires_at ) {
			return false;
		}

		return strtotime( $expires_at ) > time();
	}

	/**
	 * Get active stories, optionally filtered by author.
	 *
	 * @param int|null $author_id Optional author filter.
	 * @param int      $per_page  Items per page.
	 * @param int      $page      Page number.
	 * @return array{items: array, total: int}
	 */
	public function get_active( ?int $author_id = null, int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// Query active story IDs from mvs_media_meta custom table (not wp_postmeta).
		$story_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT m1.media_id
				FROM {$wpdb->prefix}mvs_media_meta m1
				INNER JOIN {$wpdb->prefix}mvs_media_meta m2 ON m1.media_id = m2.media_id
				WHERE m1.meta_key = 'is_story' AND m1.meta_value = '1'
				AND m2.meta_key = 'story_expires_at' AND m2.meta_value > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);

		if ( empty( $story_ids ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$args = array(
			'post_type'      => 'mvs_media',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post__in'       => array_map( 'intval', $story_ids ),
		);

		if ( $author_id ) {
			$args['author'] = $author_id;
		}

		$query = new \WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'media_id'   => $post->ID,
				'author'     => (int) $post->post_author,
				'title'      => $post->post_title,
				'expires_at' => MediaMeta::get( $post->ID, 'story_expires_at' ),
			);
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Clean up expired stories by removing story meta.
	 *
	 * Does not delete the media post — just removes the story designation.
	 *
	 * @return int Number of stories cleaned up.
	 */
	public function cleanup_expired(): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// Query expired story IDs from mvs_media_meta custom table (not wp_postmeta).
		$expired_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT m1.media_id
				FROM {$wpdb->prefix}mvs_media_meta m1
				INNER JOIN {$wpdb->prefix}mvs_media_meta m2 ON m1.media_id = m2.media_id
				WHERE m1.meta_key = 'is_story' AND m1.meta_value = '1'
				AND m2.meta_key = 'story_expires_at' AND m2.meta_value <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);

		$cleaned = 0;

		foreach ( $expired_ids as $media_id ) {
			$media_id = (int) $media_id;
			MediaMeta::delete( $media_id, 'is_story' );
			MediaMeta::delete( $media_id, 'story_expires_at' );
			++$cleaned;

			/**
			 * Fires after a story expires and is cleaned up.
			 *
			 * @param int $media_id Media post ID.
			 */
			do_action( 'mvs_story_expired', $media_id );
		}

		return $cleaned;
	}

	/**
	 * Unschedule the cleanup cron on deactivation.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CLEANUP_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CLEANUP_HOOK );
		}
	}
}
