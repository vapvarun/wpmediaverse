<?php
/**
 * A gallery is listed by its first member the viewer may see.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Repository\MediaRepository;

/**
 * Gallery rule: hide a member only behind a sibling the same listing shows.
 */
class GalleryFrontTileTest extends WP_UnitTestCase {

	private int $owner;

	public function set_up(): void {
		parent::set_up();
		$this->owner = self::factory()->user->create( array( 'role' => 'author' ) );
		MediaRepository::reset_test_cache();
	}

	private function repo(): MediaRepository {
		return Plugin::container()->get( 'media_repository' );
	}

	/**
	 * A three-item gallery; returns ids by position.
	 *
	 * @param array<int,array<string,string>> $rows Column overrides per position.
	 * @return int[]
	 */
	private function gallery( array $rows ): array {
		$group = 'grp_' . wp_generate_password( 8, false );
		$ids   = array();
		foreach ( $rows as $pos => $row ) {
			$id = $this->repo()->insert(
				array_merge(
					array(
						'title'             => 'G' . $pos,
						'post_author'       => $this->owner,
						'media_type'        => 'image',
						'status'            => 'publish',
						'moderation_status' => 'approved',
						'privacy'           => 'public',
					),
					$row
				)
			);
			$this->repo()->set( $id, 'media_group', $group );
			$this->repo()->set( $id, 'group_position', (string) $pos );
			$ids[] = $id;
		}
		MediaRepository::reset_test_cache();
		return $ids;
	}

	/**
	 * Listed ids for a viewer.
	 *
	 * @param int                 $viewer Viewer id.
	 * @param array<string,mixed> $extra  Extra query args.
	 * @return int[]
	 */
	private function listed( int $viewer, array $extra = array() ): array {
		$rows = $this->repo()->query(
			array_merge(
				array(
					'exclude_non_cover_group' => true,
					'privacy'                 => 'explore',
					'viewer_id'               => $viewer,
					'author_id'               => $this->owner,
					'per_page'                => 50,
				),
				$extra
			)
		);
		return array_map( static fn( $r ) => (int) ( is_object( $r ) ? $r->media_id : $r['media_id'] ), (array) $rows );
	}

	public function test_public_gallery_lists_only_its_cover(): void {
		$ids = $this->gallery( array( array(), array(), array() ) );
		$this->assertSame( array( $ids[0] ), $this->listed( 0 ) );
		$this->assertSame( 3, $this->repo()->gallery_tile_counts( array( $ids[0] ) )[ $ids[0] ] );
	}

	public function test_private_cover_hands_the_tile_to_the_first_visible_member(): void {
		$ids = $this->gallery( array( array( 'privacy' => 'private' ), array(), array() ) );

		$this->assertSame( array( $ids[1] ), $this->listed( 0 ), 'A visitor sees the gallery through its first public member.' );
		$this->assertSame( 2, $this->repo()->gallery_tile_counts( array( $ids[1] ) )[ $ids[1] ], 'The new front tile badges the two public items.' );
		$this->assertContains( $ids[0], $this->listed( $this->owner ), 'The owner still sees the private cover.' );
		$this->assertNotContains( $ids[1], $this->listed( $this->owner ), 'For the owner the cover still fronts the gallery.' );
	}

	public function test_owner_tile_counts_every_member_they_can_see(): void {
		$ids = $this->gallery( array( array( 'privacy' => 'private' ), array(), array() ) );

		$this->assertSame( array( $ids[0] ), $this->listed( $this->owner ) );
		$this->assertSame( 3, $this->repo()->gallery_tile_counts( array( $ids[0] ), $this->owner )[ $ids[0] ], 'The owner reaches their public members through the private cover.' );
	}

	public function test_follower_tile_counts_followers_only_members(): void {
		$ids      = $this->gallery( array( array(), array(), array( 'privacy' => 'followers' ) ) );
		$follower = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::container()->get( 'follows' )->follow( $follower, $this->owner );
		MediaRepository::reset_test_cache();

		$this->assertSame( array( $ids[0] ), $this->listed( $follower ) );
		$this->assertSame( 3, $this->repo()->gallery_tile_counts( array( $ids[0] ), $follower )[ $ids[0] ], 'A follower reaches the followers-only member.' );
		$this->assertSame( 2, $this->repo()->gallery_tile_counts( array( $ids[0] ), 0 )[ $ids[0] ], 'A visitor does not.' );
	}

	public function test_cover_held_for_review_does_not_hide_the_gallery(): void {
		$ids = $this->gallery( array( array( 'moderation_status' => 'pending' ), array(), array() ) );
		$this->assertSame( array( $ids[1] ), $this->listed( 0, array( 'moderation_status' => 'approved' ) ) );
	}

	public function test_type_filter_lists_a_member_whose_cover_is_another_type(): void {
		$ids = $this->gallery( array( array( 'media_type' => 'video' ), array( 'media_type' => 'video' ), array( 'media_type' => 'audio' ) ) );
		$this->assertSame( array( $ids[2] ), $this->listed( 0, array( 'media_types' => array( 'audio' ) ) ) );
		$this->assertSame( array( $ids[0] ), $this->listed( 0, array( 'media_types' => array( 'video' ) ) ) );
	}

	public function test_items_without_a_gallery_are_never_hidden(): void {
		$solo = $this->repo()->insert(
			array(
				'title'             => 'Solo',
				'post_author'       => $this->owner,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
			)
		);
		MediaRepository::reset_test_cache();
		$this->assertSame( array( $solo ), $this->listed( 0 ) );
	}

	public function test_rest_feed_uses_the_same_rule(): void {
		$ids = $this->gallery( array( array( 'privacy' => 'private' ), array(), array() ) );
		do_action( 'rest_api_init' );
		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'GET', '/mvs/v1/media' );
		$request->set_param( 'group_covers', 1 );
		$request->set_param( 'author', $this->owner );
		$listed = array_map( static fn( $i ) => (int) $i['id'], (array) rest_do_request( $request )->get_data() );
		$this->assertSame( array( $ids[1] ), $listed );
	}
}
