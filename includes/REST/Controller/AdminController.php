<?php
/**
 * Admin REST controller — Phase 6 admin-ajax → REST migration.
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST routes for admin-only operations that previously ran through
 * `wp-admin/admin-ajax.php`. Phase 6 of the 1.2.0 plan retires the three
 * `wp_ajax_mvs_*` handlers in favour of authenticated REST endpoints,
 * closing the Rule A loop (100% REST end-to-end).
 *
 * Migration status:
 * - ✅ welcome dismiss — POST /mvs/v1/admin/welcome/dismiss
 * - ⏳ demo data import — needs DemoSeederService refactor (Phase 6.5);
 *      the legacy seed-demo-data.php / cleanup-demo-data.php scripts emit
 *      `wp_send_json_*()` mid-flight, which can't be reused from REST/CLI/
 *      tests per the structural guideline. Refactoring those into a
 *      service is a separate, focused commit.
 */
final class AdminController extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'admin';

	/**
	 * Register the admin-area routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/welcome/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_welcome' ),
				'permission_callback' => array( $this, 'logged_in_permissions_check' ),
			)
		);
	}

	/**
	 * Any authenticated user — welcome dismiss is per-user state.
	 *
	 * @return bool|WP_Error
	 */
	public function logged_in_permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'mvs_unauthorized',
				__( 'You must be logged in.', 'wpmediaverse' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * POST /mvs/v1/admin/welcome/dismiss — mark the welcome banner dismissed
	 * for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function dismiss_welcome( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		update_user_meta( get_current_user_id(), '_mvs_welcome_dismissed', 1 );
		return rest_ensure_response( array( 'dismissed' => true ) );
	}
}
