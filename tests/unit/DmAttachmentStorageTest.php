<?php
/**
 * Chat attachments are stored as conversation-scoped 'dm' media.
 *
 * Basecamp 10392474704: /messages/upload used to make a public WordPress
 * attachment under the original file name, listed by /wp/v2/media.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

class DmAttachmentStorageTest extends WP_UnitTestCase {

	private string $tmp_dir;
	private int $sender;
	private int $recipient;
	private int $outsider;

	public function set_up(): void {
		parent::set_up();
		$this->sender    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->recipient = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->outsider  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->tmp_dir   = sys_get_temp_dir() . '/mvs-dm-test-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->tmp_dir );
		add_filter( 'mvs_upload_skip_move_uploaded_file_check', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'mvs_upload_skip_move_uploaded_file_check', '__return_true' );
		array_map( 'unlink', (array) glob( $this->tmp_dir . '/*' ) );
		rmdir( $this->tmp_dir );
		parent::tear_down();
	}

	/**
	 * A voice note as the browser sends it: original name, PHP temp path.
	 */
	private function voice_note(): array {
		$tmp = $this->tmp_dir . '/php' . wp_generate_password( 6, false );
		copy( dirname( __DIR__ ) . '/fixtures/voice-note.webm', $tmp );
		return array(
			'name'     => 'voice.webm',
			'tmp_name' => $tmp,
			'type'     => 'audio/webm',
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $tmp ),
		);
	}

	private function store( array $file ) {
		return Plugin::container()->get( 'upload' )->handle( $file, $this->sender, array( 'privacy' => 'dm' ) );
	}

	public function test_a_voice_note_is_stored_as_private_dm_media_and_is_not_an_upload(): void {
		wp_set_current_user( $this->sender );
		$uploads_before = did_action( 'mvs_media_uploaded' );

		$media_id = $this->store( $this->voice_note() );

		$this->assertIsInt( $media_id, is_wp_error( $media_id ) ? $media_id->get_error_message() : '' );
		$repo = Plugin::container()->get( 'media_repository' );
		$this->assertSame( 'dm', $repo->get( $media_id, 'privacy' ) );
		$this->assertMatchesRegularExpression( '~^\d{4}/\d{2}/[0-9a-f]{16,}\.webm$~', (string) $repo->get( $media_id, 'file_path' ), 'Random name in the protected folder, not the original name.' );
		$this->assertSame( $uploads_before, did_action( 'mvs_media_uploaded' ), 'No feed, points, streaks or export for a chat file.' );
		$this->assertSame( 0, (int) ( new \WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'any', 'author' => $this->sender, 'fields' => 'ids' ) ) )->found_posts, 'No WordPress attachment is created.' );
	}

	/**
	 * Uploading as 'dm' skips the media rules, so a chat file may never be
	 * switched to another level (it would publish around them), and nothing
	 * may be switched to 'dm'.
	 */
	public function test_a_chat_file_can_never_change_privacy_and_nothing_becomes_dm(): void {
		wp_set_current_user( $this->sender );
		$media_id = $this->store( $this->voice_note() );
		$repo     = Plugin::container()->get( 'media_repository' );

		$repo->set( $media_id, 'privacy', 'public' );
		$this->assertSame( 'dm', $repo->get( $media_id, 'privacy' ) );
		$this->assertSame( array(), $repo->set_privacy_many( array( $media_id ), 'public' ) );
		$this->assertSame( 'dm', $repo->get( $media_id, 'privacy' ) );

		$request = new \WP_REST_Request( 'POST', '/mvs/v1/media/' . $media_id );
		$request->set_param( 'privacy', 'public' );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'mvs_privacy_dm_fixed', $response->as_error()->get_error_code() );
		$this->assertSame( 'dm', $repo->get( $media_id, 'privacy' ) );

		// Bulk edit reports the chat file as kept, never as done.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$bulk = new \WP_REST_Request( 'POST', '/mvs/v1/media/bulk' );
		$bulk->set_param( 'action', 'change_privacy' );
		$bulk->set_param( 'media_ids', array( $media_id ) );
		$bulk->set_param( 'privacy', 'public' );
		$result = rest_do_request( $bulk )->get_data();
		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( 1, $result['kept'] );
		$this->assertSame( 'dm', $repo->get( $media_id, 'privacy' ) );

		$public = (int) $repo->insert(
			array(
				'title'       => 'Public photo',
				'post_author' => $this->sender,
				'media_type'  => 'image',
				'privacy'     => 'public',
				'file_url'    => 'https://example.org/wp-content/uploads/wpmediaverse/2026/10/fedcba9876543210.jpg',
			)
		);
		$repo->set( $public, 'privacy', 'dm' );
		$this->assertSame( array(), $repo->set_privacy_many( array( $public ), 'dm' ) );
		$this->assertSame( 'public', $repo->get( $public, 'privacy' ) );
	}

	public function test_dm_rules_apply_size_limit_and_no_duplicate_refusal(): void {
		wp_set_current_user( $this->sender );
		update_option( 'mvs_duplicate_action', 'skip' );
		$this->assertIsInt( $this->store( $this->voice_note() ) );
		$this->assertIsInt( $this->store( $this->voice_note() ), 'Sending the same file twice in chat is normal.' );
		delete_option( 'mvs_duplicate_action' );

		$tiny = static function () {
			return 10;
		};
		add_filter( 'mvs_dm_max_upload_size', $tiny );
		$refused = $this->store( $this->voice_note() );
		remove_filter( 'mvs_dm_max_upload_size', $tiny );
		$this->assertWPError( $refused );
		$this->assertSame( 'mvs_file_too_large', $refused->get_error_code() );
	}

	public function test_the_recipient_gets_a_playable_attachment_and_an_outsider_cannot_view(): void {
		wp_set_current_user( $this->sender );
		$media_id  = $this->store( $this->voice_note() );
		$messaging = Plugin::container()->get( 'messaging' );
		$conv      = (int) $messaging->find_or_create_conversation( $this->sender, $this->recipient )['conversation_id'];
		$sent      = $messaging->send_message(
			$conv,
			$this->sender,
			array(
				'message_type' => 'voice',
				'media_id'     => $media_id,
			)
		);
		$this->assertTrue( $sent['success'], (string) ( $sent['error'] ?? '' ) );

		wp_set_current_user( $this->recipient );
		$voice = null;
		foreach ( $messaging->get_messages( $conv, $this->recipient ) as $msg ) {
			if ( (int) $msg->media_id === $media_id ) {
				$voice = $msg;
			}
		}
		$this->assertNotNull( $voice );
		$this->assertStringContainsString( 'mvs_id=' . $media_id, (string) ( $voice->attachment['url'] ?? '' ), 'The chat UI reads attachment.url.' );

		$privacy = Plugin::container()->get( 'privacy' );
		$this->assertTrue( $privacy->can_view( $media_id, $this->recipient ) );
		$this->assertFalse( $privacy->can_view( $media_id, $this->outsider ) );
	}
}
