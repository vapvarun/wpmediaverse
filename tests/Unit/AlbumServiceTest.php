<?php
/**
 * Tests for AlbumService.
 *
 * @package WPMediaVerse
 */

use WPMediaVerse\Services\AlbumService;

class AlbumServiceTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Service instance.
	 *
	 * @var AlbumService
	 */
	private $service;

	/**
	 * Test album post ID.
	 *
	 * @var int
	 */
	private $album_id;

	/**
	 * Test media post IDs.
	 *
	 * @var int[]
	 */
	private $media_ids = array();

	protected function setUp(): void {
		parent::setUp();

		$this->service = new AlbumService();

		$this->album_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_album',
				'post_title'  => 'Test Album',
				'post_status' => 'publish',
				'post_author' => 1,
			)
		);

		// Create test media.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->media_ids[] = wp_insert_post(
				array(
					'post_type'   => 'mvs_media',
					'post_title'  => 'Album Media ' . $i,
					'post_status' => 'publish',
					'post_author' => 1,
				)
			);
		}
	}

	protected function tearDown(): void {
		$this->service->delete_all_items( $this->album_id );
		wp_delete_post( $this->album_id, true );

		foreach ( $this->media_ids as $id ) {
			wp_delete_post( $id, true );
		}

		parent::tearDown();
	}

	public function test_add_items(): void {
		$added = $this->service->add_items( $this->album_id, $this->media_ids );
		$this->assertEquals( 3, $added );
	}

	public function test_get_items(): void {
		$this->service->add_items( $this->album_id, $this->media_ids );
		$items = $this->service->get_items( $this->album_id );

		$this->assertCount( 3, $items );
		$this->assertEquals( $this->media_ids[0], (int) $items[0]['media_id'] );
	}

	public function test_get_item_count(): void {
		$this->assertEquals( 0, $this->service->get_item_count( $this->album_id ) );

		$this->service->add_items( $this->album_id, $this->media_ids );
		$this->assertEquals( 3, $this->service->get_item_count( $this->album_id ) );
	}

	public function test_remove_item(): void {
		$this->service->add_items( $this->album_id, $this->media_ids );

		$removed = $this->service->remove_item( $this->album_id, $this->media_ids[1] );
		$this->assertTrue( $removed );
		$this->assertEquals( 2, $this->service->get_item_count( $this->album_id ) );
	}

	public function test_remove_nonexistent_item(): void {
		$removed = $this->service->remove_item( $this->album_id, 999999 );
		$this->assertFalse( $removed );
	}

	public function test_reorder(): void {
		$this->service->add_items( $this->album_id, $this->media_ids );

		// Reverse order.
		$reversed = array_reverse( $this->media_ids );
		$this->service->reorder( $this->album_id, $reversed );

		$items = $this->service->get_items( $this->album_id );
		$this->assertEquals( $reversed[0], (int) $items[0]['media_id'] );
		$this->assertEquals( $reversed[2], (int) $items[2]['media_id'] );
	}

	public function test_delete_all_items(): void {
		$this->service->add_items( $this->album_id, $this->media_ids );
		$deleted = $this->service->delete_all_items( $this->album_id );

		$this->assertEquals( 3, $deleted );
		$this->assertEquals( 0, $this->service->get_item_count( $this->album_id ) );
	}

	public function test_add_items_skips_invalid_post_type(): void {
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Not Media',
				'post_status' => 'publish',
			)
		);

		$added = $this->service->add_items( $this->album_id, array( $page_id ) );
		$this->assertEquals( 0, $added );

		wp_delete_post( $page_id, true );
	}

	public function test_add_items_no_duplicates(): void {
		$this->service->add_items( $this->album_id, array( $this->media_ids[0] ) );
		// Adding same media again should fail (UNIQUE constraint).
		$added = $this->service->add_items( $this->album_id, array( $this->media_ids[0] ) );

		$this->assertEquals( 0, $added );
		$this->assertEquals( 1, $this->service->get_item_count( $this->album_id ) );
	}
}
