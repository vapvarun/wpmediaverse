<?php
/**
 * Favorite REST controller.
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
use WPMediaVerse\REST\RateLimiter;
use WPMediaVerse\Social\FavoriteService;

/**
 * REST controller for media favorites.
 */
class FavoriteController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Favorite service instance.
	 *
	 * @var FavoriteService
	 */
	private $favorites;

	/**
	 * Constructor.
	 *
	 * @param FavoriteService $favorites Favorite service instance.
	 */
	public function __construct( FavoriteService $favorites ) {
		$this->favorites = $favorites;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// POST /media/{id}/favorite — toggle favorite.
		register_rest_route(
			$this->namespace,
			'/media/(?P<media_id>[\d]+)/favorite',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'toggle_favorite' ),
					'permission_callback' => array( $this, 'auth_check' ),
					'args'                => array(
						'media_id'      => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'collection_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'toggle_favorite' ),
					'permission_callback' => array( $this, 'auth_check' ),
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

		// GET /me/favorites — current user's favorites.
		register_rest_route(
			$this->namespace,
			'/me/favorites',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_my_favorites' ),
				'permission_callback' => array( $this, 'auth_check' ),
				'args'                => array(
					'collection_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'per_page'      => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'          => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Toggle favorite on a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_favorite( $request ) {
		$rate_check = RateLimiter::check( 'favorite_toggle', 60, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id = $request->get_param( 'media_id' );

		$post = get_post( $media_id );
		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$collection_id = $request->get_param( 'collection_id' );
		$result        = $this->favorites->toggle( $media_id, get_current_user_id(), $collection_id ? (int) $collection_id : null );

		/**
		 * Fires after a favorite is toggled.
		 *
		 * @param int    $media_id Media post ID.
		 * @param int    $user_id  User ID.
		 * @param string $action   Action taken: added or removed.
		 */
		do_action( 'mvs_favorite_toggled', $media_id, get_current_user_id(), $result['action'] );

		return rest_ensure_response(
			array(
				'media_id'  => $media_id,
				'action'    => $result['action'],
				'favorited' => $result['favorited'],
				'count'     => $this->favorites->get_count( $media_id ),
			)
		);
	}

	/**
	 * Get current user's favorites.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_my_favorites( $request ) {
		$collection_id = $request->get_param( 'collection_id' );
		$per_page      = (int) $request->get_param( 'per_page' );
		$page          = (int) $request->get_param( 'page' );

		$result = $this->favorites->get_user_favorites(
			get_current_user_id(),
			$collection_id ? (int) $collection_id : null,
			$per_page,
			$page
		);

		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $result['total'] / $per_page ) );

		return $response;
	}

	/**
	 * Permissions: authenticated users only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function auth_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'mvs_unauthorized', __( 'You must be logged in.', 'wpmediaverse' ), array( 'status' => 401 ) );
		}
		return true;
	}
}
