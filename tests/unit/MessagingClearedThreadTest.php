<?php
/**
 * Deleting a conversation must not fork the pair into duplicate threads.
 *
 * Regression cover for card 10127717045: "Delete" used to flip the participant
 * to 'left' and find_or_create_conversation() skipped 'left' rows, so
 * re-contacting the same member spawned a SECOND conversation — and the other
 * member (who never deleted anything) saw two live threads for one person.
 *
 * 2.2.1 model: a direct pair keeps a single thread. "Delete" records a
 * per-user clear watermark (participants.cleared_up_to = the conversation's
 * highest message id at delete time); re-contact reuses the thread and the
 * deleting user simply sees no pre-delete history, while the other member
 * keeps everything. The watermark is a message ID rather than a timestamp so
 * a message sent in the SAME SECOND as the delete is still delivered — no
 * test here sleeps or backdates, deliberately.
 *
 * Also covers card 10127764989: attachment-only messages must store a typed
 * placeholder preview ("Photo" / "Video" / "Audio"), not an empty string.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class MessagingClearedThreadTest extends WP_UnitTestCase {

	/**
	 * @var \WPMediaVerse\Messaging\MessagingService
	 */
	private $service;

	/**
	 * @var int User A — the one who deletes the thread.
	 */
	private int $user_a;

	/**
	 * @var int User B — the one who must never see a duplicate.
	 */
	private int $user_b;

	public function set_up(): void {
		parent::set_up();

		$this->service = \WPMediaVerse\Core\Plugin::container()->get( 'messaging' );
		$this->user_a  = self::factory()->user->create();
		$this->user_b  = self::factory()->user->create();
	}

	/**
	 * Send a plain text message and return its id.
	 *
	 * @param int    $conversation_id Conversation.
	 * @param int    $sender_id       Sender.
	 * @param string $content         Body.
	 * @return int Message id.
	 */
	private function send( int $conversation_id, int $sender_id, string $content ): int {
		$result = $this->service->send_message( $conversation_id, $sender_id, array( 'content' => $content ) );
		$this->assertTrue( (bool) $result['success'], 'send_message failed: ' . ( $result['error'] ?? '' ) );
		return (int) $result['message_id'];
	}

	public function test_recontact_after_delete_reuses_the_same_thread(): void {
		$first = $this->service->find_or_create_conversation( $this->user_a, $this->user_b );
		$this->send( (int) $first['conversation_id'], $this->user_a, 'hello' );

		$this->assertTrue( $this->service->leave_conversation( (int) $first['conversation_id'], $this->user_a ) );

		$second = $this->service->find_or_create_conversation( $this->user_a, $this->user_b );

		$this->assertSame(
			(int) $first['conversation_id'],
			(int) $second['conversation_id'],
			'Re-contact after delete must reuse the pair\'s single thread, not fork a duplicate (card 10127717045).'
		);
		$this->assertFalse( (bool) $second['created'] );
	}

	public function test_other_member_never_sees_two_threads(): void {
		$conv_id = (int) $this->service->find_or_create_conversation( $this->user_a, $this->user_b )['conversation_id'];
		$this->send( $conv_id, $this->user_a, 'before delete' );

		$this->service->leave_conversation( $conv_id, $this->user_a );
		$reused = (int) $this->service->find_or_create_conversation( $this->user_a, $this->user_b )['conversation_id'];
		$this->send( $reused, $this->user_a, 'after delete' );

		$b_threads = array_filter(
			$this->service->get_conversations( $this->user_b ),
			static fn( $c ) => 'direct' === $c->type
		);

		$this->assertCount( 1, $b_threads, 'User B must see exactly one thread for the pair.' );
		$this->assertSame( $conv_id, (int) reset( $b_threads )->id );
	}

	public function test_deleter_loses_history_other_member_keeps_it(): void {
		$conv_id = (int) $this->service->find_or_create_conversation( $this->user_a, $this->user_b )['conversation_id'];
		$old_id  = $this->send( $conv_id, $this->user_a, 'old history' );

		$this->service->leave_conversation( $conv_id, $this->user_a );
		$this->service->find_or_create_conversation( $this->user_a, $this->user_b );
		// Sent in the same second as the delete — the id watermark must still
		// deliver it to A (a datetime clear point would have hidden it).
		$new_id = $this->send( $conv_id, $this->user_a, 'fresh start' );

		$a_ids = array_map( static fn( $m ) => (int) $m->id, $this->service->get_messages( $conv_id, $this->user_a ) );
		$b_ids = array_map( static fn( $m ) => (int) $m->id, $this->service->get_messages( $conv_id, $this->user_b ) );

		$this->assertNotContains( $old_id, $a_ids, 'History the user deleted must stay deleted for them.' );
		$this->assertContains( $new_id, $a_ids, 'A message sent in the same second as the delete must not be lost.' );
		$this->assertContains( $old_id, $b_ids, 'The other member keeps their full history.' );
		$this->assertContains( $new_id, $b_ids );
	}

	public function test_inbound_message_reactivates_deleter_without_restoring_history(): void {
		$conv_id = (int) $this->service->find_or_create_conversation( $this->user_a, $this->user_b )['conversation_id'];
		$old_id  = $this->send( $conv_id, $this->user_b, 'pre-delete inbound' );

		$this->service->leave_conversation( $conv_id, $this->user_a );
		$new_id = $this->send( $conv_id, $this->user_b, 'are you there?' );

		$a_threads = array_map( static fn( $c ) => (int) $c->id, $this->service->get_conversations( $this->user_a ) );
		$this->assertContains( $conv_id, $a_threads, 'An inbound message must resurrect the thread for the deleter.' );

		$a_ids = array_map( static fn( $m ) => (int) $m->id, $this->service->get_messages( $conv_id, $this->user_a ) );
		$this->assertNotContains( $old_id, $a_ids );
		$this->assertContains( $new_id, $a_ids );

		// Unread count matches what they can actually see: 1, not 2.
		$this->assertSame( 1, $this->service->get_conversation_unread_count( $conv_id, $this->user_a ) );
	}

	public function test_attachment_only_messages_store_typed_previews(): void {
		global $wpdb;
		$conv_id = (int) $this->service->find_or_create_conversation( $this->user_a, $this->user_b )['conversation_id'];

		$cases = array(
			array( 'image', 'Photo' ),
			array( 'video', 'Video' ),
			array( 'audio', 'Audio' ),
		);

		foreach ( $cases as [ $type, $expected ] ) {
			$result = $this->service->send_message(
				$conv_id,
				$this->user_a,
				array(
					'content'       => '',
					'message_type'  => $type,
					'attachment_id' => 12345,
				)
			);
			$this->assertTrue( (bool) $result['success'] );

			$preview = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT last_message_preview FROM {$wpdb->prefix}mvs_conversations WHERE id = %d", $conv_id )
			);
			$this->assertSame( $expected, $preview, "Attachment-only '{$type}' message must preview as '{$expected}' (card 10127764989)." );
		}

		// Attachment smuggled under message_type 'text' still gets a placeholder.
		$result = $this->service->send_message(
			$conv_id,
			$this->user_a,
			array(
				'content'       => '',
				'message_type'  => 'text',
				'attachment_id' => 12345,
			)
		);
		$this->assertTrue( (bool) $result['success'] );
		$preview = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT last_message_preview FROM {$wpdb->prefix}mvs_conversations WHERE id = %d", $conv_id )
		);
		$this->assertSame( 'Attachment', $preview );
	}
}
