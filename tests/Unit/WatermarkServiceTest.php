<?php
/**
 * Tests for WatermarkService.
 *
 * @package WPMediaVerse
 */

use WPMediaVerse\Services\WatermarkService;
use WPMediaVerse\Services\AccessRulesService;

class WatermarkServiceTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Watermark service under test.
	 *
	 * @var WatermarkService
	 */
	private $service;

	/**
	 * Access rules service.
	 *
	 * @var AccessRulesService
	 */
	private $access_rules;

	/**
	 * Test media post ID.
	 *
	 * @var int
	 */
	private $media_id;

	protected function setUp(): void {
		parent::setUp();

		$this->access_rules = new AccessRulesService();
		$this->service      = new WatermarkService( $this->access_rules );

		$this->media_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Watermark Test Media',
				'post_status' => 'publish',
				'post_author' => 1,
			)
		);

		update_post_meta( $this->media_id, '_mvs_file_type', 'image/jpeg' );
		update_post_meta( $this->media_id, '_mvs_file_path', '2026/03/watermark-test.jpg' );
		update_post_meta( $this->media_id, '_mvs_file_url', 'http://example.com/wp-content/uploads/wpmediaverse/2026/03/watermark-test.jpg' );
	}

	protected function tearDown(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mvs_access_rules WHERE media_id = {$this->media_id}" );
		// phpcs:enable

		delete_post_meta( $this->media_id, '_mvs_watermark_url' );
		wp_delete_post( $this->media_id, true );

		remove_all_filters( 'mvs_watermark_enabled' );
		remove_all_filters( 'mvs_generate_watermark' );
		remove_all_filters( 'mvs_watermark_config' );

		parent::tearDown();
	}

	/**
	 * Test: is_enabled returns false by default (stub).
	 */
	public function test_is_enabled_false_by_default(): void {
		$this->assertFalse( $this->service->is_enabled() );
	}

	/**
	 * Test: is_enabled returns true when filter enables it.
	 */
	public function test_is_enabled_true_via_filter(): void {
		add_filter( 'mvs_watermark_enabled', '__return_true' );

		$this->assertTrue( $this->service->is_enabled() );
	}

	/**
	 * Test: get_config returns default configuration.
	 */
	public function test_get_config_defaults(): void {
		$config = $this->service->get_config();

		$this->assertArrayHasKey( 'type', $config );
		$this->assertArrayHasKey( 'text', $config );
		$this->assertArrayHasKey( 'position', $config );
		$this->assertArrayHasKey( 'opacity', $config );
		$this->assertEquals( 'text', $config['type'] );
		$this->assertEquals( 'center', $config['position'] );
	}

	/**
	 * Test: get_config is filterable.
	 */
	public function test_get_config_filterable(): void {
		add_filter(
			'mvs_watermark_config',
			function ( $config ) {
				$config['position'] = 'bottom-right';
				$config['opacity']  = 80;
				return $config;
			}
		);

		$config = $this->service->get_config();

		$this->assertEquals( 'bottom-right', $config['position'] );
		$this->assertEquals( 80, $config['opacity'] );
	}

	/**
	 * Test: get_preview_url returns empty for non-image media.
	 */
	public function test_preview_url_empty_for_non_image(): void {
		update_post_meta( $this->media_id, '_mvs_file_type', 'video/mp4' );

		$url = $this->service->get_preview_url( $this->media_id );

		$this->assertEmpty( $url );
	}

	/**
	 * Test: get_preview_url returns empty for non-gated media.
	 */
	public function test_preview_url_empty_for_non_gated(): void {
		$url = $this->service->get_preview_url( $this->media_id );

		$this->assertEmpty( $url );
	}

	/**
	 * Test: get_preview_url returns cached URL from post meta.
	 */
	public function test_preview_url_returns_cached(): void {
		$this->access_rules->add_rule( $this->media_id, 'purchase', 'one-time', 4.99, 'USD' );
		$cached_url = 'http://example.com/watermarked/test.jpg';
		update_post_meta( $this->media_id, '_mvs_watermark_url', $cached_url );

		$url = $this->service->get_preview_url( $this->media_id );

		$this->assertEquals( $cached_url, $url );
	}

	/**
	 * Test: get_preview_url delegates to Pro filter.
	 */
	public function test_preview_url_delegates_to_filter(): void {
		$this->access_rules->add_rule( $this->media_id, 'purchase', 'one-time', 4.99, 'USD' );

		$expected_url = 'http://example.com/watermarked/generated.jpg';
		add_filter(
			'mvs_generate_watermark',
			function () use ( $expected_url ) {
				return $expected_url;
			}
		);

		$url = $this->service->get_preview_url( $this->media_id );

		$this->assertEquals( $expected_url, $url );

		// Should also be cached in post meta.
		$this->assertEquals( $expected_url, get_post_meta( $this->media_id, '_mvs_watermark_url', true ) );
	}

	/**
	 * Test: invalidate clears cached watermark.
	 */
	public function test_invalidate_clears_cache(): void {
		update_post_meta( $this->media_id, '_mvs_watermark_url', 'http://example.com/old.jpg' );

		$this->service->invalidate( $this->media_id );

		$this->assertEmpty( get_post_meta( $this->media_id, '_mvs_watermark_url', true ) );
	}

	/**
	 * Test: has_preview returns correct boolean.
	 */
	public function test_has_preview(): void {
		$this->assertFalse( $this->service->has_preview( $this->media_id ) );

		$this->access_rules->add_rule( $this->media_id, 'purchase', 'one-time', 4.99, 'USD' );
		update_post_meta( $this->media_id, '_mvs_watermark_url', 'http://example.com/preview.jpg' );

		// Need a fresh service to clear internal cache.
		$fresh = new WatermarkService( $this->access_rules );
		$this->assertTrue( $fresh->has_preview( $this->media_id ) );
	}

	/**
	 * Test: add_preview_url skips when disabled.
	 */
	public function test_add_preview_url_skips_when_disabled(): void {
		$data = array( 'id' => $this->media_id, 'file_url' => 'http://example.com/test.jpg' );

		$result = $this->service->add_preview_url( $data, $this->media_id );

		$this->assertArrayNotHasKey( 'preview_url', $result );
	}

	/**
	 * Test: add_preview_url adds preview when enabled and available.
	 */
	public function test_add_preview_url_adds_when_enabled(): void {
		add_filter( 'mvs_watermark_enabled', '__return_true' );
		$this->access_rules->add_rule( $this->media_id, 'purchase', 'one-time', 4.99, 'USD' );
		update_post_meta( $this->media_id, '_mvs_watermark_url', 'http://example.com/watermarked.jpg' );

		$fresh = new WatermarkService( $this->access_rules );
		$data  = array( 'id' => $this->media_id, 'file_url' => 'http://example.com/test.jpg' );

		$result = $fresh->add_preview_url( $data, $this->media_id );

		$this->assertEquals( 'http://example.com/watermarked.jpg', $result['preview_url'] );
		$this->assertTrue( $result['watermarked'] );
	}

	/**
	 * Test: POSITIONS constant has all expected values.
	 */
	public function test_positions_constant(): void {
		$this->assertContains( 'center', WatermarkService::POSITIONS );
		$this->assertContains( 'bottom-right', WatermarkService::POSITIONS );
		$this->assertContains( 'tile', WatermarkService::POSITIONS );
		$this->assertCount( 6, WatermarkService::POSITIONS );
	}
}
