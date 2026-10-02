<?php
/**
 * Album and collection lists show only what the viewer may see, and the core
 * wp/v2 routes that leaked private items are gone (Basecamp 10360879667).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\PrivacyService;

/**
 * @since 2.6.1
 */
class SpaceListPrivacyTest extends WP_UnitTestCase {

	private function space( string $type, int $author, string $privacy ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => $type,
				'post_status' => 'publish',
				'post_author' => $author,
			)
		);
		update_post_meta( $id, '_mvs_privacy', $privacy );
		return $id;
	}

	private function listed( string $type, int $viewer ): array {
		$query = PrivacyService::query_listable_spaces(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
			),
			$viewer
		);
		return array_map( 'intval', $query->posts );
	}

	public function test_each_viewer_lists_only_what_they_may_see(): void {
		$owner  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		foreach ( array( 'mvs_album', 'mvs_collection' ) as $type ) {
			$public  = $this->space( $type, $owner, 'public' );
			$members = $this->space( $type, $owner, 'members' );
			$private = $this->space( $type, $owner, 'private' );
			$all     = array( $public, $members, $private );

			$this->assertSame( array( $public ), array_values( array_intersect( $this->listed( $type, 0 ), $all ) ), "$type: visitor" );
			$this->assertEqualsCanonicalizing( array( $public, $members ), array_intersect( $this->listed( $type, $member ), $all ), "$type: other member" );
			$this->assertEqualsCanonicalizing( $all, array_intersect( $this->listed( $type, $owner ), $all ), "$type: owner" );
			$this->assertEqualsCanonicalizing( $all, array_intersect( $this->listed( $type, $admin ), $all ), "$type: admin" );
		}
	}

	public function test_core_rest_routes_are_not_registered(): void {
		$this->assertFalse( get_post_type_object( 'mvs_album' )->show_in_rest );
		$this->assertFalse( get_post_type_object( 'mvs_collection' )->show_in_rest );
	}
}
