<?php
/**
 * Security regressions found in the 2.5.1 release cycle.
 *
 * Both were found by the release security gate (Gate G) during the 2.5.1 cycle,
 * and both had existed for several releases:
 *
 * 1. GET /media/{id}/group answered for ANY id, to ANYONE. The route is public
 *    so a public gallery opens for a signed-out visitor, but the handler never
 *    asked can_view(), so walking the id space returned the title, description,
 *    owner, filename, tags and stats of every private and members-only item.
 * 2. MVS_CSS interpolated block CSS units into a <style> element with no
 *    allowlist, so an author-controlled "px} </style><script>..." executed for
 *    every visitor of a page using the block.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;

class SecurityRegressionTest extends WP_UnitTestCase {

	/** @var int */
	private int $owner;

	/** @var int */
	private int $other;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'mvs_media_index' ) ) ) {
			( new \WPMediaVerse\Core\Migrator() )->run();
		}

		$this->owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, fired to register routes in the test bootstrap.
		do_action( 'rest_api_init' );
	}

	private function repo() {
		return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
	}

	private function make( int $author, string $privacy ): int {
		return (int) $this->repo()->insert(
			array(
				'title'             => 'Group probe ' . $privacy,
				'post_author'       => $author,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => $privacy,
				'file_path'         => '2026/09/group-probe.jpg',
				'file_type'         => 'image/jpeg',
				'slug'              => 'group-probe-' . wp_generate_password( 10, false, false ),
			)
		);
	}

	private function group_request( int $id, int $viewer ) {
		wp_set_current_user( $viewer );

		return rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/media/' . $id . '/group' ) );
	}

	public function test_private_media_is_not_disclosed_to_anonymous_callers(): void {
		$id = $this->make( $this->owner, 'private' );

		$res = $this->group_request( $id, 0 );

		$this->assertSame( 404, $res->get_status(), 'Anonymous caller read a private item through /group.' );
		$this->assertStringNotContainsString( 'Group probe', wp_json_encode( $res->get_data() ) );
	}

	public function test_private_media_is_not_disclosed_to_another_member(): void {
		$id = $this->make( $this->owner, 'private' );

		$this->assertSame( 404, $this->group_request( $id, $this->other )->get_status() );
	}

	public function test_members_only_media_is_not_disclosed_to_anonymous_callers(): void {
		$id = $this->make( $this->owner, 'members' );

		$this->assertSame( 404, $this->group_request( $id, 0 )->get_status() );
	}

	public function test_the_owner_and_public_items_still_work(): void {
		$private = $this->make( $this->owner, 'private' );
		$public  = $this->make( $this->owner, 'public' );

		$this->assertSame( 200, $this->group_request( $private, $this->owner )->get_status() );
		$this->assertSame( 200, $this->group_request( $public, 0 )->get_status() );
	}

	public function test_block_css_units_cannot_break_out_of_the_style_element(): void {
		\WPMediaVerse\Blocks\MVS_CSS::add(
			'probe',
			array(
				'padding'      => array(
					'top'    => 1,
					'right'  => 1,
					'bottom' => 1,
					'left'   => 1,
				),
				'fontSize'     => 12,
				'paddingUnit'  => 'px} </style><script>alert(1)</script><style>x{',
				'fontSizeUnit' => 'px;} body{display:none} x{',
			)
		);

		ob_start();
		\WPMediaVerse\Blocks\MVS_CSS::output();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsStringIgnoringCase( '<script', $html );
		$this->assertSame( 1, substr_count( strtolower( $html ), '</style>' ) );
		$this->assertStringContainsString( 'padding: 1px 1px 1px 1px;', $html );
	}

	public function test_a_legitimate_unit_still_renders(): void {
		\WPMediaVerse\Blocks\MVS_CSS::add(
			'probe-legit',
			array(
				'fontSize'     => 2,
				'fontSizeUnit' => 'rem',
			)
		);

		ob_start();
		\WPMediaVerse\Blocks\MVS_CSS::output();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'font-size: 2rem;', $html );
	}

	/**
	 * A viewable album does not make its contents viewable.
	 *
	 * Found by the route-authority guard on its first run: a public album can
	 * hold a private item (add one, or flip a private album public - the
	 * privacy clamp only tightens), and every member was formatted and
	 * returned regardless.
	 */
	public function test_a_public_album_does_not_disclose_its_private_items(): void {
		$albums = \WPMediaVerse\Core\Plugin::container()->get( 'albums' );

		$public_media  = $this->make( $this->owner, 'public' );
		$private_media = $this->make( $this->owner, 'private' );

		$album = (int) $albums->create( $this->owner, array( 'title' => 'Holiday', 'privacy' => 'public' ) );
		$albums->add_items( $album, array( $public_media, $private_media ) );

		wp_set_current_user( 0 );

		$items = rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/albums/' . $album . '/items' ) );
		$body  = (string) wp_json_encode( $items->get_data() );

		$this->assertSame( 200, $items->get_status(), 'The public album itself should still open.' );
		$this->assertStringNotContainsString( 'Group probe private', $body, 'A private item leaked through the album items route.' );
		$this->assertCount( 1, (array) $items->get_data() );
		$this->assertSame( '1', (string) ( $items->get_headers()['X-WP-Total'] ?? '' ), 'The total counted items the caller cannot see.' );

		$single = rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/albums/' . $album ) );
		$data   = (array) $single->get_data();

		$this->assertSame( 1, (int) ( $data['media_count'] ?? 0 ), 'media_count counted a hidden item.' );
		$this->assertNotContains( $private_media, (array) ( $data['items'] ?? array() ), 'The private item id was enumerable.' );

		wp_set_current_user( $this->owner );
		$owner_items = rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/albums/' . $album . '/items' ) );
		$this->assertCount( 2, (array) $owner_items->get_data(), 'The owner should still see their own private item.' );
	}
}
