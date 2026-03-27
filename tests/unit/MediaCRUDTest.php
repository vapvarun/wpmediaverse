<?php
/**
 * Test media CRUD operations.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class MediaCRUDTest extends WP_UnitTestCase {

	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function test_create_media_post(): void {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Test Photo',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( 'mvs_media', get_post_type( $post_id ) );
	}

	public function test_media_meta_fields(): void {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Test Photo',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		update_post_meta( $post_id, '_mvs_media_type', 'image' );
		update_post_meta( $post_id, '_mvs_privacy', 'public' );
		update_post_meta( $post_id, '_mvs_file_size', 1024000 );

		$this->assertSame( 'image', get_post_meta( $post_id, '_mvs_media_type', true ) );
		$this->assertSame( 'public', get_post_meta( $post_id, '_mvs_privacy', true ) );
		$this->assertSame( '1024000', get_post_meta( $post_id, '_mvs_file_size', true ) );
	}

	public function test_create_album(): void {
		$album_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_album',
				'post_title'  => 'Test Album',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		$this->assertSame( 'mvs_album', get_post_type( $album_id ) );
	}

	public function test_create_collection(): void {
		$col_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_collection',
				'post_title'  => 'Test Collection',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		$this->assertSame( 'mvs_collection', get_post_type( $col_id ) );
		update_post_meta( $col_id, '_mvs_collection_type', 'smart' );
		$this->assertSame( 'smart', get_post_meta( $col_id, '_mvs_collection_type', true ) );
	}

	public function test_media_taxonomy_assignment(): void {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Tagged Photo',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		wp_set_object_terms( $post_id, array( 'nature', 'landscape' ), 'mvs_tag' );
		wp_set_object_terms( $post_id, array( 'Photography' ), 'mvs_category' );

		$tags = wp_get_object_terms( $post_id, 'mvs_tag', array( 'fields' => 'names' ) );
		$this->assertContains( 'nature', $tags );
		$this->assertContains( 'landscape', $tags );

		$cats = wp_get_object_terms( $post_id, 'mvs_category', array( 'fields' => 'names' ) );
		$this->assertContains( 'Photography', $cats );
	}

	public function test_delete_media(): void {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Delete Me',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		wp_delete_post( $post_id, true );
		$this->assertNull( get_post( $post_id ) );
	}
}
