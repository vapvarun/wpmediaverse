<?php
/**
 * User REST controller.
 *
 * Public user profiles and user search for standalone social features.
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
use WPMediaVerse\Core\MediaTypes;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\REST\RateLimiter;

/**
 * REST controller for user profiles and search.
 */
class UserController extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * Register routes.
	 *
	 * @since 1.1.0
	 */
	public function register_routes(): void {
		// POST /me/dismiss — remember that this member closed a banner.
		//
		// SERVER-SIDE, because localStorage cannot stop a banner rendering. The
		// profile prompt was painted on every dashboard load and then removed by
		// JavaScript once it had read localStorage, which collapsed 70px and
		// jumped the page under the member's cursor — measured as the largest
		// layout shift on the dashboard, at 263ms. `dismissible.js` said as much
		// in its own docblock: "the longer-term improvement is a REST-persisted
		// dismiss flag checked server-side so the banner never renders dismissed
		// in the first place."
		//
		// A dismissal is also a preference, not a browser fact: closing it on a
		// laptop should close it on a phone.
		register_rest_route(
			$this->namespace,
			'/me/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_notice' ),
				'permission_callback' => array( $this, 'logged_in_check' ),
				'args'                => array(
					'key' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// GET /users/{id} — public profile.
		// PUBLIC_OK: returns display name, bio, avatar, follower/following/
		// public-media counts to anonymous viewers. The `username`
		// (= user_login) and `registered` fields are gated to the viewer
		// themselves + admins (post-WMV-01 hardening); WP core also withholds
		// these from anon REST responses. Email and last-login were never in
		// the payload. Triaged 2026-05-01 (Item 5); username/registered
		// gating added per WMV-01 (Basecamp #9919403615).
		register_rest_route(
			$this->namespace,
			'/users/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_profile' ),
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

		// GET /users/{id}/media — user's public media.
		// PUBLIC_OK: handler at line 170-176 enforces privacy at the SQL level
		// (privacy IN ('public','members') for anon viewers; full set for the
		// owner). The __return_true is correct because the gate is in the
		// query, not the callback. Triaged 2026-05-01 (Item 5).
		register_rest_route(
			$this->namespace,
			'/users/(?P<id>[\d]+)/media',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_media' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'       => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
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
				),
			)
		);

		// GET /users/search?q=term.
		// PUBLIC_OK: returns only public profile fields (display name, avatar).
		// Mirrors WP core's behavior for showing users in directory search.
		// No emails, no IPs, no last-login. Triaged 2026-05-01 (Item 5).
		register_rest_route(
			$this->namespace,
			'/users/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_users' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'        => array(
						'type'     => 'string',
						'required' => true,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 50,
					),
				),
			)
		);

		// GET /users/suggested — "people you may know" for first-session activation.
		register_rest_route(
			$this->namespace,
			'/users/suggested',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_suggested' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Get a user's public profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_profile( $request ) {
		// Rate-limit the anon path. user_login + user_registered used to be
		// leaked here, enabling iteration-based enumeration; the fields are now
		// gated to the viewer themselves (and admins) below, and the request
		// itself is throttled so a residual enumeration via id-iteration is
		// expensive. See WMV-01 (Basecamp #9919403615).
		$rate_check = RateLimiter::check( 'user_profile', 60, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$user_id = $request->get_param( 'id' );
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'mvs_user_not_found', __( 'User not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$follows    = Plugin::container()->get( 'follows' );
		$counts     = $follows->get_counts( $user_id );
		$current_id = get_current_user_id();

		// Count public media through the repository (P1.2). The raw query this
		// replaces had already needed two corrections that its sibling twenty
		// lines down never needed — a missing status filter, then a missing type
		// filter — which is the argument for one implementation rather than two.
		// The MEDIA_LIBRARY default lives in the repository, so the count and the
		// grid beneath it cannot disagree about what a profile contains.
		$media_count = Plugin::container()->get( 'media_repository' )->query_count(
			array(
				'author_id'         => (int) $user_id,
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
			)
		);

		// WP core deliberately withholds `user_login` and `user_registered`
		// from anonymous REST responses (they enable username enumeration +
		// targeted phishing). We now mirror that policy: the two fields are
		// exposed only to the user viewing their own profile and to admins
		// (where they're already available via wp-admin). WMV-01 fix.
		$is_self_or_admin = ( $current_id === (int) $user_id )
			|| ( $current_id > 0 && user_can( $current_id, 'list_users' ) );

		$profile = array(
			'id'           => $user_id,
			'name'         => $user->display_name,
			'bio'          => $user->description,
			'avatar'       => get_avatar_url( $user_id, array( 'size' => 150 ) ),
			'profile_url'  => \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( $user_id ),
			'media_count'  => $media_count,
			'followers'    => $counts['followers'],
			'following'    => $counts['following'],
			'is_following' => $current_id ? $follows->is_following( $current_id, $user_id ) : false,
		);

		if ( $is_self_or_admin ) {
			$profile['username']   = $user->user_login;
			$profile['registered'] = $user->user_registered;
		}

		return rest_ensure_response( $profile );
	}

	/**
	 * Get a user's public media.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_user_media( $request ) {
		$user_id  = (int) $request->get_param( 'id' );
		$per_page = \WPMediaVerse\REST\Pagination::resolve_per_page( $request );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$offset   = ( $page - 1 ) * $per_page;

		$viewer_id = get_current_user_id();

		// Privacy is resolved through the ONE canonical helper
		// (MediaRepository::build_privacy_where 'profile' mode, via
		// query_by_author()/count_visible_by_author()) — the exact same path the
		// profile grid, the main feed, and /serve use. No hand-rolled privacy
		// clause here, so this API can never drift from the rest of the plugin:
		// anon = public; logged-in viewer = public + members + friends-of-author;
		// owner/admin = own media (dm excluded); group/custom need per-item checks.
		$repo  = Plugin::container()->get( 'media_repository' );
		$total = $repo->count_visible_by_author( $user_id, $viewer_id );
		$rows  = $repo->query_by_author(
			$user_id,
			array(
				'moderation_status' => 'approved',
				'limit'             => $per_page,
				'offset'            => $offset,
				'viewer_id'         => $viewer_id,
			)
		);

		// Prime post and meta caches in bulk.
		$int_ids = array_map( static fn( $row ) => (int) $row['media_id'], $rows );
		if ( $int_ids ) {
			_prime_post_caches( $int_ids, true, true );
			update_meta_cache( 'post', $int_ids );
			// Prime the MVS index+meta rows so the per-item prepare below does not
			// run get_all() once per tile (N+1). Mirrors the 1.7.0 grid prefetch.
			Plugin::container()->get( 'media_repository' )->prefetch( $int_ids );
		}

		// Batch-load the viewer's favorite/reaction state for the whole page.
		MediaController::prime_viewer_state( $int_ids, $viewer_id );

		$privacy    = Plugin::container()->get( 'privacy' );
		$media_ctrl = new MediaController( $privacy );

		$items = array();
		foreach ( $int_ids as $mid ) {
			$item = $media_ctrl->prepare_item_for_response( $mid, $request );
			if ( $item ) {
				$items[] = $item;
			}
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per_page ) ) );

		return $response;
	}

	/**
	 * Search users by name or username.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function search_users( $request ) {
		$rate_check = RateLimiter::check( 'user_search', 100, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$query    = sanitize_text_field( $request->get_param( 'q' ) );
		$per_page = \WPMediaVerse\REST\Pagination::resolve_per_page( $request );

		if ( strlen( $query ) < 2 ) {
			return rest_ensure_response( array() );
		}

		$user_query = new \WP_User_Query(
			array(
				'search'         => '*' . $query . '*',
				'search_columns' => array( 'user_login', 'display_name' ),
				'number'         => $per_page,
				'orderby'        => 'display_name',
				'fields'         => 'ID',
			)
		);

		$current_id = get_current_user_id();
		$result_ids = array_map( 'intval', $user_query->get_results() );

		// Batch load follow status.
		$following_map = array();
		if ( $current_id && $result_ids ) {
			global $wpdb;
			$placeholders  = implode( ',', array_fill( 0, count( $result_ids ), '%d' ) );
			$params        = array_merge( array( $current_id ), $result_ids );
			$followed      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT following_id FROM {$wpdb->prefix}mvs_follows WHERE follower_id = %d AND following_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...$params
				)
			);
			$following_map = array_flip( array_map( 'intval', $followed ) );
		}

		// Batch load user objects.
		$user_objects = array();
		if ( $result_ids ) {
			$batch = new \WP_User_Query(
				array(
					'include' => $result_ids,
					'fields'  => 'all',
				)
			);
			foreach ( $batch->get_results() as $u ) {
				$user_objects[ (int) $u->ID ] = $u;
			}
		}

		$results = array();
		foreach ( $result_ids as $uid ) {
			$user = $user_objects[ $uid ] ?? null;
			if ( ! $user ) {
				continue;
			}
			$results[] = array(
				'id'           => $uid,
				'name'         => $user->display_name,
				'avatar'       => get_avatar_url( $uid, array( 'size' => 48 ) ),
				'profile_url'  => \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( $uid ),
				'is_following' => isset( $following_map[ $uid ] ),
			);
		}

		return rest_ensure_response( $results );
	}

	/**
	 * GET /users/suggested — "people you may know" for first-session activation.
	 *
	 * Ranked creators (popularity + interest overlap), excluding self/followed/
	 * blocked. Each card carries up to 3 sample thumbnails so the app can show a
	 * rich follow suggestion.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_suggested( $request ) {
		$limit     = max( 1, min( 50, (int) $request->get_param( 'limit' ) ) );
		$viewer_id = get_current_user_id();

		$suggestions = ( new \WPMediaVerse\Social\SuggestionService() )->get_suggestions( $viewer_id, $limit );
		if ( empty( $suggestions ) ) {
			return rest_ensure_response( array() );
		}

		$ids = array_map( static fn ( $s ) => (int) $s['user_id'], $suggestions );

		// Batch-load user objects.
		$user_objects = array();
		$batch        = new \WP_User_Query(
			array(
				'include' => $ids,
				'fields'  => 'all',
			)
		);
		foreach ( $batch->get_results() as $u ) {
			$user_objects[ (int) $u->ID ] = $u;
		}

		$tpl     = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' );
		$results = array();
		foreach ( $suggestions as $s ) {
			$uid  = (int) $s['user_id'];
			$user = $user_objects[ $uid ] ?? null;
			if ( ! $user ) {
				continue;
			}
			$results[] = array(
				'id'             => $uid,
				'name'           => $user->display_name,
				'avatar'         => get_avatar_url( $uid, array( 'size' => 96 ) ),
				'profile_url'    => (string) $tpl->get_user_profile_url( $uid ),
				'follower_count' => (int) $s['follower_count'],
				'is_following'   => false,
				'sample_media'   => $this->sample_media_thumbs( $uid, 3, $tpl ),
			);
		}

		return rest_ensure_response( $results );
	}

	/**
	 * Up to N public-media thumbnails for a creator, for a suggestion card.
	 *
	 * @param int   $user_id Creator user ID.
	 * @param int   $n       Max thumbnails.
	 * @param mixed $tpl     Template helpers.
	 * @return string[]
	 */
	private function sample_media_thumbs( int $user_id, int $n, $tpl ): array {
		// Thumbnails for a profile card, through the repository (P1.2). A document
		// has no thumbnail, so an unfiltered sample would render blank tiles on
		// the one surface meant to show a member at a glance — the MEDIA_LIBRARY
		// default in the repository is what prevents that.
		$rows = Plugin::container()->get( 'media_repository' )->query(
			array(
				'author_id'         => (int) $user_id,
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
				'orderby'           => 'created_at',
				'order'             => 'DESC',
				'limit'             => $n,
			)
		);

		$thumbs = array();
		foreach ( $rows as $row ) {
			$mid = isset( $row['media_id'] ) ? (int) $row['media_id'] : 0;
			$url = ( $mid && $tpl ) ? (string) $tpl->get_thumb_url( $mid, 'medium' ) : '';
			if ( '' !== $url ) {
				$thumbs[] = $url;
			}
		}
		return $thumbs;
	}

	/**
	 * The banners a member may dismiss, and the meta each one sets.
	 *
	 * An ALLOWLIST because the key arrives from the browser and ends up in a
	 * meta key — an open map would let any caller write arbitrary user meta.
	 *
	 * @since 2.4.0
	 *
	 * @return array<string, string> Key => user meta key.
	 */
	public static function dismissible_notices(): array {
		/**
		 * Filter the dismissible banners.
		 *
		 * @since 2.4.0
		 *
		 * @param array<string, string> $notices Key => user meta key.
		 */
		return (array) apply_filters(
			'mvs_dismissible_notices',
			array(
				'profile_prompt' => '_mvs_profile_prompt_dismissed',
			)
		);
	}

	/**
	 * Any logged-in member — a dismissal is their own state.
	 *
	 * @since 2.4.0
	 *
	 * @return true|WP_Error
	 */
	public function logged_in_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'mvs_unauthorized',
				__( 'You must be logged in.', 'wpmediaverse' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * POST /me/dismiss — remember a closed banner for this member.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss_notice( WP_REST_Request $request ) {
		$key     = (string) $request->get_param( 'key' );
		$notices = self::dismissible_notices();

		if ( ! isset( $notices[ $key ] ) ) {
			// A refusal is never a success response (coding rule 20).
			return new WP_Error(
				'mvs_unknown_notice',
				__( 'That is not a banner this site knows about.', 'wpmediaverse' ),
				array( 'status' => 400 )
			);
		}

		update_user_meta( get_current_user_id(), $notices[ $key ], 1 );

		return rest_ensure_response( array( 'dismissed' => $key ) );
	}
}
