<?php
/**
 * 2.6.0 big-site fixes on shared paths.
 *
 * - Album items cost the same number of queries at 10 items and at 30: the
 *   per-tile stats, author and viewer-state reads are primed in one pass.
 * - Block lookups are one query per viewer, and blocking or unblocking in the
 *   same request is seen at once.
 * - A privacy change is seen by can_view() in the same request.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WPMediaVerse\Core\Plugin;

class BigSiteSharedFixesTest extends WP_UnitTestCase {

	private int $owner;

	public function set_up(): void {
		parent::set_up();
		( new \WPMediaVerse\Core\Migrator() )->run();
		$this->owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		do_action( 'rest_api_init' );
	}

	private function media( string $privacy = 'public' ): int {
		return (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'             => 'Scale probe',
				'post_author'       => $this->owner,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => $privacy,
				'file_path'         => '2026/09/probe.jpg',
				'file_type'         => 'image/jpeg',
				'slug'              => 'scale-' . wp_generate_password( 8, false, false ),
			)
		);
	}

	private function album_items_queries( int $count ): int {
		global $wpdb;
		$albums = Plugin::container()->get( 'albums' );
		$album  = (int) $albums->create( $this->owner, array( 'title' => 'Scale ' . $count ) );
		$ids    = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = $this->media();
		}
		$albums->add_items( $album, $ids );
		\WPMediaVerse\Repository\MediaRepository::reset_test_cache();

		$req = new WP_REST_Request( 'GET', '/mvs/v1/albums/' . $album . '/items' );
		$req->set_param( 'per_page', 50 );
		$before = $wpdb->num_queries;
		$res    = rest_do_request( $req );
		$this->assertSame( $count, count( $res->get_data() ) );

		return $wpdb->num_queries - $before;
	}

	public function test_album_items_query_count_does_not_grow_per_item(): void {
		wp_set_current_user( $this->owner );
		$small = $this->album_items_queries( 10 );
		$large = $this->album_items_queries( 30 );

		$this->assertLessThan( 10, $large - $small, "Album items ran per-item queries: {$small} at 10 items, {$large} at 30." );
	}

	public function test_block_cache_sees_block_and_unblock_in_the_same_request(): void {
		$reports = Plugin::container()->get( 'reports' );
		$other   = self::factory()->user->create();

		$this->assertFalse( $reports->is_blocked_either_way( $this->owner, $other ) );
		$reports->block_user( $this->owner, $other );
		$this->assertTrue( $reports->is_blocked( $this->owner, $other ) );
		$this->assertTrue( $reports->is_blocked_either_way( $other, $this->owner ) );
		$reports->unblock_user( $this->owner, $other );
		$this->assertFalse( $reports->is_blocked_either_way( $this->owner, $other ) );
	}

	public function test_privacy_change_is_seen_in_the_same_request(): void {
		$media   = $this->media( 'public' );
		$privacy = Plugin::container()->get( 'privacy' );

		$this->assertTrue( $privacy->can_view( $media, 0 ) );
		Plugin::container()->get( 'media_repository' )->set( $media, 'privacy', 'private' );
		$this->assertFalse( $privacy->can_view( $media, 0 ), 'A visitor could still open an item made private in the same request.' );
	}
}
