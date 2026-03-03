<?php
/**
 * REST API integration tests.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WPMediaVerse\Core\Plugin;

class RestMediaTest extends TestCase {

	/**
	 * Track created IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $created_ids = array();

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( 1 );
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
			$wpdb->delete( $wpdb->prefix . 'mvs_media_views', array( 'media_id' => $id ) );
		}

		parent::tearDown();
	}

	public function test_get_empty_media_list(): void {
		$request  = new WP_REST_Request( 'GET', '/mvs/v1/media' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	public function test_upload_and_retrieve_via_rest(): void {
		// Upload via service.
		$media_id = $this->upload_test_media( 'REST Test Image' );
		$this->assertIsInt( $media_id );

		// Retrieve via REST.
		$request  = new WP_REST_Request( 'GET', '/mvs/v1/media/' . $media_id );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( $media_id, $data['id'] );
		$this->assertEquals( 'REST Test Image', $data['title'] );
		$this->assertEquals( 'public', $data['privacy'] );
		$this->assertEquals( 'image', $data['media_type'] );
	}

	public function test_update_media_via_rest(): void {
		$media_id = $this->upload_test_media( 'Before Update' );

		$request = new WP_REST_Request( 'PUT', '/mvs/v1/media/' . $media_id );
		$request->set_param( 'title', 'After Update' );
		$request->set_param( 'privacy', 'members' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'After Update', $data['title'] );
		$this->assertEquals( 'members', $data['privacy'] );
	}

	public function test_delete_media_via_rest(): void {
		$media_id = $this->upload_test_media( 'To Delete' );

		// Remove from cleanup list since we're deleting manually.
		$this->created_ids = array_diff( $this->created_ids, array( $media_id ) );

		$request  = new WP_REST_Request( 'DELETE', '/mvs/v1/media/' . $media_id );
		$response = rest_do_request( $request );

		$this->assertEquals( 204, $response->get_status() );
		$this->assertNull( get_post( $media_id ) );
	}

	public function test_record_view_increments_stats(): void {
		global $wpdb;

		$media_id = $this->upload_test_media( 'View Test' );

		$request  = new WP_REST_Request( 'POST', '/mvs/v1/media/' . $media_id . '/view' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$views = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT views FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
				$media_id
			)
		);
		$this->assertEquals( 1, $views );
	}

	public function test_private_media_returns_403_for_anon(): void {
		$media_id = $this->upload_test_media( 'Private Item', 'private' );

		// Switch to anonymous.
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mvs/v1/media/' . $media_id );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );

		// Restore admin for cleanup.
		wp_set_current_user( 1 );
	}

	public function test_access_endpoint(): void {
		$media_id = $this->upload_test_media( 'Access Check' );

		$request  = new WP_REST_Request( 'GET', '/mvs/v1/media/' . $media_id . '/access' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['can_view'] );
		$this->assertTrue( $data['is_owner'] );
	}

	public function test_media_list_pagination_headers(): void {
		$id1 = $this->upload_test_media( 'Page Item 1' );
		$id2 = $this->upload_test_media( 'Page Item 2' );

		$request = new WP_REST_Request( 'GET', '/mvs/v1/media' );
		$request->set_param( 'per_page', 1 );
		$response = rest_do_request( $request );

		$headers = $response->get_headers();
		$this->assertGreaterThanOrEqual( 2, (int) $headers['X-WP-Total'] );
		$this->assertGreaterThanOrEqual( 2, (int) $headers['X-WP-TotalPages'] );
		$this->assertCount( 1, $response->get_data() );
	}

	/**
	 * Helper: upload a test media item.
	 *
	 * @param string $title   Media title.
	 * @param string $privacy Privacy level.
	 * @return int Media post ID.
	 */
	private function upload_test_media( string $title, string $privacy = 'public' ): int {
		$tmp = tempnam( sys_get_temp_dir(), 'mvs' ) . '.jpg';
		$img = imagecreatetruecolor( 2, 2 );
		$col = imagecolorallocate( $img, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) );
		imagesetpixel( $img, 0, 0, $col );
		imagejpeg( $img, $tmp );
		imagedestroy( $img );

		$upload = Plugin::container()->get( 'upload' );
		$result = $upload->handle(
			array(
				'tmp_name' => $tmp,
				'name'     => sanitize_title( $title ) . '.jpg',
				'size'     => filesize( $tmp ),
				'type'     => 'image/jpeg',
				'error'    => 0,
			),
			1,
			array(
				'title'   => $title,
				'privacy' => $privacy,
			)
		);

		$this->assertIsInt( $result, 'Upload should return int media ID, got: ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'non-int' ) );
		$this->created_ids[] = $result;

		return $result;
	}
}
