<?php
/**
 * The community-wide REST privacy gate.
 *
 * MediaVerse's mvs/v1 surface must become login-only when the host community is
 * marked private — driven by filters, so BuddyNext reuses its one existing
 * setting and standalone MediaVerse is unaffected.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WPMediaVerse\REST\CommunityPrivacyGate;

class CommunityPrivacyGateTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'mvs_rest_require_auth' );
		remove_all_filters( 'mvs_rest_can_access' );
		remove_all_filters( 'mvs_rest_gated_route_prefixes' );
		parent::tear_down();
	}

	/**
	 * Default-open: with no host community driving it, the gate never fires.
	 *
	 * This is the standalone-MediaVerse guarantee — public routes stay public.
	 *
	 * @return void
	 */
	public function test_default_open_when_no_community_sets_the_filter(): void {
		wp_set_current_user( 0 );
		$result = CommunityPrivacyGate::gate( null, null, new WP_REST_Request( 'GET', '/mvs/v1/media' ) );
		$this->assertNull( $result, 'Without mvs_rest_require_auth set, a guest passes as before.' );
	}

	/**
	 * Private + logged out → 401 on mvs/v1.
	 *
	 * @return void
	 */
	public function test_private_blocks_guest_on_mvs_routes(): void {
		add_filter( 'mvs_rest_require_auth', '__return_true' );
		wp_set_current_user( 0 );

		$result = CommunityPrivacyGate::gate( null, null, new WP_REST_Request( 'GET', '/mvs/v1/media' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mvs_community_private', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Private + logged in → passes. Private mode must not lock members out.
	 *
	 * @return void
	 */
	public function test_private_allows_logged_in_member(): void {
		add_filter( 'mvs_rest_require_auth', '__return_true' );
		wp_set_current_user( self::factory()->user->create() );

		$result = CommunityPrivacyGate::gate( null, null, new WP_REST_Request( 'GET', '/mvs/v1/media' ) );

		$this->assertNull( $result, 'A logged-in member is served normally under private mode.' );
	}

	/**
	 * The can-access filter can refine "logged in" — e.g. a membership plugin
	 * blocking a logged-in non-member.
	 *
	 * @return void
	 */
	public function test_can_access_filter_can_block_a_logged_in_non_member(): void {
		add_filter( 'mvs_rest_require_auth', '__return_true' );
		add_filter( 'mvs_rest_can_access', '__return_false' );
		wp_set_current_user( self::factory()->user->create() );

		$result = CommunityPrivacyGate::gate( null, null, new WP_REST_Request( 'GET', '/mvs/v1/media' ) );

		$this->assertInstanceOf( \WP_Error::class, $result, 'A logged-in non-member is blocked when the membership filter says so.' );
	}

	/**
	 * The media /serve route is under mvs/v1, so it is gated too — no media file
	 * leaks logged-out on a private community.
	 *
	 * @return void
	 */
	public function test_media_serve_route_is_gated(): void {
		add_filter( 'mvs_rest_require_auth', '__return_true' );
		wp_set_current_user( 0 );

		$result = CommunityPrivacyGate::gate( null, null, new WP_REST_Request( 'GET', '/mvs/v1/serve' ) );

		$this->assertInstanceOf( \WP_Error::class, $result, 'Media serving must not bypass the private gate.' );
	}

	/**
	 * Only mvs/v1 is touched — never WP core or another plugin's namespace.
	 *
	 * @return void
	 */
	public function test_non_mvs_namespaces_are_never_touched(): void {
		add_filter( 'mvs_rest_require_auth', '__return_true' );
		wp_set_current_user( 0 );

		foreach ( array( '/wp/v2/posts', '/buddynext/v1/members', '/' ) as $route ) {
			$result = CommunityPrivacyGate::gate( null, null, new WP_REST_Request( 'GET', $route ) );
			$this->assertNull( $result, "{$route} must be left to its own namespace's gate." );
		}
	}

	/**
	 * A namespace added via mvs_rest_gated_route_prefixes is gated too — this is
	 * the seam Pro uses to put /mvs-pro/v1/ behind the same gate.
	 *
	 * @return void
	 */
	public function test_prefix_filter_extends_the_gate_to_added_namespaces(): void {
		add_filter( 'mvs_rest_require_auth', '__return_true' );
		add_filter(
			'mvs_rest_gated_route_prefixes',
			static function ( array $prefixes ): array {
				$prefixes[] = '/mvs-pro/v1/';
				return $prefixes;
			}
		);
		wp_set_current_user( 0 );

		$result = CommunityPrivacyGate::gate( null, null, new WP_REST_Request( 'GET', '/mvs-pro/v1/tournaments/1/bracket' ) );
		$this->assertInstanceOf( \WP_Error::class, $result, 'An added prefix (Pro namespace) is gated for guests.' );
		$this->assertSame( 'mvs_community_private', $result->get_error_code() );

		// The default Free namespace stays gated alongside the added one.
		$free = CommunityPrivacyGate::gate( null, null, new WP_REST_Request( 'GET', '/mvs/v1/media' ) );
		$this->assertInstanceOf( \WP_Error::class, $free );
	}

	/**
	 * A prior short-circuit result is respected — the gate never overrides another
	 * pre-dispatch decision.
	 *
	 * @return void
	 */
	public function test_existing_result_is_passed_through(): void {
		add_filter( 'mvs_rest_require_auth', '__return_true' );
		wp_set_current_user( 0 );

		$prior  = new \WP_Error( 'something_else', 'x' );
		$result = CommunityPrivacyGate::gate( $prior, null, new WP_REST_Request( 'GET', '/mvs/v1/media' ) );

		$this->assertSame( $prior, $result );
	}
}
