<?php
/**
 * Unit tests for ImageOptimizationService.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\ImageOptimizationService;

class ImageOptimizationServiceTest extends WP_UnitTestCase {

	/**
	 * Test JPEG bytes — a 4x4 image with no metadata payload, embedded
	 * inline so the test has no external fixture dependency. base64 chosen
	 * over raw bytes for clean source-control diff.
	 */
	private const TEST_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAAEAAQDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/wA//2Q==';

	private string $temp_jpeg = '';

	public function setUp(): void {
		parent::setUp();
		$this->temp_jpeg = wp_tempnam( 'mvs-opt-test-' ) . '.jpg';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->temp_jpeg, base64_decode( self::TEST_JPEG_BASE64 ) );
	}

	public function tearDown(): void {
		if ( '' !== $this->temp_jpeg && file_exists( $this->temp_jpeg ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $this->temp_jpeg );
		}
		// Clean up potential WebP sibling.
		$webp = preg_replace( '/\.jpg$/', '.webp', $this->temp_jpeg );
		if ( is_string( $webp ) && file_exists( $webp ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $webp );
		}
		parent::tearDown();
	}

	public function test_filter_fires_with_expected_context(): void {
		$captured = array();
		$cb       = static function ( $path, $context ) use ( &$captured ) {
			$captured = $context;
			return $path;
		};
		add_filter( 'mvs_optimize_image', $cb, 10, 2 );

		$service = new ImageOptimizationService();
		$service->optimize(
			$this->temp_jpeg,
			array(
				'media_id' => 42,
				'variant'  => 'original',
				'mime'     => 'image/jpeg',
				'user_id'  => 7,
			)
		);

		remove_filter( 'mvs_optimize_image', $cb, 10 );

		$this->assertSame( 42, $captured['media_id'] );
		$this->assertSame( 'original', $captured['variant'] );
		$this->assertSame( 'image/jpeg', $captured['mime'] );
		$this->assertSame( 7, $captured['user_id'] );
	}

	public function test_optimize_returns_path_when_filter_returns_wp_error(): void {
		$cb = static function () {
			return new \WP_Error( 'test_failure', 'simulated' );
		};
		add_filter( 'mvs_optimize_image', $cb, 10, 2 );

		$service = new ImageOptimizationService();
		$result  = $service->optimize(
			$this->temp_jpeg,
			array(
				'media_id' => 1,
				'variant'  => 'original',
				'mime'     => 'image/jpeg',
				'user_id'  => 1,
			)
		);

		remove_filter( 'mvs_optimize_image', $cb, 10 );

		// Original path returned; file still exists.
		$this->assertSame( $this->temp_jpeg, $result );
		$this->assertFileExists( $this->temp_jpeg );
	}

	public function test_optimize_falls_back_when_filter_returns_missing_path(): void {
		$cb = static function () {
			return '/nonexistent/path/to/file.jpg';
		};
		add_filter( 'mvs_optimize_image', $cb, 10, 2 );

		$service = new ImageOptimizationService();
		$result  = $service->optimize(
			$this->temp_jpeg,
			array(
				'media_id' => 1,
				'variant'  => 'original',
				'mime'     => 'image/jpeg',
				'user_id'  => 1,
			)
		);

		remove_filter( 'mvs_optimize_image', $cb, 10 );

		$this->assertSame( $this->temp_jpeg, $result );
	}

	public function test_disabled_originals_setting_still_dispatches_filter(): void {
		update_option( ImageOptimizationService::SETTING_OPTIMIZE_ORIGINALS, false );

		$fired = false;
		$cb    = static function ( $path ) use ( &$fired ) {
			$fired = true;
			return $path;
		};
		add_filter( 'mvs_optimize_image', $cb, 10, 2 );

		$service = new ImageOptimizationService();
		$service->optimize(
			$this->temp_jpeg,
			array(
				'media_id' => 1,
				'variant'  => 'original',
				'mime'     => 'image/jpeg',
				'user_id'  => 1,
			)
		);

		remove_filter( 'mvs_optimize_image', $cb, 10 );
		delete_option( ImageOptimizationService::SETTING_OPTIMIZE_ORIGINALS );

		// External compressors must still get a crack at the file even when
		// our built-in lossless pass is off.
		$this->assertTrue( $fired );
	}

	public function test_emit_webp_sibling_returns_null_when_disabled(): void {
		// WP short-circuits update_option(X, false) when the option does not yet
		// exist (old value is also false), leaving the row unset and get_option
		// returning the true default. register_setting's default-filter would
		// fix this in real admin requests, but it runs on admin_init and is not
		// active in unit tests. Use a non-false falsy value to force a real write.
		update_option( ImageOptimizationService::SETTING_GENERATE_WEBP, '0' );

		$service = new ImageOptimizationService();
		$result  = $service->emit_webp_sibling(
			$this->temp_jpeg,
			array(
				'media_id' => 1,
				'variant'  => 'original',
				'mime'     => 'image/jpeg',
				'user_id'  => 1,
			)
		);

		delete_option( ImageOptimizationService::SETTING_GENERATE_WEBP );

		$this->assertNull( $result );
	}

	public function test_is_enabled_defaults(): void {
		delete_option( ImageOptimizationService::SETTING_OPTIMIZE_ORIGINALS );
		delete_option( ImageOptimizationService::SETTING_GENERATE_WEBP );

		$service = new ImageOptimizationService();
		// 'originals' is a LOSSY JPEG re-encode, so it is opt-in (default off) to
		// keep uploaded photos untouched (Basecamp #10073918955). WebP generation
		// is lossless-sibling and stays default-on.
		$this->assertFalse( $service->is_enabled( 'originals' ) );
		$this->assertTrue( $service->is_enabled( 'webp' ) );
		$this->assertFalse( $service->is_enabled( 'unknown' ) );
	}

	public function test_variant_keys_match_thumbnail_size_keys(): void {
		// Sanity check: the keys exposed for bulk variant iteration must
		// match the size keys produced by UploadService::get_thumbnail_sizes.
		// If someone renames a thumbnail size without updating this list,
		// the bulk command silently skips that variant.
		$this->assertSame( array( 'large', 'medium', 'thumb' ), ImageOptimizationService::variant_keys() );
	}

	public function test_optimize_media_reports_not_an_image_for_video(): void {
		// We can't easily insert a real mvs_media_index row without bootstrap
		// fixtures, but we can verify the early-exit path by inserting a
		// row directly.
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_media_index';

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'title'             => 'test video',
				'post_author'       => 1,
				'status'            => 'publish',
				'media_type'        => 'video',
				'privacy'           => 'public',
				'moderation_status' => 'approved',
				'file_url'          => 'http://example.com/foo.mp4',
				'file_path'         => '2026/05/foo.mp4',
				'file_type'         => 'video/mp4',
				'file_size'         => 12345,
				'file_hash'         => str_repeat( 'a', 64 ),
				'created_at'        => current_time( 'mysql', true ),
			)
		);
		$media_id = (int) $wpdb->insert_id;

		$service = new ImageOptimizationService();
		$result  = $service->optimize_media( $media_id );

		$this->assertContains( 'not_an_image', $result['errors'] );
		$this->assertSame( 0, $result['variants_processed'] );

		$wpdb->delete( $table, array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
