<?php
/**
 * A reply only ever previews a message from its own conversation.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10393041312.
 */
class MessageReplyScopeTest extends WP_UnitTestCase {

	public function test_reply_cannot_reference_another_conversation(): void {
		global $wpdb;

		$a = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$b = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$c = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$service = Plugin::container()->get( 'messaging' );
		$ab      = (int) $service->find_or_create_conversation( $a, $b )['conversation_id'];
		$bc      = (int) $service->find_or_create_conversation( $b, $c )['conversation_id'];

		$other = $service->send_message( $ab, $a, array( 'content' => 'between a and b' ) );
		$own   = $service->send_message( $bc, $c, array( 'content' => 'between b and c' ) );

		// C replies in their own conversation, pointing at A and B's message.
		$cross = $service->send_message( $bc, $c, array( 'content' => 'reply', 'parent_id' => $other['message_id'] ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT parent_id FROM {$wpdb->prefix}mvs_messages WHERE id = %d", $cross['message_id'] ) ) );

		// A row stored that way before the fix still shows no preview.
		$wpdb->update( $wpdb->prefix . 'mvs_messages', array( 'parent_id' => $other['message_id'] ), array( 'id' => $cross['message_id'] ) );
		$this->assertNull( $service->get_message_preview( (int) $other['message_id'], $bc ) );

		// A genuine reply in the same conversation keeps its preview.
		$reply = $service->send_message( $bc, $b, array( 'content' => 'reply', 'parent_id' => $own['message_id'] ) );
		$this->assertSame( (int) $own['message_id'], (int) $wpdb->get_var( $wpdb->prepare( "SELECT parent_id FROM {$wpdb->prefix}mvs_messages WHERE id = %d", $reply['message_id'] ) ) );
		$this->assertSame( 'between b and c', $service->get_message_preview( (int) $own['message_id'], $bc )['content'] );
	}
}
