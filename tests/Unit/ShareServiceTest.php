<?php
/**
 * Tests for ShareService.
 *
 * @package WPMediaVerse
 */

use WPMediaVerse\Social\ShareService;

class ShareServiceTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Service instance.
	 *
	 * @var ShareService
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

		$this->service = new ShareService();

		$this->media_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Share Test Media',
				'post_status' => 'publish',
				'post_author' => 1,
			)
		);

		// Initialize stats row.
		global $wpdb;
		$wpdb->replace(
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
		wp_delete_post( $this->media_id, true );

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $this->media_id ), array( '%d' ) );

		parent::tearDown();
	}

	public function test_record_share(): void {
		$result = $this->service->record_share( $this->media_id, 1, 'facebook' );

		$this->assertIsArray( $result );
		$this->assertEquals( $this->media_id, $result['media_id'] );
		$this->assertEquals( 'facebook', $result['platform'] );
		$this->assertArrayHasKey( 'share_url', $result );
	}

	public function test_get_share_count_increments(): void {
		$this->assertEquals( 0, $this->service->get_share_count( $this->media_id ) );

		$this->service->record_share( $this->media_id, 1, 'link' );
		$this->assertEquals( 1, $this->service->get_share_count( $this->media_id ) );

		$this->service->record_share( $this->media_id, 2, 'twitter' );
		$this->assertEquals( 2, $this->service->get_share_count( $this->media_id ) );
	}

	public function test_get_share_links(): void {
		$links = $this->service->get_share_links( $this->media_id );

		$this->assertArrayHasKey( 'facebook', $links );
		$this->assertArrayHasKey( 'twitter', $links );
		$this->assertArrayHasKey( 'linkedin', $links );
		$this->assertArrayHasKey( 'email', $links );
	}

	public function test_generate_share_url(): void {
		$url = $this->service->generate_share_url( $this->media_id );

		$this->assertIsString( $url );
		$this->assertNotEmpty( $url );
	}
}
