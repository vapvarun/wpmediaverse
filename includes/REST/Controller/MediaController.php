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
					// Declare every editable field so OPTIONS documents the real
					// contract (the planned mobile app + BuddyNext rely on the
					// schema) and REST validates types. None are required:
					// update_item treats an omitted key as "leave unchanged" and
					// still sanitizes each value itself — these are the schema
					// layer, not a replacement for that. `privacy` is left as a
					// free string (no enum) because the privacy set is extensible
					// via the mvs_privacy_can_view filter; a hard enum would
					// reject Pro/extension-added levels.
					'args'                => array(
						'id'             => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'title'          => array(
							'type'        => 'string',
							'description' => __( 'Media title.', 'wpmediaverse' ),
						),
						'description'    => array(
							'type'        => 'string',
							'description' => __( 'Media description (post HTML allowed).', 'wpmediaverse' ),
						),
						'slug'           => array(
							'type'        => 'string',
							'description' => __( 'Explicit URL slug. Omit to keep the current slug.', 'wpmediaverse' ),
						),
						'privacy'        => array(
							'type'        => 'string',
							'description' => __( 'Privacy level (public, members, loggedin, friends, group, private, custom, or an extension-added level).', 'wpmediaverse' ),
						),
						'allow_download' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether viewers may download the original file.', 'wpmediaverse' ),
						),
						'tags'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Tag names. Send [] to clear.', 'wpmediaverse' ),
						),
						'categories'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Category term IDs. Send [] to clear.', 'wpmediaverse' ),
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

		// Batch fetch by explicit IDs — lets the app resolve an ID list (album
		// items, etc.) into full canonical media objects in ONE call instead of
		// N. Privacy-gated per item, request order preserved, capped at 100.
		$include = $request->get_param( 'include' );
		if ( ! empty( $include ) ) {
			$ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) $include ) ) ) ), 0, 100 );
			if ( empty( $ids ) ) {
				return rest_ensure_response( array() );
			}
			$viewer = get_current_user_id();
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->prefetch( $ids );
			\WPMediaVerse\Core\Plugin::container()->get( 'access_rules' )->prefetch_active_rules( $ids );
			self::prime_viewer_state( $ids, $viewer );
			$items = array();
			foreach ( $ids as $mid ) {
				if ( ! $this->privacy->can_view( $mid, $viewer ) ) {
					continue;
				}
				$item = $this->prepare_item_for_response( $mid, $request );
				if ( $item ) {
					$items[] = $item;
				}
			}
			$response = rest_ensure_response( $items );
			$response->header( 'X-WP-Total', (string) count( $items ) );
			return $response;
		}

		$per_page = \WPMediaVerse\REST\Pagination::resolve_per_page( $request );
		$page     = \WPMediaVerse\REST\Pagination::resolve_page( $request );
		$offset   = ( $page - 1 ) * $per_page;
		$user_id  = get_current_user_id();

		$media_type = $request->get_param( 'media_type' );
		$author     = $request->get_param( 'author' );

		// media_type != '' excludes the privacy-only stub rows albums/collections
		// leave in mvs_media_index (media_type empty — see PrivacyService). Without
		// it the /media feed (and the mobile app that reads it) returned those
		// stubs as empty items. Same fix as the media-grid block. Basecamp 10074442944.
		$where  = array( 'moderation_status = %s', "media_type != ''" );
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

		// Interest filter (activation cold-start) — `interests=auto` narrows the
		// feed to the viewer's chosen interest categories so a new user's first
		// scroll is relevant, not just globally popular. Silently no-ops for
		// anonymous viewers or those with no saved interests (never empty).
		if ( 'auto' === $request->get_param( 'interests' ) && $user_id ) {
			$interest_ids = get_user_meta( $user_id, 'mvs_interests', true );
			$interest_ids = is_array( $interest_ids ) ? array_filter( array_map( 'intval', $interest_ids ) ) : array();
			if ( $interest_ids ) {
				$ti_ph  = implode( ',', array_fill( 0, count( $interest_ids ), '%d' ) );
				$tt_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'mvs_category' AND term_id IN ({$ti_ph})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						...array_values( $interest_ids )
					)
				);
				$tt_ids = array_filter( array_map( 'intval', (array) $tt_ids ) );
				if ( $tt_ids ) {
					$tt_in          = implode( ',', $tt_ids ); // Integers from the DB — safe to inline.
					$join_clauses[] = "INNER JOIN {$wpdb->term_relationships} mvs_ir ON mvs_ir.object_id = i.media_id AND mvs_ir.term_taxonomy_id IN ($tt_in)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
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

		// Scope filter. The feed blocks (Instagram/Dribbble/Flickr/Pinterest)
		// offer Public / Followers / My uploads; only 'public' was implemented
		// here, so 'followers' and 'self' silently did nothing on every layout.
		// self/followers need a logged-in viewer; anonymous falls through to the
		// public-only privacy gate above. Basecamp 10068992480.
		$scope = $request->get_param( 'scope' );
		if ( 'public' === $scope ) {
			$where[]  = 'privacy = %s';
			$params[] = 'public';
		} elseif ( 'self' === $scope && $user_id ) {
			$where[]  = 'post_author = %d';
			$params[] = $user_id;
		} elseif ( 'followers' === $scope && $user_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where[]  = "post_author IN ( SELECT following_id FROM {$wpdb->prefix}mvs_follows WHERE follower_id = %d AND status = 'active' )";
			$params[] = $user_id;
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
			// `i.media_id` (not bare `media_id`): the trending/popular data query
			// LEFT JOINs mvs_media_stats, which also has a media_id column, so an
			// unqualified ref is ambiguous and the data query silently returns 0
			// rows while the (stats-join-free) COUNT query still returns the total.
			$where[] = "(i.media_id NOT IN (
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
			$where[]  = "i.media_id IN (SELECT media_id FROM {$wpdb->prefix}mvs_media_meta WHERE meta_key = 'media_group' AND meta_value = %s)";
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

		// Batch-load the media rows + meta and the access-rules presence flag
		// for the whole page BEFORE the per-item prepare loop below, mirroring
		// the template grids (explore.php/album.php/collection.php) since
		// 1.7.0. Without this, prepare_item_for_response() -> get_all() and
		// -> sign_file_url() -> can_view() -> has_active_rules() each fire one
		// query per item (this was the REST-path gap; templates were fixed).
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$repo->prefetch( $int_ids );
		\WPMediaVerse\Core\Plugin::container()->get( 'access_rules' )->prefetch_active_rules( $int_ids );

		// Batch-load viewer favorite/reaction state for the whole page (2 queries),
		// so the per-item prepare below stays query-bounded at any list size.
		self::prime_viewer_state( $int_ids, get_current_user_id() );

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

		$int_group_ids = array_map( 'intval', $group_media_ids );
		$repo          = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$repo->prefetch( $int_group_ids );
		\WPMediaVerse\Core\Plugin::container()->get( 'access_rules' )->prefetch_active_rules( $int_group_ids );

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
				$category_ids = array_values( array_unique( array_filter( array_map( 'absint', $categories ) ) ) );

				$set_result = wp_set_object_terms( $media_id, $category_ids, 'mvs_category' );
				if ( is_wp_error( $set_result ) ) {
					// Surface the failure instead of returning a silent HTTP 200
					// that didn't persist (the "saved but not applied" contract bug).
					return new WP_Error(
						'mvs_categories_not_saved',
						__( 'Could not save categories for this media item.', 'wpmediaverse' ),
						array( 'status' => 500 )
					);
				}

				// Derive the cached name list straight from the IDs we just
				// wrote — NOT from a get_the_terms() re-read. On sites with a
				// persistent object cache the relationship cache can momentarily
				// miss right after wp_set_object_terms(), which previously sent
				// this code down a destructive else-branch that wiped the saved
				// category list to [] even though the taxonomy assignment
				// succeeded. That is exactly the reported bug: categories
				// vanished on read-back for a subset of items (HTTP 200, not
				// applied). Resolving names from the submitted IDs keeps the
				// cached meta in lockstep with the relationship table for every
				// item, and an empty array still correctly clears the list.
				$category_names = array();
				foreach ( $category_ids as $term_id ) {
					$term = get_term( $term_id, 'mvs_category' );
					if ( $term instanceof \WP_Term ) {
						$category_names[] = $term->name;
					}
				}

				\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'category', wp_json_encode( array_values( $category_names ) ) );
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
		$filename      = $filename_pick['stored'];
		$dest_path     = $dest_sub . '/' . $filename;

		// Hash the member's SOURCE bytes before anything rewrites the file in
		// place. Mirrors UploadService::handle(), where dup detection matches the
		// upload as the user supplied it rather than the post-encode bytes.
		$source_hash = (string) hash_file( 'sha256', $file['tmp_name'] );

		// A replacement is new member bytes entering the library, so it stamps —
		// the same rule as a fresh upload. This MUST run before store() below:
		// store() persists the temp file, and the WebP/AVIF siblings are cut from
		// that same temp file further down. Stamping afterwards would ship a
		// clean original alongside watermarked siblings. Basecamp 10073917553.
		Plugin::container()->get( 'watermark' )->stamp_new_upload(
			$file['tmp_name'],
			$mime,
			get_current_user_id()
		);

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
				'file_hash'  => $source_hash,
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

		// Clear stale thumbnail, variant, and media-metadata rows so the
		// pipeline below writes fresh values rather than leaving old paths
		// (pointing at now-deleted files) mixed with new ones.
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		foreach ( array( 'large', 'medium', 'thumb' ) as $size ) {
			foreach ( array( '', '_path', '_webp', '_webp_path', '_avif', '_avif_path' ) as $suffix ) {
				$repo->delete( $media_id, 'thumb_' . $size . $suffix );
			}
		}
		foreach ( array(
			'original_webp',
			'original_webp_path',
			'original_avif',
			'original_avif_path',
			\WPMediaVerse\Services\ImageOptimizationService::META_OPTIMIZED_AT,
			\WPMediaVerse\Services\ImageOptimizationService::META_BYTES_BEFORE,
			\WPMediaVerse\Services\ImageOptimizationService::META_BYTES_AFTER,
			'width',
			'height',
			'duration',
			'bitrate',
			'codec',
			'artist',
			'album_name',
		) as $meta_key ) {
			$repo->delete( $media_id, $meta_key );
		}

		// Run image optimization + WebP/AVIF emit on the temp file BEFORE
		// process_stored_file() so cloud drivers receive the optimized bytes
		// (mirrors the order in UploadService::handle). Only applies to images.
		if ( 0 === strpos( $mime, 'image/' ) ) {
			$image_opt   = Plugin::container()->get( 'image_optimization' );
			$user_id     = get_current_user_id();
			$opt_context = array(
				'media_id' => $media_id,
				'variant'  => 'original',
				'mime'     => $mime,
				'user_id'  => $user_id,
			);
			$image_opt->optimize( $file['tmp_name'], $opt_context );
			clearstatcache( true, $file['tmp_name'] );
			$opt_size = (int) filesize( $file['tmp_name'] );
			if ( $opt_size > 0 ) {
				// Update the already-persisted file_size to the post-optimization value.
				$repo->set( $media_id, 'file_size', $opt_size );
			}

			// Emit WebP / AVIF siblings from the optimized temp file and push
			// them to the same driver used for the replacement file.
			$webp_local = $image_opt->emit_webp_sibling( $file['tmp_name'], $opt_context );
			$avif_local = $image_opt->emit_avif_sibling( $file['tmp_name'], $opt_context );
			$dest_dir   = dirname( $dest_path );

			if ( null !== $webp_local && file_exists( $webp_local ) ) {
				$webp_dest = $dest_dir . '/' . pathinfo( $dest_path, PATHINFO_FILENAME ) . '.webp';
				if ( $driver->store( $webp_local, $webp_dest ) ) {
					$repo->set( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_WEBP, (string) $driver->url( $webp_dest ) );
					$repo->set( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_WEBP_PATH, $webp_dest );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $webp_local );
			}
			if ( null !== $avif_local && file_exists( $avif_local ) ) {
				$avif_dest = $dest_dir . '/' . pathinfo( $dest_path, PATHINFO_FILENAME ) . '.avif';
				if ( $driver->store( $avif_local, $avif_dest ) ) {
					$repo->set( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_AVIF, (string) $driver->url( $avif_dest ) );
					$repo->set( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_AVIF_PATH, $avif_dest );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $avif_local );
			}

			$repo->set( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_OPTIMIZED_AT, time() );
		}

		// Seed a local working copy from the still-available temp file so
		// process_stored_file() can open the optimized bytes via WP_Image_Editor
		// without having to re-download from the cloud driver.
		$upload_dir   = wp_upload_dir();
		$local_source = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/' . $dest_path;
		if ( ! file_exists( $local_source ) && file_exists( $file['tmp_name'] ) ) {
			wp_mkdir_p( dirname( $local_source ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			@copy( $file['tmp_name'], $local_source );
		}

		// Run the shared post-store pipeline: extract dimensions / duration /
		// codec and regenerate thumbnails (+ video poster / audio cover art).
		$upload_service = Plugin::container()->get( 'upload' );
		$upload_service->process_stored_file( $media_id, $dest_path, $media_type, $mime );

		$file_data = array(
			'file_url'   => $driver->url( $dest_path ),
			'file_path'  => $dest_path,
			'file_type'  => $mime,
			'media_type' => $media_type,
		);

		/**
		 * Fires after a file replacement has been fully processed.
		 *
		 * Use this hook (not mvs_media_uploaded) for replace-specific reactions
		 * such as re-queuing transcoding or re-generating captions. Deliberately
		 * NOT mvs_media_uploaded to avoid double-counting Pro quota (the item's
		 * count was already incremented on the original upload and must not be
		 * incremented again; only a genuinely new upload should do that).
		 *
		 * @since 1.7.1
		 *
		 * @param int    $media_id  The media ID whose file was replaced.
		 * @param array  $file_data File metadata. Keys: file_url, file_path,
		 *                          file_type, media_type.
		 * @param int    $user_id   The user who performed the replacement.
		 * @param string $media_type Resolved media type ('image' | 'video' | 'audio' | 'document').
		 */
		do_action( 'mvs_media_replaced', $media_id, $file_data, get_current_user_id(), $media_type );

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

		// delete_all() -> delete_cascade() fires `mvs_media_files_orphaned` first, so
		// StorageCleanupService reclaims the original AND every variant (thumbnails,
		// WebP/AVIF, posters) from local + cloud asynchronously, and also clears
		// mvs_tag/mvs_category term relationships. No inline cleanup here.
		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete_all( $media_id );

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
	 * Per-request prefill of the viewer's favorite state, media_id => true.
	 *
	 * @var array<int,bool>
	 */
	private static array $viewer_fav_set = array();

	/**
	 * Per-request prefill of the viewer's reactions, media_id => reaction_type.
	 *
	 * @var array<int,string>
	 */
	private static array $viewer_reaction_map = array();

	/**
	 * Viewer ID the prefill maps were primed for, or null when not primed.
	 *
	 * @var int|null
	 */
	private static ?int $viewer_state_primed_for = null;

	/**
	 * Batch-load the current viewer's favorite + reaction state for a page of
	 * media so prepare_item_for_response() resolves is_favorited / viewer_reaction
	 * from a set instead of one query per tile (big-site: 2 queries/page, not 2N).
	 *
	 * Call once, before looping prepare_item_for_response() over a list. The
	 * single-item path falls back to a per-item lookup when not primed.
	 *
	 * @since 1.9.0
	 *
	 * @param int[] $media_ids Media IDs on this page.
	 * @param int   $viewer_id Current user ID (0 = anonymous; clears the maps).
	 */
	public static function prime_viewer_state( array $media_ids, int $viewer_id ): void {
		self::$viewer_state_primed_for = $viewer_id;
		self::$viewer_fav_set          = array();
		self::$viewer_reaction_map     = array();

		if ( $viewer_id <= 0 ) {
			return;
		}

		self::$viewer_fav_set      = Plugin::container()->get( 'favorites' )->get_favorited_set( $viewer_id, $media_ids );
		self::$viewer_reaction_map = Plugin::container()->get( 'reactions' )->get_user_reactions_map( $viewer_id, $media_ids );
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
		$file_url = ! empty( $all['file_url'] )
			? (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_url' )
			: '';
		// Grid/feed tile: use the admin-configured size (default medium), not a
		// hardcoded 'large'. Lightbox below keeps the original. (1.7.0)
		$grid_size     = \WPMediaVerse\Core\SettingsHelper::get_grid_thumb_size_key();
		$thumbnail_url = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'thumb_' . $grid_size );
		if ( '' === $thumbnail_url ) {
			$thumbnail_url = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_thumb_url( $media_id, $grid_size );
		}

		// Lightbox URL respects the admin-chosen image source.
		$lightbox_url      = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_lightbox_url( $media_id, (string) $all['file_url'] );
		$lightbox_webp_url = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_lightbox_webp_url( $media_id, $lightbox_url );
		$lightbox_avif_url = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_lightbox_avif_url( $media_id, $lightbox_url );

		$media_type_value = ! empty( $all['media_type'] ) ? $all['media_type'] : '';

		// Card #1: a video with no generated poster must not ship an empty
		// thumbnail_url — it renders as a blank/black tile in grids. Fall back to
		// the bundled default video poster, the same asset the server-rendered
		// grid uses via media_thumbnail(). (1.7.0)
		if ( '' === $thumbnail_url && 'video' === $media_type_value ) {
			$thumbnail_url = \WPMediaVerse\Core\TemplateHelpers::default_video_poster_url();
		}

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

		// Viewer-relative interaction state (1.9.0, additive). Resolved from the
		// per-request prefill when a list endpoint primed it (2 queries/page),
		// else a single lookup for standalone single-item reads. false/null for
		// anonymous viewers.
		$is_favorited    = false;
		$viewer_reaction = null;
		if ( $viewer_id > 0 ) {
			if ( self::$viewer_state_primed_for === $viewer_id ) {
				$is_favorited    = isset( self::$viewer_fav_set[ $media_id ] );
				$viewer_reaction = self::$viewer_reaction_map[ $media_id ] ?? null;
			} else {
				$is_favorited    = \WPMediaVerse\Core\Plugin::container()->get( 'favorites' )->is_favorited( $media_id, $viewer_id );
				$viewer_reaction = \WPMediaVerse\Core\Plugin::container()->get( 'reactions' )->get_user_reaction( $media_id, $viewer_id );
			}
		}

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
			'is_favorited'      => $is_favorited,
			'viewer_reaction'   => $viewer_reaction,
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
		$author_id         = $data['author'];
		$author_name       = get_the_author_meta( 'display_name', $author_id );
		$author_name_plain = wp_strip_all_tags( (string) $author_name );
		$author_avatar     = get_avatar_url( $author_id, array( 'size' => 64 ) );
		$author_url        = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( $author_id );

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
		// The author's numeric id. A consumer that renders its own media viewer (BuddyNext's
		// lightbox) needs it to offer "Block this member" — it has a name, an avatar and a
		// profile URL, none of which identify the user to a block endpoint. Without it the only
		// abuse control reachable from a third-party viewer is Report.
		$data['author_id']     = $author_id;
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
			'include'      => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'default'     => array(),
				'description' => __( 'Resolve specific media IDs to full objects in one call (e.g. album items). Max 100, privacy-gated, order preserved.', 'wpmediaverse' ),
			),
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
