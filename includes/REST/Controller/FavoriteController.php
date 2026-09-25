<?php
/**
 * Favorite REST controller.
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
use WPMediaVerse\Social\FavoriteService;

/**
 * REST controller for media favorites.
 */
class FavoriteController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Favorite service instance.
	 *
	 * @var FavoriteService
	 */
	private $favorites;

	/**
	 * Constructor.
	 *
	 * @param FavoriteService $favorites Favorite service instance.
	 */
	public function __construct( FavoriteService $favorites ) {
		$this->favorites = $favorites;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// GET/POST/DELETE /media/{id}/favorite.
		register_rest_route(
			$this->namespace,
			'/media/(?P<media_id>[\d]+)/favorite',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_favorite_status' ),
					'permission_callback' => array( $this, 'auth_check' ),
					'args'                => array(
						'media_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'toggle_favorite' ),
					'permission_callback' => array( $this, 'auth_check' ),
					'args'                => array(
						'media_id'      => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'collection_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'toggle_favorite' ),
					'permission_callback' => array( $this, 'auth_check' ),
					'args'                => array(
						'media_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// GET /me/favorites — current user's favorites.
		register_rest_route(
			$this->namespace,
			'/me/favorites',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_my_favorites' ),
				'permission_callback' => array( $this, 'auth_check' ),
				'args'                => array(
					'collection_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'per_page'      => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'          => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					's'             => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'orderby'       => array(
						'type'    => 'string',
						'default' => 'favorited',
						// `favorited` is when the member saved it; `date` is when
						// the media itself was created. They are different
						// questions and a member sorting their favourites means
						// the first one, so it stays the default.
						'enum'    => array( 'favorited', 'title', 'date' ),
					),
					'order'         => array(
						'type'    => 'string',
						'default' => 'desc',
						'enum'    => array( 'asc', 'desc' ),
					),
				),
			)
		);
	}

	/**
	 * Toggle favorite on a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_favorite_status( $request ) {
		$media_id = $request->get_param( 'media_id' );
		$user_id  = get_current_user_id();

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$favorited = $this->favorites->is_favorited( $media_id, $user_id );

		return rest_ensure_response( array( 'favorited' => $favorited ) );
	}

	/**
	 * Toggle favorite.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_favorite( $request ) {
		$rate_check = RateLimiter::check( 'favorite_toggle', 60, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id = $request->get_param( 'media_id' );

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Favorite only media you can view (permission check is login-only).
		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( (int) $media_id, get_current_user_id() ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$collection_id = $request->get_param( 'collection_id' );
		// The Favorites collection IS the favourites list, so targeting it is a
		// plain favourite: can_view() rules, not the collection rule.
		if ( $collection_id && \WPMediaVerse\Social\FavoriteService::favorites_owner( (int) $collection_id ) ) {
			$collection_id = 0;
		}

		// Favouriting and collecting are different questions. can_view() above
		// is the right gate for a favourite - anything you may look at, you may
		// bookmark. A COLLECTION is curated and shareable, so it takes the
		// narrower rule: public, or your own. Without this, a members-only
		// photo could be pulled into a collection by any logged-in curator,
		// because can_view() admits members-level to everyone signed in.
		// Basecamp 10298612348.
		if ( $collection_id
			&& ! \WPMediaVerse\Core\Plugin::container()->get( 'collections' )->may_contain( (int) $media_id, get_current_user_id() ) ) {
			return new WP_Error(
				'mvs_not_collectable',
				__( 'Only public media, or your own uploads, can go in a collection.', 'wpmediaverse' ),
				array( 'status' => 403 )
			);
		}

		$result = $this->favorites->toggle( $media_id, get_current_user_id(), $collection_id ? (int) $collection_id : null );

		/**
		 * Fires after a favorite is toggled.
		 *
		 * @param int    $media_id Media post ID.
		 * @param int    $user_id  User ID.
		 * @param string $action   Action taken: added or removed.
		 */
		do_action( 'mvs_favorite_toggled', $media_id, get_current_user_id(), $result['action'] );

		return rest_ensure_response(
			array(
				'media_id'  => $media_id,
				'action'    => $result['action'],
				'favorited' => $result['favorited'],
				'count'     => $this->favorites->get_count( $media_id ),
			)
		);
	}

	/**
	 * Get current user's favorites.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_my_favorites( $request ) {
		$collection_id = $request->get_param( 'collection_id' );
		if ( $collection_id && \WPMediaVerse\Social\FavoriteService::favorites_owner( (int) $collection_id ) ) {
			$collection_id = 0; // The Favorites collection lists every favourite.
		}
		$per_page      = \WPMediaVerse\REST\Pagination::resolve_per_page( $request );
		$page          = \WPMediaVerse\REST\Pagination::resolve_page( $request );

		$result = $this->favorites->get_user_favorites(
			get_current_user_id(),
			$collection_id ? (int) $collection_id : null,
			$per_page,
			$page,
			(string) $request->get_param( 's' ),
			(string) $request->get_param( 'orderby' ),
			(string) $request->get_param( 'order' )
		);

		// Collect the page's media IDs (skip orphaned favorites) and keep each
		// created_at to alias back onto the canonical object below.
		$repo        = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$page_ids    = array();
		$created_map = array();
		// EXISTS IS NOT VISIBLE. This gated on existence alone, so a member who
		// had favourited an item that later went private kept seeing its title,
		// its thumbnail slot and an Unfavorite button - the image 403'd, the card
		// around it did not. Privacy was half-applied: the member could not see
		// the photo but could see what it was called and that it still existed.
		// can_view() is the same gate this controller already applies to a single
		// favourite (see the POST path). Basecamp 10297947358.
		$mvs_privacy  = \WPMediaVerse\Core\Plugin::container()->get( 'privacy' );
		$mvs_viewer   = get_current_user_id();
		$mvs_filtered = 0;
		// Prefetch the page BEFORE the existence/privacy loop: exists() and
		// can_view() read the prefetched rows, so the loop no longer runs two
		// queries per favourite (2.6.0, big-site pass).
		$repo->prefetch( array_map( 'intval', array_column( $result['items'], 'media_id' ) ) );

		foreach ( $result['items'] as $item ) {
			$media_id = (int) $item['media_id'];
			if ( ! $repo->exists( $media_id ) ) {
				continue;
			}
			if ( ! $mvs_privacy->can_view( $media_id, $mvs_viewer ) ) {
				++$mvs_filtered;
				continue;
			}
			$page_ids[]               = $media_id;
			$created_map[ $media_id ] = $item['created_at'];
		}

		// Batch-prime index/meta + viewer state so the canonical builder stays
		// query-bounded (no per-tile get_all / favorite / reaction lookups).
		if ( $page_ids ) {
			$repo->prefetch( $page_ids );
		}
		MediaController::prime_viewer_state( $page_ids, get_current_user_id() );

		$media_ctrl = new MediaController( \WPMediaVerse\Core\Plugin::container()->get( 'privacy' ) );

		// Return the canonical media object (one model app-wide) plus the legacy
		// `media_id` + `created_at` aliases the existing web blocks read
		// (media-social, dashboard-view) — additive, no breakage.
		$enriched = array();
		foreach ( $page_ids as $media_id ) {
			$media = $media_ctrl->prepare_item_for_response( $media_id, $request );
			if ( null === $media ) {
				continue;
			}
			$media['media_id']   = $media_id;
			$media['created_at'] = $created_map[ $media_id ];
			$enriched[]          = $media;
		}

		// The total drops with the rows. Leaving it whole would advertise a page
		// the viewer cannot be shown and paginate against a number that can never
		// be filled - the count-and-list disagreement this plugin keeps producing.
		// It is a per-viewer figure now, which is what the list has always been.
		$mvs_total = max( 0, (int) $result['total'] - $mvs_filtered );

		$response = rest_ensure_response( $enriched );
		// Cast at the call site: header() takes a string, and both values are
		// computed ints. Two baselined int-given errors lived here; casting
		// removes them rather than carrying the ignore forward.
		$response->header( 'X-WP-Total', (string) $mvs_total );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $mvs_total / $per_page ) : 0 ) );

		return $response;
	}

	/**
	 * Permissions: authenticated users only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function auth_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'mvs_unauthorized', __( 'You must be logged in.', 'wpmediaverse' ), array( 'status' => 401 ) );
		}
		return true;
	}
}
