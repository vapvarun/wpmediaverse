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

		$per_page = (int) $request->get_param( 'per_page' );
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

		$content = sanitize_textarea_field( $request->get_param( 'content' ) );
		if ( empty( $content ) ) {
			return new WP_Error( 'mvs_empty_comment', __( 'Comment content is required.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$parent        = (int) $request->get_param( 'parent' );
		$from_activity = (int) $request->get_param( 'from_activity' );

		// When comment originates from a BP activity lightbox, the JS already
		// posts a BP activity reply. Set a flag so BuddyPressIntegration skips
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

		$comment        = get_comment( $comment_id );
		$cmt_author_id  = (int) $comment->user_id;
		$response       = rest_ensure_response(
			array(
				'id'            => (int) $comment->comment_ID,
				'author'        => $cmt_author_id,
				'author_name'   => $comment->comment_author,
				'author_avatar' => $cmt_author_id ? (string) get_avatar_url( $cmt_author_id, array( 'size' => 48 ) ) : '',
				'content'       => $comment->comment_content,
				'parent'        => (int) $comment->comment_parent,
				'date'          => $comment->comment_date_gmt,
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

		// Verify comment belongs to media.
		if ( (int) $comment->comment_post_ID !== $media_id ) {
			return new WP_Error( 'mvs_mismatch', __( 'Comment does not belong to this media item.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		// 15-minute edit window.
		$edit_window = (int) apply_filters( 'mvs_comment_edit_window', 15 * MINUTE_IN_SECONDS );
		$comment_age = time() - strtotime( $comment->comment_date_gmt );
		if ( $comment_age > $edit_window ) {
			return new WP_Error(
				'mvs_edit_expired',
				__( 'Comments can only be edited within 15 minutes of posting.', 'wpmediaverse' ),
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

		// Verify the comment belongs to the specified media item.
		$comment = get_comment( $comment_id );
		if ( $comment && (int) $comment->comment_post_ID !== $media_id ) {
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
		return true;
	}

	/**
	 * Check if a media item exists.
	 *
	 * @param int $media_id Media post ID.
	 * @return bool
	 */
	private function media_exists( int $media_id ): bool {
		$post = get_post( $media_id );
		return $post && 'mvs_media' === $post->post_type;
	}
}
