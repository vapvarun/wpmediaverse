<?php
/**
 * Test FavoriteService operations.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Social\FavoriteService;
use WPMediaVerse\Services\MediaMeta;

class FavoriteServiceTest extends WP_UnitTestCase {

	private FavoriteService $service;
	private int $user_id;
	private int $media_id;

	public function set_up(): void {
		parent::set_up();
		$this->service  = new FavoriteService();
		$this->user_id  = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->media_id = MediaMeta::insert(
			array(
				'title'       => 'Test Photo',
				'post_author' => $this->user_id,
				'media_type'  => 'image',
			)
		);
	}

	/**
	 * Toggle adds a favorite and returns the correct response.
	 */
	public function test_toggle_add_favorite(): void {
		$result = $this->service->toggle( $this->media_id, $this->user_id );

		$this->assertSame( 'added', $result['action'] );
		$this->assertTrue( $result['favorited'] );
	}

	/**
	 * Toggling twice removes the favorite and returns the correct response.
	 */
	public function test_toggle_remove_favorite(): void {
		$this->service->toggle( $this->media_id, $this->user_id );
		$result = $this->service->toggle( $this->media_id, $this->user_id );

		$this->assertSame( 'removed', $result['action'] );
		$this->assertFalse( $result['favorited'] );
	}

	/**
	 * is_favorited returns true after a favorite is added.
	 */
	public function test_is_favorited_true(): void {
		$this->service->toggle( $this->media_id, $this->user_id );

		$this->assertTrue( $this->service->is_favorited( $this->media_id, $this->user_id ) );
	}

	/**
	 * is_favorited returns false when no favorite exists.
	 */
	public function test_is_favorited_false(): void {
		$this->assertFalse( $this->service->is_favorited( $this->media_id, $this->user_id ) );
	}

	/**
	 * get_user_favorites returns correct items after adding 3 favorites.
	 */
	public function test_get_user_favorites(): void {
		$media_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$mid = MediaMeta::insert(
				array(
					'title'       => "Fav Photo {$i}",
					'post_author' => $this->user_id,
					'media_type'  => 'image',
				)
			);
			$this->service->toggle( $mid, $this->user_id );
			$media_ids[] = $mid;
		}

		$result = $this->service->get_user_favorites( $this->user_id );

		$this->assertSame( 3, $result['total'] );
		$this->assertCount( 3, $result['items'] );

		$returned_ids = array_map( 'intval', wp_list_pluck( $result['items'], 'media_id' ) );
		foreach ( $media_ids as $mid ) {
			$this->assertContains( $mid, $returned_ids );
		}
	}

	/**
	 * get_user_favorites respects pagination parameters.
	 */
	public function test_get_user_favorites_pagination(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$mid = MediaMeta::insert(
				array(
					'title'       => "Paged Photo {$i}",
					'post_author' => $this->user_id,
					'media_type'  => 'image',
				)
			);
			$this->service->toggle( $mid, $this->user_id );
		}

		$page1 = $this->service->get_user_favorites( $this->user_id, null, 2, 1 );
		$page2 = $this->service->get_user_favorites( $this->user_id, null, 2, 2 );
		$page3 = $this->service->get_user_favorites( $this->user_id, null, 2, 3 );

		$this->assertSame( 5, $page1['total'] );
		$this->assertCount( 2, $page1['items'] );
		$this->assertCount( 2, $page2['items'] );
		$this->assertCount( 1, $page3['items'] );

		// Ensure no overlap between page 1 and page 2.
		$ids_p1 = wp_list_pluck( $page1['items'], 'media_id' );
		$ids_p2 = wp_list_pluck( $page2['items'], 'media_id' );
		$this->assertEmpty( array_intersect( $ids_p1, $ids_p2 ) );
	}

	/**
	 * get_count returns the total number of users who favorited a media item.
	 */
	public function test_get_count(): void {
		$user_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$uid        = self::factory()->user->create( array( 'role' => 'subscriber' ) );
			$user_ids[] = $uid;
			$this->service->toggle( $this->media_id, $uid );
		}

		$this->assertSame( 3, $this->service->get_count( $this->media_id ) );
	}

	/**
	 * Toggle with a collection_id stores the collection association.
	 */
	public function test_toggle_with_collection(): void {
		$collection_id = MediaMeta::insert(
			array(
				'title'       => 'Test Collection',
				'post_author' => $this->user_id,
				'media_type'  => 'collection',
			)
		);

		$result = $this->service->toggle( $this->media_id, $this->user_id, $collection_id );

		$this->assertSame( 'added', $result['action'] );
		$this->assertTrue( $result['favorited'] );

		// Verify the collection_id was persisted by fetching favorites filtered by collection.
		$favs = $this->service->get_user_favorites( $this->user_id, $collection_id );
		$this->assertSame( 1, $favs['total'] );
		$this->assertCount( 1, $favs['items'] );
		$this->assertEquals( $this->media_id, $favs['items'][0]['media_id'] );
		$this->assertEquals( $collection_id, $favs['items'][0]['collection_id'] );
	}
}
