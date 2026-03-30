<?php
/**
 * Test FollowService user-to-user relationships.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Social\FollowService;

class FollowServiceTest extends WP_UnitTestCase {

	private FollowService $service;
	private int $user_a;
	private int $user_b;
	private int $user_c;
	private int $user_d;

	public function set_up(): void {
		parent::set_up();

		$this->service = new FollowService();
		$this->user_a  = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->user_b  = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->user_c  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->user_d  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Following a valid user creates a relationship and returns row ID.
	 */
	public function test_follow_creates_relationship(): void {
		$result = $this->service->follow( $this->user_a, $this->user_b );

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}

	/**
	 * A user cannot follow themselves.
	 */
	public function test_follow_self_returns_false(): void {
		$result = $this->service->follow( $this->user_a, $this->user_a );

		$this->assertFalse( $result );
	}

	/**
	 * Following with a zero ID returns false.
	 */
	public function test_follow_zero_id_returns_false(): void {
		$this->assertFalse( $this->service->follow( 0, $this->user_b ) );
		$this->assertFalse( $this->service->follow( $this->user_a, 0 ) );
	}

	/**
	 * Following a non-existent user returns false.
	 */
	public function test_follow_invalid_user_returns_false(): void {
		$result = $this->service->follow( $this->user_a, 999999 );

		$this->assertFalse( $result );
	}

	/**
	 * Following the same user twice is idempotent -- returns true, not an error.
	 */
	public function test_follow_idempotent(): void {
		$first  = $this->service->follow( $this->user_a, $this->user_b );
		$second = $this->service->follow( $this->user_a, $this->user_b );

		$this->assertIsInt( $first );
		$this->assertGreaterThan( 0, $first );
		$this->assertTrue( $second );
	}

	/**
	 * is_following returns true after a follow.
	 */
	public function test_is_following_true(): void {
		$this->service->follow( $this->user_a, $this->user_b );

		$this->assertTrue( $this->service->is_following( $this->user_a, $this->user_b ) );
	}

	/**
	 * is_following returns false when no relationship exists.
	 */
	public function test_is_following_false(): void {
		$this->assertFalse( $this->service->is_following( $this->user_a, $this->user_b ) );
	}

	/**
	 * Unfollow removes the relationship and returns true.
	 */
	public function test_unfollow(): void {
		$this->service->follow( $this->user_a, $this->user_b );

		$this->assertTrue( $this->service->unfollow( $this->user_a, $this->user_b ) );
		$this->assertFalse( $this->service->is_following( $this->user_a, $this->user_b ) );
	}

	/**
	 * Unfollowing when not following returns false.
	 */
	public function test_unfollow_nonexistent(): void {
		$this->assertFalse( $this->service->unfollow( $this->user_a, $this->user_b ) );
	}

	/**
	 * get_followers returns the correct follower list.
	 */
	public function test_get_followers(): void {
		$this->service->follow( $this->user_a, $this->user_b );

		$result = $this->service->get_followers( $this->user_b );

		$this->assertArrayHasKey( 'users', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertContains( $this->user_a, $result['users'] );
	}

	/**
	 * get_following returns the correct following list.
	 */
	public function test_get_following(): void {
		$this->service->follow( $this->user_a, $this->user_b );

		$result = $this->service->get_following( $this->user_a );

		$this->assertArrayHasKey( 'users', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertContains( $this->user_b, $result['users'] );
	}

	/**
	 * get_counts returns accurate follower and following totals.
	 *
	 * Setup: A follows B, A follows C, B follows A.
	 * Expected: get_counts(A) = { followers: 1, following: 2 }.
	 */
	public function test_get_counts(): void {
		$this->service->follow( $this->user_a, $this->user_b );
		$this->service->follow( $this->user_a, $this->user_c );
		$this->service->follow( $this->user_b, $this->user_a );

		$counts = $this->service->get_counts( $this->user_a );

		$this->assertArrayHasKey( 'followers', $counts );
		$this->assertArrayHasKey( 'following', $counts );
		$this->assertSame( 1, $counts['followers'] );
		$this->assertSame( 2, $counts['following'] );
	}

	/**
	 * get_following_ids returns a flat int array of followed user IDs.
	 */
	public function test_get_following_ids(): void {
		$this->service->follow( $this->user_a, $this->user_b );
		$this->service->follow( $this->user_a, $this->user_c );
		$this->service->follow( $this->user_a, $this->user_d );

		$ids = $this->service->get_following_ids( $this->user_a );

		$this->assertIsArray( $ids );
		$this->assertCount( 3, $ids );
		$this->assertContains( $this->user_b, $ids );
		$this->assertContains( $this->user_c, $ids );
		$this->assertContains( $this->user_d, $ids );

		// Verify all values are integers.
		foreach ( $ids as $id ) {
			$this->assertIsInt( $id );
		}
	}

	/**
	 * The mvs_user_followed action fires when a new follow is created.
	 */
	public function test_follow_fires_action(): void {
		$fired = false;

		add_action(
			'mvs_user_followed',
			function ( $follower_id, $following_id ) use ( &$fired ) {
				$fired = true;
				$this->assertSame( $this->user_a, $follower_id );
				$this->assertSame( $this->user_b, $following_id );
			},
			10,
			2
		);

		$this->service->follow( $this->user_a, $this->user_b );

		$this->assertTrue( $fired, 'mvs_user_followed action should have fired.' );
	}

	/**
	 * The mvs_user_unfollowed action fires when an unfollow succeeds.
	 */
	public function test_unfollow_fires_action(): void {
		$this->service->follow( $this->user_a, $this->user_b );

		$fired = false;

		add_action(
			'mvs_user_unfollowed',
			function ( $follower_id, $following_id ) use ( &$fired ) {
				$fired = true;
				$this->assertSame( $this->user_a, $follower_id );
				$this->assertSame( $this->user_b, $following_id );
			},
			10,
			2
		);

		$this->service->unfollow( $this->user_a, $this->user_b );

		$this->assertTrue( $fired, 'mvs_user_unfollowed action should have fired.' );
	}
}
