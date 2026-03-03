<?php
/**
 * Tests for FavoriteService.
 *
 * @package WPMediaVerse
 */

use WPMediaVerse\Social\FavoriteService;

class FavoriteServiceTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Service instance.
	 *
	 * @var FavoriteService
	 */
	private $service;

	/**
	 * Test media post ID.
	 *
	 * @var int
	 */
	private $media_id;

	/**
	 * Second test media post ID.
	 *
	 * @var int
	 */
	private $media_id_2;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $user_id;

	protected function setUp(): void {
		parent::setUp();

		$this->service = new FavoriteService();
		$this->user_id = 1;

		$this->media_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Favorite Test Media',
				'post_status' => 'publish',
				'post_author' => $this->user_id,
			)
		);

		$this->media_id_2 = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Favorite Test Media 2',
				'post_status' => 'publish',
				'post_author' => $this->user_id,
			)
		);
	}

	protected function tearDown(): void {
		wp_delete_post( $this->media_id, true );
		wp_delete_post( $this->media_id_2, true );

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mvs_favorites WHERE user_id = {$this->user_id}" );

		parent::tearDown();
	}

	public function test_toggle_adds_favorite(): void {
		$result = $this->service->toggle( $this->media_id, $this->user_id );

		$this->assertEquals( 'added', $result['action'] );
		$this->assertTrue( $result['favorited'] );
	}

	public function test_toggle_removes_existing_favorite(): void {
		$this->service->toggle( $this->media_id, $this->user_id );
		$result = $this->service->toggle( $this->media_id, $this->user_id );

		$this->assertEquals( 'removed', $result['action'] );
		$this->assertFalse( $result['favorited'] );
	}

	public function test_is_favorited(): void {
		$this->assertFalse( $this->service->is_favorited( $this->media_id, $this->user_id ) );

		$this->service->toggle( $this->media_id, $this->user_id );
		$this->assertTrue( $this->service->is_favorited( $this->media_id, $this->user_id ) );
	}

	public function test_get_count(): void {
		$this->assertEquals( 0, $this->service->get_count( $this->media_id ) );

		$this->service->toggle( $this->media_id, 1 );
		$this->service->toggle( $this->media_id, 2 );

		$this->assertEquals( 2, $this->service->get_count( $this->media_id ) );
	}

	public function test_get_user_favorites(): void {
		$this->service->toggle( $this->media_id, $this->user_id );
		$this->service->toggle( $this->media_id_2, $this->user_id );

		$result = $this->service->get_user_favorites( $this->user_id );

		$this->assertEquals( 2, $result['total'] );
		$this->assertCount( 2, $result['items'] );
	}

	public function test_get_user_favorites_pagination(): void {
		$this->service->toggle( $this->media_id, $this->user_id );
		$this->service->toggle( $this->media_id_2, $this->user_id );

		$result = $this->service->get_user_favorites( $this->user_id, null, 1, 1 );

		$this->assertEquals( 2, $result['total'] );
		$this->assertCount( 1, $result['items'] );
	}

	public function test_toggle_with_collection_id(): void {
		$result = $this->service->toggle( $this->media_id, $this->user_id, 42 );

		$this->assertEquals( 'added', $result['action'] );

		$favorites = $this->service->get_user_favorites( $this->user_id, 42 );
		$this->assertEquals( 1, $favorites['total'] );
	}
}
