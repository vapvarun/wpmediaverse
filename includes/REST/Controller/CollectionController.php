<?php
/**
 * Collection REST controller.
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

/**
 * REST controller for collections.
 */
class CollectionController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * REST API base route.
	 *
	 * @var string
	 */
	protected $rest_base = 'collections';

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
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => function () {
						return is_user_logged_in();
					},
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => function () {
						return is_user_logged_in();
					},
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'owner_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'owner_permissions_check' ),
				),
			)
		);
	}

	/**
	 * List current user's collections.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$per_page = $request->get_param( 'per_page' );
		$per_page = $per_page ? (int) $per_page : 20;
		$page     = $request->get_param( 'page' );
		$page     = $page ? (int) $page : 1;

		$query = new \WP_Query(
			array(
				'post_type'      => 'mvs_collection',
				'post_status'    => 'publish',
				'author'         => get_current_user_id(),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->prepare_collection_response( $post );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $query->found_posts );
		$response->header( 'X-WP-TotalPages', $query->max_num_pages );

		return $response;
	}

	/**
	 * Get a single collection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post = get_post( $request->get_param( 'id' ) );
		if ( ! $post || 'mvs_collection' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Collection not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_collection_response( $post, true ) );
	}

	/**
	 * Create a collection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$rate_check = RateLimiter::check( 'collection_create', 20, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$title = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
		if ( empty( $title ) ) {
			return new WP_Error( 'mvs_missing_title', __( 'Collection title is required.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'mvs_collection',
				'post_title'   => $title,
				'post_content' => wp_kses_post( $request->get_param( 'description' ) ?? '' ),
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$response = rest_ensure_response( $this->prepare_collection_response( get_post( $post_id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Update a collection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$rate_check = RateLimiter::check( 'collection_update', 60, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$collection_id = $request->get_param( 'id' );
		$update_data   = array( 'ID' => $collection_id );

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$update_data['post_title'] = sanitize_text_field( $title );
		}

		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			$update_data['post_content'] = wp_kses_post( $description );
		}

		if ( count( $update_data ) > 1 ) {
			$result = wp_update_post( $update_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return rest_ensure_response( $this->prepare_collection_response( get_post( $collection_id ) ) );
	}

	/**
	 * Delete a collection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$collection_id = $request->get_param( 'id' );

		// Remove favorites referencing this collection.
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_favorites',
			array( 'collection_id' => null ),
			array( 'collection_id' => $collection_id ),
			array( '%s' ),
			array( '%d' )
		);

		$deleted = wp_delete_post( $collection_id, true );

		if ( ! $deleted ) {
			return new WP_Error( 'mvs_delete_failed', __( 'Failed to delete collection.', 'wpmediaverse' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Permissions: view collection (owner or admin).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$post    = get_post( $request->get_param( 'id' ) );
		$user_id = get_current_user_id();

		if ( ! $post || 'mvs_collection' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Collection not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( (int) $post->post_author === $user_id || current_user_can( 'moderate_mvs_media' ) ) {
			return true;
		}

		return new WP_Error( 'mvs_forbidden', __( 'You do not have access to this collection.', 'wpmediaverse' ), array( 'status' => 403 ) );
	}

	/**
	 * Permissions: owner only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function owner_permissions_check( $request ) {
		$post    = get_post( $request->get_param( 'id' ) );
		$user_id = get_current_user_id();

		if ( ! $post || 'mvs_collection' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Collection not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( (int) $post->post_author === $user_id || current_user_can( 'moderate_mvs_media' ) ) {
			return true;
		}

		return new WP_Error( 'mvs_forbidden', __( 'You do not have permission to modify this collection.', 'wpmediaverse' ), array( 'status' => 403 ) );
	}

	/**
	 * Prepare collection data for response.
	 *
	 * @param \WP_Post $post          Post object.
	 * @param bool     $include_items Whether to include favorited items.
	 * @return array
	 */
	private function prepare_collection_response( $post, bool $include_items = false ): array {
		$data = array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'description' => $post->post_content,
			'author'      => (int) $post->post_author,
			'date'        => $post->post_date_gmt,
		);

		if ( $include_items ) {
			global $wpdb;
			$data['favorites'] = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id, created_at FROM {$wpdb->prefix}mvs_favorites WHERE collection_id = %d ORDER BY created_at DESC",
					$post->ID
				),
				ARRAY_A
			);
		}

		return $data;
	}

	/**
	 * Get collection parameters.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
		);
	}
}
