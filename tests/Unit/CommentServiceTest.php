<?php
/**
 * Tests for CommentService.
 *
 * @package WPMediaVerse
 */

use WPMediaVerse\Social\CommentService;

class CommentServiceTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Service instance.
	 *
	 * @var CommentService
	 */
	private $service;

	/**
	 * Test media post ID.
	 *
	 * @var int
	 */
	private $media_id;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $user_id;

	protected function setUp(): void {
		parent::setUp();

		$this->service = new CommentService();
		$this->user_id = 1;

		$this->media_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Comment Test Media',
				'post_status' => 'publish',
				'post_author' => $this->user_id,
			)
		);

		// Initialize stats row.
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mvs_media_stats',
			array(
				'media_id'   => $this->media_id,
				'views'      => 0,
				'downloads'  => 0,
				'reactions'  => 0,
				'comments'   => 0,
				'shares'     => 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
		);
	}

	protected function tearDown(): void {
		// Delete all comments for this media.
		$comments = get_comments( array( 'post_id' => $this->media_id ) );
		foreach ( $comments as $comment ) {
			wp_delete_comment( $comment->comment_ID, true );
		}

		wp_delete_post( $this->media_id, true );

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $this->media_id ), array( '%d' ) );

		parent::tearDown();
	}

	public function test_add_comment(): void {
		$result = $this->service->add( $this->media_id, $this->user_id, 'Great photo!' );

		// add() returns comment ID (int) on success.
		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );

		// Verify the comment exists.
		$comment = get_comment( $result );
		$this->assertNotNull( $comment );
		$this->assertEquals( 'Great photo!', $comment->comment_content );
	}

	public function test_get_for_media(): void {
		$this->service->add( $this->media_id, $this->user_id, 'Comment 1' );
		$this->service->add( $this->media_id, $this->user_id, 'Comment 2' );

		$result = $this->service->get_for_media( $this->media_id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'comments', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertEquals( 2, $result['total'] );
	}

	public function test_threaded_comments(): void {
		$parent_id = $this->service->add( $this->media_id, $this->user_id, 'Parent comment' );
		$child_id  = $this->service->add( $this->media_id, $this->user_id, 'Reply to parent', $parent_id );

		$this->assertIsInt( $child_id );

		$child = get_comment( $child_id );
		$this->assertEquals( $parent_id, (int) $child->comment_parent );
	}

	public function test_delete_own_comment(): void {
		$comment_id = $this->service->add( $this->media_id, $this->user_id, 'To be deleted' );

		$result = $this->service->delete( $comment_id, $this->user_id );
		$this->assertTrue( $result );
	}

	public function test_delete_other_user_comment_returns_error(): void {
		$comment_id = $this->service->add( $this->media_id, $this->user_id, 'Not yours' );

		$result = $this->service->delete( $comment_id, 999 );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_stats_synced_after_add(): void {
		$this->service->add( $this->media_id, $this->user_id, 'Test comment' );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comments FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
				$this->media_id
			)
		);

		$this->assertEquals( 1, $count );
	}

	public function test_add_comment_invalid_user(): void {
		$result = $this->service->add( $this->media_id, 999999, 'Should fail' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
