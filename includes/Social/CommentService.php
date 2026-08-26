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
	 * Comment-meta key that records which media item a comment belongs to.
	 *
	 * Media comments are stored with `comment_post_ID = 0` (they are NOT tied to
	 * a WordPress post) and the real media id lives here instead. Storing the
	 * media id in `comment_post_ID` — as earlier versions did — collided with the
	 * shared wp_posts auto-increment space, so a media comment surfaced on the
	 * post/page that happened to share that id and inflated its comment count.
	 *
	 * @var string
	 */
	const MEDIA_META_KEY = 'mvs_media_id';

	/**
	 * Keep media comments (`mvs_comment`) out of every comment query that is not
	 * explicitly scoped to them — theme comment loops, feeds, wp-admin, the core
	 * `/wp/v2/comments` REST route, comment-count recalcs. Belt-and-suspenders
	 * alongside the `comment_post_ID = 0` storage: even a stray legacy row can
	 * never leak into a real post's thread. Registered once from Plugin::init().
	 *
	 * MVS's own reads always pass `type => mvs_comment`, so they are left intact.
	 *
	 * @return void
	 */
	public static function register_query_guard(): void {
		add_action( 'pre_get_comments', array( __CLASS__, 'exclude_media_comments_from_foreign_queries' ) );
	}

	/**
	 * pre_get_comments handler — exclude `mvs_comment` unless the query asked for it.
	 *
	 * @param \WP_Comment_Query $query The comment query, mutated in place.
	 * @return void
	 */
	public static function exclude_media_comments_from_foreign_queries( $query ): void {
		$vars    = $query->query_vars;
		$type    = isset( $vars['type'] ) ? $vars['type'] : '';
		$type_in = isset( $vars['type__in'] ) ? (array) $vars['type__in'] : array();

		// Our own reads scope to mvs_comment explicitly — leave them alone.
		if ( self::COMMENT_TYPE === $type || in_array( self::COMMENT_TYPE, $type_in, true ) ) {
			return;
		}

		$not_in   = isset( $vars['type__not_in'] ) ? (array) $vars['type__not_in'] : array();
		$not_in[] = self::COMMENT_TYPE;

		$query->query_vars['type__not_in'] = array_values( array_unique( $not_in ) );
	}

	/**
	 * Resolve the media id a comment belongs to from its meta.
	 *
	 * @param int $comment_id Comment id.
	 * @return int Media id, or 0 when not an MVS media comment.
	 */
	public static function comment_media_id( int $comment_id ): int {
		return (int) get_comment_meta( $comment_id, self::MEDIA_META_KEY, true );
	}

	/**
	 * Shared query args scoping a comment query to one media item's comments.
	 *
	 * @param int $media_id Media id.
	 * @return array<string,mixed>
	 */
	private function media_scope_args( int $media_id ): array {
		return array(
			'type'       => self::COMMENT_TYPE,
			'status'     => 'approve',
			'meta_key'   => self::MEDIA_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => (string) $media_id,   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		);
	}

	/**
	 * Add a comment to a media item.
	 *
	 * @param int    $media_id  Media post ID.
	 * @param int    $user_id   Commenter user ID.
	 * @param string $content   Comment content.
	 * @param int    $parent_id Parent comment ID for threading (0 for top-level).
	 * @param string $source    Optional source identifier (e.g. 'bp_activity') to prevent sync loops.
	 * @return int|WP_Error Comment ID on success.
	 */
	/**
	 * Find an identical comment by the same author on the same media, posted
	 * within the dedupe window.
	 *
	 * Matches on (media, author, parent, exact content) rather than content
	 * alone: replying "thanks" to two different people, or on two different
	 * media items, is legitimate and must still work.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $media_id  Media ID.
	 * @param int    $user_id   Author.
	 * @param string $content   Raw comment content.
	 * @param int    $parent_id Parent comment, 0 for a top-level comment.
	 * @return int Comment ID of the duplicate, or 0 if there is none.
	 */
	private function find_recent_duplicate( int $media_id, int $user_id, string $content, int $parent_id ): int {
		/**
		 * Filters the duplicate-comment window, in seconds.
		 *
		 * Wide enough to absorb a double-click or a retry on a slow connection,
		 * short enough that deliberately repeating yourself later still works.
		 * Return 0 to disable the server-side guard entirely.
		 *
		 * @since 2.3.0
		 *
		 * @param int $seconds  Window length. Default 60.
		 * @param int $media_id Media ID.
		 * @param int $user_id  Author.
		 */
		$window = (int) apply_filters( 'mvs_comment_duplicate_window', 60, $media_id, $user_id );
		if ( $window <= 0 ) {
			return 0;
		}

		$normalised = trim( $content );
		if ( '' === $normalised ) {
			return 0;
		}

		$recent = get_comments(
			array(
				'type'       => self::COMMENT_TYPE,
				'user_id'    => $user_id,
				'parent'     => $parent_id,
				'number'     => 5,
				'orderby'    => 'comment_date_gmt',
				'order'      => 'DESC',
				'status'     => 'all',
				'date_query' => array(
					array(
						'after'     => gmdate( 'Y-m-d H:i:s', time() - $window ),
						'column'    => 'comment_date_gmt',
						'inclusive' => true,
					),
				),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded to 5 rows in a 60s window, on the write path only.
					array(
						'key'   => self::MEDIA_META_KEY,
						'value' => (string) $media_id,
					),
				),
			)
		);

		foreach ( (array) $recent as $comment ) {
			if ( trim( (string) $comment->comment_content ) === $normalised ) {
				return (int) $comment->comment_ID;
			}
		}

		return 0;
	}

	public function add( int $media_id, int $user_id, string $content, int $parent_id = 0, string $source = '' ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'mvs_invalid_user', __( 'Invalid user.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		// Comment-permission gate at the single write, not on one route: being
		// able to VIEW is enough to comment on ordinary media, but a document
		// reached through a view-only share must not be commentable. Pro narrows
		// the filter for documents; all other media keeps the default true. Lives
		// here so every client — web, lightbox, BP composer, REST, the planned
		// mobile app — is covered rather than just create_item (#10240475368).
		if ( ! (bool) apply_filters( 'mvs_can_comment', true, $media_id, $user_id ) ) {
			return new WP_Error(
				'mvs_comment_forbidden',
				__( 'You do not have permission to comment on this item.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}

		// Validate parent comment belongs to same media.
		if ( $parent_id ) {
			$parent = get_comment( $parent_id );
			if ( ! $parent || self::COMMENT_TYPE !== $parent->comment_type || self::comment_media_id( $parent_id ) !== $media_id ) {
				return new WP_Error( 'mvs_invalid_parent', __( 'Invalid parent comment.', 'wpmediaverse' ), array( 'status' => 400 ) );
			}
		}

		// Duplicate guard. wp_insert_comment() below is a raw DB insert that
		// bypasses wp_allow_comment(), so WordPress core's own
		// "Duplicate comment detected" check never runs for media comments. The
		// only other throttle is a 30-per-minute rate limit, which happily
		// allows 30 copies of the same comment.
		//
		// This is the ONLY layer that covers every client: the lightbox had no
		// in-flight guard at all, the BP activity composer disabled the input
		// but not the button, and the planned mobile app is a third client we
		// cannot guard from the browser (#10148507863).
		$duplicate = $this->find_recent_duplicate( $media_id, $user_id, $content, $parent_id );
		if ( $duplicate ) {
			return new WP_Error(
				'mvs_duplicate_comment',
				__( 'You have already posted that comment.', 'wpmediaverse' ),
				array(
					'status'     => 409,
					'comment_id' => $duplicate,
				)
			);
		}

		// comment_post_ID is deliberately 0: a media comment is NOT tied to a WP
		// post. The media id lives in the MEDIA_META_KEY meta. See the constant's
		// docblock for why storing it in comment_post_ID leaked onto real posts.
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'  => 0,
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

		add_comment_meta( (int) $comment_id, self::MEDIA_META_KEY, $media_id );

		$this->sync_stats( $media_id );

		/**
		 * Fires after a media comment is created.
		 *
		 * @param int    $media_id   Media post ID.
		 * @param int    $user_id    Commenter user ID.
		 * @param int    $comment_id Comment ID.
		 * @param string $content    Comment content.
		 * @param string $source     Source of the comment (e.g. 'bp_activity').
		 */
		do_action( 'mvs_comment_created', $media_id, $user_id, $comment_id, $content, $source );

		// Parse @mentions in the comment so mentioned users get notified. The
		// whole mention pipeline (MentionService -> mvs_mentions_created ->
		// NotificationService) was wired but parse_and_store had no caller, so
		// @mentions never fired (audit 2026-06-04, #19). Skip BP-sourced
		// comments — BuddyPress runs its own @mention handling on the activity
		// comment, so parsing here too would double-notify.
		if ( 'bp_activity' !== $source ) {
			$mvs_container = \WPMediaVerse\Core\Plugin::container();
			if ( $mvs_container->has( 'mentions' ) ) {
				$mvs_container->get( 'mentions' )->parse_and_store( $content, $media_id, 'comment', (int) $comment_id );
			}
		}

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
			array_merge(
				$this->media_scope_args( $media_id ),
				array(
					'parent' => 0,
					'count'  => true,
				)
			)
		);

		// Fetch top-level comments for the current page.
		$top_level = get_comments(
			array_merge(
				$this->media_scope_args( $media_id ),
				array(
					'parent'  => 0,
					'number'  => $per_page,
					'offset'  => ( $page - 1 ) * $per_page,
					'orderby' => 'comment_date_gmt',
					'order'   => 'DESC',
				)
			)
		);

		// Fetch ALL replies for this media in one query, then build tree in memory.
		$all_replies = array();
		if ( $top_level ) {
			$replies_raw = get_comments(
				array_merge(
					$this->media_scope_args( $media_id ),
					array(
						'parent__not_in' => array( 0 ),
						'orderby'        => 'comment_date_gmt',
						'order'          => 'ASC',
						'number'         => 0, // All replies.
					)
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

		$media_id = self::comment_media_id( (int) $comment_id );
		wp_delete_comment( $comment_id, true );
		$this->sync_stats( $media_id );

		return true;
	}

	/**
	 * Viewer-relative permission flags for a comment.
	 *
	 * Mirrors the real enforcement so the REST contract does not lie (Rule 13):
	 * `can_edit` matches CommentController::update_item() — author-only, within
	 * the `mvs_comment_edit_window` (default 15 min); `can_delete` matches
	 * delete() — author OR a moderator. All false for anonymous viewers.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $author_id        Comment author user ID.
	 * @param string $comment_date_gmt Comment GMT timestamp.
	 * @return array{is_author: bool, can_edit: bool, can_delete: bool}
	 */
	private function viewer_comment_flags( int $author_id, string $comment_date_gmt ): array {
		$viewer_id = get_current_user_id();
		$is_author = $viewer_id > 0 && $viewer_id === $author_id;

		/** This filter is documented in includes/REST/Controller/CommentController.php */
		$edit_window = (int) apply_filters(
			'mvs_comment_edit_window',
			(int) get_option( 'mvs_comment_edit_window', 15 * MINUTE_IN_SECONDS )
		);
		$ts          = strtotime( $comment_date_gmt );
		$within      = $ts && ( time() - $ts ) <= $edit_window;

		return array(
			'is_author'  => $is_author,
			'can_edit'   => $is_author && $within,
			'can_delete' => $is_author || ( $viewer_id > 0 && user_can( $viewer_id, 'moderate_mvs_media' ) ),
		);
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
		$comment_ts    = strtotime( $comment->comment_date_gmt );
		$data          = array(
			'id'            => (int) $comment->comment_ID,
			'author'        => $cmt_author_id,
			'author_name'   => $comment->comment_author,
			'author_avatar' => $cmt_author_id ? (string) get_avatar_url( $cmt_author_id, array( 'size' => 96 ) ) : '',
			'author_url'    => $cmt_author_id ? \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( $cmt_author_id ) : '',
			'content'       => $comment->comment_content,
			'parent'        => (int) $comment->comment_parent,
			'date'          => $comment->comment_date_gmt,
			/* translators: %s: human-readable time difference, e.g. "2 days" */
			'date_human'    => $comment_ts ? sprintf( __( '%s ago', 'wpmediaverse' ), human_time_diff( $comment_ts, time() ) ) : '',
		);

		$data += $this->viewer_comment_flags( $cmt_author_id, (string) $comment->comment_date_gmt );

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
		$comment_ts = strtotime( $comment->comment_date_gmt );
		$data       = array(
			'id'            => $comment_id,
			'author'        => $author_id,
			'author_name'   => $comment->comment_author,
			'author_avatar' => $author_id ? (string) get_avatar_url( $author_id, array( 'size' => 96 ) ) : '',
			'author_url'    => $author_id ? \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( $author_id ) : '',
			'content'       => $comment->comment_content,
			'parent'        => (int) $comment->comment_parent,
			'date'          => $comment->comment_date_gmt,
			/* translators: %s: human-readable time difference, e.g. "2 days" */
			'date_human'    => $comment_ts ? sprintf( __( '%s ago', 'wpmediaverse' ), human_time_diff( $comment_ts, time() ) ) : '',
			'replies'       => array(),
		);

		$data += $this->viewer_comment_flags( $author_id, (string) $comment->comment_date_gmt );

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
			array_merge(
				$this->media_scope_args( $media_id ),
				array( 'count' => true )
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
