<?php
/**
 * Moderation service.
 *
 * Manages content moderation queue, approval workflows, and automated actions.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Manages content moderation queue and approval workflows.
 */
class ModerationService {

	/**
	 * Moderation statuses.
	 *
	 * @var string
	 */
	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_FLAGGED  = 'flagged';
	const STATUS_REJECTED = 'rejected';

	/**
	 * AI service instance.
	 *
	 * @var AIService
	 */
	private $ai;

	/**
	 * Constructor.
	 *
	 * @param AIService $ai AI service.
	 */
	public function __construct( AIService $ai ) {
		$this->ai = $ai;
	}

	/**
	 * Initialize hooks.
	 */
	public function init(): void {
		add_action( 'mvs_media_flagged', array( $this, 'handle_flagged' ), 10, 2 );
	}

	/**
	 * Get the moderation status for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return string
	 */
	public function get_status( int $media_id ): string {
		$status = get_post_meta( $media_id, '_mvs_moderation_status', true );
		return $status ? $status : self::STATUS_APPROVED;
	}

	/**
	 * Set the moderation status for a media item.
	 *
	 * @param int    $media_id Media post ID.
	 * @param string $status   One of the STATUS_* constants.
	 * @param int    $user_id  Moderator user ID (0 for automated).
	 * @return bool
	 */
	public function set_status( int $media_id, string $status, int $user_id = 0 ): bool {
		$allowed = array( self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_FLAGGED, self::STATUS_REJECTED );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		$old_status = $this->get_status( $media_id );
		update_post_meta( $media_id, '_mvs_moderation_status', $status );

		// Log moderation action.
		$this->log_action( $media_id, $status, $user_id );

		/**
		 * Fires when a media item's moderation status changes.
		 *
		 * @param int    $media_id   Media post ID.
		 * @param string $status     New status.
		 * @param string $old_status Previous status.
		 * @param int    $user_id    Moderator user ID (0 for automated).
		 */
		do_action( 'mvs_moderation_changed', $media_id, $status, $old_status, $user_id );

		return true;
	}

	/**
	 * Handle AI-flagged content.
	 *
	 * @param int   $media_id Media post ID.
	 * @param array $result   AI moderation result.
	 */
	public function handle_flagged( int $media_id, array $result ): void {
		$auto_action = get_option( 'mvs_moderation_auto_action', 'flag' );

		switch ( $auto_action ) {
			case 'reject':
				$this->set_status( $media_id, self::STATUS_REJECTED );
				wp_update_post(
					array(
						'ID'          => $media_id,
						'post_status' => 'draft',
					)
				);
				break;

			case 'hide':
				$this->set_status( $media_id, self::STATUS_FLAGGED );
				update_post_meta( $media_id, '_mvs_privacy', 'private' );
				break;

			case 'flag':
			default:
				$this->set_status( $media_id, self::STATUS_FLAGGED );
				break;
		}
	}

	/**
	 * Get items in the moderation queue.
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type string $status   Filter by status. Default 'flagged'.
	 *     @type int    $per_page Items per page. Default 20.
	 *     @type int    $page     Page number. Default 1.
	 * }
	 * @return array{items: \WP_Post[], total: int, pages: int}
	 */
	public function get_queue( array $args = array() ): array {
		$defaults = array(
			'status'   => self::STATUS_FLAGGED,
			'per_page' => 20,
			'page'     => 1,
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'post_type'      => 'mvs_media',
			'post_status'    => 'any',
			'posts_per_page' => $args['per_page'],
			'paged'          => $args['page'],
			'meta_key'       => '_mvs_moderation_status',
			'meta_value'     => $args['status'],
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$query = new \WP_Query( $query_args );

		return array(
			'items' => $query->posts,
			'total' => $query->found_posts,
			'pages' => $query->max_num_pages,
		);
	}

	/**
	 * Approve a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  Moderator user ID.
	 * @return bool
	 */
	public function approve( int $media_id, int $user_id ): bool {
		$result = $this->set_status( $media_id, self::STATUS_APPROVED, $user_id );

		if ( $result ) {
			wp_update_post(
				array(
					'ID'          => $media_id,
					'post_status' => 'publish',
				)
			);
		}

		return $result;
	}

	/**
	 * Reject a media item.
	 *
	 * @param int    $media_id Media post ID.
	 * @param int    $user_id  Moderator user ID.
	 * @param string $reason   Optional rejection reason.
	 * @return bool
	 */
	public function reject( int $media_id, int $user_id, string $reason = '' ): bool {
		$result = $this->set_status( $media_id, self::STATUS_REJECTED, $user_id );

		if ( $result ) {
			wp_update_post(
				array(
					'ID'          => $media_id,
					'post_status' => 'draft',
				)
			);

			if ( $reason ) {
				update_post_meta( $media_id, '_mvs_rejection_reason', sanitize_text_field( $reason ) );
			}
		}

		return $result;
	}

	/**
	 * Log a moderation action.
	 *
	 * @param int    $media_id Media post ID.
	 * @param string $status   New status.
	 * @param int    $user_id  Moderator user ID (0 for automated).
	 */
	private function log_action( int $media_id, string $status, int $user_id ): void {
		$log = get_post_meta( $media_id, '_mvs_moderation_log', true );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'status'  => $status,
			'user_id' => $user_id,
			'date'    => current_time( 'mysql', true ),
		);

		update_post_meta( $media_id, '_mvs_moderation_log', $log );
	}

	/**
	 * Get moderation log for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return array
	 */
	public function get_log( int $media_id ): array {
		$log = get_post_meta( $media_id, '_mvs_moderation_log', true );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Get queue counts by status.
	 *
	 * @return array{pending: int, flagged: int, rejected: int}
	 */
	public function get_counts(): array {
		global $wpdb;

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_value AS status, COUNT(*) AS count
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				AND meta_value IN ('pending', 'flagged', 'rejected')
				GROUP BY meta_value",
				'_mvs_moderation_status'
			),
			ARRAY_A
		);

		$counts = array(
			'pending'  => 0,
			'flagged'  => 0,
			'rejected' => 0,
		);

		foreach ( $results as $row ) {
			$counts[ $row['status'] ] = (int) $row['count'];
		}

		return $counts;
	}
}
