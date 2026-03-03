<?php
/**
 * Moderation REST controller.
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
use WPMediaVerse\Services\ModerationService;
use WPMediaVerse\Services\AIService;

/**
 * REST controller for the moderation queue.
 */
class ModerationController extends WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'moderation';

	/**
	 * Moderation service.
	 *
	 * @var ModerationService
	 */
	private $moderation;

	/**
	 * AI service.
	 *
	 * @var AIService
	 */
	private $ai;

	/**
	 * Constructor.
	 *
	 * @param ModerationService $moderation Moderation service.
	 * @param AIService         $ai         AI service.
	 */
	public function __construct( ModerationService $moderation, AIService $ai ) {
		$this->moderation = $moderation;
		$this->ai         = $ai;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_queue' ),
					'permission_callback' => array( $this, 'moderate_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/counts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_counts' ),
					'permission_callback' => array( $this, 'moderate_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/approve',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'approve_item' ),
					'permission_callback' => array( $this, 'moderate_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/reject',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reject_item' ),
					'permission_callback' => array( $this, 'moderate_permissions_check' ),
					'args'                => array(
						'id'     => array(
							'type'     => 'integer',
							'required' => true,
						),
						'reason' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/analyze',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'analyze_item' ),
					'permission_callback' => array( $this, 'moderate_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/usage',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ai_usage' ),
					'permission_callback' => array( $this, 'moderate_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Check if the current user can moderate.
	 *
	 * @return bool|WP_Error
	 */
	public function moderate_permissions_check() {
		if ( ! current_user_can( 'moderate_mvs_media' ) ) {
			return new WP_Error(
				'mvs_rest_forbidden',
				__( 'You do not have permission to moderate media.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Get the moderation queue.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_queue( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->moderation->get_queue(
			array(
				'status'   => $request->get_param( 'status' ) ?? 'flagged',
				'per_page' => $request->get_param( 'per_page' ) ?? 20,
				'page'     => $request->get_param( 'page' ) ?? 1,
			)
		);

		$items = array();
		foreach ( $result['items'] as $post ) {
			$items[] = $this->prepare_item( $post );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $result['total'] );
		$response->header( 'X-WP-TotalPages', $result['pages'] );

		return $response;
	}

	/**
	 * Get moderation counts.
	 *
	 * @return WP_REST_Response
	 */
	public function get_counts(): WP_REST_Response {
		return rest_ensure_response( $this->moderation->get_counts() );
	}

	/**
	 * Approve a media item.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_item( WP_REST_Request $request ) {
		$media_id = (int) $request->get_param( 'id' );
		$post     = get_post( $media_id );

		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Media not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$this->moderation->approve( $media_id, get_current_user_id() );

		return rest_ensure_response( $this->prepare_item( $post ) );
	}

	/**
	 * Reject a media item.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject_item( WP_REST_Request $request ) {
		$media_id = (int) $request->get_param( 'id' );
		$post     = get_post( $media_id );

		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Media not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$reason = $request->get_param( 'reason' );
		$this->moderation->reject( $media_id, get_current_user_id(), $reason );

		return rest_ensure_response( $this->prepare_item( $post ) );
	}

	/**
	 * Trigger AI analysis on a media item.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function analyze_item( WP_REST_Request $request ) {
		$media_id = (int) $request->get_param( 'id' );
		$post     = get_post( $media_id );

		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Media not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$result = $this->ai->process( $media_id );

		return rest_ensure_response(
			array(
				'media_id' => $media_id,
				'ai'       => $result,
			)
		);
	}

	/**
	 * Get AI usage stats.
	 *
	 * @return WP_REST_Response
	 */
	public function get_ai_usage(): WP_REST_Response {
		return rest_ensure_response( $this->ai->get_usage_stats() );
	}

	/**
	 * Prepare a moderation queue item for response.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	private function prepare_item( \WP_Post $post ): array {
		return array(
			'id'                => $post->ID,
			'title'             => $post->post_title,
			'author'            => (int) $post->post_author,
			'date'              => $post->post_date_gmt,
			'status'            => $post->post_status,
			'moderation_status' => $this->moderation->get_status( $post->ID ),
			'file_url'          => get_post_meta( $post->ID, '_mvs_file_url', true ),
			'file_type'         => get_post_meta( $post->ID, '_mvs_file_type', true ),
			'ai_description'    => get_post_meta( $post->ID, '_mvs_ai_description', true ),
			'ai_tags'           => get_post_meta( $post->ID, '_mvs_ai_tags', true ),
			'ai_moderation'     => get_post_meta( $post->ID, '_mvs_ai_moderation', true ),
			'rejection_reason'  => get_post_meta( $post->ID, '_mvs_rejection_reason', true ),
			'moderation_log'    => $this->moderation->get_log( $post->ID ),
		);
	}

	/**
	 * Get collection params for queue listing.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return array(
			'status'   => array(
				'type'              => 'string',
				'default'           => 'flagged',
				'enum'              => array( 'pending', 'flagged', 'rejected' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
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
		);
	}
}
