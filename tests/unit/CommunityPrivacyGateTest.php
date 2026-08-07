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
		remove_all_filters( 'mvs_rest_gate_exempt_route_prefixes' );
		remove_all_filters( 'mvs_community_gated_page' );
		set_query_var( 'mvs_media_archive', '' );
		set_query_var( 'mvs_media_slug', '' );
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
	 * The signed /serve route is EXEMPT from the gate — its credential is the
	 * HMAC signature in the URL, not the cookie session.
	 *
	 * Browsers fetch /serve as a subresource (<img>, <video>) which sends
	 * cookies but can never send X-WP-Nonce, and core anonymizes cookie-authed
	 * REST requests without a nonce. Gating /serve on is_user_logged_in()
	 * therefore 401s every media file for EVERY visitor, members included —
	 * that was the 2.2.0 regression (Basecamp 10180510437). serve_file()
	 * validates the signature and re-checks per-item privacy itself.
	 *
	 * @return void
	 */
	public function test_signed_serve_route_is_exempt_from_the_gate(): void {
		add_filter( 'mvs_rest_require_auth', '__return_true' );
		wp_set_current_user( 0 );

		$result = CommunityPrivacyGate::gate( null, null, new WP_REST_Request( 'GET', '/mvs/v1/serve' ) );

		$this->assertNull( $result, 'The signed serve route authenticates by URL signature; the session gate must not touch it.' );
	}

	/**
	 * The exemption is filterable both ways: a stricter site can re-gate
	 * /serve by removing it, and another plugin can exempt its own
	 * token-authenticated route (Pro does this for its payment webhook).
	 *
	 * @return void
	 */
	public function test_exempt_prefixes_are_filterable(): void {
		add_filter( 'mvs_rest_require_auth', '__return_true' );
		wp_set_current_user( 0 );

		// Removing every exemption puts /serve back behind the gate.
		add_filter( 'mvs_rest_gate_exempt_route_prefixes', '__return_empty_array' );
		$regated = CommunityPrivacyGate::gate( null, null, new WP_REST_Request( 'GET', '/mvs/v1/serve' ) );
		$this->assertInstanceOf( \WP_Error::class, $regated, 'An emptied exemption list re-gates the serve route.' );
		remove_all_filters( 'mvs_rest_gate_exempt_route_prefixes' );

		// A gated namespace can exempt one of its own token-authenticated routes.
		add_filter(
			'mvs_rest_gated_route_prefixes',
			static function ( array $prefixes ): array {
				$prefixes[] = '/mvs-pro/v1/';
				return $prefixes;
			}
		);
		add_filter(
			'mvs_rest_gate_exempt_route_prefixes',
			static function ( array $prefixes ): array {
				$prefixes[] = '/mvs-pro/v1/credits/webhook';
				return $prefixes;
			}
		);
		$webhook = CommunityPrivacyGate::gate( null, null, new WP_REST_Request( 'POST', '/mvs-pro/v1/credits/webhook' ) );
		$this->assertNull( $webhook, 'An HMAC-authenticated webhook exempted via the filter passes the gate.' );

		$still_gated = CommunityPrivacyGate::gate( null, null, new WP_REST_Request( 'GET', '/mvs-pro/v1/tournaments/1/bracket' ) );
		$this->assertInstanceOf( \WP_Error::class, $still_gated, 'The rest of the exempting namespace stays gated.' );
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
	 * PAGE layer: with the gate armed, a guest on an MVS virtual page (the
	 * server-rendered /media/ explore, profiles, single media) must be sent to
	 * login. The REST gate alone is not enough — those pages server-render
	 * tiles with working signed URLs, so without the page gate a logged-out
	 * visitor reads the media of a private community straight from the HTML.
	 *
	 * @return void
	 */
	public function test_page_gate_blocks_guest_on_virtual_routes(): void {
		add_filter( 'mvs_rest_require_auth', '__return_true' );
		wp_set_current_user( 0 );
		set_query_var( 'mvs_media_archive', '1' );

		$this->assertTrue( CommunityPrivacyGate::page_needs_login(), 'A guest on /media/ must be sent to login when the community is private.' );
	}

	/**
	 * PAGE layer: members pass, and with the gate unarmed (standalone
	 * MediaVerse) guests pass too.
	 *
	 * @return void
	 */
	public function test_page_gate_passes_members_and_standalone_guests(): void {
		set_query_var( 'mvs_media_archive', '1' );

		wp_set_current_user( 0 );
		$this->assertFalse( CommunityPrivacyGate::page_needs_login(), 'Standalone MediaVerse: nobody arms the filter, guests browse as before.' );

		add_filter( 'mvs_rest_require_auth', '__return_true' );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertFalse( CommunityPrivacyGate::page_needs_login(), 'A logged-in member browses the private community normally.' );
	}

	/**
	 * PAGE layer: non-MVS requests are never touched, and the page filter is
	 * the seam Pro uses to put its compete pages behind the same gate.
	 *
	 * @return void
	 */
	public function test_page_gate_scope_is_mvs_pages_plus_filter(): void {
		add_filter( 'mvs_rest_require_auth', '__return_true' );
		wp_set_current_user( 0 );

		$this->assertFalse( CommunityPrivacyGate::page_needs_login(), 'An ordinary WP request (no MVS query vars) is not MediaVerse\'s to gate.' );

		add_filter( 'mvs_community_gated_page', '__return_true' );
		$this->assertTrue( CommunityPrivacyGate::page_needs_login(), 'A host/Pro can declare additional pages gated via the filter.' );
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
