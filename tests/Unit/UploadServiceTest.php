<?php
/**
 * UploadService tests.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\UploadService;

class UploadServiceTest extends TestCase {

	/**
	 * @var UploadService
	 */
	private $upload;

	/**
	 * Track created media IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $created_ids = array();

	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );
		$this->upload = Plugin::container()->get( 'upload' );
	}

	protected function tearDown(): void {
		global $wpdb;

		foreach ( $this->created_ids as $id ) {
			$file_path = get_post_meta( $id, '_mvs_file_path', true );
			if ( $file_path ) {
				Plugin::container()->get( 'storage' )->get_driver()->delete( $file_path );
			}
			wp_delete_post( $id, true );
			$wpdb->delete( $wpdb->prefix . 'mvs_media_index', array( 'media_id' => $id ) );
			$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $id ) );
		}

		parent::tearDown();
	}

	public function test_upload_valid_jpeg(): void {
		$file = $this->create_test_jpeg();

		$result = $this->upload->handle( $file, 1, array( 'title' => 'Test JPEG' ) );

		$this->assertIsInt( $result );
		$this->created_ids[] = $result;

		$post = get_post( $result );
		$this->assertEquals( 'mvs_media', $post->post_type );
		$this->assertEquals( 'Test JPEG', $post->post_title );
		$this->assertEquals( 'image/jpeg', get_post_meta( $result, '_mvs_file_type', true ) );
		$this->assertEquals( 'image', get_post_meta( $result, '_mvs_media_type', true ) );
		$this->assertNotEmpty( get_post_meta( $result, '_mvs_file_hash', true ) );
	}

	public function test_upload_rejects_invalid_mime(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'mvs' ) . '.exe';
		file_put_contents( $tmp, 'MZ' . str_repeat( "\0", 100 ) );

		$file = array(
			'tmp_name' => $tmp,
			'name'     => 'malware.exe',
			'size'     => filesize( $tmp ),
			'type'     => 'application/x-msdownload',
			'error'    => 0,
		);

		$result = $this->upload->handle( $file, 1 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'mvs_invalid_type', $result->get_error_code() );

		@unlink( $tmp );
	}

	public function test_upload_rejects_oversized_file(): void {
		// Set a tiny max size.
		update_option( 'mvs_max_upload_size', 10 );

		$file = $this->create_test_jpeg();

		$result = $this->upload->handle( $file, 1 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'mvs_file_too_large', $result->get_error_code() );

		// Restore default.
		update_option( 'mvs_max_upload_size', 104857600 );
		@unlink( $file['tmp_name'] );
	}

	public function test_upload_creates_index_row(): void {
		global $wpdb;

		$file   = $this->create_test_jpeg();
		$result = $this->upload->handle( $file, 1, array( 'privacy' => 'members' ) );

		$this->assertIsInt( $result );
		$this->created_ids[] = $result;

		$index = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
				$result
			),
			ARRAY_A
		);

		$this->assertNotNull( $index );
		$this->assertEquals( 'image', $index['media_type'] );
		$this->assertEquals( 'members', $index['privacy'] );
		$this->assertEquals( '1', $index['post_author'] );
	}

	public function test_upload_creates_stats_row(): void {
		global $wpdb;

		$file   = $this->create_test_jpeg();
		$result = $this->upload->handle( $file, 1 );

		$this->assertIsInt( $result );
		$this->created_ids[] = $result;

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
				$result
			),
			ARRAY_A
		);

		$this->assertNotNull( $stats );
		$this->assertEquals( '0', $stats['views'] );
	}

	public function test_duplicate_skip_blocks_second_upload(): void {
		update_option( 'mvs_duplicate_action', 'skip' );

		$file1   = $this->create_test_jpeg( 'dup' );
		$result1 = $this->upload->handle( $file1, 1 );
		$this->assertIsInt( $result1 );
		$this->created_ids[] = $result1;

		// Same content → same hash → blocked.
		$file2   = $this->create_test_jpeg( 'dup' );
		$result2 = $this->upload->handle( $file2, 1 );

		$this->assertInstanceOf( 'WP_Error', $result2 );
		$this->assertEquals( 'mvs_duplicate', $result2->get_error_code() );

		update_option( 'mvs_duplicate_action', 'warn' );
		@unlink( $file2['tmp_name'] );
	}

	/**
	 * Create a test JPEG file array.
	 *
	 * @param string $seed Optional seed to make unique content.
	 * @return array File array compatible with UploadService::handle().
	 */
	private function create_test_jpeg( string $seed = '' ): array {
		$tmp = tempnam( sys_get_temp_dir(), 'mvs' ) . '.jpg';
		$img = imagecreatetruecolor( 2, 2 );

		if ( $seed ) {
			// Make content unique per seed.
			$color = imagecolorallocate( $img, crc32( $seed ) % 256, 0, 0 );
			imagesetpixel( $img, 0, 0, $color );
		}

		imagejpeg( $img, $tmp );
		imagedestroy( $img );

		return array(
			'tmp_name' => $tmp,
			'name'     => 'test-' . ( $seed ?: uniqid() ) . '.jpg',
			'size'     => filesize( $tmp ),
			'type'     => 'image/jpeg',
			'error'    => 0,
		);
	}
}
