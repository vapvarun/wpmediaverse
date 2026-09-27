<?php
/**
 * No mvs/v1 GET route crashes, whoever calls it.
 *
 * RouteAuthorityTest proves every route declares who may call it. This proves
 * the other half of Gate E (Basecamp 10302674598): calling each GET route as a
 * signed-out visitor and as a plain member never produces a 5xx. A refusal
 * (401/403/404) is a correct answer; a 500 is a crash an app or a scanner will
 * hit first. Path parameters get "1", the same substitution `wp mvs cert` uses.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

/**
 * @since 2.6.0
 */
class RestReachabilityTest extends WP_UnitTestCase {

	/**
	 * Namespace this plugin owns.
	 */
	private const NAMESPACE_PREFIX = '/mvs/v1';

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
	}

	/**
	 * Every GET route under this plugin's namespace, with path params filled.
	 *
	 * @return string[]
	 */
	private function get_routes(): array {
		$out = array();

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( self::NAMESPACE_PREFIX !== $route && 0 !== strpos( $route, self::NAMESPACE_PREFIX . '/' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( ! empty( $handler['methods']['GET'] ) ) {
					$out[] = (string) preg_replace( '/\(\?P<[^>]+>[^)]*\)/', '1', $route );
					break;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * GET every route as one user and collect the ones that answered 5xx.
	 *
	 * @param int $user_id 0 for signed out.
	 * @return string[] "route -> status (code)".
	 */
	private function crashes_as( int $user_id ): array {
		wp_set_current_user( $user_id );
		$bad = array();

		foreach ( $this->get_routes() as $route ) {
			try {
				$response = rest_do_request( new \WP_REST_Request( 'GET', $route ) );
				$status   = (int) $response->get_status();
				$data     = $response->get_data();
				$code     = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
			} catch ( \Throwable $e ) {
				$status = 500;
				$code   = get_class( $e ) . ': ' . $e->getMessage();
			}

			if ( $status >= 500 ) {
				$bad[] = sprintf( '%s -> %d (%s)', $route, $status, $code );
			}
		}

		return $bad;
	}

	public function test_the_namespace_has_get_routes_to_check(): void {
		$this->assertGreaterThan( 20, count( $this->get_routes() ), 'Route discovery found almost nothing; the test would pass vacuously.' );
	}

	public function test_no_get_route_crashes_for_a_signed_out_visitor(): void {
		$this->assertSame( array(), $this->crashes_as( 0 ) );
	}

	public function test_no_get_route_crashes_for_a_member(): void {
		$this->assertSame( array(), $this->crashes_as( self::factory()->user->create( array( 'role' => 'subscriber' ) ) ) );
	}
}
