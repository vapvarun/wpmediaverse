<?php
/**
 * Notification REST controller.
 *
 * @package    WPMediaVerse
 * @subpackage REST
 * @since      1.1.0
 */

namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPMediaVerse\REST\RateLimiter;
use WPMediaVerse\Social\NotificationService;

/**
 * REST controller for user notifications.
 */
class NotificationController extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * @var NotificationService
	 */
	private $notifications;

	/**
	 * Constructor.
	 *
	 * @param NotificationService $notifications Notification service.
	 */
	public function __construct( NotificationService $notifications ) {
		$this->notifications = $notifications;
	}

	/**
	 * Register routes.
	 *
	 * @since 1.1.0
	 */
	public function register_routes(): void {
		$auth = function () {
			return is_user_logged_in();
		};

		// GET /me/notifications.
		register_rest_route(
			$this->namespace,
			'/me/notifications',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_notifications' ),
				'permission_callback' => $auth,
				'args'                => array(
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'filter'   => array(
						'type'    => 'string',
						'default' => 'all',
					),
				),
			)
		);

		// POST /me/notifications/read — mark as read.
		register_rest_route(
			$this->namespace,
			'/me/notifications/read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_read' ),
				'permission_callback' => $auth,
				'args'                => array(
					'ids' => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'integer' ),
					),
				),
			)
		);

		// GET /me/mentions - where the member was @mentioned (2.6.0).
		register_rest_route(
			$this->namespace,
			'/me/mentions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_mentions' ),
				'permission_callback' => $auth,
				'args'                => array(
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);

		// GET /me/notifications/count.
		register_rest_route(
			$this->namespace,
			'/me/notifications/count',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_count' ),
				'permission_callback' => $auth,
			)
		);
	}

	/**
	 * Get notifications.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_notifications( $request ) {
		$data = $this->notifications->get_notifications(
			get_current_user_id(),
			\WPMediaVerse\REST\Pagination::resolve_per_page( $request ),
			\WPMediaVerse\REST\Pagination::resolve_page( $request ),
			sanitize_text_field( $request->get_param( 'filter' ) )
		);

		$response = rest_ensure_response( $data['notifications'] );
		$response->header( 'X-WP-Total', $data['total'] );

		return $response;
	}

	/**
	 * Where the member was @mentioned, newest first.
	 *
	 * Each item is the canonical media object plus `mention` {context,
	 * comment_id, created_at, by}. `by` is the comment's author for a comment
	 * mention and the media owner for a description mention (the table keeps
	 * no actor). Items the member can no longer open are left out and the
	 * total drops with them, as on /me/favorites.
	 *
	 * @since 2.6.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_mentions( $request ) {
		$container = \WPMediaVerse\Core\Plugin::container();
		$viewer    = get_current_user_id();
		$per_page  = \WPMediaVerse\REST\Pagination::resolve_per_page( $request );
		$result    = $container->get( 'mentions' )->get_for_user( $viewer, $per_page, \WPMediaVerse\REST\Pagination::resolve_page( $request ) );
		$repo      = $container->get( 'media_repository' );
		$privacy   = $container->get( 'privacy' );

		$ids = array_values( array_unique( array_map( 'intval', array_column( $result['items'], 'media_id' ) ) ) );
		if ( $ids ) {
			$repo->prefetch( $ids );
		}
		MediaController::prime_viewer_state( $ids, $viewer );
		$media_ctrl = new MediaController( $privacy );

		$items  = array();
		$hidden = 0;
		foreach ( $result['items'] as $row ) {
			$media_id = (int) $row['media_id'];
			$media    = ( $repo->exists( $media_id ) && $privacy->can_view( $media_id, $viewer ) )
				? $media_ctrl->prepare_item_for_response( $media_id, $request )
				: null;
			if ( null === $media ) {
				++$hidden;
				continue;
			}

			$comment_id = (int) $row['comment_id'];
			$comment    = $comment_id ? get_comment( $comment_id ) : null;
			$by         = $comment ? (int) $comment->user_id : (int) $repo->get( $media_id, 'post_author' );

			$media['mention'] = array(
				'context'    => (string) $row['context'],
				'comment_id' => $comment_id,
				'created_at' => (string) $row['created_at'],
				'by'         => array(
					'id'   => $by,
					'name' => $by ? (string) get_the_author_meta( 'display_name', $by ) : '',
				),
			);
			$items[]          = $media;
		}

		$total    = max( 0, (int) $result['total'] - $hidden );
		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per_page ) ) );

		return $response;
	}

	/**
	 * Mark notifications as read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function mark_read( $request ) {
		$rate_check = RateLimiter::check( 'notification_write', 60, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$ids     = array_map( 'absint', $request->get_param( 'ids' ) );
		$updated = $this->notifications->mark_read( get_current_user_id(), $ids );

		return rest_ensure_response( array( 'marked' => $updated ) );
	}

	/**
	 * Get unread notification count.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_count( $request ) {
		return rest_ensure_response(
			array( 'count' => $this->notifications->get_unread_count( get_current_user_id() ) )
		);
	}
}
