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
use WPMediaVerse\Services\AlbumService;
use WPMediaVerse\Services\PrivacyService;

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
	 * Album service instance.
	 *
	 * @var AlbumService
	 */
	private $albums;

	/**
	 * Privacy service instance.
	 *
	 * @var PrivacyService
	 */
	private $privacy;

	/**
	 * Constructor.
	 *
	 * @param AlbumService   $albums  Album service.
	 * @param PrivacyService $privacy Privacy service.
	 */
	public function __construct( AlbumService $albums, PrivacyService $privacy ) {
		$this->albums  = $albums;
		$this->privacy = $privacy;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// PUBLIC_OK on both __return_true callbacks below (GET /albums and
		// GET /albums/{id}): album reads enforce privacy at the SQL/service
		// layer. Private albums 404 for non-owners (no info disclosure). The
		// `__return_true` is correct because the gate lives in the query.
		// Triaged 2026-05-01 (Item 5). All write routes (POST/PUT/DELETE,
		// reorder, items add/remove) carry proper permission_callbacks.
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
					'args'                => array(
						'id'             => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'cover_media_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'minimum'           => 0,
						),
					),
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

		// GET + POST /albums/{id}/items.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/items',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_album_items' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id'       => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
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
				),
			)
		);

		// DELETE /albums/{id}/items/{media_id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/items/(?P<media_id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'remove_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => array(
					'id'       => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'media_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// PUT /albums/{id}/cover.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/cover',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'set_cover' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => array(
					'id'       => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'media_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
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
		$per_page = \WPMediaVerse\REST\Pagination::resolve_per_page( $request );
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

		$album_type = $request->get_param( 'album_type' );
		if ( $album_type ) {
			$args['meta_key']   = '_mvs_album_type'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = sanitize_text_field( $album_type ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		$user_id = get_current_user_id();

		// Enforce privacy at the SQL level so the pagination totals
		// (X-WP-Total / X-WP-TotalPages) reflect only the albums this viewer may
		// see. The previous per-item can_view() filter ran AFTER the query, so
		// found_posts over-reported and paginated visitors hit empty/short pages
		// ("albums not visible to visitors", Basecamp 10071400189).
		//
		// Album privacy lives in post meta (AlbumService::PRIVACY_META). LEFT JOIN it
		// and express the same rule explore_privacy_clause() applies to media:
		// public, or members-level to a logged-in viewer, or the viewer's own.
		// private / friends / group / custom are owner-only in the list, matching how
		// media explore treats them.
		//
		// Before 2.3.3 this joined mvs_media_index on wp_posts.ID, because album
		// privacy was stored there at media_id = <album post ID>. That is the defect
		// this release removes: the album ID collided with a real media_id, so the
		// clause could filter an album by an unrelated PHOTO's privacy. Migrator v26
		// writes _mvs_privacy for every existing album before this path can run
		// (Plugin::init() runs migrations on every load), so the meta is always
		// present; a missing value still falls back to public, which is the documented
		// default and matches the previous behaviour for albums with no index row.
		//
		// THIS CLAUSE IS THE ONLY PRIVACY GATE ON THIS ENDPOINT. The per-item
		// can_view() re-check was removed deliberately (see below) — do not weaken it.
		global $wpdb;
		$privacy_meta = AlbumService::PRIVACY_META;

		if ( $user_id && user_can( $user_id, 'moderate_mvs_media' ) ) {
			$priv_fragment = '1 = 1';
		} elseif ( $user_id ) {
			$priv_fragment = $wpdb->prepare(
				"( mvpriv.meta_value IS NULL OR mvpriv.meta_value IN ( 'public', 'members', 'loggedin' ) OR {$wpdb->posts}.post_author = %d )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id
			);
		} else {
			$priv_fragment = "( mvpriv.meta_value IS NULL OR mvpriv.meta_value = 'public' )";
		}

		$join_cb  = static function ( $join ) use ( $wpdb, $privacy_meta ) {
			return $join . $wpdb->prepare(
				" LEFT JOIN {$wpdb->postmeta} mvpriv ON mvpriv.post_id = {$wpdb->posts}.ID AND mvpriv.meta_key = %s ", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$privacy_meta
			);
		};
		$where_cb = static function ( $where ) use ( $priv_fragment ) {
			return $where . " AND {$priv_fragment}";
		};

		add_filter( 'posts_join', $join_cb );
		add_filter( 'posts_where', $where_cb );
		$query = new \WP_Query( $args );
		remove_filter( 'posts_join', $join_cb );
		remove_filter( 'posts_where', $where_cb );

		// Privacy is fully enforced by the SQL clause above (public / members /
		// own via wp_posts.post_author; private/friends/group/custom are owner-
		// only in the list, matching how media explore treats them). The old
		// per-item can_view() re-check was REMOVED here on purpose: album index
		// rows carry an unreliable post_author (0 or stale), so can_view() —
		// which keys on the index author for indexed albums — wrongly dropped an
		// owner's own non-public album, re-introducing the found_posts vs
		// returned-count mismatch this fix exists to kill. wp_posts.post_author
		// (used by the SQL clause) is the authoritative album author.
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->prepare_album_response( $post, true );
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

		// Privacy enforcement.
		if ( ! $this->privacy->can_view( $post->ID, get_current_user_id() ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You do not have access to this album.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response( $this->prepare_album_response( $post, true ) );
	}

	/**
	 * Get an album's media items, in album order, paginated.
	 *
	 * `GET /albums/{id}/items`. The route previously registered POST only, so
	 * every client asking for an album's contents got a 404 and rendered an
	 * empty album — the mobile app's album screen among them. `GET /albums/{id}`
	 * returns bare media IDs; this returns the hydrated rows a grid needs.
	 *
	 * Paginated deliberately: an album is unbounded, and hydrating every row
	 * would put an unbounded ID list into `get_batch()`'s prepared statement.
	 *
	 * @since 2.3.1
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_album_items( $request ) {
		$post = get_post( $request->get_param( 'id' ) );
		if ( ! $post || 'mvs_album' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Album not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Same gate as get_item() — a private album's contents are private.
		if ( ! $this->privacy->can_view( $post->ID, get_current_user_id() ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You do not have access to this album.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}

		$per_page = (int) $request->get_param( 'per_page' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		// get_items() already excludes trashed media and orders by position, so
		// the slice below is the album's real order, not a re-sort.
		$all_ids  = array_map( 'intval', array_column( $this->albums->get_items( $post->ID ), 'media_id' ) );
		$total    = count( $all_ids );
		$page_ids = array_slice( $all_ids, ( $page - 1 ) * $per_page, $per_page );

		$items = array();
		if ( ! empty( $page_ids ) ) {
			// One batched read for the page, then the shared media formatter —
			// no per-tile query, and no second copy of the response shape.
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->prefetch( $page_ids );
			$media_controller = new MediaController( $this->privacy );
			foreach ( $page_ids as $media_id ) {
				$prepared = $media_controller->prepare_item_for_response( $media_id, $request );
				if ( null !== $prepared ) {
					$items[] = $prepared;
				}
			}
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $total / $per_page ) : 0 ) );

		return $response;
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

		$album_id = \WPMediaVerse\Core\Plugin::container()->get( 'albums' )->create(
			get_current_user_id(),
			array(
				'title'       => $request->get_param( 'title' ) ?? '',
				'description' => $request->get_param( 'description' ) ?? '',
				'privacy'     => $request->get_param( 'privacy' ) ?? 'public',
				'album_type'  => $request->get_param( 'album_type' ) ?? 'default',
				'group_id'    => $request->get_param( 'group_id' ),
			)
		);

		if ( is_wp_error( $album_id ) ) {
			return $album_id;
		}

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

		// Post fields go through AlbumService::update() rather than being assembled
		// here, so the album -> post-field mapping (description => post_content)
		// lives in one place and any other caller gets the same contract.
		$post_fields = array();

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$post_fields['title'] = (string) $title;
		}

		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			$post_fields['description'] = (string) $description;
		}

		if ( ! empty( $post_fields ) ) {
			$result = $this->albums->update( $album_id, $post_fields );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$privacy = $request->get_param( 'privacy' );
		if ( $privacy ) {
			$this->albums->set_privacy( $album_id, sanitize_text_field( $privacy ) );
		}

		// Read the raw JSON body so "not sent" can be told apart from "explicitly sent
		// as 0" for cover_media_id — get_param() alone cannot make that distinction.
		$json_params = (array) $request->get_json_params();

		// Cover image — only act when the caller explicitly sends cover_media_id
		// (0 = clear the pinned cover; >0 = set to that media item).
		// Delegates to AlbumService::set_cover() which validates post type, file
		// type, and atomically adds the item when it's not already in the album.
		if ( array_key_exists( 'cover_media_id', $json_params ) ) {
			$cover_media_id = (int) $request->get_param( 'cover_media_id' );
			$cover_result   = $this->albums->set_cover( $album_id, $cover_media_id );
			if ( is_wp_error( $cover_result ) ) {
				return $cover_result;
			}
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

		$this->albums->delete_all_items( $album_id );

		// No taxonomy cleanup here. Albums no longer carry categories (2.3.3), and this
		// call was itself a data-loss vector: an album's post ID can equal a real
		// media_id, and wp_delete_object_term_relationships() keys on that shared
		// object_id space — so deleting an album wiped the colliding photo's categories.
		// Legacy album term rows are cleared once by Migrator v26.

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

		$this->albums->reorder( $album_id, $order );

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

		$added = $this->albums->add_items( $album_id, $media_ids );

		return rest_ensure_response( array( 'added' => $added ) );
	}

	/**
	 * Remove a media item from an album.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_item( $request ) {
		$album_id = $request->get_param( 'id' );
		$media_id = $request->get_param( 'media_id' );

		$removed = $this->albums->remove_item( $album_id, $media_id );

		if ( ! $removed ) {
			return new WP_Error( 'mvs_not_found', __( 'Item not found in album.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Set album cover image.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_cover( $request ) {
		$album_id = (int) $request->get_param( 'id' );
		$media_id = (int) $request->get_param( 'media_id' );

		$result = $this->albums->set_cover( $album_id, $media_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'album_id'  => $album_id,
				'media_id'  => $media_id,
				'cover_url' => $this->albums->get_cover_url( $album_id ),
			)
		);
	}

	/**
	 * Permissions: create album.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'upload_mvs_media' ) ) {
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
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'mvs_unauthorized', __( 'You must be logged in.', 'wpmediaverse' ), array( 'status' => 401 ) );
		}

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
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'mvs_unauthorized', __( 'You must be logged in.', 'wpmediaverse' ), array( 'status' => 401 ) );
		}

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
		$privacy_value = $this->albums->get_privacy( $album_id );
		$album_type    = $this->albums->get_album_type( $album_id );

		$viewer   = get_current_user_id();
		$is_owner = $viewer > 0 && (int) $post->post_author === $viewer;
		// Mirrors update_item_permissions_check: owner or edit_others_mvs_medias.
		$can_edit = $is_owner || ( $viewer > 0 && current_user_can( 'edit_others_mvs_medias' ) );

		$data = array(
			'id'             => $album_id,
			'title'          => $post->post_title,
			'description'    => $post->post_content,
			'author'         => (int) $post->post_author,
			'date'           => $post->post_date_gmt,
			'link'           => get_permalink( $album_id ),
			'privacy'        => $privacy_value ? $privacy_value : 'public',
			'album_type'     => $album_type ? $album_type : 'default',
			'media_count'    => $this->albums->get_item_count( $album_id ),
			'cover_url'      => $this->albums->get_cover_url( $album_id ),
			'cover_media_id' => $this->albums->get_cover_media_id( $album_id ),
			'is_owner'       => $is_owner,
			'can_edit'       => $can_edit,
		);

		if ( $include_items ) {
			$data['items'] = array_map( 'intval', array_column( $this->albums->get_items( $album_id ), 'media_id' ) );
		}

		/**
		 * Filters the album REST response data.
		 *
		 * @since 1.1.0
		 *
		 * @param array $data     Album response data.
		 * @param int   $album_id Album post ID.
		 */
		return apply_filters( 'mvs_album_response', $data, $album_id );
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
			'author'     => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'album_type' => array(
				'type'              => 'string',
				'enum'              => array( 'default', 'playlist' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
