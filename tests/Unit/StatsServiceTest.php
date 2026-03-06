<?php
/**
 * Tests for StatsService.
 *
 * @package WPMediaVerse
 */

use WPMediaVerse\Services\StatsService;

class StatsServiceTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Service instance.
	 *
	 * @var StatsService
	 */
	private $service;

	/**
	 * Test media post ID.
	 *
	 * @var int
	 */
	private $media_id;

	protected function setUp(): void {
		parent::setUp();

		$this->service = new StatsService();

		$this->media_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Stats Test Media',
				'post_status' => 'publish',
				'post_author' => 1,
			)
		);

		// Initialize stats row (use replace to handle publish_mvs_media hook).
		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'mvs_media_stats',
			array(
				'media_id'   => $this->media_id,
				'views'      => 5,
				'downloads'  => 2,
				'reactions'  => 3,
				'comments'   => 1,
				'shares'     => 1,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
		);

		// Insert index row for user stats query (use replace for same reason).
		$wpdb->replace(
			$wpdb->prefix . 'mvs_media_index',
			array(
				'media_id'          => $this->media_id,
				'post_author'       => 1,
				'media_type'        => 'image',
				'privacy'           => 'public',
				'moderation_status' => 'approved',
				'created_at'        => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	protected function tearDown(): void {
		wp_delete_post( $this->media_id, true );

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $this->media_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'mvs_media_index', array( 'media_id' => $this->media_id ), array( '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}mvs_media_views WHERE media_id = %d",
				$this->media_id
			)
		);

		parent::tearDown();
	}

	public function test_get_for_media(): void {
		$stats = $this->service->get_for_media( $this->media_id );

		$this->assertIsArray( $stats );
		$this->assertEquals( $this->media_id, $stats['media_id'] );
		$this->assertEquals( 5, $stats['views'] );
		$this->assertEquals( 2, $stats['downloads'] );
		$this->assertEquals( 3, $stats['reactions'] );
		$this->assertEquals( 1, $stats['comments'] );
		$this->assertEquals( 1, $stats['shares'] );
	}

	public function test_get_for_media_nonexistent(): void {
		$stats = $this->service->get_for_media( 999999 );

		$this->assertNull( $stats );
	}

	public function test_get_for_user(): void {
		$stats = $this->service->get_for_user( 1 );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total_media', $stats );
		$this->assertArrayHasKey( 'total_views', $stats );
		$this->assertGreaterThanOrEqual( 1, $stats['total_media'] );
		$this->assertGreaterThanOrEqual( 5, $stats['total_views'] );
	}

	public function test_get_for_user_no_media(): void {
		$stats = $this->service->get_for_user( 999999 );

		$this->assertIsArray( $stats );
		$this->assertEquals( 0, $stats['total_media'] );
		$this->assertEquals( 0, $stats['total_views'] );
	}

	public function test_record_download(): void {
		$this->service->record_download( $this->media_id, 1 );

		$stats = $this->service->get_for_media( $this->media_id );
		$this->assertEquals( 3, $stats['downloads'] );
	}

	public function test_prune_views(): void {
		// Insert an old view record.
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mvs_media_views',
			array(
				'media_id'   => $this->media_id,
				'user_id'    => 1,
				'ip_hash'    => 'testhash',
				'event_type' => 'view',
				'created_at' => '2020-01-01 00:00:00',
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		$deleted = $this->service->prune_views( 90 );
		$this->assertGreaterThanOrEqual( 1, $deleted );
	}
}
