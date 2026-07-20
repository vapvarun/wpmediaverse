<?php
/**
 * Member-initiated account deletion over REST.
 *
 * @package WPMediaVerse
 * @since   2.1.0
 */

namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPMediaVerse\REST\RateLimiter;
use WPMediaVerse\Services\AccountDeletionService;

/**
 * DELETE /me — a member deletes their own account.
 *
 * Apple App Store guideline 5.1.1(v) requires any app that lets you create an
 * account to let you begin deleting it from inside the app. Before 2.1.0 the
 * only erasure path was WordPress's admin-mediated Tools -> Erase Personal Data
 * flow, which a member cannot complete themselves — so the mobile app could not
 * ship at all.
 *
 * A member is, at the end of the day, a WordPress user: deletion goes through
 * wp_delete_user(), core fires `deleted_user`, and the existing
 * UserDeletionService cascade runs unchanged. No purge logic is reimplemented
 * here, and no parallel identity system is invented.
 *
 * Route shape is DELETE on the `me` resource, matching the existing /me/*
 * namespace (ProfileController already owns DELETE /me/avatar). The password is
 * required — see AccountDeletionService for why that matters when the caller
 * authenticated with an Application Password.
 */
class AccountController {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected string $namespace = 'mvs/v1';

	/**
	 * Deletion service.
	 *
	 * @var AccountDeletionService
	 */
	private AccountDeletionService $service;

	/**
	 * Constructor.
	 *
	 * @param AccountDeletionService $service Deletion service.
	 */
	public function __construct( AccountDeletionService $service ) {
		$this->service = $service;
	}

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/me',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_account' ),
					'permission_callback' => array( $this, 'auth_check' ),
					'args'                => array(
						'password' => array(
							'type'        => 'string',
							'description' => __( 'The account password, re-entered.', 'wpmediaverse' ),
						),
						'confirm'  => array(
							'type'        => 'string',
							'required'    => true,
							'description' => __( 'Must be the literal string DELETE.', 'wpmediaverse' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/me/deletion',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'auth_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'cancel_deletion' ),
					'permission_callback' => array( $this, 'auth_check' ),
				),
			)
		);
	}

	/**
	 * Only a signed-in member may act on their own account.
	 *
	 * @return bool
	 */
	public function auth_check(): bool {
		return is_user_logged_in();
	}

	/**
	 * DELETE /me — request deletion of your own account.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_account( WP_REST_Request $request ) {
		$limited = RateLimiter::check( 'account_delete', 3, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$user_id = get_current_user_id();

		// A deliberate, explicit confirmation. Guards against a mis-wired client
		// firing DELETE /me by accident — which is not a recoverable mistake.
		$confirm = (string) $request->get_param( 'confirm' );
		if ( 'DELETE' !== strtoupper( trim( $confirm ) ) ) {
			return new WP_Error(
				'mvs_confirmation_required',
				__( 'Confirm deletion by sending confirm=DELETE.', 'wpmediaverse' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->service->request( $user_id, (string) $request->get_param( 'password' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 'deleted' === $result['status'] ? 200 : 202 );
	}

	/**
	 * GET /me/deletion — is a deletion pending, and when does it run?
	 *
	 * The app needs this on boot: a member who requested deletion and then signs
	 * back in must be told the date, and offered a way out.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status(): WP_REST_Response {
		$user_id = get_current_user_id();
		$when    = $this->service->scheduled_at( $user_id );

		if ( $when <= 0 ) {
			return new WP_REST_Response(
				array(
					'status' => 'active',
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'status'        => 'scheduled',
				'scheduled_for' => gmdate( 'c', $when ),
				'message'       => sprintf(
					/* translators: %s: date the account will be deleted. */
					__( 'Your account will be permanently deleted on %s. You can cancel until then.', 'wpmediaverse' ),
					date_i18n( get_option( 'date_format' ), $when )
				),
			),
			200
		);
	}

	/**
	 * DELETE /me/deletion — cancel a pending deletion.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_deletion() {
		$result = $this->service->cancel( get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}
}
