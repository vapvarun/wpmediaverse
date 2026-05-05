<?php
/**
 * Access rules REST controller.
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
use WPMediaVerse\Services\AccessRulesService;

/**
 * REST controller for access rules and grants.
 */
class AccessController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Access rules service instance.
	 *
	 * @var AccessRulesService
	 */
	private $access;

	/**
	 * Constructor.
	 *
	 * @param AccessRulesService $access Access rules service.
	 */
	public function __construct( AccessRulesService $access ) {
		$this->access = $access;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// Rules routes.
		register_rest_route(
			$this->namespace,
			'/media/(?P<media_id>[\d]+)/rules',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rules' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => $this->get_media_id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_rules' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array_merge(
						$this->get_media_id_arg(),
						array(
							'rules' => array(
								'type'     => 'array',
								'required' => true,
								'items'    => array(
									'type'       => 'object',
									'properties' => array(
										'rule_type'  => array(
											'type'     => 'string',
											'required' => true,
											'enum'     => AccessRulesService::RULE_TYPES,
										),
										'rule_value' => array(
											'type'     => 'string',
											'required' => true,
										),
										'price'      => array(
											'type' => 'number',
										),
										'currency'   => array(
											'type'      => 'string',
											'maxLength' => 3,
										),
									),
								),
							),
						)
					),
				),
			)
		);

		// Single rule delete.
		register_rest_route(
			$this->namespace,
			'/media/(?P<media_id>[\d]+)/rules/(?P<rule_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_rule' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array_merge(
						$this->get_media_id_arg(),
						array(
							'rule_id' => array(
								'type'              => 'integer',
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
						)
					),
				),
			)
		);

		// Grant access.
		register_rest_route(
			$this->namespace,
			'/media/(?P<media_id>[\d]+)/grant',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'grant_access' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array_merge(
						$this->get_media_id_arg(),
						array(
							'user_id'    => array(
								'type'              => 'integer',
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
							'source'     => array(
								'type'    => 'string',
								'default' => 'manual',
								'enum'    => AccessRulesService::GRANT_SOURCES,
							),
							'expires_at' => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
						)
					),
				),
			)
		);

		// Revoke access.
		register_rest_route(
			$this->namespace,
			'/media/(?P<media_id>[\d]+)/grant/(?P<user_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke_access' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array_merge(
						$this->get_media_id_arg(),
						array(
							'user_id' => array(
								'type'              => 'integer',
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
						)
					),
				),
			)
		);

		// Current user grants.
		register_rest_route(
			$this->namespace,
			'/me/grants',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_grants' ),
					'permission_callback' => array( $this, 'authenticated_check' ),
					'args'                => array(
						'per_page'    => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
						'page'        => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'active_only' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Get rules for a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_rules( $request ) {
		$media_id = $request->get_param( 'media_id' );

		if ( ! $this->media_exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$rules = $this->access->get_rules( $media_id );

		return rest_ensure_response(
			array(
				'media_id' => $media_id,
				'rules'    => $rules,
			)
		);
	}

	/**
	 * Set (replace) rules for a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_rules( $request ) {
		$rate_check = RateLimiter::check( 'access_write', 30, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id = $request->get_param( 'media_id' );

		if ( ! $this->media_exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$rules = $request->get_param( 'rules' );
		$count = $this->access->set_rules( $media_id, $rules );

		return rest_ensure_response(
			array(
				'media_id'      => $media_id,
				'rules_created' => $count,
				'rules'         => $this->access->get_rules( $media_id ),
			)
		);
	}

	/**
	 * Delete a single rule.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_rule( $request ) {
		$rule_id  = $request->get_param( 'rule_id' );
		$media_id = $request->get_param( 'media_id' );

		// Verify the rule belongs to the specified media item.
		$rules = $this->access->get_rules( $media_id );
		$found = false;
		foreach ( $rules as $rule ) {
			if ( (int) $rule['id'] === $rule_id ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error( 'mvs_not_found', __( 'Rule not found for this media item.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$deleted = $this->access->delete_rule( $rule_id );

		if ( ! $deleted ) {
			return new WP_Error( 'mvs_not_found', __( 'Rule not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Grant access to a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function grant_access( $request ) {
		$rate_check = RateLimiter::check( 'access_write', 30, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id   = $request->get_param( 'media_id' );
		$user_id    = $request->get_param( 'user_id' );
		$source     = $request->get_param( 'source' );
		$expires_at = $request->get_param( 'expires_at' );

		if ( ! $this->media_exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'mvs_invalid_user', __( 'User not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$grant_id = $this->access->grant_access( $media_id, $user_id, $source, $expires_at );

		if ( ! $grant_id ) {
			return new WP_Error( 'mvs_grant_failed', __( 'Failed to grant access.', 'wpmediaverse' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'grant_id' => $grant_id,
				'media_id' => $media_id,
				'user_id'  => $user_id,
				'source'   => $source,
			)
		);
	}

	/**
	 * Revoke access for a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke_access( $request ) {
		$media_id = $request->get_param( 'media_id' );
		$user_id  = $request->get_param( 'user_id' );

		$revoked = $this->access->revoke_access( $media_id, $user_id );

		if ( ! $revoked ) {
			return new WP_Error( 'mvs_not_found', __( 'No active grant found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Get current user's grants.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_my_grants( $request ) {
		$mvs_per_page = $request->get_param( 'per_page' );
		$page         = $request->get_param( 'page' );
		$active_only  = $request->get_param( 'active_only' );

		$result = $this->access->get_user_grants( get_current_user_id(), $mvs_per_page, $page, $active_only );

		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', $result['total'] );
		$response->header( 'X-WP-TotalPages', ceil( $result['total'] / max( 1, $mvs_per_page ) ) );

		return $response;
	}

	/**
	 * Permission check: owner or manage_mvs_access capability.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function manage_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'mvs_unauthorized', __( 'You must be logged in.', 'wpmediaverse' ), array( 'status' => 401 ) );
		}

		$media_id = $request->get_param( 'media_id' );

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Owner or user with manage_mvs_access capability.
		if ( get_current_user_id() === \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $media_id ) || current_user_can( 'manage_mvs_access' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return true;
		}

		return new WP_Error( 'mvs_forbidden', __( 'You do not have permission to manage access rules.', 'wpmediaverse' ), array( 'status' => 403 ) );
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
		return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id );
	}

	/**
	 * Common media_id argument definition.
	 *
	 * @return array
	 */
	private function get_media_id_arg(): array {
		return array(
			'media_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
	}
}
