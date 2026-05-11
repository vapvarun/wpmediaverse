<?php
/**
 * Activity feed REST controller.
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
use WPMediaVerse\Social\ActivityService;

/**
 * REST controller for the activity feed.
 */
class ActivityController extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * @var ActivityService
	 */
	private $activities;

	/**
	 * Constructor.
	 *
	 * @param ActivityService $activities Activity service.
	 */
	public function __construct( ActivityService $activities ) {
		$this->activities = $activities;
	}

	/**
	 * Register routes.
	 *
	 * @since 1.1.0
	 */
	public function register_routes(): void {
		// PUBLIC_OK on both __return_true callbacks below (GET /feed and
		// GET /users/{id}/activity): activity entries are filtered to
		// public-media events at the query layer. Private message events
		// + private media uploads NEVER appear. The /feed scope='following'
		// short-circuits to empty for anon callers (handler reads
		// is_user_logged_in internally). Triaged 2026-05-01 (Item 5).
		// GET /feed — public or following feed.
		register_rest_route(
			$this->namespace,
			'/feed',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_feed' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'scope'    => array(
						'type'    => 'string',
						'default' => 'public',
						'enum'    => array( 'public', 'following' ),
					),
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

		// GET /users/{id}/activity — user's activity.
		register_rest_route(
			$this->namespace,
			'/users/(?P<id>[\d]+)/activity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_activity' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'       => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
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
	}

	/**
	 * Get feed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_feed( $request ) {
		$scope = $request->get_param( 'scope' );

		// Following scope requires authentication.
		if ( 'following' === $scope && ! is_user_logged_in() ) {
			$scope = 'public';
		}

		$data = $this->activities->get_feed(
			array(
				'scope'    => $scope,
				'user_id'  => get_current_user_id(),
				'per_page' => \WPMediaVerse\REST\Pagination::resolve_per_page( $request ),
				'page'     => \WPMediaVerse\REST\Pagination::resolve_page( $request ),
			)
		);

		$response = rest_ensure_response( $data['activities'] );
		$response->header( 'X-WP-Total', $data['total'] );

		return $response;
	}

	/**
	 * Get a specific user's activity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_user_activity( $request ) {
		$data = $this->activities->get_feed(
			array(
				'scope'    => 'user',
				'user_id'  => (int) $request->get_param( 'id' ),
				'per_page' => \WPMediaVerse\REST\Pagination::resolve_per_page( $request ),
				'page'     => \WPMediaVerse\REST\Pagination::resolve_page( $request ),
			)
		);

		$response = rest_ensure_response( $data['activities'] );
		$response->header( 'X-WP-Total', $data['total'] );

		return $response;
	}
}
