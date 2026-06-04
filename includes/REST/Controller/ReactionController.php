<?php
/**
 * Reaction REST controller.
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
use WPMediaVerse\Social\ReactionService;

/**
 * REST controller for media reactions.
 */
class ReactionController extends WP_REST_Controller {

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
	protected $rest_base = 'media/(?P<media_id>[\d]+)/reactions';

	/**
	 * Reaction service instance.
	 *
	 * @var ReactionService
	 */
	private $reactions;

	/**
	 * Constructor.
	 *
	 * @param ReactionService $reactions Reaction service instance.
	 */
	public function __construct( ReactionService $reactions ) {
		$this->reactions = $reactions;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// PUBLIC_OK on the GET /reactions __return_true below: aggregated
		// counts grouped by reaction type — not a per-user reaction list.
		// The "did_I_react" data only emits when a logged-in viewer hits
		// the route (handler reads get_current_user_id() and conditionally
		// adds the field). Public counts mirror Twitter's "12 likes"
		// indicator. POST/DELETE on the same route carry proper permission
		// callbacks. Triaged 2026-05-01 (Item 5).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
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
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'media_id'      => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'reaction_type' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => ReactionService::TYPES,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
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
	}

	/**
	 * Get reactions for a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$media_id = $request->get_param( 'media_id' );

		if ( ! $this->media_exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Privacy gate — the aggregate reaction tally on a members-only/private
		// item must not be world-readable via this public route (audit
		// 2026-06-04). Mirror the single-media page: non-viewer gets a 404.
		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( (int) $media_id, get_current_user_id() ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$counts        = $this->reactions->get_counts( $media_id );
		$total         = array_sum( $counts );
		$user_reaction = null;

		if ( is_user_logged_in() ) {
			$user_reaction = $this->reactions->get_user_reaction( $media_id, get_current_user_id() );
		}

		return rest_ensure_response(
			array(
				'media_id'      => $media_id,
				'counts'        => $counts,
				'total'         => $total,
				'user_reaction' => $user_reaction,
			)
		);
	}

	/**
	 * Toggle a reaction on a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$rate_check = RateLimiter::check( 'reaction_toggle', 60, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id      = $request->get_param( 'media_id' );
		$reaction_type = $request->get_param( 'reaction_type' );

		if ( ! $this->media_exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// React only to media you can view (permission check is login-only).
		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( (int) $media_id, get_current_user_id() ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( ! in_array( $reaction_type, ReactionService::TYPES, true ) ) {
			return new WP_Error( 'mvs_invalid_type', __( 'Invalid reaction type.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$result = $this->reactions->toggle( $media_id, get_current_user_id(), $reaction_type );

		/**
		 * Fires after a reaction is toggled on a media item.
		 *
		 * @param int    $media_id      Media post ID.
		 * @param int    $user_id       User ID.
		 * @param string $reaction_type Reaction type.
		 * @param string $action        Action taken: added, updated, or removed.
		 */
		do_action( 'mvs_reaction_toggled', $media_id, get_current_user_id(), $reaction_type, $result['action'] );

		// Fire specific hooks for integrations (BuddyPress, webhooks).
		if ( 'added' === $result['action'] || 'updated' === $result['action'] ) {
			/** This action is documented in includes/Integrations/BuddyPress/ActivitySyncIntegration.php */
			do_action( 'mvs_reaction_added', $media_id, get_current_user_id(), $reaction_type );
		}

		$counts = $this->reactions->get_counts( $media_id );

		return rest_ensure_response(
			array(
				'media_id'      => $media_id,
				'action'        => $result['action'],
				'reaction_type' => $result['reaction_type'],
				'counts'        => $counts,
				'total'         => array_sum( $counts ),
			)
		);
	}

	/**
	 * Remove a reaction from a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$media_id = $request->get_param( 'media_id' );

		if ( ! $this->media_exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media item not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$removed = $this->reactions->remove( $media_id, get_current_user_id() );

		if ( ! $removed ) {
			return new WP_Error( 'mvs_no_reaction', __( 'No reaction to remove.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Permissions: authenticated users can react.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'mvs_unauthorized', __( 'You must be logged in to react.', 'wpmediaverse' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Check if a media item exists.
	 *
	 * @param int $media_id Media post ID.
	 * @return bool
	 */
	private function media_exists( int $media_id ): bool {
		return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id );
	}
}
