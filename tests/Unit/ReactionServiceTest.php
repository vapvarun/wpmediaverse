<?php
/**
 * Tests for ReactionService.
 *
 * @package WPMediaVerse
 */

use WPMediaVerse\Social\ReactionService;

class ReactionServiceTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Reaction service instance.
	 *
	 * @var ReactionService
	 */
	private $service;

	/**
	 * Test media post ID.
	 *
	 * @var int
	 */
	private $media_id;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $user_id;

	protected function setUp(): void {
		parent::setUp();

		$this->service = new ReactionService();
		$this->user_id = 1;

		// Create a test media post.
		$this->media_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Reaction Test Media',
				'post_status' => 'publish',
				'post_author' => $this->user_id,
			)
		);

		// Initialize stats row.
		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'mvs_media_stats',
			array(
				'media_id'   => $this->media_id,
				'views'      => 0,
				'downloads'  => 0,
				'reactions'  => 0,
				'comments'   => 0,
				'shares'     => 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
		);
	}

	protected function tearDown(): void {
		wp_delete_post( $this->media_id, true );

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'mvs_reactions', array( 'media_id' => $this->media_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $this->media_id ), array( '%d' ) );

		parent::tearDown();
	}

	public function test_add_reaction(): void {
		$result = $this->service->toggle( $this->media_id, $this->user_id, 'like' );

		$this->assertEquals( 'added', $result['action'] );
		$this->assertEquals( 'like', $result['reaction_type'] );
	}

	public function test_get_user_reaction(): void {
		$this->service->toggle( $this->media_id, $this->user_id, 'love' );

		$reaction = $this->service->get_user_reaction( $this->media_id, $this->user_id );
		$this->assertEquals( 'love', $reaction );
	}

	public function test_toggle_same_type_removes(): void {
		$this->service->toggle( $this->media_id, $this->user_id, 'like' );
		$result = $this->service->toggle( $this->media_id, $this->user_id, 'like' );

		$this->assertEquals( 'removed', $result['action'] );
		$this->assertNull( $result['reaction_type'] );

		$reaction = $this->service->get_user_reaction( $this->media_id, $this->user_id );
		$this->assertNull( $reaction );
	}

	public function test_toggle_different_type_updates(): void {
		$this->service->toggle( $this->media_id, $this->user_id, 'like' );
		$result = $this->service->toggle( $this->media_id, $this->user_id, 'love' );

		$this->assertEquals( 'updated', $result['action'] );
		$this->assertEquals( 'love', $result['reaction_type'] );

		$reaction = $this->service->get_user_reaction( $this->media_id, $this->user_id );
		$this->assertEquals( 'love', $reaction );
	}

	public function test_get_counts(): void {
		// User 1 likes.
		$this->service->toggle( $this->media_id, 1, 'like' );
		// User 2 loves.
		$this->service->toggle( $this->media_id, 2, 'love' );
		// User 3 likes.
		$this->service->toggle( $this->media_id, 3, 'like' );

		$counts = $this->service->get_counts( $this->media_id );

		$this->assertEquals( 2, $counts['like'] );
		$this->assertEquals( 1, $counts['love'] );
		$this->assertEquals( 0, $counts['haha'] );
	}

	public function test_get_total(): void {
		$this->service->toggle( $this->media_id, 1, 'like' );
		$this->service->toggle( $this->media_id, 2, 'wow' );

		$total = $this->service->get_total( $this->media_id );
		$this->assertEquals( 2, $total );
	}

	public function test_remove(): void {
		$this->service->toggle( $this->media_id, $this->user_id, 'like' );
		$removed = $this->service->remove( $this->media_id, $this->user_id );

		$this->assertTrue( $removed );
		$this->assertNull( $this->service->get_user_reaction( $this->media_id, $this->user_id ) );
	}

	public function test_remove_nonexistent_returns_false(): void {
		$removed = $this->service->remove( $this->media_id, $this->user_id );
		$this->assertFalse( $removed );
	}

	public function test_stats_synced_after_add(): void {
		$this->service->toggle( $this->media_id, 1, 'like' );
		$this->service->toggle( $this->media_id, 2, 'love' );

		global $wpdb;
		$reactions_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT reactions FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
				$this->media_id
			)
		);

		$this->assertEquals( 2, $reactions_count );
	}

	public function test_stats_synced_after_remove(): void {
		$this->service->toggle( $this->media_id, 1, 'like' );
		$this->service->toggle( $this->media_id, 2, 'love' );
		$this->service->toggle( $this->media_id, 1, 'like' ); // Remove user 1's reaction.

		global $wpdb;
		$reactions_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT reactions FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
				$this->media_id
			)
		);

		$this->assertEquals( 1, $reactions_count );
	}

	public function test_no_user_reaction_returns_null(): void {
		$reaction = $this->service->get_user_reaction( $this->media_id, 999 );
		$this->assertNull( $reaction );
	}
}
