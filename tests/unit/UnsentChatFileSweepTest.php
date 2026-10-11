<?php
/**
 * Chat files uploaded but never sent are deleted by the daily sweep after a day.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Messaging\ChatFiles;
use WPMediaVerse\Repository\MediaRepository;

/**
 * Chat files uploaded but never sent are deleted by the daily sweep after a day.
 */
class UnsentChatFileSweepTest extends WP_UnitTestCase {

	public function test_sweep_deletes_only_old_unsent_chat_files(): void {
		global $wpdb;
		$a    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$b    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$repo = Plugin::container()->get( 'media_repository' );
		$old  = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );

		$unsent = $repo->insert( array( 'title' => 'Removed before send', 'post_author' => $a, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'dm', 'created_at' => $old ) );
		$sent   = $repo->insert( array( 'title' => 'Sent', 'post_author' => $a, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'dm', 'created_at' => $old ) );
		$fresh  = $repo->insert( array( 'title' => 'Being written', 'post_author' => $a, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'dm' ) );

		$messaging = Plugin::container()->get( 'messaging' );
		$conv      = (int) $messaging->find_or_create_conversation( $a, $b )['conversation_id'];
		$wpdb->insert( $wpdb->prefix . 'mvs_messages', array( 'conversation_id' => $conv, 'sender_id' => $a, 'message_type' => 'image', 'media_id' => $sent, 'content' => '', 'created_at' => $old ) );

		ChatFiles::sweep_unsent();
		MediaRepository::reset_test_cache();

		$this->assertFalse( $repo->exists( $unsent ), 'An old upload no message uses is deleted.' );
		$this->assertTrue( $repo->exists( $sent ), 'A file a message uses is never touched.' );
		$this->assertTrue( $repo->exists( $fresh ), 'A file attached in the last day may still be sent.' );
	}
}
