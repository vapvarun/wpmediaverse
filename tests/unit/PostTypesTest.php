<?php
/**
 * Test custom post types and taxonomies registration.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class PostTypesTest extends WP_UnitTestCase {

	public function test_mvs_media_post_type_registered(): void {
		$this->assertTrue( post_type_exists( 'mvs_media' ) );
	}

	public function test_mvs_album_post_type_registered(): void {
		$this->assertTrue( post_type_exists( 'mvs_album' ) );
	}

	public function test_mvs_collection_post_type_registered(): void {
		$this->assertTrue( post_type_exists( 'mvs_collection' ) );
	}

	public function test_mvs_tag_taxonomy_registered(): void {
		$this->assertTrue( taxonomy_exists( 'mvs_tag' ) );
	}

	public function test_mvs_category_taxonomy_registered(): void {
		$this->assertTrue( taxonomy_exists( 'mvs_category' ) );
	}

	public function test_mvs_media_supports_rest(): void {
		$pt = get_post_type_object( 'mvs_media' );
		$this->assertTrue( $pt->show_in_rest );
	}

	public function test_mvs_media_has_correct_capability_type(): void {
		$pt = get_post_type_object( 'mvs_media' );
		$this->assertSame( 'mvs_media', $pt->capability_type );
	}
}
