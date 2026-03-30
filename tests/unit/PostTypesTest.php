<?php
/**
 * Test custom post types and taxonomies registration.
 *
 * Note: mvs_media is NO LONGER a CPT — media lives in mvs_media_index custom table.
 * Albums and Collections remain as CPTs.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class PostTypesTest extends WP_UnitTestCase {

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

	public function test_mvs_media_is_not_a_cpt(): void {
		$this->assertFalse( post_type_exists( 'mvs_media' ), 'mvs_media should NOT be a CPT — media lives in custom tables.' );
	}
}
