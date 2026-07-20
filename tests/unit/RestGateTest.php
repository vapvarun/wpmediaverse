<?php
/**
 * Contract tests for the REST write gate.
 *
 * These lock the security contract stated in RestGuards' docblock: Application
 * Passwords bypass any plugin login gate, so block and suspension enforcement
 * has to happen on the REST side. Before 2.1.0 only three of ~112 write
 * endpoints checked, and a blocked member could still favourite, share, or
 * otherwise interact with the person who blocked them.
 *
 * The coverage test at the bottom is the one that matters long-term: it fails
 * when a new write route ships without being classified, so this cannot rot
 * back to where it was.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\REST\RestGate;
use WPMediaVerse\REST\RestGuards;

class RestGateTest extends WP_UnitTestCase {

	/**
	 * The member who does the blocking, and owns the media.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * The member who gets blocked.
	 *
	 * @var int
	 */
	private int $blocked;

	/**
	 * An uninvolved member. Nothing we do should ever affect them.
	 *
	 * @var int
	 */
	private int $bystander;

	/**
	 * Media owned by $owner.
	 *
	 * @var int
	 */
	private int $media;

	public function set_up(): void {
		parent::set_up();

		// The guards memoise per request; WP_UnitTestCase rolls the DB back
		// behind them, so a primed entry would go stale across cases.
		RestGuards::flush_cache();

		do_action( 'rest_api_init' );

		$this->owner     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->blocked   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->bystander = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->media = self::factory()->attachment->create( array( 'post_author' => $this->owner ) );

		/** @var \WPMediaVerse\Social\ReportService $reports */
		$reports = Plugin::container()->get( 'reports' );
		$reports->block_user( $this->owner, $this->blocked );
	}

	/**
	 * Dispatch a request as a given user and return the status code.
	 */
	private function status_as( int $user_id, string $method, string $route, array $params = array() ): int {
		wp_set_current_user( $user_id );
		RestGuards::flush_cache();

		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request )->get_status();
	}

	public function test_blocked_member_cannot_comment_on_blocker_media(): void {
		$this->assertSame(
			403,
			$this->status_as( $this->blocked, 'POST', "/mvs/v1/media/{$this->media}/comments", array( 'content' => 'hi' ) )
		);
	}

	/**
	 * The hole RestGate was written for. Favourite was never gated.
	 */
	public function test_blocked_member_cannot_favorite_blocker_media(): void {
		$this->assertSame(
			403,
			$this->status_as( $this->blocked, 'POST', "/mvs/v1/media/{$this->media}/favorite" )
		);
	}

	public function test_blocked_member_cannot_follow_blocker(): void {
		$this->assertSame(
			403,
			$this->status_as( $this->blocked, 'POST', "/mvs/v1/users/{$this->owner}/follow" )
		);
	}

	/**
	 * Blocking must never silence the person who was blocked. If it did, an
	 * abuser could block their victim to suppress the report.
	 */
	public function test_blocked_member_can_still_report_their_blocker(): void {
		$this->assertNotSame(
			403,
			$this->status_as( $this->blocked, 'POST', "/mvs/v1/media/{$this->media}/report", array( 'reason' => 'spam' ) )
		);
	}

	/**
	 * Retraction is always allowed. Create and delete used to share one
	 * permission callback, so a blocked member was locked out of removing their
	 * own earlier reaction — the gate pointed the wrong way.
	 */
	public function test_blocked_member_can_still_unfollow(): void {
		$this->assertNotSame(
			403,
			$this->status_as( $this->blocked, 'DELETE', "/mvs/v1/users/{$this->owner}/follow" )
		);
	}

	public function test_suspended_member_cannot_write_at_all(): void {
		update_user_meta( $this->bystander, 'mvs_suspended', 1 );

		$this->assertSame(
			403,
			$this->status_as( $this->bystander, 'POST', '/mvs/v1/albums', array( 'title' => 'mine' ) ),
			'A suspended member must not be able to write, even to their own resources.'
		);
	}

	public function test_uninvolved_member_is_unaffected(): void {
		$this->assertNotSame(
			403,
			$this->status_as( $this->bystander, 'POST', "/mvs/v1/media/{$this->media}/favorite" )
		);
	}

	public function test_gate_can_be_switched_off(): void {
		add_filter( 'mvs_rest_gate_enabled', '__return_false' );

		$this->assertNotSame(
			403,
			$this->status_as( $this->blocked, 'POST', "/mvs/v1/media/{$this->media}/favorite" )
		);

		remove_filter( 'mvs_rest_gate_enabled', '__return_false' );
	}

	public function test_gate_ignores_reads(): void {
		$this->assertNotSame(
			403,
			$this->status_as( $this->blocked, 'GET', "/mvs/v1/media/{$this->media}" ),
			'The gate governs writes. A blocked member may still read public content.'
		);
	}

	/**
	 * The anti-rot test.
	 *
	 * Every write route in our namespaces must resolve to a rule in the map, or
	 * fall through to `self` deliberately. This asserts the map still covers the
	 * second-party routes we know about — if someone adds, say, a new "gift" or
	 * "mention" write endpoint between two members and forgets to classify it,
	 * add it here and to RestGate::map() in the same commit.
	 */
	public function test_known_between_member_writes_are_all_gated(): void {
		$must_be_gated = array(
			array( 'POST', "/mvs/v1/media/{$this->media}/comments", array( 'content' => 'x' ) ),
			array( 'POST', "/mvs/v1/media/{$this->media}/favorite", array() ),
			array( 'POST', "/mvs/v1/media/{$this->media}/share", array() ),
			array( 'POST', "/mvs/v1/users/{$this->owner}/follow", array() ),
		);

		foreach ( $must_be_gated as $case ) {
			list( $method, $route, $params ) = $case;

			$this->assertSame(
				403,
				$this->status_as( $this->blocked, $method, $route, $params ),
				"{$method} {$route} is a write between two members and must be gated."
			);
		}
	}

	/**
	 * Classify a concrete route via the private classifier.
	 */
	private function mode_of( string $method, string $route ): string {
		$reflection = new \ReflectionMethod( RestGate::class, 'classify' );
		$reflection->setAccessible( true );
		$rule = $reflection->invoke( null, $route, $method );

		return (string) $rule['mode'];
	}

	/**
	 * The anti-rot test.
	 *
	 * Asserts the CORRECT mode for every route whose classification carries
	 * security meaning — not merely that it resolves to *a* known mode.
	 *
	 * That distinction is the whole point. An unclassified write silently falls
	 * through to `self`, which is a valid mode, so a weaker assertion would pass
	 * happily while a between-member route sat wide open. That is precisely how
	 * the original bug shipped: favourite/share/battle/story-view were all
	 * "valid", just ungated. If a refactor drops a rule from RestGate::map() or
	 * from Pro's mvs_rest_gate_map filter, this test fails.
	 *
	 * Adding a new write route between two members? Add it here and to the map,
	 * in the same commit.
	 *
	 * @dataProvider provide_expected_modes
	 */
	public function test_route_classifies_to_the_expected_mode( string $method, string $route, string $expected ): void {
		$this->assertSame(
			$expected,
			$this->mode_of( $method, $route ),
			"{$method} {$route} must classify as '{$expected}'. A between-member write that "
			. "falls through to 'self' is an open door, not a safe default."
		);
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public function provide_expected_modes(): array {
		return array(
			// --- Free: writes that touch another member. ---------------------
			'comment on media'      => array( 'POST', '/mvs/v1/media/1/comments', 'gated' ),
			'react to media'        => array( 'POST', '/mvs/v1/media/1/reactions', 'gated' ),
			'favorite media'        => array( 'POST', '/mvs/v1/media/1/favorite', 'gated' ),
			'share media'           => array( 'POST', '/mvs/v1/media/1/share', 'gated' ),
			'follow member'         => array( 'POST', '/mvs/v1/users/1/follow', 'gated' ),
			'start conversation'    => array( 'POST', '/mvs/v1/conversations', 'gated' ),
			'send message'          => array( 'POST', '/mvs/v1/conversations/1/messages', 'gated' ),
			'upload DM attachment'  => array( 'POST', '/mvs/v1/messages/upload', 'gated' ),
			'react to message'      => array( 'POST', '/mvs/v1/messages/1/reactions', 'gated' ),
			'accept msg request'    => array( 'POST', '/mvs/v1/conversations/1/accept', 'gated' ),

			// --- Free: safety valves. Must never be gated. --------------------
			'report media'          => array( 'POST', '/mvs/v1/media/1/report', 'exempt' ),
			'report member'         => array( 'POST', '/mvs/v1/users/1/report', 'exempt' ),
			'block member'          => array( 'POST', '/mvs/v1/users/1/block', 'exempt' ),
			'unblock member'        => array( 'DELETE', '/mvs/v1/users/1/block', 'exempt' ),
			'decline msg request'   => array( 'POST', '/mvs/v1/conversations/1/decline', 'exempt' ),

			// --- Free: retractions. Must never be gated. ----------------------
			'unfollow'              => array( 'DELETE', '/mvs/v1/users/1/follow', 'exempt' ),
			'un-favorite'           => array( 'DELETE', '/mvs/v1/media/1/favorite', 'exempt' ),
			'un-react to media'     => array( 'DELETE', '/mvs/v1/media/1/reactions', 'exempt' ),
			'un-react to message'   => array( 'DELETE', '/mvs/v1/messages/1/reactions', 'exempt' ),
			'delete own comment'    => array( 'DELETE', '/mvs/v1/media/1/comments/2', 'exempt' ),

			// --- Free: own-resource writes. Suspension gate only. -------------
			'upload media'          => array( 'POST', '/mvs/v1/media', 'self' ),
			'create album'          => array( 'POST', '/mvs/v1/albums', 'self' ),

			// --- Pro: writes that touch another member. -----------------------
			'challenge to battle'   => array( 'POST', '/mvs-pro/v1/battles', 'gated' ),
			'view their story'      => array( 'POST', '/mvs-pro/v1/stories/1/view', 'gated' ),
			'save their media'      => array( 'POST', '/mvs-pro/v1/media/1/collections', 'gated' ),
			'create group'          => array( 'POST', '/mvs-pro/v1/groups', 'gated' ),
			'add group participant' => array( 'POST', '/mvs-pro/v1/groups/1/participants', 'gated' ),

			// --- Pro: safety valve. ------------------------------------------
			'leave group'           => array( 'POST', '/mvs-pro/v1/groups/1/leave', 'exempt' ),

			// --- Leaving the community, and taking it back. -------------------
			// Requesting deletion suspends the member for the grace window, and
			// the suspension gate denies every write — so it denied the cancel
			// route too. A member who scheduled deletion could never change their
			// mind: the grace period, whose whole purpose is second thoughts, was
			// a one-way door. Both must stay exempt.
			'delete own account'    => array( 'DELETE', '/mvs/v1/me', 'exempt' ),
			'cancel deletion'       => array( 'DELETE', '/mvs/v1/me/deletion', 'exempt' ),
		);
	}

	/**
	 * A gated route must actually resolve its target.
	 *
	 * Classification is not enforcement. The DM-reaction rule was correctly
	 * classified `gated`, and a blocked member could still react — because the
	 * resolver read `user_id` from MessagingService::get_participants(), which
	 * returns rows keyed `id`. It found nobody, and a gated route with no target
	 * has nothing to check, so it passed. The gate failed open and every
	 * classification test still went green.
	 *
	 * This asserts the resolver finds the peer. If someone changes the shape of
	 * get_participants() again, this fails instead of quietly reopening the door.
	 */
	public function test_conversation_peers_resolver_finds_the_other_member(): void {
		/** @var \WPMediaVerse\Messaging\MessagingService $messaging */
		$messaging = Plugin::container()->get( 'messaging' );

		$convo    = $messaging->find_or_create_conversation( $this->owner, $this->bystander );
		$convo_id = (int) ( $convo['conversation_id'] ?? 0 );

		$this->assertGreaterThan( 0, $convo_id, 'Fixture failed: no conversation was created.' );

		wp_set_current_user( $this->bystander );

		$ref = new \ReflectionMethod( RestGate::class, 'conversation_peers' );
		$ref->setAccessible( true );
		$peers = $ref->invoke( null, $convo_id );

		$this->assertSame(
			array( $this->owner ),
			array_values( array_map( 'intval', $peers ) ),
			'The resolver must find the other participant. An empty result silently disables the gate.'
		);
	}

	/**
	 * Reporting must be available out of the box.
	 *
	 * The regression sentinel for the other half of this incident: reporting
	 * defaulted to OFF behind a filter no shipped code ever set, so every report
	 * on every install — Free and Pro — returned 403, while Pro rendered a User
	 * Reports queue that could never receive one. Nothing in the test suite,
	 * the cert run, or the journeys would have noticed.
	 *
	 * If someone "tidies" the default back to false, this fails.
	 */
	public function test_reporting_is_enabled_by_default(): void {
		delete_option( 'mvs_enable_reports' );

		$this->assertTrue(
			\WPMediaVerse\Social\ReportService::reports_enabled(),
			'Members must be able to report abuse on a fresh install, with no filter or mu-plugin.'
		);

		$this->assertNotSame(
			403,
			$this->status_as( $this->bystander, 'POST', "/mvs/v1/media/{$this->media}/report", array( 'reason' => 'spam' ) ),
			'A fresh install must accept a report, not refuse it.'
		);
	}

	/**
	 * ...and the site owner must still be able to turn it off.
	 */
	public function test_reporting_can_be_disabled_by_the_owner(): void {
		update_option( 'mvs_enable_reports', false );

		$this->assertFalse( \WPMediaVerse\Social\ReportService::reports_enabled() );

		$this->assertSame(
			403,
			$this->status_as( $this->bystander, 'POST', "/mvs/v1/media/{$this->media}/report", array( 'reason' => 'spam' ) )
		);

		delete_option( 'mvs_enable_reports' );
	}

	/**
	 * The coverage guarantee the class docblock promises: a write route cannot
	 * ship unclassified.
	 *
	 * Walks every write route WordPress actually registered under /mvs/v1/,
	 * concretises its regex to a sample path, classifies it through the live
	 * RestGate, and asserts the result matches the recorded expectation. A NEW
	 * write route that nobody added to RestGate::map() (and here) falls through to
	 * a default it was never reviewed for and fails this test; a route whose mode
	 * DRIFTS from the recorded value fails too. Either way the door cannot open
	 * silently the way favourite/share/follow once did.
	 *
	 * Adding or changing a write route? Classify it in RestGate::map() and record
	 * the concrete route => mode below, in the same commit.
	 */
	public function test_every_registered_write_route_is_classified(): void {
		$expected = $this->expected_write_route_modes();
		$writable = array( 'POST', 'PUT', 'PATCH', 'DELETE' );
		$problems = array();

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( 0 !== strpos( $route, '/mvs/v1/' ) ) {
				continue; // Pro (/mvs-pro/v1/) is covered by Pro's own suite.
			}
			foreach ( (array) $handlers as $handler ) {
				if ( empty( $handler['methods'] ) || ! is_array( $handler['methods'] ) ) {
					continue;
				}
				foreach ( array_keys( array_filter( $handler['methods'] ) ) as $method ) {
					$method = strtoupper( (string) $method );
					if ( ! in_array( $method, $writable, true ) ) {
						continue;
					}
					$concrete = $this->concretise_route( (string) $route );
					$key      = $method . ' ' . $concrete;
					$mode     = $this->mode_of( $method, $concrete );

					if ( ! isset( $expected[ $key ] ) ) {
						$problems[] = "{$key} is registered but not recorded (classifies '{$mode}'). "
							. 'Classify it in RestGate::map() and record it in expected_write_route_modes().';
					} elseif ( $expected[ $key ] !== $mode ) {
						$problems[] = "{$key} classifies '{$mode}' but the matrix expects '{$expected[ $key ]}'.";
					}
				}
			}
		}

		$this->assertSame( array(), $problems, "RestGate coverage gaps:\n" . implode( "\n", $problems ) );
	}

	/**
	 * Turn a registered route regex into a concrete sample path RestGate::map()
	 * patterns can match: numeric id groups become 1, any other group becomes x.
	 */
	private function concretise_route( string $route ): string {
		$route = preg_replace( '#\(\?P<[a-z_]+>\[\\\\d\]\+\)#i', '1', $route );
		$route = preg_replace( '#\(\?P<[a-z_]+>\\\\d\+\)#i', '1', $route );
		$route = preg_replace( '#\(\?P<[a-z_]+>[^)]*\)#i', 'x', $route );
		return (string) $route;
	}

	/**
	 * Every registered /mvs/v1/ write route and the mode it must classify as.
	 * Generated from the live route registry; keep it in lockstep with
	 * RestGate::map(). 'self' = suspension gate only (own-resource write),
	 * 'gated' = blocked+suspension (touches another member), 'exempt' = never
	 * gated (safety valve / retraction / leaving).
	 *
	 * @return array<string, string>
	 */
	private function expected_write_route_modes(): array {
		return array(
			'DELETE /mvs/v1/albums/1' => 'self',
			'DELETE /mvs/v1/albums/1/items/1' => 'self',
			'DELETE /mvs/v1/collections/1' => 'self',
			'DELETE /mvs/v1/conversations/1' => 'self',
			'DELETE /mvs/v1/me' => 'exempt',
			'DELETE /mvs/v1/me/avatar' => 'self',
			'DELETE /mvs/v1/me/deletion' => 'exempt',
			'DELETE /mvs/v1/media/1' => 'self',
			'DELETE /mvs/v1/media/1/comments/1' => 'exempt',
			'DELETE /mvs/v1/media/1/favorite' => 'exempt',
			'DELETE /mvs/v1/media/1/grant/1' => 'self',
			'DELETE /mvs/v1/media/1/reactions' => 'exempt',
			'DELETE /mvs/v1/media/1/rules/1' => 'self',
			'DELETE /mvs/v1/messages/1' => 'self',
			'DELETE /mvs/v1/messages/1/reactions' => 'exempt',
			'DELETE /mvs/v1/messages/1/unsend' => 'self',
			'DELETE /mvs/v1/tags/1' => 'self',
			'DELETE /mvs/v1/users/1/block' => 'exempt',
			'DELETE /mvs/v1/users/1/follow' => 'exempt',
			'PATCH /mvs/v1/albums/1' => 'self',
			'PATCH /mvs/v1/albums/1/cover' => 'self',
			'PATCH /mvs/v1/albums/1/reorder' => 'self',
			'PATCH /mvs/v1/collections/1' => 'self',
			'PATCH /mvs/v1/collections/1/rules' => 'self',
			'PATCH /mvs/v1/conversations/1' => 'self',
			'PATCH /mvs/v1/me/profile' => 'self',
			'PATCH /mvs/v1/media/1' => 'self',
			'PATCH /mvs/v1/media/1/comments/1' => 'exempt',
			'PATCH /mvs/v1/tags/1' => 'self',
			'POST /mvs/v1/admin/welcome/dismiss' => 'self',
			'POST /mvs/v1/albums' => 'self',
			'POST /mvs/v1/albums/1' => 'self',
			'POST /mvs/v1/albums/1/cover' => 'self',
			'POST /mvs/v1/albums/1/items' => 'self',
			'POST /mvs/v1/albums/1/reorder' => 'self',
			'POST /mvs/v1/collections' => 'self',
			'POST /mvs/v1/collections/1' => 'self',
			'POST /mvs/v1/collections/1/rules' => 'self',
			'POST /mvs/v1/conversations' => 'gated',
			'POST /mvs/v1/conversations/1/accept' => 'gated',
			'POST /mvs/v1/conversations/1/decline' => 'exempt',
			'POST /mvs/v1/conversations/1/messages' => 'gated',
			'POST /mvs/v1/conversations/1/read' => 'self',
			'POST /mvs/v1/conversations/1/typing' => 'self',
			'POST /mvs/v1/me/avatar' => 'self',
			'POST /mvs/v1/me/interests' => 'self',
			'POST /mvs/v1/me/notifications/read' => 'self',
			'POST /mvs/v1/me/onboarding/complete' => 'self',
			'POST /mvs/v1/me/profile' => 'self',
			'POST /mvs/v1/media' => 'self',
			'POST /mvs/v1/media/1' => 'self',
			'POST /mvs/v1/media/1/comments' => 'gated',
			'POST /mvs/v1/media/1/comments/1' => 'self',
			'POST /mvs/v1/media/1/download' => 'self',
			'POST /mvs/v1/media/1/favorite' => 'gated',
			'POST /mvs/v1/media/1/grant' => 'self',
			'POST /mvs/v1/media/1/reactions' => 'gated',
			'POST /mvs/v1/media/1/replace' => 'self',
			'POST /mvs/v1/media/1/report' => 'exempt',
			'POST /mvs/v1/media/1/rules' => 'self',
			'POST /mvs/v1/media/1/share' => 'gated',
			'POST /mvs/v1/media/1/view' => 'self',
			'POST /mvs/v1/media/bulk' => 'self',
			'POST /mvs/v1/messages/1/reactions' => 'gated',
			'POST /mvs/v1/messages/upload' => 'gated',
			'POST /mvs/v1/moderation/1/analyze' => 'self',
			'POST /mvs/v1/moderation/1/approve' => 'self',
			'POST /mvs/v1/moderation/1/reject' => 'self',
			'POST /mvs/v1/tags' => 'self',
			'POST /mvs/v1/tags/1' => 'self',
			'POST /mvs/v1/tags/merge' => 'self',
			'POST /mvs/v1/users/1/block' => 'exempt',
			'POST /mvs/v1/users/1/follow' => 'gated',
			'POST /mvs/v1/users/1/report' => 'exempt',
			'PUT /mvs/v1/albums/1' => 'self',
			'PUT /mvs/v1/albums/1/cover' => 'self',
			'PUT /mvs/v1/albums/1/reorder' => 'self',
			'PUT /mvs/v1/collections/1' => 'self',
			'PUT /mvs/v1/collections/1/rules' => 'self',
			'PUT /mvs/v1/me/profile' => 'self',
			'PUT /mvs/v1/media/1' => 'self',
			'PUT /mvs/v1/media/1/comments/1' => 'exempt',
			'PUT /mvs/v1/tags/1' => 'self',
		);
	}
}
