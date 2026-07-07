<?php
/**
 * Follow REST controller.
 *
 * @package    WPMediaVerse
 * @subpackage REST
 * @since      1.1.0
 */

namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPMediaVerse\REST\RateLimiter;
use WPMediaVerse\Social\FollowService;

/**
 * REST controller for follow/unfollow and follower/following lists.
 */
class FollowController extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * @var FollowService
	 */
	private $follows;

	/**
	 * Constructor.
	 *
	 * @param FollowService $follows Follow service.
	 */
	public function __construct( FollowService $follows ) {
		$this->follows = $follows;
	}

	/**
	 * Register routes.
	 *
	 * @since 1.1.0
	 */
	public function register_routes(): void {
		// POST /users/{id}/follow — follow.
		// DELETE /users/{id}/follow — unfollow.
		register_rest_route(
			$this->namespace,
			'/users/(?P<id>[\d]+)/follow',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'follow_user' ),
					'permission_callback' => function ( $request ) {
						if ( ! is_user_logged_in() ) {
							return false;
						}
						// Block gate — App Passwords bypass login; a blocked
						// relationship cannot follow the other member.
						$blocked = \WPMediaVerse\REST\RestGuards::deny_if_blocked( get_current_user_id(), (int) $request['id'] );
						return $blocked instanceof \WP_Error ? $blocked : true;
					},
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
					'callback'            => array( $this, 'unfollow_user' ),
					'permission_callback' => function () {
						return is_user_logged_in();
					},
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

		// GET /users/{id}/followers and GET /users/{id}/following.
		// PUBLIC_OK on both: returns display name + avatar of users in the
		// follow graph. Mirrors any public social network's "who follows X"
		// list. Note that /me/followers and /me/following (separate routes
		// below) DO require is_user_logged_in — the public variants are
		// intentional. Triaged 2026-05-01 (Item 5).
		register_rest_route(
			$this->namespace,
			'/users/(?P<id>[\d]+)/followers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_followers' ),
				'permission_callback' => '__return_true',
				'args'                => $this->get_pagination_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/users/(?P<id>[\d]+)/following',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_following' ),
				'permission_callback' => '__return_true',
				'args'                => $this->get_pagination_args(),
			)
		);

		// GET /me/following.
		register_rest_route(
			$this->namespace,
			'/me/following',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_my_following' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'args'                => $this->get_pagination_args(),
			)
		);

		// GET /me/followers.
		register_rest_route(
			$this->namespace,
			'/me/followers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_my_followers' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'args'                => $this->get_pagination_args(),
			)
		);
	}

	/**
	 * Follow a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function follow_user( $request ) {
		$rate_check = RateLimiter::check( 'follow', 30, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$target_id = $request->get_param( 'id' );
		$result    = $this->follows->follow( get_current_user_id(), $target_id );

		if ( false === $result ) {
			return new WP_Error( 'mvs_follow_failed', __( 'Unable to follow this user.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'following' => true,
				'counts'    => $this->follows->get_counts( $target_id ),
			)
		);
	}

	/**
	 * Unfollow a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function unfollow_user( $request ) {
		$rate_check = RateLimiter::check( 'follow', 30, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$target_id = $request->get_param( 'id' );
		$this->follows->unfollow( get_current_user_id(), $target_id );

		return rest_ensure_response(
			array(
				'following' => false,
				'counts'    => $this->follows->get_counts( $target_id ),
			)
		);
	}

	/**
	 * Get followers of a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_followers( $request ) {
		$user_id  = $request->get_param( 'id' );
		$per_page = \WPMediaVerse\REST\Pagination::resolve_per_page( $request );
		$page     = (int) $request->get_param( 'page' );

		$data  = $this->follows->get_followers( $user_id, $per_page, $page );
		$users = $this->format_user_list( $data['users'], get_current_user_id() );

		$response = rest_ensure_response( $users );
		$response->header( 'X-WP-Total', $data['total'] );

		return $response;
	}

	/**
	 * Get users that a user follows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_following( $request ) {
		$user_id  = $request->get_param( 'id' );
		$per_page = \WPMediaVerse\REST\Pagination::resolve_per_page( $request );
		$page     = (int) $request->get_param( 'page' );

		$data  = $this->follows->get_following( $user_id, $per_page, $page );
		$users = $this->format_user_list( $data['users'], get_current_user_id() );

		$response = rest_ensure_response( $users );
		$response->header( 'X-WP-Total', $data['total'] );

		return $response;
	}

	/**
	 * Get current user's following list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_my_following( $request ) {
		$request->set_param( 'id', get_current_user_id() );
		return $this->get_following( $request );
	}

	/**
	 * Get current user's followers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_my_followers( $request ) {
		$request->set_param( 'id', get_current_user_id() );
		return $this->get_followers( $request );
	}

	/**
	 * Format user IDs into user objects for response.
	 *
	 * @param int[] $user_ids       User IDs.
	 * @param int   $current_user   Current user ID for follow status.
	 * @return array
	 */
	private function format_user_list( array $user_ids, int $current_user ): array {
		if ( empty( $user_ids ) ) {
			return array();
		}

		// Batch load all user objects in one query.
		$user_query = new \WP_User_Query(
			array(
				'include' => $user_ids,
				'orderby' => 'include',
				'fields'  => 'all',
			)
		);

		// Batch load follow status if logged in.
		$following_map = array();
		if ( $current_user ) {
			global $wpdb;
			$placeholders  = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
			$params        = array_merge( array( $current_user ), $user_ids );
			$followed      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT following_id FROM {$wpdb->prefix}mvs_follows WHERE follower_id = %d AND following_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...$params
				)
			);
			$following_map = array_flip( array_map( 'intval', $followed ) );
		}

		$users = array();
		foreach ( $user_query->get_results() as $user ) {
			$uid     = (int) $user->ID;
			$users[] = array(
				'id'           => $uid,
				'name'         => $user->display_name,
				'avatar'       => get_avatar_url( $uid, array( 'size' => 96 ) ),
				'profile_url'  => \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( $uid ),
				'is_following' => isset( $following_map[ $uid ] ),
			);
		}
		return $users;
	}

	/**
	 * Pagination args.
	 *
	 * @return array
	 */
	private function get_pagination_args(): array {
		return array(
			'per_page' => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			),
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
		);
	}
}
