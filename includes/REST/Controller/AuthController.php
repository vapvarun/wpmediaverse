<?php
/**
 * Auth REST controller.
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
use WPMediaVerse\Auth\AppCredentials;
use WPMediaVerse\REST\RateLimiter;

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

		// POST /auth/app-password — trade a WordPress login for an
		// Application Password, the credential the mobile app actually holds.
		//
		// Public by necessity (Rule-2 allowlisted): this is how a member gets
		// their FIRST credential, so there is nothing to authenticate with
		// yet. Guarded in AppCredentials: owner switch, TLS gate, uniform
		// failures, the suspension gate, and a 409 rather than a silent 2FA
		// bypass. Rate limiting happens in the callback BEFORE any credential
		// is read.
		register_rest_route(
			$this->namespace,
			'/auth/app-password',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'issue_app_password' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'username' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Your email address or username.', 'wpmediaverse' ),
					),
					'password' => array(
						'required'    => true,
						'type'        => 'string',
						'description' => __( 'Your account password.', 'wpmediaverse' ),
					),
					'app_name' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Name shown beside this credential in your profile.', 'wpmediaverse' ),
					),
					'app_id'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Stable per-install id, so a repeat sign-in replaces the row instead of adding another.', 'wpmediaverse' ),
					),
				),
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

	/**
	 * POST /auth/app-password — hand back the credential the app will hold.
	 *
	 * The account password is read from the request, passed straight to
	 * `wp_authenticate()`, and never stored, logged or echoed. Only the
	 * minted Application Password comes back, with `no-store` so nothing on
	 * the path keeps a copy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function issue_app_password( WP_REST_Request $request ) {
		// Throttle BEFORE a credential is read. Two failure buckets, because
		// one alone is not enough: the IP bucket stops one host grinding
		// through passwords, the username bucket stops a distributed run at
		// a single account. Only rejected CREDENTIALS count against them.
		$username = (string) $request->get_param( 'username' );
		$ip       = ! empty( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';
		$buckets  = array( 'ip:' . $ip, 'user:' . strtolower( $username ) );

		foreach ( $buckets as $bucket ) {
			if ( AppCredentials::is_locked_out( $bucket ) ) {
				return new WP_Error(
					'mvs_too_many_attempts',
					__( 'Too many sign-in attempts. Please wait a few minutes and try again.', 'wpmediaverse' ),
					array( 'status' => 429 )
				);
			}
		}

		// The coarse per-IP request cap on top — every attempt counts here,
		// bounding even a slow distributed probe.
		$coarse = RateLimiter::check( 'auth_app_password', 20, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $coarse ) ) {
			return $coarse;
		}

		$app_name = (string) $request->get_param( 'app_name' );
		$app_id   = (string) $request->get_param( 'app_id' );

		$result = AppCredentials::exchange(
			$username,
			(string) $request->get_param( 'password' ),
			'' !== $app_name ? $app_name : __( 'MediaVerse app', 'wpmediaverse' ),
			$app_id
		);

		if ( is_wp_error( $result ) ) {
			if ( 'mvs_login_failed' === $result->get_error_code() ) {
				foreach ( $buckets as $bucket ) {
					AppCredentials::record_failure( $bucket );
				}
			}

			return $result;
		}

		foreach ( $buckets as $bucket ) {
			AppCredentials::clear_failures( $bucket );
		}

		$response = new WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
