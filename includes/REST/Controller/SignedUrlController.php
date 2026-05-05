<?php
/**
 * Signed URL REST controller.
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
use WPMediaVerse\Services\PrivacyService;
use WPMediaVerse\Services\SignedUrlService;

/**
 * REST controller for signed URL generation and file serving.
 */
class SignedUrlController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Signed URL service.
	 *
	 * @var SignedUrlService
	 */
	private $signed_urls;

	/**
	 * Privacy service.
	 *
	 * @var PrivacyService
	 */
	private $privacy;

	/**
	 * Constructor.
	 *
	 * @param SignedUrlService $signed_urls Signed URL service.
	 * @param PrivacyService   $privacy     Privacy service.
	 */
	public function __construct( SignedUrlService $signed_urls, PrivacyService $privacy ) {
		$this->signed_urls = $signed_urls;
		$this->privacy     = $privacy;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// GET /media/{id}/signed-url — generate a signed URL.
		register_rest_route(
			$this->namespace,
			'/media/(?P<media_id>[\d]+)/signed-url',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_signed_url' ),
					'permission_callback' => array( $this, 'get_signed_url_permissions_check' ),
					'args'                => array(
						'media_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'download' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'ttl'      => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// GET /serve — serve a file via signed URL (no auth required, signature validates).
		// PUBLIC_OK: this is the analogue of S3 pre-signed URLs — the HMAC
		// signature on the URL itself is the credential. Validation happens
		// inside serve_file() (signature + expiry + bound user_id all
		// checked). The __return_true is intentional. Triaged 2026-05-01
		// (Item 5).
		register_rest_route(
			$this->namespace,
			'/serve',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'serve_file' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						SignedUrlService::PARAM_MEDIA_ID  => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						SignedUrlService::PARAM_USER      => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						SignedUrlService::PARAM_EXPIRES   => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						SignedUrlService::PARAM_SIGNATURE => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						SignedUrlService::PARAM_DOWNLOAD  => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Generate a signed URL for a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_signed_url( $request ) {
		$rate_check = RateLimiter::check( 'signed_url', 60, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id = $request->get_param( 'media_id' );
		$download = (bool) $request->get_param( 'download' );
		$ttl      = $request->get_param( 'ttl' );
		$user_id  = get_current_user_id();

		$url = $this->signed_urls->generate( $media_id, $user_id, $ttl ? $ttl : 0, $download );

		if ( ! $url ) {
			return new WP_Error(
				'mvs_forbidden',
				__( 'You do not have access to this media item.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}

		return rest_ensure_response(
			array(
				'media_id'   => $media_id,
				'signed_url' => $url,
				'expires_in' => $ttl ? $ttl : SignedUrlService::DEFAULT_TTL,
				'download'   => $download,
			)
		);
	}

	/**
	 * Serve a file via validated signed URL.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function serve_file( $request ) {
		// Generous rate limit for community-site image grids. Any media-heavy
		// page (Explore feed, album view, BP activity) easily renders 60-200
		// image requests on first load — the previous 60/60s limit caused
		// HTTP 429 on roughly half of them, which the browser shows as broken
		// images. The signed URL signature is the actual access gate; this
		// rate check is only defense-in-depth against per-user replay loops,
		// so it can be much higher than write-endpoint limits without
		// weakening security.
		$rate_check = RateLimiter::check( 'serve_file', 1200, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$params = array(
			SignedUrlService::PARAM_MEDIA_ID  => $request->get_param( SignedUrlService::PARAM_MEDIA_ID ),
			SignedUrlService::PARAM_USER      => $request->get_param( SignedUrlService::PARAM_USER ),
			SignedUrlService::PARAM_EXPIRES   => $request->get_param( SignedUrlService::PARAM_EXPIRES ),
			SignedUrlService::PARAM_SIGNATURE => $request->get_param( SignedUrlService::PARAM_SIGNATURE ),
		);

		$dl = $request->get_param( SignedUrlService::PARAM_DOWNLOAD );
		if ( $dl ) {
			$params[ SignedUrlService::PARAM_DOWNLOAD ] = 1;
		}

		$size = $request->get_param( SignedUrlService::PARAM_SIZE );
		if ( $size ) {
			$params[ SignedUrlService::PARAM_SIZE ] = sanitize_text_field( $size );
		}

		// This method exits after sending the file.
		$this->signed_urls->serve( $params );
	}

	/**
	 * Permission check: authenticated user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_signed_url_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'mvs_unauthorized',
				__( 'You must be logged in to request a signed URL.', 'wpmediaverse' ),
				array( 'status' => 401 )
			);
		}

		$media_id = $request->get_param( 'media_id' );

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error(
				'mvs_not_found',
				__( 'Media item not found.', 'wpmediaverse' ),
				array( 'status' => 404 )
			);
		}

		// Verify the user can view this media item.
		if ( ! $this->privacy->can_view( $media_id, get_current_user_id() ) ) {
			return new WP_Error(
				'mvs_forbidden',
				__( 'You do not have access to this media item.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
