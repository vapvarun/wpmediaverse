<?php
/**
 * The message poll cursor survives second-precision truncation.
 *
 * Regression cover for the message half of the poll-cursor race. 2.2.1 widened
 * the reaction sweep (see MessagingReactionUpdatesTest) but left the identical
 * defect on `poll()` itself: `created_at` is a second-precision DATETIME and the
 * client's cursor is the previous response's `server_time`, stamped AFTER the
 * poll query ran. A message inserted in the remainder of that same second was
 * missed by that query and then excluded by the next poll's strict `>`, so it
 * never reached the other participant until a reload or a thread switch.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class MessagingPollCursorTest extends WP_UnitTestCase {

	/**
	 * @var \WPMediaVerse\Messaging\MessagingService
	 */
	private $service;

	/**
	 * @var int Sender.
	 */
	private int $author;

	/**
	 * @var int Recipient — the one who polls.
	 */
	private int $recipient;

	/**
	 * @var int The shared conversation.
	 */
	private int $conversation_id;

	public function set_up(): void {
		parent::set_up();

		$this->service   = \WPMediaVerse\Core\Plugin::container()->get( 'messaging' );
		$this->author    = self::factory()->user->create();
		$this->recipient = self::factory()->user->create();

		$conversation          = $this->service->find_or_create_conversation( $this->author, $this->recipient );
		$this->conversation_id = (int) $conversation['conversation_id'];
	}

	/**
	 * Read a message's stored created_at, which is what a cursor collides with.
	 *
	 * @param int $message_id Message.
	 * @return string MySQL datetime.
	 */
	private function created_at( int $message_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}mvs_messages WHERE id = %d", $message_id )
		);
	}

	/**
	 * Pull the message ids a poll would deliver.
	 *
	 * @param int    $user_id Viewer.
	 * @param string $since   Cursor.
	 * @return array<int,int>
	 */
	private function polled_ids( int $user_id, string $since ): array {
		// poll() returns a flat list of message rows; the REST controller is what
		// wraps them under a `messages` key.
		return array_map(
			static fn( $m ) => (int) $m->id,
			(array) $this->service->poll( $user_id, $since )
		);
	}

	/**
	 * A message landing in the SAME SECOND as the cursor is still delivered.
	 *
	 * The defect: cursor == the message's own second, strict `>` excludes it, and
	 * the client has already advanced past it, so it is lost permanently. This
	 * test deliberately does not sleep — that is the whole point.
	 *
	 * @return void
	 */
	public function test_message_in_the_same_second_as_the_cursor_is_still_delivered(): void {
		$message    = $this->service->send_message( $this->conversation_id, $this->author, array( 'content' => 'same-second' ) );
		$message_id = (int) $message['message_id'];

		// The cursor a recipient would hold: the very second the message landed.
		$since = $this->created_at( $message_id );

		$this->assertContains(
			$message_id,
			$this->polled_ids( $this->recipient, $since ),
			'A message created in the same second as the cursor must not be skipped.'
		);
	}

	/**
	 * The sweep does not reach back so far that it re-serves old history.
	 *
	 * Guards the other direction: widening the cursor must stay bounded, or every
	 * poll would re-ship the whole thread and the client would do needless work.
	 *
	 * @return void
	 */
	public function test_messages_older_than_the_sweep_window_are_not_re_served(): void {
		global $wpdb;

		$message    = $this->service->send_message( $this->conversation_id, $this->author, array( 'content' => 'ancient' ) );
		$message_id = (int) $message['message_id'];

		// Age the row well past the sweep window.
		$wpdb->update(
			$wpdb->prefix . 'mvs_messages',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ),
			array( 'id' => $message_id )
		);

		$this->assertNotContains(
			$message_id,
			$this->polled_ids( $this->recipient, gmdate( 'Y-m-d H:i:s' ) ),
			'A message far older than the sweep window must not be re-served.'
		);
	}

	/**
	 * Re-serving inside the overlap is safe because the client keys off id.
	 *
	 * Two polls a second apart may both carry the same message. That is by
	 * design: the client returns early when `.bn-dm-msg[data-msg-id]` is already
	 * rendered, which is also how its own optimistic send survives the echo. This
	 * asserts the server side of that contract — a stable id, not a duplicate row.
	 *
	 * @return void
	 */
	public function test_overlap_re_serves_the_same_id_rather_than_duplicating(): void {
		$message    = $this->service->send_message( $this->conversation_id, $this->author, array( 'content' => 'overlap' ) );
		$message_id = (int) $message['message_id'];
		$since      = $this->created_at( $message_id );

		$first  = $this->polled_ids( $this->recipient, $since );
		$second = $this->polled_ids( $this->recipient, $since );

		$this->assertSame( $first, $second, 'The same cursor must yield the same ids.' );
		$this->assertSame(
			array( $message_id ),
			array_values( array_unique( array_filter( $first, fn( $id ) => $id === $message_id ) ) ),
			'The message must appear exactly once per response.'
		);
	}
}
