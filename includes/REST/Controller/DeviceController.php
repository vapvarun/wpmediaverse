<?php
/**
 * Device-token REST controller — register/unregister a device for push.
 *
 * @package    WPMediaVerse
 * @subpackage REST
 * @since      2.4.0
 */

namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPMediaVerse\Social\PushService;

/**
 * REST controller for a member's push device tokens.
 *
 * @since 2.4.0
 */
class DeviceController extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * @var PushService
	 */
	private $push;

	/**
	 * Constructor.
	 *
	 * @param PushService $push Push service.
	 */
	public function __construct( PushService $push ) {
		$this->push = $push;
	}

	/**
	 * Register routes.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes(): void {
		$auth = function () {
			return is_user_logged_in();
		};

		register_rest_route(
			$this->namespace,
			'/me/devices',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'register_device' ),
					'permission_callback' => $auth,
					'args'                => array(
						'platform' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'ios', 'android', 'web' ),
						),
						'token'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unregister_device' ),
					'permission_callback' => $auth,
					'args'                => array(
						'token' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Register (or refresh) the caller's device token.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function register_device( WP_REST_Request $request ): WP_REST_Response {
		$ok = $this->push->register_token(
			get_current_user_id(),
			(string) $request->get_param( 'platform' ),
			(string) $request->get_param( 'token' )
		);

		return rest_ensure_response( array( 'registered' => $ok ) );
	}

	/**
	 * Remove a device token (logout / uninstall).
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function unregister_device( WP_REST_Request $request ): WP_REST_Response {
		$removed = $this->push->unregister_token( (string) $request->get_param( 'token' ) );

		return rest_ensure_response( array( 'removed' => $removed ) );
	}
}
