<?php
/**
 * Conversation state a member sees: typing, pin flags, a block.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * MessagingService state seen by the other member.
 */
class MessagingStateTest extends WP_UnitTestCase {

	private int $a;
	private int $b;

	/**
	 * Messaging service.
	 *
	 * @var \WPMediaVerse\Messaging\MessagingService
	 */
	private $messaging;

	public function set_up(): void {
		parent::set_up();
		$this->a         = self::factory()->user->create();
		$this->b         = self::factory()->user->create();
		$this->messaging = Plugin::container()->get( 'messaging' );
	}

	private function conversation(): int {
		return (int) $this->messaging->find_or_create_conversation( $this->a, $this->b )['conversation_id'];
	}

	public function test_sending_ends_the_typing_marker(): void {
		$conv = $this->conversation();
		$this->messaging->set_typing( $conv, $this->a );
		$this->assertNotEmpty( $this->messaging->get_typing_users( $conv, $this->b ), 'Typing shows while A types.' );

		$this->messaging->send_message( $conv, $this->a, array( 'content' => 'hello' ) );

		$this->assertEmpty( $this->messaging->get_typing_users( $conv, $this->b ), 'The sent message ends the typing marker.' );
	}

	public function test_member_flags_are_booleans(): void {
		$conv = $this->conversation();
		$this->messaging->send_message( $conv, $this->a, array( 'content' => 'hello' ) );

		$rows = $this->messaging->get_conversations( $this->b );
		$this->assertNotEmpty( $rows );
		$this->assertFalse( $rows[0]->is_pinned, 'An unpinned conversation is false, not the string "0" JavaScript reads as true.' );
		$this->assertFalse( $rows[0]->is_muted );
		$this->assertFalse( $this->messaging->get_conversation( $conv, $this->b )->is_pinned );
	}

	public function test_a_blocked_pair_cannot_reopen_their_conversation(): void {
		$conv = $this->conversation();
		$this->messaging->send_message( $conv, $this->a, array( 'content' => 'hello' ) );
		Plugin::container()->get( 'reports' )->block_user( $this->b, $this->a );

		foreach ( array( array( $this->a, $this->b ), array( $this->b, $this->a ) ) as $pair ) {
			$result = $this->messaging->find_or_create_conversation( $pair[0], $pair[1] );
			$this->assertSame( 'blocked', $result['status'], 'Refused in either direction.' );
			$this->assertSame( 0, $result['conversation_id'] );
		}
	}

	public function test_an_empty_direct_conversation_stays_out_of_the_recipients_inbox(): void {
		$conv = $this->conversation();

		$ids = static fn( array $rows ) => array_map( static fn( $r ) => (int) $r->id, $rows );
		$this->assertNotContains( $conv, $ids( $this->messaging->get_conversations( $this->b ) ), 'Nothing sent yet: not in B\'s inbox.' );
		$this->assertContains( $conv, $ids( $this->messaging->get_conversations( $this->a ) ), 'The member who opened it still finds it.' );

		$this->messaging->send_message( $conv, $this->a, array( 'content' => 'hello' ) );
		$this->assertContains( $conv, $ids( $this->messaging->get_conversations( $this->b ) ), 'The first message brings it to B.' );
	}
}
