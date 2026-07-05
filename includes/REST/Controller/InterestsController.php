<?php
/**
 * Interests REST controller — onboarding interest picker.
 *
 * @package WPMediaVerse
 * @since   1.9.0
 */

namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Interest categories for first-session activation.
 *
 * Reuses the existing `mvs_category` taxonomy — no new schema. The picker seeds
 * relevance for the trending feed (`?interests=auto`) and suggested-follows.
 * The available list is cached (it changes rarely); the viewer's picks live in
 * the `mvs_interests` user-meta.
 */
final class InterestsController extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Cache key for the public interest list.
	 */
	const CACHE_KEY = 'mvs_app_interests';

	/**
	 * User-meta key for a member's chosen interests.
	 */
	const META_KEY = 'mvs_interests';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/app/interests',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_interests' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/me/interests',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_interests' ),
					'permission_callback' => array( $this, 'logged_in_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_my_interests' ),
					'permission_callback' => array( $this, 'logged_in_check' ),
					'args'                => array(
						'interest_ids' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'integer' ),
						),
					),
				),
			)
		);
	}

	/**
	 * GET /app/interests — available interest chips (cached).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_interests( $request ) {
		unset( $request );

		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'mvs_category',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 40,
			)
		);

		$items = array();
		if ( ! is_wp_error( $terms ) ) {
			$tpl = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' );
			foreach ( $terms as $term ) {
				$items[] = array(
					'id'        => (int) $term->term_id,
					'name'      => $term->name,
					'slug'      => $term->slug,
					'count'     => (int) $term->count,
					'cover_url' => $this->category_cover_url( (int) $term->term_taxonomy_id, $tpl ),
				);
			}
		}

		/**
		 * Interest-list cache TTL (seconds). 0 disables caching.
		 *
		 * @since 1.9.0
		 *
		 * @param int $ttl Default 1 hour.
		 */
		$ttl = (int) apply_filters( 'mvs_app_interests_cache_ttl', HOUR_IN_SECONDS );
		if ( $ttl > 0 ) {
			set_transient( self::CACHE_KEY, $items, $ttl );
		}

		return rest_ensure_response( $items );
	}

	/**
	 * GET /me/interests — the viewer's saved interest IDs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_my_interests( $request ) {
		unset( $request );
		return rest_ensure_response( array( 'interest_ids' => $this->read_interests( get_current_user_id() ) ) );
	}

	/**
	 * POST /me/interests — save the viewer's interest picks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_my_interests( $request ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $request->get_param( 'interest_ids' ) ) ) ) );

		// Keep only real mvs_category term IDs.
		$valid = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id, 'mvs_category' );
			if ( $term && ! is_wp_error( $term ) ) {
				$valid[] = (int) $id;
			}
		}

		$user_id = get_current_user_id();
		update_user_meta( $user_id, self::META_KEY, $valid );
		// Completing the picker counts as onboarding done (resilient to the app
		// not calling /me/onboarding/complete).
		update_user_meta( $user_id, 'mvs_onboarded', 1 );

		return rest_ensure_response( array( 'interest_ids' => $valid ) );
	}

	/**
	 * Read a user's saved interest term IDs.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	private function read_interests( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $raw ) ? array_values( array_map( 'intval', $raw ) ) : array();
	}

	/**
	 * One popular thumbnail to represent a category in the picker.
	 *
	 * @param int   $tt_id Category term_taxonomy_id.
	 * @param mixed $tpl   Template helpers service.
	 * @return string
	 */
	private function category_cover_url( int $tt_id, $tpl ): string {
		global $wpdb;
		$media_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT i.media_id
				FROM {$wpdb->prefix}mvs_media_index i
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = i.media_id AND tr.term_taxonomy_id = %d
				WHERE i.status = 'publish' AND i.moderation_status = 'approved' AND i.privacy = 'public'
				ORDER BY i.reaction_count DESC
				LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$tt_id
			)
		);
		return ( $media_id && $tpl ) ? (string) $tpl->get_thumb_url( $media_id, 'medium' ) : '';
	}

	/**
	 * Permission: logged-in users only.
	 *
	 * @return bool|WP_Error
	 */
	public function logged_in_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'mvs_unauthorized', __( 'You must be logged in.', 'wpmediaverse' ), array( 'status' => 401 ) );
		}
		return true;
	}
}
