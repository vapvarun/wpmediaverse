<?php
/**
 * A deleted comment takes its notifications with it.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * NotificationService::on_comment_deleted().
 */
class CommentNotificationWithdrawTest extends WP_UnitTestCase {

	public function test_deleting_a_comment_removes_its_notifications(): void {
		global $wpdb;
		$owner    = self::factory()->user->create();
		$other    = self::factory()->user->create();
		$media_id = Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'             => 'N',
				'post_author'       => $owner,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
			)
		);
		$comments = Plugin::container()->get( 'comments' );
		$parent   = (int) $comments->add( $media_id, $other, 'Nice one' );
		$reply    = (int) $comments->add( $media_id, $other, 'And again', $parent );

		$count = static fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_notifications WHERE media_id = %d AND comment_id IN ( %d, %d )", $media_id, $parent, $reply ) );
		$this->assertGreaterThan( 0, $count(), 'The owner was told about the comments.' );

		$comments->delete( $parent, $other );

		$this->assertSame( 0, $count(), 'The comment and its reply took their notifications with them.' );
	}
}
