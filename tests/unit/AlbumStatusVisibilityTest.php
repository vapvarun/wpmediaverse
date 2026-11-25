<?php
/**
 * An album that is not published is not served, by any read path.
 *
 * Trashing an album to take it down did not remove it from pages that embed it or from the REST API:
 * the access check read the album's privacy and never its post status.
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\PrivacyService;

/**
 * Album status and visibility.
 */
class AlbumStatusVisibilityTest extends WP_UnitTestCase {

	/**
	 * Album owner.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * Another member.
	 *
	 * @var int
	 */
	private int $other;

	/**
	 * A moderator.
	 *
	 * @var int
	 */
	private int $moderator;

	/**
	 * Members.
	 */
	public function set_up(): void {
		parent::set_up();
		( new \WPMediaVerse\Core\Migrator() )->run();
		$this->owner     = self::factory()->user->create();
		$this->other     = self::factory()->user->create();
		$this->moderator = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * A public album in the given status.
	 *
	 * @param string $status Post status.
	 * @return int Album id.
	 */
	private function album( string $status ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'mvs_album',
				'post_author' => $this->owner,
				'post_status' => $status,
			)
		);
		update_post_meta( $id, '_mvs_privacy', 'public' );
		return $id;
	}

	/**
	 * Only a published public album is served to a stranger; the owner and a moderator keep their access.
	 */
	public function test_a_public_album_is_served_only_while_it_is_published(): void {
		$privacy = Plugin::container()->get( 'privacy' );

		$published = $this->album( 'publish' );
		$this->assertTrue( $privacy->can_view( $published, 0, PrivacyService::SPACE_CPT ), 'A published public album is served to a visitor.' );

		foreach ( array( 'draft', 'pending', 'trash' ) as $status ) {
			$id = $this->album( $status );
			$this->assertFalse( $privacy->can_view( $id, 0, PrivacyService::SPACE_CPT ), "A $status album is not served to a visitor." );
			$this->assertFalse( $privacy->can_view( $id, $this->other, PrivacyService::SPACE_CPT ), "A $status album is not served to another member." );
			$this->assertTrue( $privacy->can_view( $id, $this->owner, PrivacyService::SPACE_CPT ), "The owner still reaches their $status album." );
			$this->assertTrue( $privacy->can_view( $id, $this->moderator, PrivacyService::SPACE_CPT ), "A moderator still reaches a $status album." );
		}
	}

	/**
	 * Both REST routes answer 404 for a withdrawn album, and 200 for the same album while it is published.
	 */
	public function test_the_rest_routes_do_not_serve_a_trashed_album(): void {
		$id = $this->album( 'publish' );
		wp_set_current_user( 0 );

		foreach ( array( "/mvs/v1/albums/$id", "/mvs/v1/albums/$id/items" ) as $route ) {
			$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status(), "$route serves it while published." );
		}

		// A trash happens in its own request; the access check remembers its answers for the one it runs in.
		wp_trash_post( $id );
		Plugin::container()->get( 'privacy' )->flush_cache();

		foreach ( array( "/mvs/v1/albums/$id", "/mvs/v1/albums/$id/items" ) as $route ) {
			$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status(), "$route does not serve it once trashed." );
		}
	}
}
