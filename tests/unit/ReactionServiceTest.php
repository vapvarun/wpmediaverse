<?php
/**
 * Test ReactionService.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Social\ReactionService;
use WPMediaVerse\Services\MediaMeta;

class ReactionServiceTest extends WP_UnitTestCase {

	private ReactionService $service;
	private int $user_id;
	private int $media_id;

	public function set_up(): void {
		parent::set_up();

		$this->service  = new ReactionService();
		$this->user_id  = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->media_id = MediaMeta::insert(
			array(
				'title'       => 'Test Photo',
				'post_author' => $this->user_id,
				'media_type'  => 'image',
			)
		);

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mvs_media_stats',
			array(
				'media_id'  => $this->media_id,
				'reactions' => 0,
			)
		);
	}

	/**
	 * Adding a reaction when none exists returns action=added.
	 */
	public function test_toggle_add_reaction(): void {
		$result = $this->service->toggle( $this->media_id, $this->user_id, 'like' );

		$this->assertSame( 'added', $result['action'] );
		$this->assertSame( 'like', $result['reaction_type'] );
	}

	/**
	 * Toggling the same reaction type removes it.
	 */
	public function test_toggle_remove_same_type(): void {
		$this->service->toggle( $this->media_id, $this->user_id, 'like' );
		$result = $this->service->toggle( $this->media_id, $this->user_id, 'like' );

		$this->assertSame( 'removed', $result['action'] );
		$this->assertNull( $result['reaction_type'] );
	}

	/**
	 * Toggling a different reaction type updates it.
	 */
	public function test_toggle_update_different_type(): void {
		$this->service->toggle( $this->media_id, $this->user_id, 'like' );
		$result = $this->service->toggle( $this->media_id, $this->user_id, 'love' );

		$this->assertSame( 'updated', $result['action'] );
		$this->assertSame( 'love', $result['reaction_type'] );
	}

	/**
	 * get_user_reaction returns the current reaction type.
	 */
	public function test_get_user_reaction(): void {
		$this->service->toggle( $this->media_id, $this->user_id, 'haha' );

		$this->assertSame( 'haha', $this->service->get_user_reaction( $this->media_id, $this->user_id ) );
	}

	/**
	 * get_user_reaction returns null when the user has no reaction.
	 */
	public function test_get_user_reaction_none(): void {
		$this->assertNull( $this->service->get_user_reaction( $this->media_id, $this->user_id ) );
	}

	/**
	 * get_counts returns per-type breakdown for multiple reactions.
	 */
	public function test_get_counts(): void {
		$user2 = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user3 = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->service->toggle( $this->media_id, $this->user_id, 'like' );
		$this->service->toggle( $this->media_id, $user2, 'like' );
		$this->service->toggle( $this->media_id, $user3, 'love' );

		$counts = $this->service->get_counts( $this->media_id );

		$this->assertSame( 2, $counts['like'] );
		$this->assertSame( 1, $counts['love'] );
		$this->assertSame( 0, $counts['haha'] );
		$this->assertSame( 0, $counts['wow'] );
		$this->assertSame( 0, $counts['sad'] );
		$this->assertSame( 0, $counts['angry'] );
	}

	/**
	 * get_total returns the total reaction count.
	 */
	public function test_get_total(): void {
		$user2 = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->service->toggle( $this->media_id, $this->user_id, 'like' );
		$this->service->toggle( $this->media_id, $user2, 'wow' );

		$this->assertSame( 2, $this->service->get_total( $this->media_id ) );
	}

	/**
	 * remove() returns true when a reaction exists.
	 */
	public function test_remove(): void {
		$this->service->toggle( $this->media_id, $this->user_id, 'sad' );

		$this->assertTrue( $this->service->remove( $this->media_id, $this->user_id ) );
		$this->assertNull( $this->service->get_user_reaction( $this->media_id, $this->user_id ) );
	}

	/**
	 * remove() returns false when no reaction exists.
	 */
	public function test_remove_nonexistent(): void {
		$this->assertFalse( $this->service->remove( $this->media_id, $this->user_id ) );
	}

	/**
	 * Adding a reaction syncs the count to mvs_media_stats.
	 */
	public function test_stats_sync_on_add(): void {
		$this->service->toggle( $this->media_id, $this->user_id, 'like' );

		global $wpdb;
		$reactions = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT reactions FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
				$this->media_id
			)
		);

		$this->assertSame( 1, $reactions );
	}

	/**
	 * Removing a reaction decrements the stats count.
	 */
	public function test_stats_sync_on_remove(): void {
		// Add two reactions from different users so we can verify decrement.
		$user2 = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->service->toggle( $this->media_id, $this->user_id, 'like' );
		$this->service->toggle( $this->media_id, $user2, 'love' );

		// Remove one reaction (toggle same type).
		$this->service->toggle( $this->media_id, $this->user_id, 'like' );

		global $wpdb;
		$reactions = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT reactions FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
				$this->media_id
			)
		);

		$this->assertSame( 1, $reactions );
	}
}
