<?php
/**
 * Comment REST controller.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPMediaVerse\REST\RateLimiter;
use WPMediaVerse\Social\CommentService;

/**
 * REST controller for media comments.
 */
class CommentController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * REST API base route.
	 *
	 * @var string
	 */
	protected $rest_base = 'media/(?P<media_id>[\d]+)/comments';

	/**
	 * Comment service instance.
	 *
	 * @var CommentService
	 */
	private $comments;

	/**
	 * Constructor.
	 *
	 * @param CommentService $comments Comment service instance.
	 */
	public function __construct( CommentService $comments ) {
		$this->comments = $comments;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// PUBLIC_OK on the GET /comments __return_true below: comments inherit
		// the parent media's visibility. If the media is private the comments
		// query returns empty (the underlying repo joins on
		// mvs_media_index.privacy). Same model as WP core comments REST.
		// POST /comments + edit/delete below carry proper permission_callbacks.
		// Triaged 2026-05-01 (Item 5).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'media_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'media_id'      => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'content'       => array(
							'type'     => 'string',
							'required' => true,
						),
						'parent'        => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'from_activity' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<comment_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'media_id'   => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'comment_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'content'    => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'media_id'   => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'comment_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Get comments for a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$media_id = $request->get_param( 'media_id' );

		if ( ! $this->media_exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Privacy gate. The route is public (comments inherit the media's
		// visibility), but get_for_media() is a plain get_comments() with NO
		// privacy join — so without this an anonymous caller could read every
		// comment on a members-only/private item (audit 2026-06-04). Mirror the
		// single-media page: a viewer who can't see the media gets a 404.
		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( (int) $media_id, get_current_user_id() ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$per_page = \WPMediaVerse\REST\Pagination::resolve_per_page( $request );
		$page     = (int) $request->get_param( 'page' );

		$result = $this->comments->get_for_media( $media_id, $per_page, $page );

		$response = rest_ensure_response( $result['comments'] );
		$response->header( 'X-WP-Total', $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $result['total'] / $per_page ) );

		return $response;
	}

	/**
	 * Create a comment on a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$rate_check = RateLimiter::check( 'comment_create', 30, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id = $request->get_param( 'media_id' );

		if ( ! $this->media_exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// You can only comment on media you can view — the permission check is
		// login-only, so without this a logged-in user could comment on a
		// private/members item they can't see (audit 2026-06-04).
		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( (int) $media_id, get_current_user_id() ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Being able to VIEW is enough to comment on ordinary media — for a photo
		// or video there is no separate "comment" permission. Documents (Pro) do
		// have a view/comment/edit ladder, and a view-only grantee can view the
		// file (Pro widens mvs_privacy_can_view for documents) yet must not be able
		// to write. Pro narrows this filter for documents; all other media keeps
		// the default true, so behaviour is unchanged everywhere else.
		$can_comment = (bool) apply_filters( 'mvs_can_comment', true, (int) $media_id, get_current_user_id() );
		if ( ! $can_comment ) {
			return new WP_Error( 'mvs_comment_forbidden', __( 'You do not have permission to comment on this item.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}

		$content = sanitize_textarea_field( $request->get_param( 'content' ) );
		if ( empty( $content ) ) {
			return new WP_Error( 'mvs_empty_comment', __( 'Comment content is required.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$parent        = (int) $request->get_param( 'parent' );
		$from_activity = (int) $request->get_param( 'from_activity' );

		// When comment originates from a BP activity lightbox, the JS already
		// posts a BP activity reply. Set a flag so ActivitySyncIntegration skips
		// creating a duplicate standalone activity.
		if ( $from_activity > 0 ) {
			if ( ! defined( 'MVS_COMMENT_FROM_ACTIVITY' ) ) {
				define( 'MVS_COMMENT_FROM_ACTIVITY', $from_activity );
			}
		}

		$comment_id = $this->comments->add( $media_id, get_current_user_id(), $content, $parent );

		if ( is_wp_error( $comment_id ) ) {
			return $comment_id;
		}

		$comment       = get_comment( $comment_id );
		$cmt_author_id = (int) $comment->user_id;
		$comment_ts    = strtotime( $comment->comment_date_gmt );
		$response      = rest_ensure_response(
			array(
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
			)
		);
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Edit a comment (own comments only, within 15-minute window).
	 *
	 * @since 1.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$rate_check = RateLimiter::check( 'comment_write', 30, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$comment_id = $request->get_param( 'comment_id' );
		$media_id   = $request->get_param( 'media_id' );
		$user_id    = get_current_user_id();

		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return new WP_Error( 'mvs_not_found', __( 'Comment not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Verify ownership.
		if ( (int) $comment->user_id !== $user_id ) {
			return new WP_Error( 'mvs_forbidden', __( 'You can only edit your own comments.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}

		// Verify comment belongs to media. Media comments are detached from the
		// post-ID space (comment_post_ID = 0); the owning media id is in meta.
		if ( \WPMediaVerse\Social\CommentService::comment_media_id( (int) $comment_id ) !== (int) $media_id ) {
			return new WP_Error( 'mvs_mismatch', __( 'Comment does not belong to this media item.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		// Edit window — option-driven with filter override. Option is declared in
		// SettingsRegistrar::register_undocumented_settings(); filter kept for
		// back-compat and programmatic override per-site.
		$edit_window = (int) apply_filters(
			'mvs_comment_edit_window',
			(int) get_option( 'mvs_comment_edit_window', 15 * MINUTE_IN_SECONDS )
		);
		$comment_age = time() - strtotime( $comment->comment_date_gmt );
		if ( $comment_age > $edit_window ) {
			$window_minutes = max( 1, (int) round( $edit_window / MINUTE_IN_SECONDS ) );
			return new WP_Error(
				'mvs_edit_expired',
				sprintf(
					/* translators: %d: number of minutes in the edit window. */
					_n(
						'Comments can only be edited within %d minute of posting.',
						'Comments can only be edited within %d minutes of posting.',
						$window_minutes,
						'wpmediaverse'
					),
					$window_minutes
				),
				array( 'status' => 403 )
			);
		}

		$content = sanitize_textarea_field( $request->get_param( 'content' ) );
		if ( empty( $content ) ) {
			return new WP_Error( 'mvs_empty_comment', __( 'Comment content is required.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$result = wp_update_comment(
			array(
				'comment_ID'      => $comment_id,
				'comment_content' => $content,
			)
		);

		if ( ! $result ) {
			return new WP_Error( 'mvs_update_failed', __( 'Failed to update comment.', 'wpmediaverse' ), array( 'status' => 500 ) );
		}

		$updated = get_comment( $comment_id );

		return rest_ensure_response(
			array(
				'id'          => (int) $updated->comment_ID,
				'author'      => (int) $updated->user_id,
				'author_name' => $updated->comment_author,
				'content'     => $updated->comment_content,
				'parent'      => (int) $updated->comment_parent,
				'date'        => $updated->comment_date_gmt,
				'edited'      => true,
			)
		);
	}

	/**
	 * Delete a comment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$rate_check = RateLimiter::check( 'comment_write', 30, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$comment_id = $request->get_param( 'comment_id' );
		$media_id   = $request->get_param( 'media_id' );

		// Verify the comment belongs to the specified media item (media id lives
		// in comment meta; comment_post_ID is 0 for detached media comments).
		$comment = get_comment( $comment_id );
		if ( $comment && \WPMediaVerse\Social\CommentService::comment_media_id( (int) $comment_id ) !== (int) $media_id ) {
			return new WP_Error( 'mvs_mismatch', __( 'Comment does not belong to this media item.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$result = $this->comments->delete( $comment_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Permissions: authenticated users can comment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'mvs_unauthorized', __( 'You must be logged in to comment.', 'wpmediaverse' ), array( 'status' => 401 ) );
		}

		// Block gate — App Passwords bypass login, so enforce here, not only at
		// the data layer. A blocked relationship cannot comment on the other's media.
		$author  = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( (int) $request->get_param( 'media_id' ) );
		$blocked = \WPMediaVerse\REST\RestGuards::deny_if_blocked( get_current_user_id(), $author );
		if ( $blocked instanceof WP_Error ) {
			return $blocked;
		}

		return true;
	}

	/**
	 * Check if a media item exists.
	 *
	 * @param int $media_id Media post ID.
	 * @return bool
	 */
	private function media_exists( int $media_id ): bool {
		return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id );
	}
}
