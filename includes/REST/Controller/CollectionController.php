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
use WPMediaVerse\Services\CollectionService;
use WPMediaVerse\Repository\MediaRepository;

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
	 * Collection service instance.
	 *
	 * @var CollectionService
	 */
	private $collections;

	/**
	 * Constructor.
	 *
	 * @param CollectionService $collections Collection service.
	 */
	public function __construct( CollectionService $collections ) {
		$this->collections = $collections;
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

		// PUT /collections/{id}/rules — set smart collection rules.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/rules',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'set_rules' ),
				'permission_callback' => array( $this, 'owner_permissions_check' ),
				'args'                => array(
					'id'    => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'rules' => array(
						'type'     => 'array',
						'required' => true,
					),
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
		$per_page = \WPMediaVerse\REST\Pagination::resolve_per_page( $request );
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
	 * Get a single collection with its items.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post = get_post( $request->get_param( 'id' ) );
		if ( ! $post || 'mvs_collection' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Collection not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$per_page = \WPMediaVerse\REST\Pagination::resolve_per_page( $request );
		$per_page = $per_page ? (int) $per_page : 20;
		$page     = $request->get_param( 'page' );
		$page     = $page ? (int) $page : 1;

		return rest_ensure_response( $this->prepare_collection_response( $post, true, $per_page, $page ) );
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

		// Set smart collection rules if provided.
		$rules = $request->get_param( 'rules' );
		if ( is_array( $rules ) && ! empty( $rules ) ) {
			$this->collections->save_rules( $post_id, $rules );
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

		// Nullify collection_id in favorites referencing this collection.
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
	 * Set smart collection rules.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_rules( $request ) {
		$collection_id = $request->get_param( 'id' );
		$rules         = $request->get_param( 'rules' );

		if ( ! is_array( $rules ) ) {
			return new WP_Error( 'mvs_invalid_rules', __( 'Rules must be an array.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$this->collections->save_rules( $collection_id, $rules );

		return rest_ensure_response(
			array(
				'collection_id' => $collection_id,
				'type'          => 'smart',
				'rules'         => $this->collections->get_rules( $collection_id ),
			)
		);
	}

	/**
	 * Permissions: view collection (owner or admin).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$post = get_post( $request->get_param( 'id' ) );

		if ( ! $post || 'mvs_collection' !== $post->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Collection not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Published collections are viewable by anyone (shareable live views).
		if ( 'publish' === $post->post_status ) {
			return true;
		}

		$user_id = get_current_user_id();
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
	 * @param bool     $include_items Whether to include items.
	 * @param int      $per_page      Items per page.
	 * @param int      $page          Page number.
	 * @return array
	 */
	private function prepare_collection_response( $post, bool $include_items = false, int $per_page = 20, int $page = 1 ): array {
		$collection_type = $this->collections->get_type( $post->ID );

		$viewer   = get_current_user_id();
		$is_owner = $viewer > 0 && (int) $post->post_author === $viewer;
		// Mirrors get_item_permissions_check: owner or a moderator may edit.
		$can_edit = $is_owner || ( $viewer > 0 && current_user_can( 'moderate_mvs_media' ) );

		// Resolve a smart collection's matches ONCE — they drive both the cover
		// and the item count. Manual membership lives in the favorites table.
		// Every response (list AND detail) now carries a real `total`: before,
		// the count was only computed for smart collections in the detail view,
		// so the list endpoint the app + dashboard consume reported "0 items"
		// beside a populated cover.
		$smart_items = array();
		if ( 'smart' === $collection_type ) {
			$resolved    = $this->collections->resolve( $post->ID, $include_items ? $per_page : 5, $page );
			$smart_items = $resolved['items'];
			$total       = (int) $resolved['total'];
			$cover_ids   = wp_list_pluck( $smart_items, 'media_id' );
		} else {
			$cover_ids = $this->manual_cover_ids( $post->ID );
			$total     = $this->count_manual_items( $post->ID );
		}

		$data = array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'description' => $post->post_content,
			'author'      => (int) $post->post_author,
			'date'        => $post->post_date_gmt,
			'type'        => $collection_type,
			'link'        => get_permalink( $post->ID ),
			'cover_url'   => $this->cover_from_media_ids( $cover_ids ),
			'is_owner'    => $is_owner,
			'can_edit'    => $can_edit,
			'total'       => $total,
		);

		if ( 'smart' === $collection_type ) {
			$raw_rules     = $this->collections->get_rules( $post->ID );
			$data['rules'] = array_map( array( $this, 'enrich_rule' ), $raw_rules );
		}

		if ( $include_items ) {
			if ( 'smart' === $collection_type ) {
				$data['items'] = $smart_items;
			} else {
				global $wpdb;
				$data['favorites'] = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT media_id, created_at FROM {$wpdb->prefix}mvs_favorites WHERE collection_id = %d ORDER BY created_at DESC",
						$post->ID
					),
					ARRAY_A
				);
			}
		}

		/**
		 * Filters the collection REST response data.
		 *
		 * @since 1.1.0
		 *
		 * @param array $data          Collection response data.
		 * @param int   $collection_id Collection post ID.
		 */
		return apply_filters( 'mvs_collection_response', $data, $post->ID );
	}

	/**
	 * Enrich a rule with a human-readable label.
	 *
	 * The stored 'value' (term/user ID) is preserved so clients can round-trip
	 * rules through the edit modal without corrupting them; the readable name
	 * is exposed separately as 'label'. Before 1.6.0 this method overwrote
	 * 'value' with the name, which the dashboard edit modal then saved back —
	 * permanently breaking the collection's rules.
	 *
	 * @param array $rule Rule array with 'key' and 'value'.
	 * @return array Rule with original 'value' plus readable 'label'.
	 */
	private function enrich_rule( array $rule ): array {
		$key           = $rule['key'] ?? '';
		$value         = $rule['value'] ?? '';
		$rule['label'] = (string) $value;

		switch ( $key ) {
			case 'tag':
				$term = get_term( (int) $value, 'mvs_tag' );
				if ( $term && ! is_wp_error( $term ) ) {
					$rule['label'] = $term->name;
				}
				break;
			case 'category':
				$term = get_term( (int) $value, 'mvs_category' );
				if ( $term && ! is_wp_error( $term ) ) {
					$rule['label'] = $term->name;
				}
				break;
			case 'author':
				$user = get_userdata( (int) $value );
				if ( $user ) {
					$rule['label'] = $user->display_name;
				}
				break;
		}

		return $rule;
	}

	/**
	 * Newest manual-collection member media IDs, for cover selection.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return int[] Up to five media IDs, most recent first.
	 */
	private function manual_cover_ids( int $collection_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_favorites WHERE collection_id = %d ORDER BY created_at DESC LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$collection_id
			)
		);

		/** This filter is documented in includes/Social/FavoriteService.php */
		return apply_filters( 'mvs_collection_media_ids', array_map( 'intval', $ids ), $collection_id, 5 );
	}

	/**
	 * Count the members of a manual collection.
	 *
	 * Free-side source of truth; the Pro `mvs_collection_response` filter merges
	 * its own table and overrides this for combo installs.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return int Distinct member count.
	 */
	private function count_manual_items( int $collection_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT media_id) FROM {$wpdb->prefix}mvs_favorites WHERE collection_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$collection_id
			)
		);
	}

	/**
	 * First renderable thumbnail across a list of media IDs.
	 *
	 * An item can legitimately lack a thumb (audio without cover art
	 * mid-processing, import shells), so picking item #1 blindly produced empty
	 * covers when the newest item had no file.
	 *
	 * @param int[] $media_ids Candidate media IDs, most relevant first.
	 * @return string|null Thumbnail URL, or null when none is renderable.
	 */
	private function cover_from_media_ids( array $media_ids ): ?string {
		foreach ( $media_ids as $media_id ) {
			$thumb = \WPMediaVerse\Core\MediaUrl::thumb( (int) $media_id );
			if ( '' !== $thumb ) {
				return $thumb;
			}
		}

		return null;
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
