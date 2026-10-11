<?php
/**
 * Following again after an unfollow does not notify twice.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10393276031 (repeat follow notifications).
 */
class FollowNotifyOnceTest extends WP_UnitTestCase {

	public function test_refollow_does_not_notify_again(): void {
		global $wpdb;
		$a = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$b = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$follows = Plugin::container()->get( 'follows' );
		$follows->follow( $a, $b );
		$follows->unfollow( $a, $b );
		$follows->follow( $a, $b );

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_notifications WHERE user_id = %d AND actor_id = %d AND type = 'new_follower'", $b, $a ) );
		$this->assertSame( 1, $count );
	}

	public function test_refavorite_does_not_notify_again(): void {
		global $wpdb;
		$owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$fan   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$media = Plugin::container()->get( 'media_repository' )->insert( array( 'title' => 'Fav probe', 'post_author' => $owner, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'public' ) );

		$notes = Plugin::container()->get( 'notifications' );
		$notes->on_favorite( $media, $fan );
		$notes->on_favorite( $media, $fan );

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_notifications WHERE user_id = %d AND actor_id = %d AND type = 'media_favorite'", $owner, $fan ) );
		$this->assertSame( 1, $count );
	}
}
