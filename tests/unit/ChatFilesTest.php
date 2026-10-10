<?php
/**
 * Chat files: moving pre-2.6.2 attachments, hiding them meanwhile, and removing
 * a file when its message is deleted. Basecamp 10392474704.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Messaging\ChatFiles;
use WPMediaVerse\Services\StorageLimitService;

class ChatFilesTest extends WP_UnitTestCase {

	private int $sender;
	private int $recipient;
	private $messaging;

	public function set_up(): void {
		parent::set_up();
		delete_option( ChatFiles::OPTION );
		$this->sender    = self::factory()->user->create();
		$this->recipient = self::factory()->user->create();
		$this->messaging = Plugin::container()->get( 'messaging' );
	}

	/**
	 * A chat attachment as 2.6.1 made it: a public WordPress attachment with
	 * the DM size meta, owned by the sender.
	 */
	private function legacy_attachment(): int {
		$id = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/test-image-1.jpg' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => $this->sender,
			)
		);
		update_post_meta( $id, StorageLimitService::DM_SIZE_META, (int) filesize( get_attached_file( $id ) ) );
		return $id;
	}

	private function legacy_message( int $attachment_id ): int {
		global $wpdb;
		$conv = (int) $this->messaging->find_or_create_conversation( $this->sender, $this->recipient )['conversation_id'];
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_messages',
			array(
				'conversation_id' => $conv,
				'sender_id'       => $this->sender,
				'content'         => '',
				'message_type'    => 'image',
				'attachment_id'   => $attachment_id,
				'created_at'      => current_time( 'mysql', true ),
				'updated_at'      => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	private function message( int $id ): \stdClass {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mvs_messages WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_a_sent_attachment_moves_into_dm_media_and_the_public_copy_goes(): void {
		$attachment = $this->legacy_attachment();
		$public     = get_attached_file( $attachment );
		$message    = $this->legacy_message( $attachment );

		ChatFiles::run_batch();

		$row = $this->message( $message );
		$this->assertNull( $row->attachment_id );
		$this->assertGreaterThan( 0, (int) $row->media_id );
		$repo = Plugin::container()->get( 'media_repository' );
		$this->assertSame( 'dm', $repo->get( (int) $row->media_id, 'privacy' ) );
		$this->assertSame( $this->sender, (int) $repo->get( (int) $row->media_id, 'post_author' ) );
		$this->assertNull( get_post( $attachment ), 'The WordPress attachment is gone.' );
		$this->assertFileDoesNotExist( $public, 'The public copy is gone.' );
		$this->assertTrue( Plugin::container()->get( 'privacy' )->can_view( (int) $row->media_id, $this->recipient ), 'The recipient still sees it.' );

		$state = get_option( ChatFiles::OPTION );
		$this->assertSame( 1, $state['moved'] );
		$this->assertTrue( $state['done'] );
	}

	public function test_a_never_sent_attachment_is_deleted(): void {
		$attachment = $this->legacy_attachment();

		ChatFiles::run_batch();

		$this->assertNull( get_post( $attachment ) );
		$this->assertSame( 1, get_option( ChatFiles::OPTION )['removed'] );
	}

	public function test_old_chat_files_are_hidden_from_attachment_listings_until_moved(): void {
		$legacy = $this->legacy_attachment();
		$normal = (int) self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/test-image-1.jpg' );

		$listed = ( new \WP_Query(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'fields'      => 'ids',
				'post__in'    => array( $legacy, $normal ),
			)
		) )->posts;

		$this->assertContains( $normal, $listed );
		$this->assertNotContains( $legacy, $listed );

		// The single-item route answers like a missing id (ids are sequential).
		wp_set_current_user( 0 );
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/media/' . $legacy ) )->get_status() );
		$this->assertSame( 200, rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/media/' . $normal ) )->get_status() );
	}

	public function test_an_attachment_that_cannot_be_moved_stays_hidden_after_the_job_is_done(): void {
		$attachment = $this->legacy_attachment();
		$this->legacy_message( $attachment );
		$no_jpeg = static function () {
			return array( 'video/mp4' ); // Makes the move refuse this JPEG.
		};
		add_filter( 'mvs_dm_allowed_file_types', $no_jpeg );
		ChatFiles::run_batch();
		remove_filter( 'mvs_dm_allowed_file_types', $no_jpeg );

		$state = get_option( ChatFiles::OPTION );
		$this->assertTrue( $state['done'] );
		$this->assertSame( 1, $state['failed'] );
		$this->assertNotNull( get_post( $attachment ), 'Not moved, so not deleted.' );

		$listed = ( new \WP_Query(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'fields'      => 'ids',
				'post__in'    => array( $attachment ),
			)
		) )->posts;
		$this->assertNotContains( $attachment, $listed, 'Fail closed: still hidden.' );
	}

	public function test_a_failed_move_is_retried_daily_and_shows_in_site_health_until_it_works(): void {
		$attachment = $this->legacy_attachment();
		$message    = $this->legacy_message( $attachment );
		$no_jpeg    = static function () {
			return array( 'video/mp4' );
		};
		add_filter( 'mvs_dm_allowed_file_types', $no_jpeg );
		ChatFiles::run_batch();
		remove_filter( 'mvs_dm_allowed_file_types', $no_jpeg );

		$tests = ChatFiles::register_health_test( array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'wpmediaverse_chat_files', $tests['direct'] );
		$this->assertSame( 'recommended', ChatFiles::health_result()['status'] );

		// Retried from an admin page load (never a visitor request).
		set_current_screen( 'dashboard' );
		ChatFiles::maybe_start();
		$this->assertFalse( get_option( ChatFiles::OPTION )['done'], 'Retry queued.' );
		ChatFiles::run_batch();
		$this->assertSame( 'moved', $this->message( $message )->media_id ? 'moved' : 'not moved' );
		$this->assertSame( 0, get_option( ChatFiles::OPTION )['failed'] );
		$this->assertArrayNotHasKey( 'wpmediaverse_chat_files', ChatFiles::register_health_test( array( 'direct' => array() ) )['direct'] );
	}

	public function test_unsending_removes_the_chat_file_unless_another_message_shows_it(): void {
		$upload = Plugin::container()->get( 'upload' );
		add_filter( 'mvs_upload_skip_move_uploaded_file_check', '__return_true' );
		$tmp = wp_tempnam( 'voice.webm' );
		copy( dirname( __DIR__ ) . '/fixtures/voice-note.webm', $tmp );
		wp_set_current_user( $this->sender );
		$media = $upload->handle(
			array(
				'name'     => 'voice.webm',
				'tmp_name' => $tmp,
				'type'     => 'audio/webm',
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $tmp ),
			),
			$this->sender,
			array( 'privacy' => 'dm' )
		);
		remove_filter( 'mvs_upload_skip_move_uploaded_file_check', '__return_true' );
		$this->assertIsInt( $media );

		$conv  = (int) $this->messaging->find_or_create_conversation( $this->sender, $this->recipient )['conversation_id'];
		$first = $this->messaging->send_message(
			$conv,
			$this->sender,
			array(
				'message_type' => 'voice',
				'media_id'     => $media,
			)
		);
		$again = $this->messaging->send_message(
			$conv,
			$this->sender,
			array(
				'message_type' => 'voice',
				'media_id'     => $media,
			)
		);
		$repo  = Plugin::container()->get( 'media_repository' );

		$this->messaging->unsend_message( (int) $first['message_id'], $this->sender );
		$this->assertTrue( $repo->exists( $media ), 'Still shown by the second message.' );

		$this->messaging->unsend_message( (int) $again['message_id'], $this->sender );
		$this->assertFalse( $repo->exists( $media ), 'Unsent everywhere: the file goes, for everyone.' );
	}
}
