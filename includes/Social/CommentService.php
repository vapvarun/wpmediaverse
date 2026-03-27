<?php
/**
 * Comment service.
 *
 * Handles threaded comments with @mention support.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Social;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Handles threaded comments on media items using WordPress comments.
 */
class CommentService {

	/**
	 * Custom comment type for media comments.
	 *
	 * @var string
	 */
	const COMMENT_TYPE = 'mvs_comment';

	/**
	 * Add a comment to a media item.
	 *
	 * @param int    $media_id  Media post ID.
	 * @param int    $user_id   Commenter user ID.
	 * @param string $content   Comment content.
	 * @param int    $parent_id Parent comment ID for threading (0 for top-level).
	 * @return int|WP_Error Comment ID on success.
	 */
	public function add( int $media_id, int $user_id, string $content, int $parent_id = 0 ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'mvs_invalid_user', __( 'Invalid user.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		// Validate parent comment belongs to same media.
		if ( $parent_id ) {
			$parent = get_comment( $parent_id );
			if ( ! $parent || (int) $parent->comment_post_ID !== $media_id || self::COMMENT_TYPE !== $parent->comment_type ) {
				return new WP_Error( 'mvs_invalid_parent', __( 'Invalid parent comment.', 'wpmediaverse' ), array( 'status' => 400 ) );
			}
		}

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'  => $media_id,
				'user_id'          => $user_id,
				'comment_author'   => $user->display_name,
				'comment_content'  => wp_kses_post( $content ),
				'comment_type'     => self::COMMENT_TYPE,
				'comment_parent'   => $parent_id,
				'comment_approved' => 1,
			)
		);

		if ( ! $comment_id ) {
			return new WP_Error( 'mvs_comment_failed', __( 'Failed to create comment.', 'wpmediaverse' ), array( 'status' => 500 ) );
		}

		$this->sync_stats( $media_id );

		/**
		 * Fires after a media comment is created.
		 *
		 * @param int    $media_id   Media post ID.
		 * @param int    $user_id    Commenter user ID.
		 * @param int    $comment_id Comment ID.
		 * @param string $content    Comment content.
		 */
		do_action( 'mvs_comment_created', $media_id, $user_id, $comment_id, $content );

		return $comment_id;
	}

	/**
	 * Get comments for a media item (threaded).
	 *
	 * @param int $media_id Media post ID.
	 * @param int $per_page Comments per page.
	 * @param int $page     Page number.
	 * @return array{comments: array, total: int}
	 */
	public function get_for_media( int $media_id, int $per_page = 20, int $page = 1 ): array {
		// Count total top-level comments.
		$total = (int) get_comments(
			array(
				'post_id' => $media_id,
				'type'    => self::COMMENT_TYPE,
				'status'  => 'approve',
				'parent'  => 0,
				'count'   => true,
			)
		);

		// Fetch top-level comments for the current page.
		$top_level = get_comments(
			array(
				'post_id' => $media_id,
				'type'    => self::COMMENT_TYPE,
				'status'  => 'approve',
				'parent'  => 0,
				'number'  => $per_page,
				'offset'  => ( $page - 1 ) * $per_page,
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			)
		);

		// Fetch ALL replies for this media in one query, then build tree in memory.
		$all_replies = array();
		if ( $top_level ) {
			$replies_raw = get_comments(
				array(
					'post_id'        => $media_id,
					'type'           => self::COMMENT_TYPE,
					'status'         => 'approve',
					'parent__not_in' => array( 0 ),
					'orderby'        => 'comment_date_gmt',
					'order'          => 'ASC',
					'number'         => 0, // All replies.
				)
			);
			foreach ( $replies_raw as $reply ) {
				$parent_id = (int) $reply->comment_parent;
				if ( ! isset( $all_replies[ $parent_id ] ) ) {
					$all_replies[ $parent_id ] = array();
				}
				$all_replies[ $parent_id ][] = $reply;
			}
		}

		$result = array();
		foreach ( $top_level as $comment ) {
			$result[] = $this->format_comment_with_replies( $comment, $all_replies );
		}

		return array(
			'comments' => $result,
			'total'    => $total,
		);
	}

	/**
	 * Delete a comment.
	 *
	 * @param int $comment_id Comment ID.
	 * @param int $user_id    User requesting deletion.
	 * @return true|WP_Error
	 */
	public function delete( int $comment_id, int $user_id ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment || self::COMMENT_TYPE !== $comment->comment_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Comment not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Only the comment author or a moderator can delete.
		if ( (int) $comment->user_id !== $user_id && ! user_can( $user_id, 'moderate_mvs_media' ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You cannot delete this comment.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}

		$media_id = (int) $comment->comment_post_ID;
		wp_delete_comment( $comment_id, true );
		$this->sync_stats( $media_id );

		return true;
	}

	/**
	 * Format a comment for API response.
	 *
	 * @param \WP_Comment $comment         Comment object.
	 * @param bool        $include_replies Whether to include replies.
	 * @return array
	 */
	private function format_comment( $comment, bool $include_replies = false ): array {
		$cmt_author_id = (int) $comment->user_id;
		$data          = array(
			'id'            => (int) $comment->comment_ID,
			'author'        => $cmt_author_id,
			'author_name'   => $comment->comment_author,
			'author_avatar' => $cmt_author_id ? (string) get_avatar_url( $cmt_author_id, array( 'size' => 48 ) ) : '',
			'content'       => $comment->comment_content,
			'parent'        => (int) $comment->comment_parent,
			'date'          => $comment->comment_date_gmt,
		);

		if ( $include_replies ) {
			$replies = get_comments(
				array(
					'parent'  => $comment->comment_ID,
					'type'    => self::COMMENT_TYPE,
					'status'  => 'approve',
					'orderby' => 'comment_date_gmt',
					'order'   => 'ASC',
				)
			);

			$data['replies'] = array();
			foreach ( $replies as $reply ) {
				$data['replies'][] = $this->format_comment( $reply, false );
			}
		}

		return $data;
	}

	/**
	 * Format a comment with replies from pre-loaded reply map (no N+1).
	 *
	 * @param \WP_Comment $comment     Comment object.
	 * @param array       $replies_map Map of parent_id => WP_Comment[].
	 * @return array
	 */
	private function format_comment_with_replies( $comment, array $replies_map ): array {
		$comment_id = (int) $comment->comment_ID;
		$author_id  = (int) $comment->user_id;
		$data       = array(
			'id'            => $comment_id,
			'author'        => $author_id,
			'author_name'   => $comment->comment_author,
			'author_avatar' => $author_id ? (string) get_avatar_url( $author_id, array( 'size' => 48 ) ) : '',
			'content'       => $comment->comment_content,
			'parent'        => (int) $comment->comment_parent,
			'date'          => $comment->comment_date_gmt,
			'replies'       => array(),
		);

		if ( isset( $replies_map[ $comment_id ] ) ) {
			foreach ( $replies_map[ $comment_id ] as $reply ) {
				$data['replies'][] = $this->format_comment( $reply, false );
			}
		}

		return $data;
	}

	/**
	 * Sync the comment count in the stats table.
	 *
	 * @param int $media_id Media post ID.
	 */
	private function sync_stats( int $media_id ): void {
		$total = (int) get_comments(
			array(
				'post_id' => $media_id,
				'type'    => self::COMMENT_TYPE,
				'status'  => 'approve',
				'count'   => true,
			)
		);

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_stats',
			array(
				'comments'   => $total,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'media_id' => $media_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}
}
