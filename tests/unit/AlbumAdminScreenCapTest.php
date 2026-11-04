<?php
/**
 * The Albums and Collections admin lists are for site managers only.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

/**
 * Tied to edit_posts, an Author or Editor got MediaVerse > Albums / Collections
 * in wp-admin, and both answered 403 under a parent that needs
 * mvs_settings_screen (Basecamp 10463043524).
 */
class AlbumAdminScreenCapTest extends WP_UnitTestCase {

	/**
	 * Data provider: the two post types.
	 *
	 * @return array
	 */
	public function post_types(): array {
		return array( array( 'mvs_album' ), array( 'mvs_collection' ) );
	}

	/**
	 * @dataProvider post_types
	 *
	 * @param string $post_type Post type.
	 */
	public function test_list_screen_needs_the_settings_screen_cap( string $post_type ): void {
		$cap = get_post_type_object( $post_type )->cap->edit_posts;

		$this->assertSame( 'mvs_settings_screen', $cap );

		foreach ( array( 'contributor', 'author', 'editor' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertFalse( current_user_can( $cap ), "$role must not reach the $post_type list" );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( $cap ) );
	}
}
