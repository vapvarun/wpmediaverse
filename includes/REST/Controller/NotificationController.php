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
			(int) $request->get_param( 'per_page' ),
			(int) $request->get_param( 'page' ),
			sanitize_text_field( $request->get_param( 'filter' ) )
		);

		$response = rest_ensure_response( $data['notifications'] );
		$response->header( 'X-WP-Total', $data['total'] );

		return $response;
	}

	/**
	 * Mark notifications as read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function mark_read( $request ) {
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
