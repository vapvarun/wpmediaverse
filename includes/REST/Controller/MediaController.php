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
		// PUBLIC_OK on the four __return_true callbacks in this controller
		// (GET /media, POST /media/{id}/view, GET /media/{id}/access,
		// GET /media/{id}/group):
		// - GET /media — handler SQL at line 254-262 enforces
		// `moderation_status='approved' AND (privacy='public' OR
		// privacy='members' OR post_author=$current)`. Privacy gate
		// in the query, not the callback.
		// - POST /media/{id}/view — deliberate analytics ingest; counts
		// anonymous views by intent. Rate-limited inside record_view().
		// Hardening flagged for v1.3 (require session token); not a bug
		// today.
		// - GET /media/{id}/access — read-only access boolean. Returns
		// {can_view: bool} only — non-disclosive in itself.
		// - GET /media/{id}/group — gallery navigation; inherits the
		// privacy filter via MediaRepository.
		// Triaged 2026-05-01 (Item 5). All write routes
		// (POST /media, PUT/DELETE /media/{id}, POST /media/{id}/replace)
		// carry proper permission_callbacks.
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

		// POST /media/{id}/download — record a download event + increment downloads stat.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/download',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'record_download' ),
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

		// POST /media/{id}/share — record a share event + increment shares stat.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/share',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'record_share' ),
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

		$per_page = \WPMediaVerse\REST\Pagination::resolve_per_page( $request );
		$page     = \WPMediaVerse\REST\Pagination::resolve_page( $request );
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

		// Search filter — FULLTEXT index when supported, LIKE fallback otherwise.
		// FULLTEXT swap is the 100k-readiness fix (Phase 3B): LIKE '%term%' on
		// 100k rows is a sequential scan, MATCH/AGAINST hits the index.
		// `media_search_ft` is created in Migrator::migrate_to_13.
		$search = trim( (string) $request->get_param( 's' ) );
		if ( '' !== $search ) {
			$ft_term = $this->build_fulltext_search_term( $search );
			if ( null !== $ft_term && self::has_fulltext_search_index() ) {
				$where[]  = 'MATCH(title, description) AGAINST (%s IN BOOLEAN MODE)';
				$params[] = $ft_term;
			} else {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where[]  = '(title LIKE %s OR description LIKE %s)';
				$params[] = $like;
				$params[] = $like;
			}
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
		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$privacy = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'privacy' );
		if ( 'public' !== $privacy ) {
			$viewer_id = get_current_user_id();
			if ( ! $this->privacy->can_view( $media_id, $viewer_id ) ) {
				return new WP_Error( 'mvs_forbidden', __( 'You do not have permission to view this media.', 'wpmediaverse' ), array( 'status' => 403 ) );
			}
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

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$group_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'media_group' );
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

		// Client-generated video thumbnail (JS canvas captured first frame).
		// Used as a last-resort poster when the server-side ffmpeg / cover-atom
		// paths both failed. Routed through PosterService so every poster write
		// hits the same dir + filename convention. The staged frame then feeds
		// `UploadService::generate_thumbnails()` for sized variants + WebP/AVIF
		// siblings. Failure path is non-fatal; meta stays empty so the frontend
		// renders the default video poster at template time.
		if ( ! empty( $files['thumbnail'] ) && ! $files['thumbnail']['error'] ) {
			$existing_thumb = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'thumb_large' );

			// Regenerate when there is no thumb yet OR the stored value is not a
			// valid image URL. A prior write could have stored the video file URL
			// itself (the broken video-thumbnail case); skipping on a truthy-but-
			// invalid value left the thumbnail permanently broken.
			$needs_thumb = true;
			if ( is_string( $existing_thumb ) && '' !== $existing_thumb ) {
				$needs_thumb = ! preg_match( '#\.(jpe?g|png|gif|webp|avif)(?:[?\#].*)?$#i', $existing_thumb );
			}

			if ( $needs_thumb ) {
				$staged_path = \WPMediaVerse\Core\Plugin::container()->get( 'poster' )->stage_client_frame( $media_id, $files['thumbnail']['tmp_name'] );
				if ( $staged_path ) {
					$upload_service->generate_thumbnails( $media_id, $staged_path, 'image/jpeg' );
				}
			}
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
				\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'tags', wp_json_encode( array_values( $tags ) ) );
			}
		}

		$categories = $request->get_param( 'categories' );
		if ( $categories && is_array( $categories ) ) {
			wp_set_object_terms( $media_id, array_map( 'absint', $categories ), 'mvs_category' );
			$cat_terms = get_the_terms( $media_id, 'mvs_category' );
			if ( $cat_terms && ! is_wp_error( $cat_terms ) ) {
				\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'category', wp_json_encode( array_values( wp_list_pluck( $cat_terms, 'name' ) ) ) );
			}
		}

		// Store media group (gallery post) metadata.
		$media_group = sanitize_text_field( $request->get_param( 'media_group' ) ?? '' );
		if ( $media_group ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'media_group', $media_group );
			$group_position = absint( $request->get_param( 'group_position' ) );
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'group_position', $group_position );
			if ( 0 === $group_position ) {
				\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'group_cover', 1 );
			}
		}

		// Set group association if group_id is provided and user is a member.
		$group_id = absint( $request->get_param( 'group_id' ) );
		if ( $group_id > 0 && function_exists( 'groups_is_user_member' ) && groups_is_user_member( get_current_user_id(), $group_id ) ) {
			// privacy is an index column — \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set writes directly to mvs_media_index.
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'privacy', 'group' );
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'group_id', $group_id );

			/**
			 * Fires after media is assigned to a group.
			 *
			 * @param int $media_id Media ID.
			 * @param int $group_id Group ID.
			 */
			do_action( 'mvs_media_group_assigned', $media_id, $group_id );
		}

		$data = $this->prepare_item_for_response( $media_id, $request );

		// Surface a duplicate upload warning ("warn" mode) so the client can display a notice.
		$duplicate_of = $upload_service->get_last_duplicate_warning();
		if ( $duplicate_of > 0 ) {
			$data['duplicate_warning'] = true;
			$data['existing_media_id'] = $duplicate_of;
		}

		$response = rest_ensure_response( $data );
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

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$update_data = array();

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$update_data['title'] = sanitize_text_field( $title );
			// IMPORTANT: do NOT regenerate slug from the title. Title edits
			// must NOT change the public URL — that breaks inbound links,
			// shared OG cards, search-engine cache, and the URL the user
			// just had in their address bar (which would 404 on reload).
			// Callers that want to change the slug pass it explicitly via
			// the `slug` param below; everyone else gets URL stability.
		}

		// Explicit slug change — only when the caller explicitly supplies a
		// slug. Sanitized + uniqueness-checked against every other row.
		$slug_param = $request->get_param( 'slug' );
		if ( null !== $slug_param && '' !== trim( (string) $slug_param ) ) {
			$repo                = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
			$requested_slug      = sanitize_title( (string) $slug_param );
			$update_data['slug'] = $repo->generate_unique_slug( $requested_slug, $media_id );
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

		// JSON body inspection — used by the tags/categories block further
		// down to distinguish "key omitted" (leave alone) from "key sent as
		// null/empty" (intentional clear).
		$json_params = $request->get_json_params();
		$json_params = is_array( $json_params ) ? $json_params : array();

		// Per-media download flag — honor regardless of body encoding so
		// JSON apiFetch, form-encoded clients, and internal REST calls all
		// land the change. Read via get_param (covers all sources) and
		// distinguish "absent" (null) from "intentional false" (boolean
		// false) so a `false` value correctly stores '0'. Only honored
		// when the global mvs_allow_downloads toggle is also on at read
		// time. Stored as '1' / '0' string so it round-trips cleanly.
		$allow_download_param = $request->get_param( 'allow_download' );
		if ( null !== $allow_download_param ) {
			$update_data['allow_download'] = rest_sanitize_boolean( $allow_download_param ) ? '1' : '0';
		}

		// Write all index/meta changes in one call.
		if ( ! empty( $update_data ) ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set_many( $media_id, $update_data );
		}

		if ( array_key_exists( 'tags', $json_params ) ) {
			$tags = $request->get_param( 'tags' );
			if ( is_array( $tags ) ) {
				$sanitized_tags = array_map( 'sanitize_text_field', $tags );
				wp_set_object_terms( $media_id, $sanitized_tags, 'mvs_tag' );
				\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'tags', wp_json_encode( array_values( $sanitized_tags ) ) );
			}
		}

		if ( array_key_exists( 'categories', $json_params ) ) {
			$categories = $request->get_param( 'categories' );
			if ( is_array( $categories ) ) {
				wp_set_object_terms( $media_id, array_map( 'absint', $categories ), 'mvs_category' );
				$cat_terms = get_the_terms( $media_id, 'mvs_category' );
				if ( $cat_terms && ! is_wp_error( $cat_terms ) ) {
					\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'category', wp_json_encode( array_values( wp_list_pluck( $cat_terms, 'name' ) ) ) );
				} else {
					// Empty array sent → user cleared categories, so clear the cached list too.
					\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'category', wp_json_encode( array() ) );
				}
			}
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

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
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
		// PHP 8.5 deprecated finfo_close — handle is GC'd at end of scope.

		// PDF/document uploads are not supported. Mirror the hard guard in
		// UploadService::handle() so a member can't slip a PDF in via the
		// replace endpoint even if a legacy mvs_allowed_file_types option still
		// lists application/pdf (audit 2026-06-04, #9962125462 — caught by the
		// double-verifier as a replace_file bypass of the upload guard).
		if ( 'application/pdf' === $mime || 'document' === $upload_service->get_media_type_public( $mime ) ) {
			return new \WP_Error( 'mvs_document_not_supported', __( 'PDF uploads are not supported.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $mime, $allowed, true ) ) {
			return new \WP_Error( 'mvs_invalid_type', __( 'This file type is not allowed.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		// Store new file.
		$storage  = Plugin::container()->get( 'storage' );
		$driver   = $storage->get_driver();
		$dest_sub = gmdate( 'Y/m' );
		// Route through FilenameStrategy exactly like the primary upload path
		// (UploadService::handle) so the configured strategy — hashed by
		// default since 1.6.0 — applies to replacements too. Before 1.6.0 this
		// called sanitize_file_name() directly, so replacing a file leaked the
		// original filename in the URL even on hashed sites (audit 2026-06-04,
		// #9962530792).
		$filename_pick = \WPMediaVerse\Services\FilenameStrategy::pick(
			(string) $file['name'],
			wp_upload_dir()['basedir'] . '/wpmediaverse/' . $dest_sub,
			get_current_user_id()
		);
		$filename  = $filename_pick['stored'];
		$dest_path = $dest_sub . '/' . $filename;

		if ( ! $driver->store( $file['tmp_name'], $dest_path ) ) {
			return new \WP_Error( 'mvs_storage_failed', __( 'Failed to store the file.', 'wpmediaverse' ), array( 'status' => 500 ) );
		}

		// Delete old file.
		$old_path = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_path' );
		if ( $old_path ) {
			$driver->delete( $old_path );
		}

		// Update media index with new file data.
		$media_type = explode( '/', $mime )[0]; // image, video, audio.
		if ( ! in_array( $media_type, array( 'image', 'video', 'audio' ), true ) ) {
			$media_type = 'document';
		}

		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set_many(
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

		// Keep original_filename in sync with the replacement: store the new
		// display name when the strategy hashed the on-disk basename (so
		// downloads + Content-Disposition stay correct), and clear any stale
		// value otherwise so a hashed-then-replaced-with-readable file doesn't
		// keep the old display name. Mirrors UploadService::handle.
		if ( 'hashed' === $filename_pick['strategy'] && '' !== $filename_pick['original'] ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set(
				$media_id,
				'original_filename',
				$filename_pick['original']
			);
		} else {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete( $media_id, 'original_filename' );
		}

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

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$author_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $media_id );

		// Remove from custom tables. delete_all() -> delete_cascade() fires
		// `mvs_media_files_orphaned` first, so StorageCleanupService reclaims the
		// original AND every variant (thumbnails, WebP/AVIF, posters) from local
		// + cloud asynchronously. No inline single-driver delete here.
		global $wpdb;
		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete_all( $media_id );
		$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Remove taxonomy relationships.
		wp_delete_object_term_relationships( $media_id, array( 'mvs_tag', 'mvs_category' ) );

		// mvs_media_deleted is now fired inside MediaRepository::delete_cascade()
		// (the single funnel for every delete path), so it is NOT fired here —
		// doing so would double-fire it on the REST path (audit 2026-06-04).

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

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
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
	 * Record a download event for a media item and increment the downloads
	 * stat. Mirrors `record_view` but writes `event_type='download'` to
	 * `mvs_media_views` and increments the `downloads` column on
	 * `mvs_media_stats`. Public callers (anonymous viewers) can record
	 * downloads — same surface as views.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function record_download( $request ) {
		// Global toggle — when admin disables downloads, reject the event
		// regardless of media-level privacy. Defense-in-depth: the lightbox
		// button is also hidden, but a savvy caller could still hit this
		// endpoint directly.
		if ( ! (bool) get_option( 'mvs_allow_downloads', true ) ) {
			return new WP_Error( 'mvs_downloads_disabled', __( 'Downloads are disabled on this site.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}

		// Rate limit: 30 downloads/min per user/IP — half the view rate
		// since downloads are higher-cost operations.
		$rate_check = RateLimiter::check( 'record_download', 30, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id = (int) $request->get_param( 'id' );

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Privacy gate: callers without view access can't record a download.
		$user_id = get_current_user_id();
		if ( ! $this->privacy->can_view( $media_id, $user_id ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You do not have access to this media item.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}

		// Per-media opt-out: owner can disable downloads on a single item
		// even when the global toggle is on. Absent meta = default allow.
		$per_media = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'allow_download' );
		if ( '0' === $per_media ) {
			return new WP_Error( 'mvs_download_blocked', __( 'The owner has disabled downloads for this media.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}

		global $wpdb;

		$ip_hash = hash( 'sha256', self::get_client_ip() . wp_salt() );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_views',
			array(
				'media_id'   => $media_id,
				'user_id'    => $user_id ? $user_id : null,
				'ip_hash'    => $ip_hash,
				'event_type' => 'download',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		// Ensure stats row exists, then increment downloads.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}mvs_media_stats (media_id, downloads, updated_at) VALUES (%d, 1, %s)
				ON DUPLICATE KEY UPDATE downloads = downloads + 1, updated_at = VALUES(updated_at)",
				$media_id,
				current_time( 'mysql', true )
			)
		);

		return rest_ensure_response( array( 'recorded' => true ) );
	}

	/**
	 * Record a share event for a media item and increment the shares stat.
	 * Mirrors record_download but writes shares instead. Same privacy gate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function record_share( $request ) {
		// Rate limit: 30 shares/min per user/IP (same as downloads).
		$rate_check = RateLimiter::check( 'record_share', 30, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id = (int) $request->get_param( 'id' );

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$user_id = get_current_user_id();
		if ( ! $this->privacy->can_view( $media_id, $user_id ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You do not have access to this media item.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}

		global $wpdb;

		// Ensure stats row exists, then increment shares.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}mvs_media_stats (media_id, shares, updated_at) VALUES (%d, 1, %s)
				ON DUPLICATE KEY UPDATE shares = shares + 1, updated_at = VALUES(updated_at)",
				$media_id,
				current_time( 'mysql', true )
			)
		);

		/**
		 * Fires when a media item is shared.
		 *
		 * Listeners include `CacheService::on_media_stat_change` (drops the
		 * cached stats row so the next read sees the incremented count).
		 *
		 * @since 1.2.0
		 *
		 * @param int $media_id Media post ID that was shared.
		 * @param int $user_id  User who shared (0 for anonymous).
		 */
		do_action( 'mvs_share_recorded', $media_id, $user_id );

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

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$user_id  = get_current_user_id();
		$can_view = $this->privacy->can_view( $media_id, $user_id );
		$is_owner = $user_id && \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $media_id ) === $user_id;

		// Only expose privacy details to users who can view or own the media.
		if ( $can_view || $is_owner ) {
			$privacy_meta = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'privacy' );
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

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
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

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $media_id ) === $user_id && current_user_can( 'edit_mvs_medias' ) ) {
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

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $media_id ) === $user_id && current_user_can( 'delete_mvs_medias' ) ) {
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
		$all      = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_all( $media_id );

		if ( empty( $all ) ) {
			return null;
		}

		// Always-signed via MediaRepository — Phase 0a item 5 consolidated
		// signing into the data layer; this controller is now a thin emitter.
		$file_url      = ! empty( $all['file_url'] )
			? (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_url' )
			: '';
		$thumbnail_url = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'thumb_large' );
		if ( '' === $thumbnail_url ) {
			$thumbnail_url = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_thumb_url( $media_id, 'large' );
		}

		// Lightbox URL respects the admin-chosen image source.
		$lightbox_url      = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_lightbox_url( $media_id, (string) $all['file_url'] );
		$lightbox_webp_url = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_lightbox_webp_url( $media_id, $lightbox_url );
		$lightbox_avif_url = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_lightbox_avif_url( $media_id, $lightbox_url );

		$media_type_value = ! empty( $all['media_type'] ) ? $all['media_type'] : '';
		$privacy_value    = ! empty( $all['privacy'] ) ? $all['privacy'] : 'public';
		$moderation_value = ! empty( $all['moderation_status'] ) ? $all['moderation_status'] : 'approved';

		$author_id_raw = ! empty( $all['post_author'] ) ? (int) $all['post_author'] : 0;
		$viewer_id     = get_current_user_id();
		$can_edit      = $viewer_id > 0
			&& ( $viewer_id === $author_id_raw || user_can( $viewer_id, 'manage_options' ) );

		// allow_download: per-media flag. Absent meta = default true.
		// '0' string = explicit opt-out by the owner. The lightbox button
		// honors both this AND the global mvs_allow_downloads setting.
		$allow_download_raw = isset( $all['allow_download'] ) ? (string) $all['allow_download'] : '';
		$allow_download     = ( '0' !== $allow_download_raw );

		$data = array(
			'id'                => $media_id,
			'title'             => ! empty( $all['title'] ) ? $all['title'] : '',
			'description'       => ! empty( $all['description'] ) ? $all['description'] : '',
			'author'            => $author_id_raw,
			'date'              => ! empty( $all['created_at'] ) ? $all['created_at'] : '',
			'link'              => \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $media_id ),
			'file_url'          => $file_url,
			'file_size'         => ! empty( $all['file_size'] ) ? (int) $all['file_size'] : 0,
			'file_type'         => ! empty( $all['file_type'] ) ? $all['file_type'] : '',
			'media_type'        => $media_type_value,
			'privacy'           => $privacy_value,
			'allow_download'    => $allow_download,
			// Display filename — original user-provided name when the upload
			// strategy hashed the on-disk basename (1.2.1+). Falls back to the
			// stored file_path basename for older uploads / sanitized strategy.
			'original_filename' => ! empty( $all['original_filename'] )
				? (string) $all['original_filename']
				: ( ! empty( $all['file_path'] ) ? basename( (string) $all['file_path'] ) : '' ),
			'moderation_status' => $moderation_value,
			'tags'              => self::parse_meta_list( $all['tags'] ?? '' ),
			'categories'        => self::parse_meta_list( $all['category'] ?? '' ),
			'thumbnail_url'     => $thumbnail_url,
			'lightbox_url'      => $lightbox_url,
			'lightbox_webp_url' => $lightbox_webp_url,
			'lightbox_avif_url' => $lightbox_avif_url,
			'can_edit'          => $can_edit,
		);

		// Add author data for lightbox sidebar.
		// `name` is the plain display name — safe in aria-label / alt contexts.
		// `badge_html` carries optional trusted decoration (verified-member
		// badge, VIP/role badges, anything any plugin registers via the
		// `mvs_user_badge_html` filter) rendered in a sibling node via
		// `data-wp-html`. Keeping the two fields separate means the plain
		// value stays escapable for attribute use; the lightbox path (REST →
		// `data-wp-text`) had been bypassing the `mvs_user_display_name`
		// filter chain so the badge never reached the popup — see Basecamp
		// card #9872031539, follow-up 2026-05-11.
		// `get_the_author_meta( 'display_name' )` flows through
		// `bp_core_get_user_displayname` — which bp-verified-member hooks
		// to append its badge `<span>`. The legacy `$data['author_name']`
		// field keeps that combined string (PHP consumers rely on it). For
		// the lightbox payload we strip tags so `author_data.name` is plain
		// text safe to bind via `data-wp-text`, and surface decorations
		// separately through `author_data.badge_html`.
		$author_id             = $data['author'];
		$author_name           = get_the_author_meta( 'display_name', $author_id );
		$author_name_plain     = wp_strip_all_tags( (string) $author_name );
		$author_avatar         = get_avatar_url( $author_id, array( 'size' => 64 ) );
		$author_url            = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( $author_id );

		/**
		 * Accumulated badge HTML for a user. Listeners append their own
		 * decoration markup (verified badge, VIP badge, role badge, etc.)
		 * to the running string and return it. Returned HTML is rendered
		 * as a sibling to the display name in the media lightbox via
		 * `data-wp-html`, so it must be trusted markup — never echo
		 * untrusted input through this filter.
		 *
		 * @since 1.2.2
		 *
		 * @param string $badges_html Accumulated badge HTML (initially '').
		 * @param int    $user_id     User the badges are being collected for.
		 */
		$author_badge_html     = (string) apply_filters( 'mvs_user_badge_html', '', (int) $author_id );
		$data['author_name']   = $author_name;
		$data['author_avatar'] = $author_avatar;
		$data['author_url']    = $author_url;
		$data['author_data']   = array(
			'name'        => $author_name_plain,
			'badge_html'  => $author_badge_html,
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

	/**
	 * Build a BOOLEAN MODE search term for MATCH/AGAINST. Returns null when
	 * the input is too short or every token gets dropped (fall back to LIKE).
	 *
	 * Splits on whitespace, drops tokens shorter than the InnoDB minimum
	 * token length (3 chars by default), strips MySQL boolean-mode operators
	 * (`+`, `-`, `*`, `(`, `)`, `~`, `<`, `>`, `"`, `@`, NUL) and appends a
	 * trailing `*` for prefix matching ("auto" matches "automotive"). Each
	 * token also gets a leading `+` so the user effectively sees AND-search.
	 *
	 * @since 1.2.1
	 *
	 * @param string $search Raw search input.
	 * @return string|null   BOOLEAN MODE term or null to indicate fallback.
	 */
	private static function build_fulltext_search_term( string $search ): ?string {
		$cleaned = preg_replace( '/[+\-*()~<>"@\x00]/', ' ', $search );
		$tokens  = preg_split( '/\s+/u', (string) $cleaned, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $tokens ) {
			return null;
		}

		$kept = array();
		foreach ( $tokens as $token ) {
			if ( mb_strlen( $token, 'UTF-8' ) < 3 ) {
				continue;
			}
			$kept[] = '+' . $token . '*';
		}
		if ( ! $kept ) {
			return null;
		}

		return implode( ' ', $kept );
	}

	/**
	 * Detect whether the FULLTEXT search index exists. Cached for the
	 * request lifetime via a static — schema doesn't change between
	 * REST calls, so a single SHOW INDEX per request is plenty.
	 *
	 * @since 1.2.1
	 */
	private static function has_fulltext_search_index(): bool {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_media_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$exists = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'media_search_ft'" );
		$cached = (bool) $exists;
		return $cached;
	}
}
