<?php
/**
 * Stats REST controller.
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
use WPMediaVerse\Services\StatsService;
use WPMediaVerse\Services\PrivacyService;

/**
 * REST controller for media statistics.
 */
class StatsController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Stats service instance.
	 *
	 * @var StatsService
	 */
	private $stats;

	/**
	 * Privacy service instance.
	 *
	 * @var PrivacyService
	 */
	private $privacy;

	/**
	 * Constructor.
	 *
	 * @param StatsService   $stats   Stats service instance.
	 * @param PrivacyService $privacy Privacy service instance.
	 */
	public function __construct( StatsService $stats, PrivacyService $privacy ) {
		$this->stats   = $stats;
		$this->privacy = $privacy;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// GET /media/{id}/stats.
		register_rest_route(
			$this->namespace,
			'/media/(?P<media_id>[\d]+)/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_media_stats' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'media_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /me/stats.
		register_rest_route(
			$this->namespace,
			'/me/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_my_stats' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * Get stats for a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_media_stats( $request ) {
		$media_id = $request->get_param( 'media_id' );

		$post = get_post( $media_id );
		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Privacy check — don't expose stats for private media.
		if ( ! $this->privacy->can_view( $media_id, get_current_user_id() ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You do not have access to this media item.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}

		$stats = $this->stats->get_for_media( $media_id );

		if ( ! $stats ) {
			return new WP_Error( 'mvs_no_stats', __( 'Stats not available.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $stats );
	}

	/**
	 * Get aggregated stats for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_my_stats( $request ) {
		$stats = $this->stats->get_for_user( get_current_user_id() );
		return rest_ensure_response( $stats );
	}
}
