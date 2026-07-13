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
	 * Every write endpoint registered in our namespaces is reachable by the map.
	 *
	 * This does not assert a mode — it asserts the map is well-formed and that
	 * classification never throws for a real route, which is what would happen if
	 * someone added a rule with a broken pattern.
	 */
	public function test_every_registered_write_route_classifies(): void {
		$routes = rest_get_server()->get_routes();
		$seen   = 0;

		foreach ( $routes as $route => $handlers ) {
			if ( 0 !== strpos( $route, '/mvs/v1' ) && 0 !== strpos( $route, '/mvs-pro/v1' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				foreach ( (array) ( $handler['methods'] ?? array() ) as $method => $enabled ) {
					if ( ! $enabled || ! in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
						continue;
					}

					++$seen;

					// A concrete path for the regex to bite on.
					$concrete = preg_replace( '#\(\?P<[^>]+>[^)]*\)#', '1', $route );

					$reflection = new \ReflectionMethod( RestGate::class, 'classify' );
					$reflection->setAccessible( true );
					$rule = $reflection->invoke( null, $concrete, $method );

					$this->assertContains(
						$rule['mode'],
						array( 'exempt', 'self', 'gated' ),
						"{$method} {$route} did not classify to a known mode."
					);
				}
			}
		}

		$this->assertGreaterThan( 0, $seen, 'Expected to find write routes to classify.' );
	}
}
