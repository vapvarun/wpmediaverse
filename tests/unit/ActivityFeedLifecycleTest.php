<?php
/**
 * The activity feed does not list taken-down media.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10393276020 item 17 (feed).
 */
class ActivityFeedLifecycleTest extends WP_UnitTestCase {

	/**
	 * Media ids in the feed as a viewer.
	 *
	 * @param int $viewer Viewer.
	 * @return int[]
	 */
	private function feed_media_ids( int $viewer ): array {
		wp_set_current_user( $viewer );
		$feed = Plugin::container()->get( 'activity' )->get_feed( array( 'scope' => 'public', 'per_page' => 50 ) );
		return array_map( static fn( $a ) => (int) ( is_array( $a ) ? $a['media_id'] : $a->media_id ), (array) $feed['activities'] );
	}

	public function test_rejected_media_is_not_in_the_feed_but_its_pending_owner_sees_it(): void {
		$owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$repo  = Plugin::container()->get( 'media_repository' );

		$rejected = $repo->insert( array( 'title' => 'Rejected', 'post_author' => $owner, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'public', 'moderation_status' => 'rejected' ) );
		$pending  = $repo->insert( array( 'title' => 'Pending', 'post_author' => $owner, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'public', 'moderation_status' => 'pending' ) );
		$activity = Plugin::container()->get( 'activity' );
		$activity->record( $owner, 'media_upload', $rejected );
		$activity->record( $owner, 'media_upload', $pending );
		$this->assertNotFalse( $activity->record( $owner, 'media_upload', $repo->insert( array( 'title' => 'Control', 'post_author' => $owner, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'public', 'moderation_status' => 'approved' ) ) ) );

		$seen = $this->feed_media_ids( $other );
		$this->assertNotContains( $rejected, $seen );
		$this->assertNotContains( $pending, $seen );
		$this->assertContains( $pending, $this->feed_media_ids( $owner ), 'An owner still sees their own pending upload.' );
	}
}
