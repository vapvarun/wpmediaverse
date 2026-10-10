<?php
/**
 * GET /mvs/v1/media?slug= answers like the id route for an item the viewer may not see.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10393041290: the slug branch of get_items returned a private item's
 * title, owner and file name to anyone, while /media/{id} answered 404.
 */
class SlugLookupPrivacyTest extends WP_UnitTestCase {

	private int $owner;
	private int $stranger;
	private int $media_id;

	public function set_up(): void {
		parent::set_up();

		// Subscribers: moderate_mvs_media would pass the gate on every path.
		$this->owner    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->media_id = Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Slug probe private',
				'slug'        => 'slug-probe-private',
				'post_author' => $this->owner,
				'status'      => 'publish',
				'media_type'  => 'image',
				'privacy'     => 'private',
			)
		);
		Plugin::container()->get( 'privacy' )->flush_cache();
	}

	/**
	 * Ids returned by a slug lookup as the given user (0 = visitor).
	 *
	 * @param int $user_id Viewer.
	 * @return int[]
	 */
	private function lookup_as( int $user_id ): array {
		wp_set_current_user( $user_id );
		Plugin::container()->get( 'privacy' )->flush_cache();

		$request = new WP_REST_Request( 'GET', '/mvs/v1/media' );
		$request->set_query_params( array( 'slug' => 'slug-probe-private' ) );
		$data = rest_do_request( $request )->get_data();

		return array_map( static fn( $item ) => (int) ( $item['id'] ?? 0 ), (array) $data );
	}

	public function test_visitor_and_stranger_get_nothing_by_slug(): void {
		$this->assertSame( array(), $this->lookup_as( 0 ), 'A visitor read a private item by its slug.' );
		$this->assertSame( array(), $this->lookup_as( $this->stranger ), 'Another member read a private item by its slug.' );
	}

	public function test_owner_still_finds_their_item_by_slug(): void {
		$this->assertSame( array( $this->media_id ), $this->lookup_as( $this->owner ) );
	}
}
