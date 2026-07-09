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
	 * @param int $media_id Media ID (mvs_media_index PK, not a wp_posts ID).
	 * @return string
	 */
	public function get_status( int $media_id ): string {
		$status = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'moderation_status' );
		return $status ? $status : self::STATUS_APPROVED;
	}

	/**
	 * Set the moderation status for a media item.
	 *
	 * @param int    $media_id Media ID (mvs_media_index PK, not a wp_posts ID).
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
		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'moderation_status', $status );

		// Log moderation action.
		$this->log_action( $media_id, $status, $user_id );

		/**
		 * Fires when a media item's moderation status changes.
		 *
		 * @param int    $media_id   Media ID (mvs_media_index PK, not a wp_posts ID).
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
	 * @param int   $media_id Media ID (mvs_media_index PK, not a wp_posts ID).
	 * @param array $result   AI moderation result.
	 */
	public function handle_flagged( int $media_id, array $result ): void {
		$auto_action = get_option( 'mvs_moderation_auto_action', 'flag' );

		switch ( $auto_action ) {
			case 'delete':
				// Hard removal: purge the record AND every stored file (original
				// + variants) from local and the active cloud driver via the
				// shared delete funnel, so AI-flagged content cannot linger on
				// BunnyCDN/S3/R2. Irreversible by design.
				\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete_cascade( $media_id );
				break;

			case 'reject':
				// Media lives in mvs_media_index, not wp_posts — moderation state
				// is the moderation_status column; feed/list queries filter on it.
				$this->set_status( $media_id, self::STATUS_REJECTED );
				break;

			case 'hide':
				$this->set_status( $media_id, self::STATUS_FLAGGED );
				\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'privacy', 'private' );
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
	 * @return array{items: int[], total: int, pages: int} Items are mvs_media_index media IDs — NOT wp_posts IDs (the two id-spaces can collide). Resolve via MediaRepository, never get_post($media_id).
	 */
	public function get_queue( array $args = array() ): array {
		$defaults = array(
			'status'   => self::STATUS_FLAGGED,
			'per_page' => 20,
			'page'     => 1,
		);

		$args = wp_parse_args( $args, $defaults );

		global $wpdb;
		$index_table = $wpdb->prefix . 'mvs_media_index';
		$offset      = ( $args['page'] - 1 ) * $args['per_page'];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index_table} WHERE moderation_status = %s",
				$args['status']
			)
		);

		$media_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT media_id FROM {$index_table}
				WHERE moderation_status = %s
				ORDER BY created_at DESC
				LIMIT %d OFFSET %d",
				$args['status'],
				$args['per_page'],
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$pages = $args['per_page'] > 0 ? (int) ceil( $total / $args['per_page'] ) : 1;

		return array(
			'items' => $media_ids,
			'total' => $total,
			'pages' => $pages,
		);
	}

	/**
	 * Approve a media item.
	 *
	 * @param int $media_id Media ID (mvs_media_index PK, not a wp_posts ID).
	 * @param int $user_id  Moderator user ID.
	 * @return bool
	 */
	public function approve( int $media_id, int $user_id ): bool {
		// Media lives in mvs_media_index; approval only clears the moderation
		// hold (moderation_status column) — it must not touch any wp_posts row.
		return $this->set_status( $media_id, self::STATUS_APPROVED, $user_id );
	}

	/**
	 * Reject a media item.
	 *
	 * @param int    $media_id Media ID (mvs_media_index PK, not a wp_posts ID).
	 * @param int    $user_id  Moderator user ID.
	 * @param string $reason   Optional rejection reason.
	 * @return bool
	 */
	public function reject( int $media_id, int $user_id, string $reason = '' ): bool {
		$result = $this->set_status( $media_id, self::STATUS_REJECTED, $user_id );

		// Rejection hides media via the moderation_status filter in feed/list
		// queries — no wp_posts mutation. Only persist the optional reason.
		if ( $result && $reason ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'rejection_reason', sanitize_text_field( $reason ) );
		}

		return $result;
	}

	/**
	 * Log a moderation action.
	 *
	 * @param int    $media_id Media ID (mvs_media_index PK, not a wp_posts ID).
	 * @param string $status   New status.
	 * @param int    $user_id  Moderator user ID (0 for automated).
	 */
	private function log_action( int $media_id, string $status, int $user_id ): void {
		$log = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'moderation_log' );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'status'  => $status,
			'user_id' => $user_id,
			'date'    => current_time( 'mysql', true ),
		);

		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'moderation_log', $log );
	}

	/**
	 * Get moderation log for a media item.
	 *
	 * @param int $media_id Media ID (mvs_media_index PK, not a wp_posts ID).
	 * @return array
	 */
	public function get_log( int $media_id ): array {
		$log = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'moderation_log' );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Get queue counts by status.
	 *
	 * @return array{pending: int, flagged: int, rejected: int}
	 */
	public function get_counts(): array {
		global $wpdb;

		$index_table = $wpdb->prefix . 'mvs_media_index';
		$results     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT moderation_status AS status, COUNT(*) AS count
			FROM {$index_table}
			WHERE moderation_status IN ('pending', 'flagged', 'rejected')
			GROUP BY moderation_status", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
