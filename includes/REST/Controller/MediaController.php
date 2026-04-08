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
use WPMediaVerse\Core\TemplateHelpers;
use WPMediaVerse\REST\RateLimiter;
use WPMediaVerse\Repository\MediaRepository;
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

		// POST /media/{id}/replace — replace the file for an existing media item.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/replace',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'replace_file' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
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

		// GET /media/{id}/group — get all items in same gallery group.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/group',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_group_items' ),
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
		// Rate limit public reads: 120/min per user/IP to prevent scraping.
		$rate_check = RateLimiter::check( 'media_read', 120, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		global $wpdb;

		// Slug lookup — return single item by slug column in mvs_media_index.
		$slug = $request->get_param( 'slug' );
		if ( $slug ) {
			$found_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE slug = %s AND status = 'publish' LIMIT 1",
					sanitize_title( $slug )
				)
			);
			if ( $found_id ) {
				$item = $this->prepare_item_for_response( (int) $found_id, $request );
				return rest_ensure_response( $item ? array( $item ) : array() );
			}
			return rest_ensure_response( array() );
		}

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

		// Tag filter (by slug).
		$join_clauses = array();
		$tag_slug     = $request->get_param( 'tag' );
		if ( $tag_slug ) {
			$tag_term = get_term_by( 'slug', $tag_slug, 'mvs_tag' );
			if ( $tag_term ) {
				$join_clauses[] = $wpdb->prepare(
					"INNER JOIN {$wpdb->term_relationships} mvs_tr ON mvs_tr.object_id = i.media_id AND mvs_tr.term_taxonomy_id = %d",
					$tag_term->term_taxonomy_id
				);
			}
		}

		// Category filter (by slug).
		$cat_slug = $request->get_param( 'category' );
		if ( $cat_slug ) {
			$cat_term = get_term_by( 'slug', $cat_slug, 'mvs_category' );
			if ( $cat_term ) {
				$join_clauses[] = $wpdb->prepare(
					"INNER JOIN {$wpdb->term_relationships} mvs_cr ON mvs_cr.object_id = i.media_id AND mvs_cr.term_taxonomy_id = %d",
					$cat_term->term_taxonomy_id
				);
			}
		}

		// Search filter.
		$search = $request->get_param( 's' );
		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(title LIKE %s OR description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		// Scope filter.
		$scope = $request->get_param( 'scope' );
		if ( 'public' === $scope ) {
			$where[]  = 'privacy = %s';
			$params[] = 'public';
		}

		// Exclude media from blocked users.
		if ( $user_id ) {
			$blocked_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT blocked_id FROM {$wpdb->prefix}mvs_blocks WHERE blocker_id = %d",
					$user_id
				)
			);
			if ( $blocked_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $blocked_ids ), '%d' ) );
				$where[]      = "post_author NOT IN ($placeholders)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $blocked_ids as $bid ) {
					$params[] = (int) $bid;
				}
			}
		}

		// Exclude non-cover gallery group items from feeds (only when explicitly requested).
		$group_covers = $request->get_param( 'group_covers' );
		if ( $group_covers ) {
			$where[] = "(media_id NOT IN (
				SELECT mm.media_id FROM {$wpdb->prefix}mvs_media_meta mm
				WHERE mm.meta_key = 'media_group' AND mm.media_id != (
					SELECT mm2.media_id FROM {$wpdb->prefix}mvs_media_meta mm2
					WHERE mm2.meta_key = 'media_group' AND mm2.meta_value = mm.meta_value
					ORDER BY mm2.media_id ASC LIMIT 1
				)
			))";
		}

		// Filter by specific media group ID.
		$media_group_param = sanitize_text_field( $request->get_param( 'media_group' ) ?? '' );
		if ( $media_group_param ) {
			$where[]  = "media_id IN (SELECT media_id FROM {$wpdb->prefix}mvs_media_meta WHERE meta_key = 'media_group' AND meta_value = %s)";
			$params[] = $media_group_param;
		}

		$where_sql = implode( ' AND ', $where );

		/**
		 * Filters the media feed query arguments.
		 *
		 * @since 1.1.0
		 *
		 * @param array           $query_args {
		 *     @type string[] $where    WHERE clause fragments.
		 *     @type array    $params   Prepared statement parameters.
		 *     @type string   $orderby  Sort order (date|trending|popular).
		 *     @type int      $per_page Items per page.
		 *     @type int      $offset   Query offset.
		 * }
		 * @param WP_REST_Request $request REST request object.
		 */
		$feed_args = apply_filters(
			'mvs_feed_query_args',
			array(
				'where'    => $where,
				'params'   => $params,
				'orderby'  => $request->get_param( 'orderby' ),
				'per_page' => $per_page,
				'offset'   => $offset,
			),
			$request
		);

		// Re-extract in case the filter modified them.
		$where    = $feed_args['where'];
		$params   = $feed_args['params'];
		$per_page = $feed_args['per_page'];
		$offset   = $feed_args['offset'];

		$where_sql = implode( ' AND ', $where );
		$join_sql  = ! empty( $join_clauses ) ? ' ' . implode( ' ', $join_clauses ) : '';

		// Count query.
		$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index i{$join_sql} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		// Determine sort order.
		$orderby  = $request->get_param( 'orderby' );
		$params[] = $per_page;
		$params[] = $offset;

		if ( 'trending' === $orderby ) {
			// Trending score: (reactions * 3 + comments * 5 + views) / age_hours^1.5
			// JOIN mvs_media_stats for engagement data.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$data_sql = "SELECT i.media_id,
				((COALESCE(s.reactions, 0) * 3 + COALESCE(s.comments, 0) * 5 + COALESCE(s.views, 0))
				/ POWER(GREATEST(TIMESTAMPDIFF(HOUR, i.created_at, NOW()), 1), 1.5)) AS trending_score
				FROM {$wpdb->prefix}mvs_media_index i
				LEFT JOIN {$wpdb->prefix}mvs_media_stats s ON i.media_id = s.media_id{$join_sql}
				WHERE {$where_sql}
				ORDER BY trending_score DESC
				LIMIT %d OFFSET %d";
		} elseif ( 'popular' === $orderby ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$data_sql = "SELECT i.media_id
				FROM {$wpdb->prefix}mvs_media_index i
				LEFT JOIN {$wpdb->prefix}mvs_media_stats s ON i.media_id = s.media_id{$join_sql}
				WHERE {$where_sql}
				ORDER BY COALESCE(s.views, 0) DESC
				LIMIT %d OFFSET %d";
		} else {
			$data_sql = "SELECT i.media_id FROM {$wpdb->prefix}mvs_media_index i{$join_sql} WHERE {$where_sql} ORDER BY i.created_at DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$results = $wpdb->get_col( $wpdb->prepare( $data_sql, ...$params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$ids     = $results;

		$int_ids = array_map( 'intval', $ids );

		/**
		 * Filter the final list of media IDs returned by the feed query.
		 *
		 * Allows Pro to reorder results (e.g. promote boosted media).
		 *
		 * @since 1.1.0
		 *
		 * @param int[]           $int_ids Media IDs in display order.
		 * @param WP_REST_Request $request REST request object.
		 */
		$int_ids = apply_filters( 'mvs_feed_media_ids', $int_ids, $request );

		$items = array();
		foreach ( $int_ids as $media_id ) {
			$item = $this->prepare_item_for_response( $media_id, $request );
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
		$media_id = (int) $request->get_param( 'id' );
		if ( ! MediaRepository::exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_item_for_response( $media_id, $request ) );
	}

	/**
	 * Get all media items in the same gallery group.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_group_items( $request ) {
		$media_id = (int) $request->get_param( 'id' );

		if ( ! MediaRepository::exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$group_id = MediaRepository::get( $media_id, 'media_group' );
		if ( ! $group_id ) {
			return rest_ensure_response( array( $this->prepare_item_for_response( $media_id, $request ) ) );
		}

		// Query group members from mvs_media_meta custom table.
		global $wpdb;
		$group_media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT mm.media_id FROM {$wpdb->prefix}mvs_media_meta mm
				INNER JOIN {$wpdb->prefix}mvs_media_index mi ON mm.media_id = mi.media_id
				WHERE mm.meta_key = 'media_group' AND mm.meta_value = %s AND mi.status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$group_id
			)
		);

		if ( empty( $group_media_ids ) ) {
			return rest_ensure_response( array( $this->prepare_item_for_response( $media_id, $request ) ) );
		}

		$items = array();
		foreach ( $group_media_ids as $gid ) {
			$item = $this->prepare_item_for_response( (int) $gid, $request );
			if ( $item ) {
				$items[] = $item;
			}
		}

		// Sort by group_position.
		usort(
			$items,
			function ( $a, $b ) {
				return ( $a['group_position'] ?? 0 ) - ( $b['group_position'] ?? 0 );
			}
		);

		return rest_ensure_response( $items );
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
			'status'      => sanitize_text_field( $request->get_param( 'status' ) ?? 'publish' ),
			'publish_at'  => sanitize_text_field( $request->get_param( 'publish_at' ) ?? '' ),
		);

		// Only set privacy if explicitly provided; otherwise UploadService uses the default option.
		$privacy_param = $request->get_param( 'privacy' );
		if ( ! empty( $privacy_param ) ) {
			$args['privacy'] = sanitize_text_field( $privacy_param );
		}

		$media_id = $upload_service->handle( $file, get_current_user_id(), $args );

		if ( is_wp_error( $media_id ) ) {
			return $media_id;
		}

		// Apply tags and categories if provided.
		$tags = $request->get_param( 'tags' );
		if ( $tags ) {
			// Accept comma-separated string or array.
			if ( is_string( $tags ) ) {
				$tags = array_map( 'trim', explode( ',', $tags ) );
			}
			$tags = array_filter( array_map( 'sanitize_text_field', $tags ) );
			if ( $tags ) {
				wp_set_object_terms( $media_id, $tags, 'mvs_tag' );
			}
		}

		$categories = $request->get_param( 'categories' );
		if ( $categories && is_array( $categories ) ) {
			wp_set_object_terms( $media_id, array_map( 'absint', $categories ), 'mvs_category' );
		}

		// Store media group (gallery post) metadata.
		$media_group = sanitize_text_field( $request->get_param( 'media_group' ) ?? '' );
		if ( $media_group ) {
			MediaRepository::set( $media_id, 'media_group', $media_group );
			$group_position = absint( $request->get_param( 'group_position' ) );
			MediaRepository::set( $media_id, 'group_position', $group_position );
			if ( 0 === $group_position ) {
				MediaRepository::set( $media_id, 'group_cover', 1 );
			}
		}

		// Set group association if group_id is provided and user is a member.
		$group_id = absint( $request->get_param( 'group_id' ) );
		if ( $group_id > 0 && function_exists( 'groups_is_user_member' ) && groups_is_user_member( get_current_user_id(), $group_id ) ) {
			// privacy is an index column — MediaRepository::set writes directly to mvs_media_index.
			MediaRepository::set( $media_id, 'privacy', 'group' );
			MediaRepository::set( $media_id, 'group_id', $group_id );

			/**
			 * Fires after media is assigned to a group.
			 *
			 * @param int $media_id Media ID.
			 * @param int $group_id Group ID.
			 */
			do_action( 'mvs_media_group_assigned', $media_id, $group_id );
		}

		$response = rest_ensure_response( $this->prepare_item_for_response( $media_id, $request ) );
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

		$media_id = (int) $request->get_param( 'id' );

		if ( ! MediaRepository::exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$update_data = array();

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$update_data['title'] = sanitize_text_field( $title );
			$update_data['slug']  = MediaRepository::generate_unique_slug( $update_data['title'] );
		}

		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			$update_data['description'] = wp_kses_post( $description );
		}

		// Update privacy.
		$privacy = $request->get_param( 'privacy' );
		if ( $privacy ) {
			$update_data['privacy'] = sanitize_text_field( $privacy );
		}

		// Write all index/meta changes in one call.
		if ( ! empty( $update_data ) ) {
			MediaRepository::set_many( $media_id, $update_data );
		}

		// Update tags if provided.
		$tags = $request->get_param( 'tags' );
		if ( null !== $tags && is_array( $tags ) ) {
			wp_set_object_terms( $media_id, array_map( 'sanitize_text_field', $tags ), 'mvs_tag' );
		}

		// Update categories if provided.
		$categories = $request->get_param( 'categories' );
		if ( null !== $categories && is_array( $categories ) ) {
			wp_set_object_terms( $media_id, array_map( 'absint', $categories ), 'mvs_category' );
		}

		return rest_ensure_response( $this->prepare_item_for_response( $media_id, $request ) );
	}

	/**
	 * Replace the file of an existing media item.
	 *
	 * Accepts a new file upload, stores it, updates the media index,
	 * and deletes the old file. Preserves all metadata, reactions, and comments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function replace_file( $request ) {
		$rate_check = RateLimiter::check( 'media_replace', 30, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id = (int) $request->get_param( 'id' );

		if ( ! MediaRepository::exists( $media_id ) ) {
			return new \WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'mvs_no_file', __( 'No file provided.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$file = $files['file'];

		// Validate MIME type using UploadService.
		$upload_service = Plugin::container()->get( 'upload' );
		$allowed        = $upload_service->get_allowed_types_public();
		$finfo          = finfo_open( FILEINFO_MIME_TYPE );
		$mime           = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );

		if ( ! in_array( $mime, $allowed, true ) ) {
			return new \WP_Error( 'mvs_invalid_type', __( 'This file type is not allowed.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		// Store new file.
		$storage  = Plugin::container()->get( 'storage' );
		$driver   = $storage->get_driver();
		$dest_sub = gmdate( 'Y/m' );
		$filename = wp_unique_filename(
			wp_upload_dir()['basedir'] . '/wpmediaverse/' . $dest_sub,
			sanitize_file_name( $file['name'] )
		);
		$dest_path = $dest_sub . '/' . $filename;

		if ( ! $driver->store( $file['tmp_name'], $dest_path ) ) {
			return new \WP_Error( 'mvs_storage_failed', __( 'Failed to store the file.', 'wpmediaverse' ), array( 'status' => 500 ) );
		}

		// Delete old file.
		$old_path = MediaRepository::get( $media_id, 'file_path' );
		if ( $old_path ) {
			$driver->delete( $old_path );
		}

		// Update media index with new file data.
		$media_type = explode( '/', $mime )[0]; // image, video, audio.
		if ( ! in_array( $media_type, array( 'image', 'video', 'audio' ), true ) ) {
			$media_type = 'document';
		}

		MediaRepository::set_many(
			$media_id,
			array(
				'file_url'   => $driver->url( $dest_path ),
				'file_path'  => $dest_path,
				'file_type'  => $mime,
				'file_size'  => filesize( $file['tmp_name'] ) ?: 0,
				'file_hash'  => hash_file( 'sha256', $file['tmp_name'] ),
				'media_type' => $media_type,
			)
		);

		return rest_ensure_response( $this->prepare_item_for_response( $media_id, $request ) );
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

		$media_id = (int) $request->get_param( 'id' );

		if ( ! MediaRepository::exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$author_id = MediaRepository::get_author( $media_id );

		// Delete stored file.
		$file_path = MediaRepository::get( $media_id, 'file_path' );
		if ( $file_path ) {
			$storage = Plugin::container()->get( 'storage' );
			$storage->get_driver()->delete( $file_path );
		}

		// Remove from custom tables.
		global $wpdb;
		MediaRepository::delete_all( $media_id );
		$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Remove taxonomy relationships.
		wp_delete_object_term_relationships( $media_id, array( 'mvs_tag', 'mvs_category' ) );

		/**
		 * Fires after a media item has been permanently deleted.
		 *
		 * Pro uses this to decrement quota usage counters.
		 *
		 * @since 1.1.0
		 *
		 * @param int $media_id  The deleted media ID.
		 * @param int $author_id The author user ID.
		 */
		do_action( 'mvs_media_deleted', $media_id, $author_id );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Record a view for a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function record_view( $request ) {
		// Rate limit: 60 views/min per user/IP to prevent view count manipulation.
		$rate_check = RateLimiter::check( 'record_view', 60, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id = (int) $request->get_param( 'id' );

		if ( ! MediaRepository::exists( $media_id ) ) {
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

		// Ensure stats row exists, then increment.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}mvs_media_stats (media_id, views, updated_at) VALUES (%d, 1, %s)
				ON DUPLICATE KEY UPDATE views = views + 1, updated_at = VALUES(updated_at)",
				$media_id,
				current_time( 'mysql', true )
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
		$media_id = (int) $request->get_param( 'id' );

		if ( ! MediaRepository::exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$user_id  = get_current_user_id();
		$can_view = $this->privacy->can_view( $media_id, $user_id );
		$is_owner = $user_id && MediaRepository::get_author( $media_id ) === $user_id;

		// Only expose privacy details to users who can view or own the media.
		if ( $can_view || $is_owner ) {
			$privacy_meta = MediaRepository::get( $media_id, 'privacy' );
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
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'upload_mvs_media' ) ) {
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
		$media_id = (int) $request->get_param( 'id' );

		if ( ! MediaRepository::exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$user_id = get_current_user_id();

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
		$media_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();

		if ( ! MediaRepository::exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( MediaRepository::get_author( $media_id ) === $user_id && current_user_can( 'edit_mvs_medias' ) ) {
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
		$media_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();

		if ( ! MediaRepository::exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( MediaRepository::get_author( $media_id ) === $user_id && current_user_can( 'delete_mvs_medias' ) ) {
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
	 * Builds the response entirely from the mvs_media_index and mvs_media_meta
	 * custom tables — no WP_Post dependency.
	 *
	 * @param int             $media_id Media ID (row in mvs_media_index).
	 * @param WP_REST_Request $request  Request.
	 * @return array|null
	 */
	public function prepare_item_for_response( $media_id, $request ) {
		global $wpdb;

		$media_id = (int) $media_id;
		$all      = MediaRepository::get_all( $media_id );

		if ( empty( $all ) ) {
			return null;
		}

		$file_url = ! empty( $all['file_url'] ) ? set_url_scheme( $all['file_url'] ) : '';

		// Build thumbnail URL from custom meta.
		$thumbnail_url = TemplateHelpers::get_thumb_url( $media_id, 'large' );

		$media_type_value = ! empty( $all['media_type'] ) ? $all['media_type'] : '';
		$privacy_value    = ! empty( $all['privacy'] ) ? $all['privacy'] : 'public';
		$moderation_value = ! empty( $all['moderation_status'] ) ? $all['moderation_status'] : 'approved';

		$data = array(
			'id'                => $media_id,
			'title'             => ! empty( $all['title'] ) ? $all['title'] : '',
			'description'       => ! empty( $all['description'] ) ? $all['description'] : '',
			'author'            => ! empty( $all['post_author'] ) ? (int) $all['post_author'] : 0,
			'date'              => ! empty( $all['created_at'] ) ? $all['created_at'] : '',
			'link'              => MediaRepository::get_permalink( $media_id ),
			'file_url'          => $file_url,
			'file_size'         => ! empty( $all['file_size'] ) ? (int) $all['file_size'] : 0,
			'file_type'         => ! empty( $all['file_type'] ) ? $all['file_type'] : '',
			'media_type'        => $media_type_value,
			'privacy'           => $privacy_value,
			'moderation_status' => $moderation_value,
			'tags'              => self::parse_meta_list( $all['tags'] ?? '' ),
			'categories'        => self::parse_meta_list( $all['category'] ?? '' ),
			'thumbnail_url'     => $thumbnail_url,
		);

		// Add author data for lightbox sidebar.
		$author_id             = $data['author'];
		$author_name           = get_the_author_meta( 'display_name', $author_id );
		$author_avatar         = get_avatar_url( $author_id, array( 'size' => 64 ) );
		$author_url            = TemplateHelpers::get_user_profile_url( $author_id );
		$data['author_name']   = $author_name;
		$data['author_avatar'] = $author_avatar;
		$data['author_url']    = $author_url;
		$data['author_data']   = array(
			'name'        => $author_name,
			'avatar'      => $author_avatar,
			'profile_url' => $author_url,
		);

		// Add media group (gallery post) data.
		$media_group = ! empty( $all['media_group'] ) ? $all['media_group'] : '';
		if ( $media_group ) {
			$data['media_group']    = $media_group;
			$data['group_position'] = ! empty( $all['group_position'] ) ? (int) $all['group_position'] : 0;
			$data['group_cover']    = ! empty( $all['group_cover'] ) ? (bool) $all['group_cover'] : false;

			// Count group members.
			$data['group_count'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_meta WHERE meta_key = 'media_group' AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$media_group
				)
			);
		}

		// Add media-type-specific metadata.
		if ( in_array( $media_type_value, array( 'video', 'audio' ), true ) ) {
			$data['duration'] = ! empty( $all['duration'] ) ? (float) $all['duration'] : null;
			$data['bitrate']  = ! empty( $all['bitrate'] ) ? (int) $all['bitrate'] : null;
			$data['codec']    = ! empty( $all['codec'] ) ? $all['codec'] : null;
		}

		if ( in_array( $media_type_value, array( 'video', 'image' ), true ) ) {
			$data['width']  = ! empty( $all['width'] ) ? (int) $all['width'] : null;
			$data['height'] = ! empty( $all['height'] ) ? (int) $all['height'] : null;
		}

		if ( 'audio' === $media_type_value ) {
			$data['artist']     = ! empty( $all['artist'] ) ? $all['artist'] : null;
			$data['album_name'] = ! empty( $all['album_name'] ) ? $all['album_name'] : null;
		}

		// Include engagement stats for card builders.
		$stats_row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT views, reactions, comments FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$media_id
			),
			ARRAY_A
		);

		$data['stats'] = array(
			'views'     => (int) ( $stats_row['views'] ?? 0 ),
			'reactions' => (int) ( $stats_row['reactions'] ?? 0 ),
			'comments'  => (int) ( $stats_row['comments'] ?? 0 ),
		);

		/**
		 * Filter the media item REST response data.
		 *
		 * @param array $data     Response data.
		 * @param int   $media_id Media ID.
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
			'per_page'     => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				/**
				 * Filters the maximum items per page for media feed REST requests.
				 *
				 * @since 1.1.0
				 *
				 * @param int $maximum Maximum per_page value.
				 */
				'maximum'           => apply_filters( 'mvs_rest_pagination_max', 100 ),
				'sanitize_callback' => 'absint',
			),
			'page'         => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'media_type'   => array(
				'type'              => 'string',
				'enum'              => array( 'image', 'video', 'audio', 'document' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'author'       => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'slug'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			),
			'orderby'      => array(
				'type'              => 'string',
				'default'           => 'date',
				/**
				 * Filters the available sort options for the media feed.
				 *
				 * @since 1.1.0
				 *
				 * @param string[] $options Sort option slugs.
				 */
				'enum'              => apply_filters( 'mvs_feed_sort_options', array( 'date', 'trending', 'popular' ) ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'tag'          => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'category'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			's'            => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'scope'        => array(
				'type'              => 'string',
				'enum'              => array( 'public', 'all' ),
				'default'           => 'all',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'group_covers' => array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Get client IP.
	 *
	 * @return string
	 */
	/**
	 * Parse a meta value that may be JSON array or comma-separated into a flat array.
	 *
	 * @param string $raw Raw meta value.
	 * @return string[]
	 */
	private static function parse_meta_list( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( array_map( 'trim', $decoded ) ) );
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	private static function get_client_ip(): string {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '127.0.0.1';
	}
}
