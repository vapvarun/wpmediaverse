<?php
/**
 * Deleting a media comment removes its replies, as on social networks.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Deleting a media comment removes its replies, as on social networks.
 */
class CommentRepliesDeleteTest extends WP_UnitTestCase {

	public function test_thread_goes_with_the_comment_unless_filtered(): void {
		$owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$media = Plugin::container()->get( 'media_repository' )->insert( array( 'title' => 'Talked about', 'post_author' => $owner, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'public' ) );
		$svc   = Plugin::container()->get( 'comments' );

		$top    = (int) $svc->add( $media, $owner, 'Top' );
		$reply  = (int) $svc->add( $media, $other, 'Reply', $top );
		$nested = (int) $svc->add( $media, $owner, 'Nested', $reply );

		$this->assertTrue( $svc->delete( $top, $owner ) );
		$this->assertNull( get_comment( $reply ), 'The reply goes with its comment.' );
		$this->assertNull( get_comment( $nested ), 'So does a reply to the reply.' );

		add_filter( 'mvs_comment_delete_replies', '__return_false' );
		$top2   = (int) $svc->add( $media, $owner, 'Top two' );
		$reply2 = (int) $svc->add( $media, $other, 'Reply two', $top2 );
		$svc->delete( $top2, $owner );
		$this->assertNotNull( get_comment( $reply2 ), 'The filter keeps replies.' );
		remove_filter( 'mvs_comment_delete_replies', '__return_false' );
	}
}
