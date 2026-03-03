<?php
/**
 * Mention service.
 *
 * Parses @mentions from text, stores records, and triggers hooks.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Parses @mentions and stores records in the mentions table.
 */
class MentionService {

	/**
	 * Regex pattern for @mentions.
	 *
	 * @var string
	 */
	const MENTION_PATTERN = '/@([a-zA-Z0-9_]+)/';

	/**
	 * Parse text for @mentions and store them.
	 *
	 * @param string   $text       Text to parse.
	 * @param int      $media_id   Media post ID.
	 * @param string   $context    Context: 'comment' or 'description'.
	 * @param int|null $comment_id Comment ID if context is comment.
	 * @return int[] Array of mentioned user IDs.
	 */
	public function parse_and_store( string $text, int $media_id, string $context = 'description', ?int $comment_id = null ): array {
		$usernames     = $this->extract_usernames( $text );
		$mentioned_ids = array();

		foreach ( $usernames as $username ) {
			$user = get_user_by( 'login', $username );
			if ( ! $user ) {
				continue;
			}

			$this->store_mention( $media_id, $user->ID, $context, $comment_id );
			$mentioned_ids[] = $user->ID;
		}

		if ( ! empty( $mentioned_ids ) ) {
			/**
			 * Fires after mentions are parsed and stored.
			 *
			 * @param int   $media_id      Media post ID.
			 * @param int[] $mentioned_ids  User IDs that were mentioned.
			 * @param string $context       Context: 'comment' or 'description'.
			 * @param int|null $comment_id  Comment ID if applicable.
			 */
			do_action( 'mvs_mentions_created', $media_id, $mentioned_ids, $context, $comment_id );
		}

		return $mentioned_ids;
	}

	/**
	 * Extract @usernames from text.
	 *
	 * @param string $text Text to parse.
	 * @return string[] Array of usernames (without @ prefix).
	 */
	public function extract_usernames( string $text ): array {
		preg_match_all( self::MENTION_PATTERN, $text, $matches );
		return array_unique( $matches[1] );
	}

	/**
	 * Store a mention record.
	 *
	 * @param int      $media_id         Media post ID.
	 * @param int      $mentioned_user_id Mentioned user ID.
	 * @param string   $context          Context.
	 * @param int|null $comment_id       Comment ID.
	 */
	private function store_mention( int $media_id, int $mentioned_user_id, string $context, ?int $comment_id ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_mentions',
			array(
				'media_id'          => $media_id,
				'mentioned_user_id' => $mentioned_user_id,
				'context'           => $context,
				'comment_id'        => $comment_id,
				'created_at'        => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * Get mentions for a user.
	 *
	 * @param int $user_id  User ID.
	 * @param int $per_page Items per page.
	 * @param int $page     Page number.
	 * @return array{items: array, total: int}
	 */
	public function get_for_user( int $user_id, int $per_page = 20, int $page = 1 ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'mvs_mentions';
		$offset = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE mentioned_user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			)
		);

		$items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id, context, comment_id, created_at FROM {$table} WHERE mentioned_user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
