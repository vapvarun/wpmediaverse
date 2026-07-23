<?php
/**
 * Importer seam: conversations and messages accept a backdated created_at.
 *
 * The buddynext-importer's future MessageImporter replays a source DM history
 * through this service (BuddyNext card 10124307358); without the seam every
 * migrated conversation and message would be stamped with the migration run
 * time (the bug class of card 10124307318). Mirrors the seams already landed
 * in buddynext (Core\Backdate) and jetonomy (Journey_Backdate): UTC in,
 * future/invalid values fall back to now, absent input is byte-identical to
 * live behaviour.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class MessagingBackdateTest extends WP_UnitTestCase {

	/**
	 * @var \WPMediaVerse\Messaging\MessagingService
	 */
	private $service;

	/**
	 * @var int Conversation initiator.
	 */
	private int $sender;

	/**
	 * @var int Recipient.
	 */
	private int $recipient;

	public function set_up(): void {
		parent::set_up();

		$this->service   = \WPMediaVerse\Core\Plugin::container()->get( 'messaging' );
		$this->sender    = self::factory()->user->create();
		$this->recipient = self::factory()->user->create();
	}

	public function test_direct_conversation_honours_backdated_created_at(): void {
		global $wpdb;

		$result = $this->service->find_or_create_conversation(
			$this->sender,
			$this->recipient,
			array( 'created_at' => '2020-06-06 06:06:06' )
		);
		$conv_id = (int) $result['conversation_id'];
		$this->assertGreaterThan( 0, $conv_id );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT created_at, last_activity_at FROM {$wpdb->prefix}mvs_conversations WHERE id = %d", $conv_id ),
			ARRAY_A
		);
		$this->assertSame( '2020-06-06 06:06:06', $row['created_at'] );
		$this->assertSame( '2020-06-06 06:06:06', $row['last_activity_at'] );

		$joined = $wpdb->get_col(
			$wpdb->prepare( "SELECT joined_at FROM {$wpdb->prefix}mvs_conversation_participants WHERE conversation_id = %d", $conv_id )
		);
		$this->assertCount( 2, $joined );
		foreach ( $joined as $joined_at ) {
			$this->assertSame( '2020-06-06 06:06:06', $joined_at, 'participants joined when the source conversation began, not at migration time' );
		}
	}

	public function test_send_message_honours_backdated_created_at_and_bumps_last_activity(): void {
		global $wpdb;

		$conv    = $this->service->find_or_create_conversation(
			$this->sender,
			$this->recipient,
			array( 'created_at' => '2020-06-06 06:06:06' )
		);
		$conv_id = (int) $conv['conversation_id'];

		$sent = $this->service->send_message(
			$conv_id,
			$this->sender,
			array(
				'content'    => 'Imported message',
				'created_at' => '2020-07-07 07:07:07',
			)
		);
		$this->assertTrue( $sent['success'] );

		$this->assertSame(
			'2020-07-07 07:07:07',
			$wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}mvs_messages WHERE id = %d", (int) $sent['message_id'] ) )
		);
		$this->assertSame(
			'2020-07-07 07:07:07',
			$wpdb->get_var( $wpdb->prepare( "SELECT last_activity_at FROM {$wpdb->prefix}mvs_conversations WHERE id = %d", $conv_id ) ),
			'a backdated message carries its date into last_activity_at, not the migration run time'
		);
	}

	public function test_live_message_still_stamps_now(): void {
		global $wpdb;

		$conv    = $this->service->find_or_create_conversation( $this->sender, $this->recipient );
		$conv_id = (int) $conv['conversation_id'];

		$sent = $this->service->send_message( $conv_id, $this->sender, array( 'content' => 'Live message' ) );
		$this->assertTrue( $sent['success'] );

		$created = (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}mvs_messages WHERE id = %d", (int) $sent['message_id'] ) );
		$this->assertGreaterThan( time() - MINUTE_IN_SECONDS, strtotime( $created . ' UTC' ) );
	}

	public function test_future_created_at_is_clamped_to_now(): void {
		global $wpdb;

		$result  = $this->service->find_or_create_conversation(
			$this->sender,
			$this->recipient,
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) )
		);
		$conv_id = (int) $result['conversation_id'];

		$created = (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}mvs_conversations WHERE id = %d", $conv_id ) );
		$this->assertLessThanOrEqual( time() + 2, strtotime( $created . ' UTC' ) );
	}

	public function test_group_conversation_honours_backdated_created_at(): void {
		global $wpdb;

		$third   = self::factory()->user->create();
		$conv_id = (int) $this->service->create_group_conversation(
			$this->sender,
			array( $this->recipient, $third ),
			'Imported group',
			array( 'created_at' => '2021-08-08 08:08:08' )
		);
		$this->assertGreaterThan( 0, $conv_id );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT created_at, last_activity_at FROM {$wpdb->prefix}mvs_conversations WHERE id = %d", $conv_id ),
			ARRAY_A
		);
		$this->assertSame( '2021-08-08 08:08:08', $row['created_at'] );
		$this->assertSame( '2021-08-08 08:08:08', $row['last_activity_at'] );
	}
}
