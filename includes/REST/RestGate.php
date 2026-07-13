<?php
/**
 * Dispatch-level write gate for every MediaVerse REST route.
 *
 * @package WPMediaVerse
 * @since   2.1.0
 */

namespace WPMediaVerse\REST;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WPMediaVerse\Core\Plugin;

/**
 * Applies the block and suspension guards to every write route, from one place.
 *
 * Why a single gate instead of a guard call in each permission callback: the
 * plugins register ~112 write endpoints across Free and Pro, and before 2.1.0
 * exactly three of them consulted RestGuards. The rest were open. A blocked
 * member could still favourite the person who blocked them, view their story,
 * challenge them to a battle, or pull them into a group. Patching forty
 * callbacks would have closed today's holes and guaranteed the next new route
 * shipped ungated.
 *
 * So: one filter on `rest_request_before_callbacks` — the choke point that runs
 * after WordPress has matched the route and authenticated the request (cookie
 * *or* Application Password) and before the handler executes — plus a
 * declarative map that classifies every write route as one of:
 *
 *   exempt — no gate. Safety valves (report, unblock, unfollow, leave group) and
 *            retractions. A blocked member must always be able to withdraw
 *            consent or report their blocker; blocking must never become a
 *            shield for the blocker or a trap for the blocked.
 *   self   — acts only on the actor's own resource. No second party, so no block
 *            gate — but the suspension gate still applies.
 *   gated  — a write that touches another member. Block gate + suspension gate.
 *
 * The map is the audit artifact. RestGateCoverageTest fails if any registered
 * write route is missing from it, so a new route cannot ship unclassified.
 */
final class RestGate {

	/**
	 * Namespaces this gate governs.
	 */
	private const NAMESPACES = array( '/mvs/v1/', '/mvs-pro/v1/' );

	/**
	 * Methods that mutate state.
	 */
	private const WRITE_METHODS = array( 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * Register the gate.
	 */
	public static function register(): void {
		add_filter( 'rest_request_before_callbacks', array( self::class, 'gate' ), 10, 3 );
	}

	/**
	 * The route map.
	 *
	 * Order matters: the first pattern that matches the route *and* the method
	 * wins. Anything unmatched inside our namespaces falls through to `self`
	 * (suspension gate only) — safe by default, because a route that touches a
	 * second party must be listed explicitly, and the coverage test enforces it.
	 *
	 * @return array<int, array{pattern:string, methods:array<int,string>, mode:string, resolver?:string}>
	 */
	public static function map(): array {
		$map = array(
			// --- Safety valves. Never gated. -------------------------------
			// Reporting and blocking must work *because* someone blocked you.
			// Gating these would let an abuser block their victim to silence
			// the report.
			array(
				'pattern' => '#^/mvs/v1/users/\d+/(block|report)$#',
				'methods' => array( 'POST', 'DELETE' ),
				'mode'    => 'exempt',
			),
			array(
				'pattern' => '#^/mvs/v1/media/\d+/report$#',
				'methods' => array( 'POST' ),
				'mode'    => 'exempt',
			),

			// --- Retractions. Never gated. ---------------------------------
			// Withdrawing something you already did must always be allowed. Before
			// 2.1.0 the create/delete routes shared one permission callback, so a
			// blocked member was locked out of deleting their own earlier comment
			// or un-reacting — the gate pointed the wrong way.
			array(
				'pattern' => '#^/mvs/v1/users/\d+/follow$#',
				'methods' => array( 'DELETE' ),
				'mode'    => 'exempt',
			),
			array(
				'pattern' => '#^/mvs/v1/media/\d+/comments/\d+$#',
				'methods' => array( 'PUT', 'PATCH', 'DELETE' ),
				'mode'    => 'exempt',
			),
			array(
				'pattern' => '#^/mvs/v1/media/\d+/reactions$#',
				'methods' => array( 'DELETE' ),
				'mode'    => 'exempt',
			),
			array(
				'pattern' => '#^/mvs/v1/media/\d+/favorite$#',
				'methods' => array( 'DELETE' ),
				'mode'    => 'exempt',
			),
			array(
				'pattern' => '#^/mvs-pro/v1/groups/\d+/leave$#',
				'methods' => array( 'POST' ),
				'mode'    => 'exempt',
			),

			// Leaving the community, and taking that back, must ALWAYS be
			// possible — including while suspended.
			//
			// This is not a nicety. Requesting deletion suspends the member for
			// the grace window (that is what stops a thief with a stolen token
			// from posting while the real owner is locked out). But the
			// suspension gate denies every write, so it also denied the cancel
			// route: a member who scheduled deletion could never change their
			// mind, and the grace period — whose entire purpose is second
			// thoughts — was a one-way door. Caught end-to-end, not by reading
			// the code.
			//
			// A member suspended by a moderator must also still be able to
			// delete their account. Apple guideline 5.1.1(v) has no carve-out
			// for members you are unhappy with, and you cannot hold someone's
			// account hostage.
			array(
				'pattern' => '#^/mvs/v1/me$#',
				'methods' => array( 'DELETE' ),
				'mode'    => 'exempt',
			),
			array(
				'pattern' => '#^/mvs/v1/me/deletion$#',
				'methods' => array( 'DELETE' ),
				'mode'    => 'exempt',
			),

			// --- Between-members writes. Block gate applies. ----------------
			array(
				'pattern'  => '#^/mvs/v1/users/(\d+)/follow$#',
				'methods'  => array( 'POST' ),
				'mode'     => 'gated',
				'resolver' => 'route_user',
			),
			array(
				'pattern'  => '#^/mvs/v1/media/(\d+)/comments$#',
				'methods'  => array( 'POST' ),
				'mode'     => 'gated',
				'resolver' => 'media_author',
			),
			array(
				'pattern'  => '#^/mvs/v1/media/(\d+)/reactions$#',
				'methods'  => array( 'POST' ),
				'mode'     => 'gated',
				'resolver' => 'media_author',
			),
			// The hole this whole class was written for: favouriting was never
			// gated, so a blocked member could keep interacting with the person
			// who blocked them.
			array(
				'pattern'  => '#^/mvs/v1/media/(\d+)/favorite$#',
				'methods'  => array( 'POST' ),
				'mode'     => 'gated',
				'resolver' => 'media_author',
			),
			array(
				'pattern'  => '#^/mvs/v1/conversations$#',
				'methods'  => array( 'POST' ),
				'mode'     => 'gated',
				'resolver' => 'param_recipients',
			),
			array(
				'pattern'  => '#^/mvs/v1/conversations/(\d+)/messages$#',
				'methods'  => array( 'POST' ),
				'mode'     => 'gated',
				'resolver' => 'conversation_peers',
			),
			array(
				'pattern'  => '#^/mvs/v1/messages/upload$#',
				'methods'  => array( 'POST' ),
				'mode'     => 'gated',
				'resolver' => 'param_conversation_peers',
			),
			// Reacting to a message someone sent you. Same shape as reacting to
			// their media, and it was missed for the same reason: the block gate
			// was applied per-controller instead of per-route.
			array(
				'pattern'  => '#^/mvs/v1/messages/(\d+)/reactions$#',
				'methods'  => array( 'POST' ),
				'mode'     => 'gated',
				'resolver' => 'message_peers',
			),
			// Un-reacting is a retraction. Always allowed.
			array(
				'pattern' => '#^/mvs/v1/messages/\d+/reactions$#',
				'methods' => array( 'DELETE' ),
				'mode'    => 'exempt',
			),
			// Accepting a message request from someone who has since blocked you
			// would re-open a channel they closed.
			array(
				'pattern'  => '#^/mvs/v1/conversations/(\d+)/accept$#',
				'methods'  => array( 'POST' ),
				'mode'     => 'gated',
				'resolver' => 'conversation_peers',
			),
			// Declining is a safety valve. Never gated — refusing contact must
			// always be possible.
			array(
				'pattern' => '#^/mvs/v1/conversations/\d+/decline$#',
				'methods' => array( 'POST' ),
				'mode'    => 'exempt',
			),
			array(
				'pattern'  => '#^/mvs/v1/media/(\d+)/share$#',
				'methods'  => array( 'POST' ),
				'mode'     => 'gated',
				'resolver' => 'media_author',
			),
		);

		/**
		 * Filter the REST write-gate route map.
		 *
		 * The seam Pro uses to classify its own routes, so Pro never edits a Free
		 * file. Third parties adding routes to these namespaces should append
		 * their rules here.
		 *
		 * @since 2.1.0
		 *
		 * @param array<int, array<string, mixed>> $map Route rules.
		 */
		return (array) apply_filters( 'mvs_rest_gate_map', $map );
	}

	/**
	 * Gate a request before its handler runs.
	 *
	 * @param mixed           $response Current response (WP_Error short-circuits).
	 * @param array           $handler  Matched route handler.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed WP_Error to deny, otherwise $response untouched.
	 */
	public static function gate( $response, $handler, $request ) {
		// Someone upstream already failed the request; don't second-guess them.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		/**
		 * Filter whether the write gate runs at all.
		 *
		 * A per-site kill switch. These plugins run on thousands of sites and
		 * this gate turns some previously-allowed writes into 403s; an owner who
		 * hits an unforeseen interaction needs a way out that is not "downgrade".
		 *
		 * @since 2.1.0
		 *
		 * @param bool $enabled Whether the gate is active. Default true.
		 */
		if ( ! apply_filters( 'mvs_rest_gate_enabled', true ) ) {
			return $response;
		}

		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );

		if ( ! self::owns_route( $route ) || ! in_array( $method, self::WRITE_METHODS, true ) ) {
			return $response;
		}

		$actor = get_current_user_id();

		// An unauthenticated write is the route's own business (401/public).
		// The gate decides *who* may write, not *whether* auth is required.
		if ( $actor <= 0 ) {
			return $response;
		}

		$rule = self::classify( $route, $method );

		if ( 'exempt' === $rule['mode'] ) {
			return $response;
		}

		// Suspension applies to every authenticated write, including writes on
		// your own resources — a suspended member must not be able to keep
		// uploading. This is the check core cannot do for us, because App
		// Password auth skips the `authenticate` filter chain entirely.
		$suspended = RestGuards::deny_if_suspended( $actor );
		if ( $suspended instanceof WP_Error ) {
			self::denied( $route, $method, $actor, 'suspended' );
			return $suspended;
		}

		if ( 'gated' === $rule['mode'] ) {
			$targets = array_filter( self::resolve_targets( $rule, $route, $request ) );

			if ( empty( $targets ) ) {
				// A gated route whose resolver finds nobody is indistinguishable
				// from a route with no second party — so the gate passes. That is
				// exactly how a resolver bug hides: it looks like "nothing to
				// check" rather than "I failed to look". Announce it so a broken
				// resolver is detectable in logs and CI instead of silently
				// disabling the gate for that route.
				do_action( 'mvs_rest_gate_unresolved', $route, $method, $actor );

				return $response;
			}

			$blocked = RestGuards::deny_if_blocked( $actor, $targets );
			if ( $blocked instanceof WP_Error ) {
				self::denied( $route, $method, $actor, 'blocked' );
				return $blocked;
			}
		}

		return $response;
	}

	/**
	 * Whether the route belongs to a namespace this gate governs.
	 *
	 * @param string $route Route path.
	 * @return bool
	 */
	private static function owns_route( string $route ): bool {
		foreach ( self::NAMESPACES as $ns ) {
			if ( 0 === strpos( $route, $ns ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classify a route + method against the map.
	 *
	 * Unmatched write routes fall through to `self`: the suspension gate still
	 * applies, but no block gate, because we have no second party to check. Any
	 * route that *does* touch a second party must be listed — RestGateCoverageTest
	 * is what stops that from being forgotten.
	 *
	 * @param string $route  Route path.
	 * @param string $method HTTP method.
	 * @return array{mode:string, resolver?:string, matches?:array<int,string>}
	 */
	private static function classify( string $route, string $method ): array {
		foreach ( self::map() as $rule ) {
			if ( empty( $rule['pattern'] ) || empty( $rule['methods'] ) ) {
				continue;
			}

			if ( ! in_array( $method, (array) $rule['methods'], true ) ) {
				continue;
			}

			$matches = array();
			if ( preg_match( (string) $rule['pattern'], $route, $matches ) ) {
				return array(
					'mode'     => (string) $rule['mode'],
					'resolver' => isset( $rule['resolver'] ) ? (string) $rule['resolver'] : '',
					'matches'  => $matches,
				);
			}
		}

		return array( 'mode' => 'self' );
	}

	/**
	 * Resolve the member(s) a gated write targets.
	 *
	 * Every resolver goes through core or an existing service — no raw $wpdb.
	 *
	 * @param array           $rule    Matched rule (carries regex captures).
	 * @param string          $route   Route path.
	 * @param WP_REST_Request $request The request.
	 * @return int[] Target user IDs.
	 */
	private static function resolve_targets( array $rule, string $route, WP_REST_Request $request ): array {
		$resolver = isset( $rule['resolver'] ) ? (string) $rule['resolver'] : '';
		$captured = isset( $rule['matches'][1] ) ? (int) $rule['matches'][1] : 0;

		switch ( $resolver ) {
			case 'route_user':
				return array( $captured );

			case 'media_author':
				// Must go through the repository. MediaVerse does not keep the
				// owner in wp_posts.post_author (it is 0 on imported media), so
				// get_post_field() here silently resolved to user 0 and the block
				// gate quietly passed everything.
				/** @var \WPMediaVerse\Repository\MediaRepository $media */
				$media = Plugin::container()->get( 'media_repository' );
				return array( $media->get_author( $captured ) );

			case 'param_recipients':
				$ids = $request->get_param( 'participant_ids' );
				if ( null === $ids ) {
					$ids = $request->get_param( 'recipient_id' );
				}
				return array_map( 'intval', (array) $ids );

			case 'conversation_peers':
				return self::conversation_peers( $captured );

			case 'param_conversation_peers':
				return self::conversation_peers( (int) $request->get_param( 'conversation_id' ) );

			case 'message_peers':
				/** @var \WPMediaVerse\Messaging\MessagingService $messaging */
				$messaging = Plugin::container()->get( 'messaging' );
				return self::conversation_peers( $messaging->get_message_conversation_id( $captured ) );
		}

		/**
		 * Resolve targets for a custom gate resolver.
		 *
		 * Lets Pro (and third parties) supply resolvers for their own routes
		 * without touching this file.
		 *
		 * @since 2.1.0
		 *
		 * @param int[]           $targets  Target user IDs. Default empty.
		 * @param string          $resolver Resolver name from the rule.
		 * @param array           $rule     The matched rule.
		 * @param WP_REST_Request $request  The request.
		 */
		return array_map(
			'intval',
			(array) apply_filters( 'mvs_rest_gate_resolve_targets', array(), $resolver, $rule, $request )
		);
	}

	/**
	 * Everyone in a conversation except the actor.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return int[]
	 */
	private static function conversation_peers( int $conversation_id ): array {
		if ( $conversation_id <= 0 ) {
			return array();
		}

		/** @var \WPMediaVerse\Messaging\MessagingService $messaging */
		$messaging = Plugin::container()->get( 'messaging' );

		$actor = get_current_user_id();
		$peers = array();

		foreach ( (array) $messaging->get_participants( $conversation_id ) as $participant ) {
			$id = 0;

			// MessagingService::get_participants() returns presentation rows keyed
			// `id`, not `user_id`. Reading the wrong key here returned an empty
			// target list, and an empty list means the gate has nobody to check —
			// so it passed. A resolver that cannot find its target must never be
			// mistaken for "there is no target".
			$row = is_object( $participant ) ? (array) $participant : $participant;

			if ( is_array( $row ) ) {
				$id = (int) ( $row['id'] ?? $row['user_id'] ?? 0 );
			} elseif ( is_numeric( $participant ) ) {
				$id = (int) $participant;
			}

			if ( $id > 0 && $id !== $actor ) {
				$peers[] = $id;
			}
		}

		return $peers;
	}

	/**
	 * Announce a denial, for logging and telemetry.
	 *
	 * @param string $route  Route path.
	 * @param string $method HTTP method.
	 * @param int    $actor  Acting user ID.
	 * @param string $reason 'blocked' or 'suspended'.
	 */
	private static function denied( string $route, string $method, int $actor, string $reason ): void {
		/**
		 * Fires when the write gate denies a request.
		 *
		 * @since 2.1.0
		 *
		 * @param string $route  Route path.
		 * @param string $method HTTP method.
		 * @param int    $actor  Acting user ID.
		 * @param string $reason 'blocked' or 'suspended'.
		 */
		do_action( 'mvs_rest_gate_denied', $route, $method, $actor, $reason );
	}
}
