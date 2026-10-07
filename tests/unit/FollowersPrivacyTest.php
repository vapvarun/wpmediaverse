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

	/**
	 * The level can be chosen and saved, not only read: it is in the one list of
	 * choosable levels, the accepted levels, and the tightening order, and a
	 * REST save takes it (Basecamp 10374860312: offered by Pro, refused here).
	 */
	public function test_the_owner_can_choose_and_save_the_level(): void {
		$this->assertArrayHasKey( 'followers', \WPMediaVerse\Core\TemplateHelpers::privacy_choices() );
		$this->assertContains( 'followers', \WPMediaVerse\Services\PrivacyService::supported_levels() );
		$repo = \WPMediaVerse\Repository\MediaRepository::class;
		$this->assertGreaterThan( $repo::privacy_rank( 'members' ), $repo::privacy_rank( 'followers' ) );
		$this->assertLessThan( $repo::privacy_rank( 'private' ), $repo::privacy_rank( 'followers' ) );

		Plugin::container()->get( 'media_repository' )->set( $this->media_id, 'privacy', 'public' );
		wp_set_current_user( $this->owner );
		$request = new \WP_REST_Request( 'PUT', '/mvs/v1/media/' . $this->media_id );
		$request->set_body_params( array( 'privacy' => 'followers' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'followers', $response->get_data()['privacy'] );
	}

	/**
	 * Every level a picker offers is one a save accepts.
	 */
	public function test_every_choosable_level_is_an_accepted_level(): void {
		$this->assertSame(
			array(),
			array_diff( array_keys( \WPMediaVerse\Core\TemplateHelpers::privacy_choices() ), \WPMediaVerse\Services\PrivacyService::supported_levels() )
		);
	}
}
