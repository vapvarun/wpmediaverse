<?php
/**
 * TransactionController — REST access to a member's usage ledger.
 *
 * GET /mvs/v1/me/transactions — the member's own mvs_transactions history,
 * newest first, paginated (X-WP-Total / X-WP-TotalPages headers). The API entry
 * point of the usage-ledger wiring (see Services\TransactionService).
 *
 * @package WPMediaVerse\REST\Controller
 */

namespace WPMediaVerse\REST\Controller;

use WPMediaVerse\Services\TransactionService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only REST surface for the usage ledger.
 */
class TransactionController extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Ledger service.
	 *
	 * @var TransactionService
	 */
	private $transactions;

	/**
	 * @param TransactionService $transactions Ledger service.
	 */
	public function __construct( TransactionService $transactions ) {
		$this->transactions = $transactions;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/me/transactions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_transactions' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
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
	}

	/**
	 * GET /me/transactions — the current member's usage history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_transactions( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );

		$rows  = $this->transactions->get_for_user(
			$user_id,
			array(
				'per_page' => $per_page,
				'page'     => $page,
			)
		);
		$total = $this->transactions->count_for_user( $user_id );

		$data = array_map(
			static function ( array $row ): array {
				return array(
					'id'            => (int) $row['id'],
					'media_type'    => (string) $row['media_type'],
					'delta'         => (int) $row['delta'],
					'balance_after' => (int) $row['balance_after'],
					'reason'        => (string) $row['reason'],
					'created_at'    => (string) $row['created_at'],
				);
			},
			$rows
		);

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $total / $per_page ) : 1 ) );
		return $response;
	}
}
