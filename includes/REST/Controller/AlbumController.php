<?php
/**
 * Album REST controller.
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
 * REST controller for albums.
 */
class AlbumController extends WP_REST_Controller {

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
	protected $rest_base = 'albums';

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
					'permission_callback' => '__return_true',
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
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
					'permission_callback' => '__return_true',
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
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);

		// PUT /albums/{id}/reorder.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/reorder',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'reorder_items' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => array(
					'id'    => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'order' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
				),
			)
		);

		// POST /albums/{id}/items.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/items',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_items' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => array(
					'id'        => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'media_ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * List albums.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$per_page = $request->get_param( 'per_page' );
		$per_page = $per_page ? (int) $per_page : 20;
		$page     = $request->get_param( 'page' );
		$page     = $page ? (int) $page : 1;

		$args = array(
			'post_type'      => 'mvs_album',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$author = $request->get_param( 'author' );
		if ( $author ) {
			$args['author'] = (int) $author;
		}

		$query = new \WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->prepare_album_response( $post );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $query->found_posts );
		$response->header( 'X-WP-TotalPages', $query->max_num_pages );

		return $response;
	}

	/**
	 * Get a single album.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post = get_post( $request->get_param( 'id' ) );
		if ( ! $post || 'mvs_album' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Album not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_album_response( $post, true ) );
	}

	/**
	 * Create an album.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$rate_check = RateLimiter::check( 'album_create', 20, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$title = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
		if ( empty( $title ) ) {
			return new WP_Error( 'mvs_missing_title', __( 'Album title is required.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$post_data = array(
			'post_type'    => 'mvs_album',
			'post_title'   => $title,
			'post_content' => wp_kses_post( $request->get_param( 'description' ) ?? '' ),
			'post_status'  => 'publish',
			'post_author'  => get_current_user_id(),
		);

		$album_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $album_id ) ) {
			return $album_id;
		}

		$privacy = sanitize_text_field( $request->get_param( 'privacy' ) ?? 'public' );
		update_post_meta( $album_id, '_mvs_privacy', $privacy );

		$response = rest_ensure_response( $this->prepare_album_response( get_post( $album_id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Update an album.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$rate_check = RateLimiter::check( 'album_update', 60, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$album_id = $request->get_param( 'id' );
		$post     = get_post( $album_id );

		if ( ! $post || 'mvs_album' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Album not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$update_data = array( 'ID' => $album_id );

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

		$privacy = $request->get_param( 'privacy' );
		if ( $privacy ) {
			update_post_meta( $album_id, '_mvs_privacy', sanitize_text_field( $privacy ) );
		}

		return rest_ensure_response( $this->prepare_album_response( get_post( $album_id ) ) );
	}

	/**
	 * Delete an album.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$rate_check = RateLimiter::check( 'album_delete', 20, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$album_id = $request->get_param( 'id' );
		$post     = get_post( $album_id );

		if ( ! $post || 'mvs_album' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Album not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Remove album items from junction table.
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'mvs_album_items', array( 'album_id' => $album_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$deleted = wp_delete_post( $album_id, true );

		if ( ! $deleted ) {
			return new WP_Error( 'mvs_delete_failed', __( 'Failed to delete album.', 'wpmediaverse' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Reorder album items.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reorder_items( $request ) {
		$album_id = $request->get_param( 'id' );
		$order    = $request->get_param( 'order' );

		if ( ! is_array( $order ) ) {
			return new WP_Error( 'mvs_invalid_order', __( 'Order must be an array of media IDs.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		global $wpdb;

		foreach ( $order as $position => $media_id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_album_items',
				array( 'position' => (int) $position ),
				array(
					'album_id' => $album_id,
					'media_id' => (int) $media_id,
				),
				array( '%d' ),
				array( '%d', '%d' )
			);
		}

		return rest_ensure_response( array( 'reordered' => true ) );
	}

	/**
	 * Add media items to an album.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_items( $request ) {
		$rate_check = RateLimiter::check( 'album_add_items', 60, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$album_id  = $request->get_param( 'id' );
		$media_ids = $request->get_param( 'media_ids' );

		if ( ! is_array( $media_ids ) || empty( $media_ids ) ) {
			return new WP_Error( 'mvs_invalid_ids', __( 'media_ids must be a non-empty array.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		global $wpdb;

		// Get current max position.
		$max_pos = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(position) FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d",
				$album_id
			)
		);

		$added = 0;
		foreach ( $media_ids as $media_id ) {
			$media_id = (int) $media_id;
			$post     = get_post( $media_id );

			if ( ! $post || 'mvs_media' !== $post->post_type ) {
				continue;
			}

			++$max_pos;
			$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_album_items',
				array(
					'album_id' => $album_id,
					'media_id' => $media_id,
					'position' => $max_pos,
					'added_at' => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%d', '%s' )
			);

			if ( false !== $result ) {
				++$added;
			}
		}

		return rest_ensure_response( array( 'added' => $added ) );
	}

	/**
	 * Permissions: create album.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! current_user_can( 'upload_mvs_media' ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You do not have permission to create albums.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permissions: update album.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$post    = get_post( $request->get_param( 'id' ) );
		$user_id = get_current_user_id();

		if ( ! $post || 'mvs_album' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Album not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( (int) $post->post_author === $user_id || current_user_can( 'edit_others_mvs_medias' ) ) {
			return true;
		}

		return new WP_Error( 'mvs_forbidden', __( 'You do not have permission to edit this album.', 'wpmediaverse' ), array( 'status' => 403 ) );
	}

	/**
	 * Permissions: delete album.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		$post    = get_post( $request->get_param( 'id' ) );
		$user_id = get_current_user_id();

		if ( ! $post || 'mvs_album' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Album not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( (int) $post->post_author === $user_id || current_user_can( 'delete_others_mvs_medias' ) ) {
			return true;
		}

		return new WP_Error( 'mvs_forbidden', __( 'You do not have permission to delete this album.', 'wpmediaverse' ), array( 'status' => 403 ) );
	}

	/**
	 * Prepare album data for response.
	 *
	 * @param \WP_Post $post          Post object.
	 * @param bool     $include_items Whether to include album items.
	 * @return array
	 */
	private function prepare_album_response( $post, bool $include_items = false ): array {
		$album_id      = $post->ID;
		$privacy_value = get_post_meta( $album_id, '_mvs_privacy', true );

		$data = array(
			'id'          => $album_id,
			'title'       => $post->post_title,
			'description' => $post->post_content,
			'author'      => (int) $post->post_author,
			'date'        => $post->post_date_gmt,
			'privacy'     => $privacy_value ? $privacy_value : 'public',
		);

		if ( $include_items ) {
			global $wpdb;
			$data['items'] = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id, position FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d ORDER BY position ASC",
					$album_id
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
			'author'   => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}
}
