<?php
/**
 * A member's reaction notifies the owner once per item.
 *
 * Basecamp 10392589159: removing a reaction and reacting again added a second
 * "reacted to" to the owner's bell.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

class ReactionNotificationOnceTest extends WP_UnitTestCase {

	private function count_for( int $owner, int $media_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_notifications WHERE user_id = %d AND media_id = %d AND type = 'media_reaction'", $owner, $media_id )
		);
	}

	public function test_react_remove_react_again_notifies_once_and_another_member_still_notifies(): void {
		$owner    = self::factory()->user->create();
		$member   = self::factory()->user->create();
		$other    = self::factory()->user->create();
		$media_id = (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'             => 'Reacted photo',
				'post_author'       => $owner,
				'media_type'        => 'image',
				'privacy'           => 'public',
				'status'            => 'publish',
				'moderation_status' => 'approved',
			)
		);
		$reactions = Plugin::container()->get( 'reactions' );

		$reactions->toggle( $media_id, $member, 'love' );
		$reactions->remove( $media_id, $member );
		$reactions->toggle( $media_id, $member, 'love' );
		$this->assertSame( 1, $this->count_for( $owner, $media_id ), 'One member, one "reacted to".' );

		$reactions->toggle( $media_id, $other, 'like' );
		$this->assertSame( 2, $this->count_for( $owner, $media_id ), 'A different member still notifies.' );
	}
}
