<?php
/**
 * Checkout REST controller.
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
use WPMediaVerse\Services\PaymentBridgeService;

/**
 * REST controller for checkout and code redemption.
 */
class CheckoutController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Payment bridge service.
	 *
	 * @var PaymentBridgeService
	 */
	private $payments;

	/**
	 * Constructor.
	 *
	 * @param PaymentBridgeService $payments Payment bridge service.
	 */
	public function __construct( PaymentBridgeService $payments ) {
		$this->payments = $payments;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// POST /checkout — initiate a purchase.
		register_rest_route(
			$this->namespace,
			'/checkout',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_checkout' ),
					'permission_callback' => array( $this, 'authenticated_check' ),
					'args'                => array(
						'media_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// POST /checkout/redeem — redeem an access code.
		register_rest_route(
			$this->namespace,
			'/checkout/redeem',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'redeem_code' ),
					'permission_callback' => array( $this, 'authenticated_check' ),
					'args'                => array(
						'media_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'code'     => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// GET /media/{id}/pricing — get pricing info.
		register_rest_route(
			$this->namespace,
			'/media/(?P<media_id>[\d]+)/pricing',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pricing' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'media_id' => array(
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
	 * Initiate a checkout for a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_checkout( $request ) {
		$media_id = $request->get_param( 'media_id' );

		if ( ! $this->media_exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$result = $this->payments->create_checkout( $media_id, get_current_user_id() );

		return rest_ensure_response( $result );
	}

	/**
	 * Redeem an access code.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function redeem_code( $request ) {
		$media_id = $request->get_param( 'media_id' );
		$code     = $request->get_param( 'code' );

		if ( ! $this->media_exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$redeemed = $this->payments->redeem_code( $media_id, get_current_user_id(), $code );

		if ( ! $redeemed ) {
			return new WP_Error( 'mvs_invalid_code', __( 'Invalid or expired access code.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'granted'  => true,
				'media_id' => $media_id,
				'message'  => __( 'Access granted!', 'wpmediaverse' ),
			)
		);
	}

	/**
	 * Get pricing information for a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pricing( $request ) {
		$media_id = $request->get_param( 'media_id' );

		if ( ! $this->media_exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$pricing = $this->payments->get_pricing( $media_id );

		return rest_ensure_response(
			array(
				'media_id' => $media_id,
				'price'    => $pricing['price'],
				'currency' => $pricing['currency'],
				'type'     => $pricing['rule_type'],
			)
		);
	}

	/**
	 * Permission check: authenticated user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function authenticated_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'mvs_unauthorized', __( 'You must be logged in.', 'wpmediaverse' ), array( 'status' => 401 ) );
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
