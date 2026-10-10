<?php
/**
 * A DM only grants access to media its OWNER shared.
 *
 * Basecamp 10392490030: any member could post another member's private media
 * id into a DM with their own second account, and the DM grant then let them
 * view and download it.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

class DmMediaGrantTest extends WP_UnitTestCase {

	private $service;
	private $privacy;
	private int $victim;
	private int $attacker;
	private int $friend;

	public function set_up(): void {
		parent::set_up();
		$this->service  = Plugin::container()->get( 'messaging' );
		$this->privacy  = Plugin::container()->get( 'privacy' );
		$this->victim   = self::factory()->user->create();
		$this->attacker = self::factory()->user->create();
		$this->friend   = self::factory()->user->create();
	}

	private function private_media( int $owner ): int {
		return (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Private ' . wp_generate_password( 6, false ),
				'post_author' => $owner,
				'media_type'  => 'image',
				'privacy'     => 'private',
				'file_url'    => 'https://example.org/wp-content/uploads/wpmediaverse/2026/10/0123456789abcdef.jpg',
			)
		);
	}

	private function conversation( int $a, int $b ): int {
		$conv = $this->service->find_or_create_conversation( $a, $b );
		$this->assertGreaterThan( 0, $conv['conversation_id'] );
		return (int) $conv['conversation_id'];
	}

	public function test_posting_someone_elses_private_media_is_refused_and_grants_nothing(): void {
		$media = $this->private_media( $this->victim );
		$conv  = $this->conversation( $this->attacker, $this->friend );

		$sent = $this->service->send_message(
			$conv,
			$this->attacker,
			array(
				'message_type' => 'media_share',
				'media_id'     => $media,
			)
		);

		$this->assertFalse( $sent['success'] );
		$this->assertSame( 'attachment_unavailable', $sent['error'] );
		$this->assertFalse( $this->privacy->can_view( $media, $this->attacker ) );
		$this->assertFalse( $this->privacy->can_view( $media, $this->friend ) );
	}

	public function test_a_stored_non_owner_share_grants_nothing(): void {
		// Rows written before the send check (or by a direct insert) must not grant either.
		global $wpdb;
		$media = $this->private_media( $this->victim );
		$conv  = $this->conversation( $this->attacker, $this->friend );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_messages',
			array(
				'conversation_id' => $conv,
				'sender_id'       => $this->attacker,
				'content'         => '',
				'message_type'    => 'media_share',
				'media_id'        => $media,
				'attachment_id'   => $media,
				'created_at'      => current_time( 'mysql', true ),
				'updated_at'      => current_time( 'mysql', true ),
			)
		);

		$this->assertFalse( $this->privacy->can_view( $media, $this->attacker ) );
	}

	public function test_the_owner_sharing_their_own_private_media_still_lets_the_recipient_see_it(): void {
		$media = $this->private_media( $this->victim );
		$conv  = $this->conversation( $this->victim, $this->friend );

		$sent = $this->service->send_message(
			$conv,
			$this->victim,
			array(
				'message_type' => 'media_share',
				'media_id'     => $media,
			)
		);

		$this->assertTrue( $sent['success'], (string) ( $sent['error'] ?? '' ) );
		$this->assertTrue( $this->privacy->can_view( $media, $this->friend ) );
		$this->assertFalse( $this->privacy->can_view( $media, $this->attacker ) );
	}
}
