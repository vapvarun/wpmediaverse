<?php
/**
 * Reactions on already-delivered messages surface through the poll.
 *
 * Regression cover for card 10122929662: a reaction never moves a message's
 * created_at, so poll() (which filters on created_at) skipped it and the other
 * participant saw nothing until a full reload. poll_reaction_updates() is the
 * companion query that closes that gap.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class MessagingReactionUpdatesTest extends WP_UnitTestCase {

	/**
	 * @var \WPMediaVerse\Messaging\MessagingService
	 */
	private $service;

	/**
	 * @var int Message author / one participant.
	 */
	private int $author;

	/**
	 * @var int The other participant, who reacts.
	 */
	private int $reactor;

	/**
	 * @var int A message from $author in the shared conversation.
	 */
	private int $message_id;

	public function set_up(): void {
		parent::set_up();

		$this->service = \WPMediaVerse\Core\Plugin::container()->get( 'messaging' );
		$this->author  = self::factory()->user->create();
		$this->reactor = self::factory()->user->create();

		$conversation     = $this->service->find_or_create_conversation( $this->author, $this->reactor );
		$conversation_id  = (int) $conversation['conversation_id'];
		$message          = $this->service->send_message( $conversation_id, $this->author, array( 'content' => 'hello' ) );
		$this->message_id = (int) $message['message_id'];
	}

	/**
	 * A reaction on an already-seen message appears in reaction_updates.
	 *
	 * @return void
	 */
	public function test_reaction_add_surfaces_for_the_other_participant(): void {
		global $wpdb;
		$created = $wpdb->get_var(
			$wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}mvs_messages WHERE id = %d", $this->message_id )
		);
		// The message is "already delivered" as of its own created_at.
		$since = (string) $created;

		// Nothing has changed yet.
		$this->assertSame( array(), $this->service->poll_reaction_updates( $this->author, $since ) );

		// A later reaction moves updated_at past `since` (guard the 1s clock grain).
		sleep( 1 );
		$this->service->add_reaction( $this->message_id, $this->reactor, 'love' );

		$updates = $this->service->poll_reaction_updates( $this->author, $since );

		$this->assertCount( 1, $updates );
		$this->assertSame( $this->message_id, $updates[0]['id'] );
		$this->assertSame( 'love', $updates[0]['reactions'][0]['emoji'] );
		$this->assertSame( array( $this->reactor ), $updates[0]['reactions'][0]['user_ids'] );
	}

	/**
	 * A reaction landing in the SAME SECOND as the cursor still surfaces.
	 *
	 * This is the 2.2.0 live-reaction loss. `updated_at` is a second-precision
	 * DATETIME and the client's cursor is the previous response's server_time, so
	 * when a reaction landed inside that same second the strict `updated_at >
	 * $since` comparison skipped it — and because the client then advanced its
	 * cursor, it was skipped FOREVER, leaving the reaction invisible until a full
	 * page reload. With a ~5s poll that lost roughly one reaction in five,
	 * reproduced live two-actor before the fix.
	 *
	 * Note the sibling test above sleeps 1s to "guard the 1s clock grain" — it
	 * worked AROUND this bug rather than catching it. This test deliberately does
	 * not sleep: `since` is the reaction's own second.
	 *
	 * @return void
	 */
	public function test_reaction_in_the_same_second_as_the_cursor_still_surfaces(): void {
		$this->service->add_reaction( $this->message_id, $this->reactor, 'love' );

		global $wpdb;
		// The cursor a client would hold: the very second the reaction landed.
		$since = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT updated_at FROM {$wpdb->prefix}mvs_messages WHERE id = %d", $this->message_id )
		);

		$updates = $this->service->poll_reaction_updates( $this->author, $since );

		$this->assertCount( 1, $updates, 'A same-second reaction must not be skipped by the cursor.' );
		$this->assertSame( $this->message_id, $updates[0]['id'] );
		$this->assertSame( 'love', $updates[0]['reactions'][0]['emoji'] );
	}

	/**
	 * A reaction REMOVAL also surfaces — the case a reactions-table scan misses.
	 *
	 * Removal is a hard delete with no timestamp of its own, so only the parent
	 * message's updated_at can carry it. The update reports an empty reaction set.
	 *
	 * @return void
	 */
	public function test_reaction_removal_surfaces_with_empty_set(): void {
		global $wpdb;
		$created = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}mvs_messages WHERE id = %d", $this->message_id )
		);

		$this->service->add_reaction( $this->message_id, $this->reactor, 'love' );

		sleep( 1 );
		$since = gmdate( 'Y-m-d H:i:s' ); // after the add, before the remove
		sleep( 1 );
		$this->service->remove_reaction( $this->message_id, $this->reactor );

		$updates = $this->service->poll_reaction_updates( $this->author, $since );

		$this->assertCount( 1, $updates, 'A removal must be caught, not only an add.' );
		$this->assertSame( $this->message_id, $updates[0]['id'] );
		$this->assertSame( array(), $updates[0]['reactions'], 'The message reports no reactions after removal.' );

		// Sanity: the message itself was created before all of this.
		$this->assertLessThan( $since, $created );
	}

	/**
	 * A brand-new message is NOT double-reported here.
	 *
	 * New messages arrive through poll()'s own list (with their reactions); this
	 * companion query is only for messages created on or before `since`, so a
	 * message created after `since` must be excluded even if it has a reaction.
	 *
	 * @return void
	 */
	public function test_new_message_is_not_in_reaction_updates(): void {
		$since = gmdate( 'Y-m-d H:i:s' );
		sleep( 1 );

		$conversation = $this->service->find_or_create_conversation( $this->author, $this->reactor );
		$fresh        = $this->service->send_message( (int) $conversation['conversation_id'], $this->author, array( 'content' => 'new' ) );
		$this->service->add_reaction( (int) $fresh['message_id'], $this->reactor, 'wow' );

		$updates = $this->service->poll_reaction_updates( $this->author, $since );
		$ids     = array_map( static fn( array $u ): int => $u['id'], $updates );

		$this->assertNotContains( (int) $fresh['message_id'], $ids, 'A message created after `since` belongs in poll(), not reaction_updates.' );
	}

	/**
	 * A non-participant never sees another conversation's reaction updates.
	 *
	 * @return void
	 */
	public function test_non_participant_sees_nothing(): void {
		$outsider = self::factory()->user->create();
		global $wpdb;
		$created  = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}mvs_messages WHERE id = %d", $this->message_id )
		);

		sleep( 1 );
		$this->service->add_reaction( $this->message_id, $this->reactor, 'love' );

		$this->assertSame(
			array(),
			$this->service->poll_reaction_updates( $outsider, $created ),
			'An outsider must not receive reaction updates for a conversation they are not in.'
		);
	}
}
