<?php
/**
 * Auth REST controller.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller providing a nonce-refresh endpoint for the front-end REST client.
 *
 * Route: GET /mvs/v1/auth/nonce
 *
 * Called automatically by window.mvsRest when a 403 rest_cookie_invalid_nonce
 * response is received. Returns a fresh wp_rest nonce so the client can retry
 * the original request without a hard page reload.
 *
 * @since 1.7.1
 */
class AuthController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// GET /auth/nonce — requires a logged-in user (cookie auth).
		register_rest_route(
			$this->namespace,
			'/auth/nonce',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_nonce' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * Return a fresh wp_rest nonce.
	 *
	 * @return WP_REST_Response
	 */
	public function get_nonce(): WP_REST_Response {
		return new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ), 200 );
	}
}
