<?php
/**
 * Tests for MentionService.
 *
 * @package WPMediaVerse
 */

use WPMediaVerse\Social\MentionService;

class MentionServiceTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Service instance.
	 *
	 * @var MentionService
	 */
	private $service;

	/**
	 * Test media post ID.
	 *
	 * @var int
	 */
	private $media_id;

	/**
	 * Admin username for test environment.
	 *
	 * @var string
	 */
	private $admin_username;

	protected function setUp(): void {
		parent::setUp();

		$this->service = new MentionService();

		$this->media_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Mention Test Media',
				'post_status' => 'publish',
				'post_author' => 1,
			)
		);

		// Get the actual admin username for test environment.
		$admin = get_user_by( 'ID', 1 );
		$this->admin_username = $admin ? $admin->user_login : '';
	}

	protected function tearDown(): void {
		wp_delete_post( $this->media_id, true );

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}mvs_mentions WHERE media_id = %d",
				$this->media_id
			)
		);

		parent::tearDown();
	}

	public function test_extract_usernames_single(): void {
		$usernames = $this->service->extract_usernames( 'Hello @testuser how are you?' );

		$this->assertCount( 1, $usernames );
		$this->assertEquals( 'testuser', $usernames[0] );
	}

	public function test_extract_usernames_multiple(): void {
		$usernames = $this->service->extract_usernames( '@alice and @bob were here' );

		$this->assertCount( 2, $usernames );
		$this->assertContains( 'alice', $usernames );
		$this->assertContains( 'bob', $usernames );
	}

	public function test_extract_usernames_no_duplicates(): void {
		$usernames = $this->service->extract_usernames( '@same said @same again' );

		$this->assertCount( 1, $usernames );
	}

	public function test_extract_usernames_empty(): void {
		$usernames = $this->service->extract_usernames( 'No mentions here' );

		$this->assertCount( 0, $usernames );
	}

	public function test_extract_usernames_with_underscore(): void {
		$usernames = $this->service->extract_usernames( 'Hey @user_name check this' );

		$this->assertCount( 1, $usernames );
		$this->assertEquals( 'user_name', $usernames[0] );
	}

	public function test_extract_usernames_with_numbers(): void {
		$usernames = $this->service->extract_usernames( 'Paging @user123' );

		$this->assertCount( 1, $usernames );
		$this->assertEquals( 'user123', $usernames[0] );
	}

	public function test_parse_and_store_with_existing_user(): void {
		if ( empty( $this->admin_username ) ) {
			$this->markTestSkipped( 'No admin user found in test environment.' );
		}

		$mentioned_ids = $this->service->parse_and_store( 'Hello @' . $this->admin_username, $this->media_id, 'description' );

		$this->assertContains( 1, $mentioned_ids );
	}

	public function test_parse_and_store_nonexistent_user(): void {
		$mentioned_ids = $this->service->parse_and_store( 'Hello @nonexistent_user_xyz_abc', $this->media_id, 'description' );

		$this->assertEmpty( $mentioned_ids );
	}

	public function test_get_for_user(): void {
		if ( empty( $this->admin_username ) ) {
			$this->markTestSkipped( 'No admin user found in test environment.' );
		}

		// Store a mention for admin user.
		$this->service->parse_and_store( 'Hey @' . $this->admin_username . ' look', $this->media_id, 'comment', 1 );

		$result = $this->service->get_for_user( 1 );

		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
	}
}
