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

	/**
	 * Media taxonomies ride on mvs_album only to keep their term screen. The Albums
	 * list must not offer them: Quick/Bulk Edit would write album IDs into the
	 * media term space. Basecamp 10379842826.
	 */
	public function test_media_taxonomies_are_not_on_the_albums_list(): void {
		foreach ( array( 'mvs_tag', 'mvs_category' ) as $taxonomy ) {
			$tax = get_taxonomy( $taxonomy );
			$this->assertFalse( $tax->show_admin_column, $taxonomy . ' column on Albums' );
			$this->assertFalse( $tax->show_in_quick_edit, $taxonomy . ' in Albums Quick/Bulk Edit' );
		}
	}

	public function test_mvs_media_is_not_a_cpt(): void {
		$this->assertFalse( post_type_exists( 'mvs_media' ), 'mvs_media should NOT be a CPT — media lives in custom tables.' );
	}
}
