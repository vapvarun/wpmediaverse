<?php
/**
 * Test CommentService.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_Error;
use WPMediaVerse\Social\CommentService;
use WPMediaVerse\Services\MediaMeta;

class CommentServiceTest extends WP_UnitTestCase {

	private CommentService $service;
	private int $user_id;
	private int $media_id;

	public function set_up(): void {
		parent::set_up();

		$this->service  = new CommentService();
		$this->user_id  = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->media_id = MediaMeta::insert(
			array(
				'title'       => 'Test Photo',
				'post_author' => $this->user_id,
				'media_type'  => 'image',
			)
		);

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mvs_media_stats',
			array(
				'media_id' => $this->media_id,
				'comments' => 0,
			)
		);
	}

	/**
	 * Adding a comment returns a positive integer comment ID.
	 */
	public function test_add_comment(): void {
		$comment_id = $this->service->add( $this->media_id, $this->user_id, 'Great photo!' );

		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		$comment = get_comment( $comment_id );
		$this->assertSame( CommentService::COMMENT_TYPE, $comment->comment_type );
		$this->assertSame( 'Great photo!', $comment->comment_content );
	}

	/**
	 * Comment content is sanitized via wp_kses_post (strips dangerous tags).
	 */
	public function test_add_comment_content_sanitized(): void {
		$dirty_html = '<b>Bold</b> <script>alert("xss")</script> <a href="https://example.com">link</a>';
		$comment_id = $this->service->add( $this->media_id, $this->user_id, $dirty_html );

		$comment = get_comment( $comment_id );

		// <script> should be stripped, <b> and <a> should survive.
		$this->assertStringNotContainsString( '<script>', $comment->comment_content );
		$this->assertStringContainsString( '<b>Bold</b>', $comment->comment_content );
		$this->assertStringContainsString( '<a href="https://example.com">link</a>', $comment->comment_content );
	}

	/**
	 * Passing a non-existent user ID returns WP_Error.
	 */
	public function test_add_with_invalid_user(): void {
		$result = $this->service->add( $this->media_id, 99999, 'Hello' );

		$this->assertWPError( $result );
		$this->assertSame( 'mvs_invalid_user', $result->get_error_code() );
	}

	/**
	 * A reply's parent matches the parent comment ID.
	 */
	public function test_add_threaded_comment(): void {
		$parent_id = $this->service->add( $this->media_id, $this->user_id, 'Parent comment' );
		$reply_id  = $this->service->add( $this->media_id, $this->user_id, 'Reply to parent', $parent_id );

		$this->assertIsInt( $reply_id );
		$this->assertGreaterThan( 0, $reply_id );

		$reply = get_comment( $reply_id );
		$this->assertSame( $parent_id, (int) $reply->comment_parent );
	}

	/**
	 * Using a parent_id from a different media returns WP_Error.
	 */
	public function test_add_invalid_parent(): void {
		// Create a second media item and add a comment to it.
		$other_media = MediaMeta::insert(
			array(
				'title'       => 'Other Photo',
				'post_author' => $this->user_id,
				'media_type'  => 'image',
			)
		);
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mvs_media_stats',
			array(
				'media_id' => $other_media,
				'comments' => 0,
			)
		);

		$other_comment = $this->service->add( $other_media, $this->user_id, 'Comment on other media' );

		// Try to reply to that comment on the first media — should fail.
		$result = $this->service->add( $this->media_id, $this->user_id, 'Cross-media reply', $other_comment );

		$this->assertWPError( $result );
		$this->assertSame( 'mvs_invalid_parent', $result->get_error_code() );
	}

	/**
	 * get_for_media returns all comments with expected fields.
	 */
	public function test_get_for_media(): void {
		$this->service->add( $this->media_id, $this->user_id, 'Comment 1' );
		$this->service->add( $this->media_id, $this->user_id, 'Comment 2' );
		$this->service->add( $this->media_id, $this->user_id, 'Comment 3' );

		$result = $this->service->get_for_media( $this->media_id );

		$this->assertArrayHasKey( 'comments', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertSame( 3, $result['total'] );
		$this->assertCount( 3, $result['comments'] );

		// Verify expected fields on the first comment.
		$comment = $result['comments'][0];
		$this->assertArrayHasKey( 'id', $comment );
		$this->assertArrayHasKey( 'author', $comment );
		$this->assertArrayHasKey( 'author_name', $comment );
		$this->assertArrayHasKey( 'author_avatar', $comment );
		$this->assertArrayHasKey( 'author_url', $comment );
		$this->assertArrayHasKey( 'content', $comment );
		$this->assertArrayHasKey( 'parent', $comment );
		$this->assertArrayHasKey( 'date', $comment );
		$this->assertArrayHasKey( 'replies', $comment );
	}

	/**
	 * get_for_media returns threaded structure: parent has replies array.
	 */
	public function test_get_for_media_with_replies(): void {
		$parent_id = $this->service->add( $this->media_id, $this->user_id, 'Parent' );
		$this->service->add( $this->media_id, $this->user_id, 'Reply 1', $parent_id );
		$this->service->add( $this->media_id, $this->user_id, 'Reply 2', $parent_id );

		$result = $this->service->get_for_media( $this->media_id );

		// Only 1 top-level comment.
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['comments'] );

		// That comment has 2 replies.
		$parent = $result['comments'][0];
		$this->assertCount( 2, $parent['replies'] );
		$this->assertSame( 'Reply 1', $parent['replies'][0]['content'] );
		$this->assertSame( 'Reply 2', $parent['replies'][1]['content'] );
	}

	/**
	 * Pagination: per_page limits returned comments, total reflects all.
	 */
	public function test_get_for_media_pagination(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->service->add( $this->media_id, $this->user_id, "Comment {$i}" );
		}

		$result = $this->service->get_for_media( $this->media_id, 2, 1 );

		$this->assertSame( 5, $result['total'] );
		$this->assertCount( 2, $result['comments'] );
	}

	/**
	 * The comment author can delete their own comment.
	 */
	public function test_delete_own_comment(): void {
		$comment_id = $this->service->add( $this->media_id, $this->user_id, 'Delete me' );

		$result = $this->service->delete( $comment_id, $this->user_id );

		$this->assertTrue( $result );
		$this->assertNull( get_comment( $comment_id ) );
	}

	/**
	 * A non-author, non-moderator user cannot delete another user's comment.
	 */
	public function test_delete_other_user_forbidden(): void {
		$comment_id = $this->service->add( $this->media_id, $this->user_id, 'Protected comment' );

		$other_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$result     = $this->service->delete( $comment_id, $other_user );

		$this->assertWPError( $result );
		$this->assertSame( 'mvs_forbidden', $result->get_error_code() );
	}

	/**
	 * Deleting a non-existent comment returns WP_Error with 404.
	 */
	public function test_delete_nonexistent(): void {
		$result = $this->service->delete( 999999, $this->user_id );

		$this->assertWPError( $result );
		$this->assertSame( 'mvs_not_found', $result->get_error_code() );

		$data = $result->get_error_data( 'mvs_not_found' );
		$this->assertSame( 404, $data['status'] );
	}

	/**
	 * The source parameter is passed through to the mvs_comment_created action.
	 */
	public function test_source_parameter_passed(): void {
		$captured_source = null;

		$callback = function ( $media_id, $user_id, $comment_id, $content, $source ) use ( &$captured_source ) {
			$captured_source = $source;
		};

		add_action( 'mvs_comment_created', $callback, 10, 5 );

		$this->service->add( $this->media_id, $this->user_id, 'From BP', 0, 'bp_activity' );

		remove_action( 'mvs_comment_created', $callback, 10 );

		$this->assertSame( 'bp_activity', $captured_source );
	}

	/**
	 * Adding a comment updates the comments column in mvs_media_stats.
	 */
	public function test_stats_sync_on_add(): void {
		$this->service->add( $this->media_id, $this->user_id, 'First comment' );
		$this->service->add( $this->media_id, $this->user_id, 'Second comment' );

		global $wpdb;
		$comments = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comments FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
				$this->media_id
			)
		);

		$this->assertSame( 2, $comments );
	}
}
