<?php
/**
 * Media REST controller.
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
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\REST\RateLimiter;
use WPMediaVerse\Services\PrivacyService;

/**
 * REST controller for media items.
 */
class MediaController extends WP_REST_Controller {

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
	protected $rest_base = 'media';

	/**
	 * Privacy service instance.
	 *
	 * @var PrivacyService
	 */
	private $privacy;

	/**
	 * Constructor.
	 *
	 * @param PrivacyService $privacy Privacy service instance.
	 */
	public function __construct( PrivacyService $privacy ) {
		$this->privacy = $privacy;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// GET /media — list, POST /media — create.
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
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// GET/PUT/DELETE /media/{id}.
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
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// POST /media/{id}/view — record a view.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/view',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'record_view' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /media/{id}/access — check access.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/access',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check_access' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /me/media — current user's media.
		register_rest_route(
			$this->namespace,
			'/me/media',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_my_items' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'args'                => $this->get_collection_params(),
			)
		);
	}

	/**
	 * Get collection of media items.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		global $wpdb;

		$per_page = $request->get_param( 'per_page' );
		$per_page = $per_page ? (int) $per_page : 20;
		$page     = $request->get_param( 'page' );
		$page     = $page ? (int) $page : 1;
		$offset   = ( $page - 1 ) * $per_page;
		$user_id  = get_current_user_id();

		$media_type = $request->get_param( 'media_type' );
		$author     = $request->get_param( 'author' );

		$where  = array( 'moderation_status = %s' );
		$params = array( 'approved' );

		// Privacy filtering via index table.
		if ( ! $user_id ) {
			$where[]  = 'privacy = %s';
			$params[] = 'public';
		} elseif ( ! user_can( $user_id, 'moderate_mvs_media' ) ) {
			$where[]  = "(privacy = 'public' OR privacy = 'members' OR post_author = %d)";
			$params[] = $user_id;
		}

		if ( $media_type ) {
			$where[]  = 'media_type = %s';
			$params[] = sanitize_text_field( $media_type );
		}

		if ( $author ) {
			$where[]  = 'post_author = %d';
			$params[] = (int) $author;
		}

		$where_sql = implode( ' AND ', $where );

		// Count query.
		$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		// Data query.
		$params[] = $per_page;
		$params[] = $offset;
		$data_sql = "SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$ids      = $wpdb->get_col( $wpdb->prepare( $data_sql, ...$params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		$items = array();
		foreach ( $ids as $media_id ) {
			$item = $this->prepare_item_for_response( get_post( (int) $media_id ), $request );
			if ( $item ) {
				$items[] = $item;
			}
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Get a single media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post = get_post( $request->get_param( 'id' ) );
		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_item_for_response( $post, $request ) );
	}

	/**
	 * Create a media item via file upload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$rate_check = RateLimiter::check( 'media_create', 30, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'mvs_no_file', __( 'No file was uploaded.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$file = $files['file'];
		if ( $file['error'] ) {
			return new WP_Error( 'mvs_upload_error', __( 'File upload error.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$upload_service = Plugin::container()->get( 'upload' );
		$args           = array(
			'title'       => sanitize_text_field( $request->get_param( 'title' ) ?? '' ),
			'description' => wp_kses_post( $request->get_param( 'description' ) ?? '' ),
			'privacy'     => sanitize_text_field( $request->get_param( 'privacy' ) ?? '' ),
		);

		$media_id = $upload_service->handle( $file, get_current_user_id(), $args );

		if ( is_wp_error( $media_id ) ) {
			return $media_id;
		}

		// Apply tags and categories if provided.
		$tags = $request->get_param( 'tags' );
		if ( $tags && is_array( $tags ) ) {
			wp_set_object_terms( $media_id, array_map( 'sanitize_text_field', $tags ), 'mvs_tag' );
		}

		$categories = $request->get_param( 'categories' );
		if ( $categories && is_array( $categories ) ) {
			wp_set_object_terms( $media_id, array_map( 'absint', $categories ), 'mvs_category' );
		}

		$post     = get_post( $media_id );
		$response = rest_ensure_response( $this->prepare_item_for_response( $post, $request ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Update a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$rate_check = RateLimiter::check( 'media_update', 60, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id = $request->get_param( 'id' );
		$post     = get_post( $media_id );

		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$update_data = array( 'ID' => $media_id );

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

		// Update privacy.
		$privacy = $request->get_param( 'privacy' );
		if ( $privacy ) {
			$privacy = sanitize_text_field( $privacy );
			update_post_meta( $media_id, '_mvs_privacy', $privacy );

			// Update index table.
			global $wpdb;
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_media_index',
				array( 'privacy' => $privacy ),
				array( 'media_id' => $media_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		$post = get_post( $media_id );
		return rest_ensure_response( $this->prepare_item_for_response( $post, $request ) );
	}

	/**
	 * Delete a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$rate_check = RateLimiter::check( 'media_delete', 30, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id = $request->get_param( 'id' );
		$post     = get_post( $media_id );

		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Delete stored file.
		$file_path = get_post_meta( $media_id, '_mvs_file_path', true );
		if ( $file_path ) {
			$storage = Plugin::container()->get( 'storage' );
			$storage->get_driver()->delete( $file_path );
		}

		// Remove from index.
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'mvs_media_index', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Delete the post.
		$deleted = wp_delete_post( $media_id, true );

		if ( ! $deleted ) {
			return new WP_Error( 'mvs_delete_failed', __( 'Failed to delete media item.', 'wpmediaverse' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Record a view for a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function record_view( $request ) {
		$media_id = $request->get_param( 'id' );
		$post     = get_post( $media_id );

		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Check view access.
		$user_id = get_current_user_id();
		if ( ! $this->privacy->can_view( $media_id, $user_id ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You do not have access to this media item.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}

		global $wpdb;

		$ip_hash = hash( 'sha256', self::get_client_ip() . wp_salt() );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_views',
			array(
				'media_id'   => $media_id,
				'user_id'    => $user_id ? $user_id : null,
				'ip_hash'    => $ip_hash,
				'event_type' => 'view',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		// Ensure stats row exists (covers media created before publish hook).
		\WPMediaVerse\Core\Plugin::ensure_media_rows( $media_id, $post );

		// Increment stats.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mvs_media_stats SET views = views + 1, updated_at = %s WHERE media_id = %d",
				current_time( 'mysql', true ),
				$media_id
			)
		);

		return rest_ensure_response( array( 'recorded' => true ) );
	}

	/**
	 * Check access for a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function check_access( $request ) {
		$media_id = $request->get_param( 'id' );
		$post     = get_post( $media_id );

		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$user_id  = get_current_user_id();
		$can_view = $this->privacy->can_view( $media_id, $user_id );
		$is_owner = $user_id && (int) $post->post_author === $user_id;

		// Only expose privacy details to users who can view or own the media.
		if ( $can_view || $is_owner ) {
			$privacy_meta = get_post_meta( $media_id, '_mvs_privacy', true );
			return rest_ensure_response(
				array(
					'media_id' => $media_id,
					'can_view' => $can_view,
					'privacy'  => $privacy_meta ? $privacy_meta : 'public',
					'is_owner' => $is_owner,
				)
			);
		}

		return rest_ensure_response(
			array(
				'media_id' => $media_id,
				'can_view' => false,
			)
		);
	}

	/**
	 * Get current user's media items.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_my_items( $request ) {
		$request->set_param( 'author', get_current_user_id() );
		return $this->get_items( $request );
	}

	/**
	 * Permissions: create item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! current_user_can( 'upload_mvs_media' ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You do not have permission to upload media.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permissions: get item (privacy check).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$media_id = $request->get_param( 'id' );
		$user_id  = get_current_user_id();

		if ( ! $this->privacy->can_view( $media_id, $user_id ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You do not have access to this media item.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permissions: update item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$post    = get_post( $request->get_param( 'id' ) );
		$user_id = get_current_user_id();

		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( (int) $post->post_author === $user_id && current_user_can( 'edit_mvs_medias' ) ) {
			return true;
		}

		if ( current_user_can( 'edit_others_mvs_medias' ) ) {
			return true;
		}

		return new WP_Error( 'mvs_forbidden', __( 'You do not have permission to edit this media item.', 'wpmediaverse' ), array( 'status' => 403 ) );
	}

	/**
	 * Permissions: delete item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		$post    = get_post( $request->get_param( 'id' ) );
		$user_id = get_current_user_id();

		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( (int) $post->post_author === $user_id && current_user_can( 'delete_mvs_medias' ) ) {
			return true;
		}

		if ( current_user_can( 'delete_others_mvs_medias' ) ) {
			return true;
		}

		return new WP_Error( 'mvs_forbidden', __( 'You do not have permission to delete this media item.', 'wpmediaverse' ), array( 'status' => 403 ) );
	}

	/**
	 * Prepare a media item for REST response.
	 *
	 * @param \WP_Post        $post    Post object.
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function prepare_item_for_response( $post, $request ) {
		if ( ! $post ) {
			return null;
		}

		$media_id         = $post->ID;
		$privacy_value    = get_post_meta( $media_id, '_mvs_privacy', true );
		$moderation_value = get_post_meta( $media_id, '_mvs_moderation_status', true );

		$data = array(
			'id'                => $media_id,
			'title'             => $post->post_title,
			'description'       => $post->post_content,
			'author'            => (int) $post->post_author,
			'date'              => $post->post_date_gmt,
			'file_url'          => get_post_meta( $media_id, '_mvs_file_url', true ),
			'file_size'         => (int) get_post_meta( $media_id, '_mvs_file_size', true ),
			'file_type'         => get_post_meta( $media_id, '_mvs_file_type', true ),
			'media_type'        => get_post_meta( $media_id, '_mvs_media_type', true ),
			'privacy'           => $privacy_value ? $privacy_value : 'public',
			'moderation_status' => $moderation_value ? $moderation_value : 'approved',
			'tags'              => wp_get_object_terms( $media_id, 'mvs_tag', array( 'fields' => 'names' ) ),
			'categories'        => wp_get_object_terms( $media_id, 'mvs_category', array( 'fields' => 'names' ) ),
		);

		/**
		 * Filter the media item REST response data.
		 *
		 * @param array $data     Response data.
		 * @param int   $media_id Media post ID.
		 */
		return apply_filters( 'mvs_media_response', $data, $media_id );
	}

	/**
	 * Get collection parameters.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'per_page'   => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'page'       => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'media_type' => array(
				'type'              => 'string',
				'enum'              => array( 'image', 'video', 'audio', 'document' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'author'     => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Get client IP.
	 *
	 * @return string
	 */
	private static function get_client_ip(): string {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '127.0.0.1';
	}
}
