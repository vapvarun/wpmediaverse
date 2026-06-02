<?php
/**
 * Profile REST controller.
 *
 * Endpoints for editing own profile and managing avatar.
 * Works standalone — no BuddyPress dependency.
 *
 * @package    WPMediaVerse
 * @subpackage REST
 * @since      1.1.0
 */

namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPMediaVerse\Services\ProfileService;

/**
 * REST controller for profile management.
 */
class ProfileController extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * @var ProfileService
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param ProfileService $service Profile service.
	 */
	public function __construct( ProfileService $service ) {
		$this->service = $service;
	}

	/**
	 * Register routes.
	 *
	 * @since 1.1.0
	 */
	public function register_routes(): void {
		// GET /me/profile — get own profile.
		// PUT /me/profile — update own profile fields.
		register_rest_route(
			$this->namespace,
			'/me/profile',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_profile' ),
					'permission_callback' => array( $this, 'check_logged_in' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_profile' ),
					'permission_callback' => array( $this, 'check_logged_in' ),
					'args'                => array(
						'first_name'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'last_name'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'display_name' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'description'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'dm_access'     => array(
							'type'              => 'string',
							'enum'              => ProfileService::META_VALUES['dm_access'],
							'sanitize_callback' => 'sanitize_key',
						),
						'online_status' => array(
							'type'              => 'string',
							'enum'              => ProfileService::META_VALUES['online_status'],
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// POST /me/avatar — upload custom avatar.
		register_rest_route(
			$this->namespace,
			'/me/avatar',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_avatar' ),
					'permission_callback' => array( $this, 'check_logged_in' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_avatar' ),
					'permission_callback' => array( $this, 'check_logged_in' ),
				),
			)
		);
	}

	/**
	 * Permission check: user must be logged in.
	 *
	 * @return bool|WP_Error
	 */
	public function check_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'mvs_not_logged_in', __( 'You must be logged in.', 'wpmediaverse' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Get own profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_profile( $request ) {
		$profile = $this->service->get_profile( get_current_user_id() );

		if ( ! $profile ) {
			return new WP_Error( 'mvs_profile_error', __( 'Could not load profile.', 'wpmediaverse' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $profile );
	}

	/**
	 * Update own profile fields.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_profile( $request ) {
		$user_id = get_current_user_id();
		$fields  = array();

		$accepted = array_merge( ProfileService::ALLOWED_FIELDS, array_keys( ProfileService::META_FIELDS ) );
		foreach ( $accepted as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$fields[ $field ] = $value;
			}
		}

		if ( empty( $fields ) ) {
			return new WP_Error( 'mvs_no_fields', __( 'No fields to update.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$result = $this->service->update_profile( $user_id, $fields );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Return updated profile.
		$profile = $this->service->get_profile( $user_id );

		return rest_ensure_response( $profile );
	}

	/**
	 * Upload a custom avatar.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_avatar( $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['avatar'] ) ) {
			return new WP_Error( 'mvs_no_file', __( 'No avatar file uploaded. Send as "avatar" field.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$user_id = get_current_user_id();
		$result  = $this->service->upload_avatar( $user_id, $files['avatar'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'avatar_id'  => $result,
				'avatar_url' => $this->service->get_custom_avatar_url( $user_id, 150 ),
			)
		);
	}

	/**
	 * Delete custom avatar (revert to Gravatar).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_avatar( $request ) {
		$user_id = get_current_user_id();
		$deleted = $this->service->delete_avatar( $user_id );

		if ( ! $deleted ) {
			return new WP_Error( 'mvs_no_avatar', __( 'No custom avatar to delete.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'deleted'    => true,
				'avatar_url' => get_avatar_url( $user_id, array( 'size' => 150 ) ),
			)
		);
	}
}
