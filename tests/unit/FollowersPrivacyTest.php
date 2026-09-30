<?php
/**
 * A followers-only item is visible to the owner's followers, and only them.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10350019949.
 *
 * PrivacyService had no `followers` case, so the level fell through to the
 * default deny and hid the item from the owner's own followers.
 */
class FollowersPrivacyTest extends WP_UnitTestCase {

	private int $owner;
	private int $follower;
	private int $stranger;
	private int $media_id;

	public function set_up(): void {
		parent::set_up();

		$this->owner    = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->follower = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->media_id = (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Followers Only',
				'post_author' => $this->owner,
				'privacy'     => 'followers',
			)
		);

		Plugin::container()->get( 'follows' )->follow( $this->follower, $this->owner );
	}

	private function can_view( int $user_id ): bool {
		$privacy = Plugin::container()->get( 'privacy' );
		$privacy->flush_cache();

		return $privacy->can_view( $this->media_id, $user_id );
	}

	public function test_a_follower_can_view(): void {
		$this->assertTrue( $this->can_view( $this->follower ) );
	}

	public function test_the_owner_can_view(): void {
		$this->assertTrue( $this->can_view( $this->owner ) );
	}

	public function test_a_stranger_and_a_visitor_cannot_view(): void {
		$this->assertFalse( $this->can_view( $this->stranger ) );
		$this->assertFalse( $this->can_view( 0 ) );
	}

	public function test_unfollowing_removes_access(): void {
		Plugin::container()->get( 'follows' )->unfollow( $this->follower, $this->owner );

		$this->assertFalse( $this->can_view( $this->follower ) );
	}
}
