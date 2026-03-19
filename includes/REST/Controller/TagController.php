<?php
/**
 * Tag REST controller.
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

/**
 * REST controller for media tags (mvs_tag taxonomy).
 */
class TagController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// GET /tags — search/autocomplete.
		register_rest_route(
			$this->namespace,
			'/tags',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tags' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'search'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'orderby'  => array(
						'type'    => 'string',
						'default' => 'name',
						'enum'    => array( 'name', 'count' ),
					),
				),
			)
		);

		// GET /tags/cloud — top tags with counts.
		register_rest_route(
			$this->namespace,
			'/tags/cloud',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cloud' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 200,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /tags/merge — merge source tag into target.
		register_rest_route(
			$this->namespace,
			'/tags/merge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'merge_tags' ),
				'permission_callback' => array( $this, 'admin_check' ),
				'args'                => array(
					'source_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'target_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// PUT /tags/{id} — rename tag.
		register_rest_route(
			$this->namespace,
			'/tags/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'rename_tag' ),
				'permission_callback' => array( $this, 'admin_check' ),
				'args'                => array(
					'id'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'name' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Get tags with optional search filter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_tags( $request ) {
		$args = array(
			'taxonomy'   => 'mvs_tag',
			'hide_empty' => false,
			'number'     => (int) $request->get_param( 'per_page' ),
			'orderby'    => $request->get_param( 'orderby' ),
			'order'      => 'count' === $request->get_param( 'orderby' ) ? 'DESC' : 'ASC',
		);

		$search = $request->get_param( 'search' );
		if ( $search ) {
			$args['search'] = $search;
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return rest_ensure_response( array() );
		}

		$data = array();
		foreach ( $terms as $term ) {
			$data[] = $this->format_term( $term );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Get tag cloud (top tags by count).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_cloud( $request ) {
		$limit = (int) $request->get_param( 'limit' );

		$terms = get_terms(
			array(
				'taxonomy'   => 'mvs_tag',
				'hide_empty' => true,
				'number'     => $limit,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return rest_ensure_response( array() );
		}

		$data = array();
		foreach ( $terms as $term ) {
			$data[] = $this->format_term( $term );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Merge source tag into target tag.
	 *
	 * Reassigns all posts from source to target, then deletes source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function merge_tags( $request ) {
		$source_id = $request->get_param( 'source_id' );
		$target_id = $request->get_param( 'target_id' );

		if ( $source_id === $target_id ) {
			return new WP_Error( 'mvs_same_tag', __( 'Source and target tags must be different.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$source = get_term( $source_id, 'mvs_tag' );
		$target = get_term( $target_id, 'mvs_tag' );

		if ( ! $source || is_wp_error( $source ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Source tag not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( ! $target || is_wp_error( $target ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Target tag not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Get posts with the source tag in batches to avoid unbounded memory usage.
		$batch_size   = 500;
		$batch_page   = 1;
		$posts        = array();
		$total_merged = 0;

		do {
			$batch = get_posts(
				array(
					'post_type'      => 'mvs_media',
					'posts_per_page' => $batch_size,
					'paged'          => $batch_page,
					'fields'         => 'ids',
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => 'mvs_tag',
							'terms'    => $source_id,
						),
					),
				)
			);

			foreach ( $batch as $post_id ) {
				wp_set_object_terms( $post_id, $target_id, 'mvs_tag', true );
			}

			$total_merged += count( $batch );
			$posts         = array_merge( $posts, $batch );
			++$batch_page;
		} while ( count( $batch ) === $batch_size );

		// Delete source tag.
		wp_delete_term( $source_id, 'mvs_tag' );

		/**
		 * Fires after tags are merged.
		 *
		 * @param int   $source_id Source term ID (deleted).
		 * @param int   $target_id Target term ID (kept).
		 * @param int[] $posts     Post IDs affected.
		 */
		do_action( 'mvs_tags_merged', $source_id, $target_id, $posts );

		return rest_ensure_response(
			array(
				'merged'         => true,
				'target'         => $this->format_term( get_term( $target_id, 'mvs_tag' ) ),
				'posts_affected' => count( $posts ),
			)
		);
	}

	/**
	 * Rename a tag.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rename_tag( $request ) {
		$term_id = $request->get_param( 'id' );
		$name    = $request->get_param( 'name' );

		$term = get_term( $term_id, 'mvs_tag' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Tag not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$result = wp_update_term(
			$term_id,
			'mvs_tag',
			array(
				'name' => $name,
				'slug' => sanitize_title( $name ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->format_term( get_term( $term_id, 'mvs_tag' ) ) );
	}

	/**
	 * Format a term for API response.
	 *
	 * @param \WP_Term $term Term object.
	 * @return array
	 */
	private function format_term( $term ): array {
		return array(
			'id'    => $term->term_id,
			'name'  => $term->name,
			'slug'  => $term->slug,
			'count' => $term->count,
		);
	}

	/**
	 * Permissions: admin/moderator only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function admin_check( $request ) {
		if ( ! current_user_can( 'moderate_mvs_media' ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You do not have permission to manage tags.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
