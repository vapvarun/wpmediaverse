<?php
/**
 * Read notifications past the retention window are pruned; unread ones stay.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\ViewRetentionService;

/**
 * Basecamp 10393276031 (notifications never pruned).
 */
class NotificationRetentionTest extends WP_UnitTestCase {

	public function test_old_read_notifications_go_unread_and_recent_stay(): void {
		global $wpdb;
		$t   = $wpdb->prefix . 'mvs_notifications';
		$old = gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS );
		$new = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$rows = array(
			'old_read'   => array( 'created_at' => $old, 'read_at' => $old ),
			'old_unread' => array( 'created_at' => $old, 'read_at' => null ),
			'new_read'   => array( 'created_at' => $new, 'read_at' => $new ),
		);
		$ids = array();
		foreach ( $rows as $key => $times ) {
			$wpdb->insert( $t, array_merge( array( 'user_id' => 1, 'type' => 'media_reaction', 'actor_id' => 2, 'media_id' => 0 ), $times ) );
			$ids[ $key ] = (int) $wpdb->insert_id;
		}

		ViewRetentionService::purge_notifications();

		$left = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$t}" ) ); // phpcs:ignore
		$this->assertNotContains( $ids['old_read'], $left );
		$this->assertContains( $ids['old_unread'], $left );
		$this->assertContains( $ids['new_read'], $left );
	}
}
