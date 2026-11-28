<?php
/**
 * Messaging doors other plugins use: sender lookup and the group size limit.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Messaging doors other plugins use: sender lookup and the group size limit.
 */
class MessagingDoorsTest extends WP_UnitTestCase {

	public function test_senders_and_group_size_limit_including_rejoin(): void {
		$a       = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$b       = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$c       = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$service = Plugin::container()->get( 'messaging' );

		$conv = (int) $service->find_or_create_conversation( $a, $b )['conversation_id'];
		$m1   = (int) $service->send_message( $conv, $a, array( 'content' => 'one' ) )['message_id'];
		$m2   = (int) $service->send_message( $conv, $b, array( 'content' => 'two' ) )['message_id'];
		$this->assertSame(
			array(
				$m1 => $a,
				$m2 => $b,
			),
			$service->get_message_senders( array( $m1, $m2, 999999 ) )
		);

		$limit = static function () {
			return 2;
		};
		add_filter( 'mvs_group_conversation_max_participants', $limit );
		$this->assertSame( 0, $service->create_group_conversation( $a, array( $b, $c ) ), 'A group larger than the filtered size is refused.' );

		remove_filter( 'mvs_group_conversation_max_participants', $limit );
		$group = $service->create_group_conversation( $a, array( $b ) );
		$this->assertGreaterThan( 0, $group );

		add_filter( 'mvs_group_conversation_max_participants', $limit );
		$this->assertFalse( $service->add_participant( $group, $c ), 'Adding past the limit is refused.' );
		remove_filter( 'mvs_group_conversation_max_participants', $limit );
	}

	public function test_host_can_refuse_group_creation_and_attachment_only_sends(): void {
		$a       = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$b       = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$service = Plugin::container()->get( 'messaging' );
		$group   = (int) $service->create_group_conversation( $a, array( $b ) );
		$this->assertGreaterThan( 0, $group, 'With no filter a group is created.' );

		$deny = static function () {
			return false;
		};
		add_filter( 'mvs_can_create_conversation', $deny );
		$this->assertSame( 0, $service->create_group_conversation( $a, array( $b ) ), 'A denying filter blocks group creation.' );
		$this->assertFalse( $service->may_create_group( $a, array( $b ) ) );
		remove_filter( 'mvs_can_create_conversation', $deny );

		$attachment = self::factory()->attachment->create(
			array(
				'post_author'    => $a,
				'post_mime_type' => 'image/jpeg',
			)
		);
		$checked    = array();
		$spy        = static function ( $ok, $content, $sender ) use ( &$checked ) {
			$checked[] = array( $content, $sender );
			return new \WP_Error( 'suspended', 'no' );
		};
		add_filter( 'mvs_message_content_check', $spy, 10, 3 );
		$result = $service->send_message(
			$group,
			$a,
			array(
				'message_type'  => 'image',
				'attachment_id' => $attachment,
			)
		);
		remove_filter( 'mvs_message_content_check', $spy, 10 );

		$this->assertSame( array( array( '', $a ) ), $checked, 'The content check runs for an attachment-only send.' );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'suspended', $result['error'] );
	}
}
