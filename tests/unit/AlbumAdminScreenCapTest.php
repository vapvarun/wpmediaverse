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
	public function test_list_screen_is_for_site_managers_only( string $post_type ): void {
		$cap = get_post_type_object( $post_type )->cap->edit_posts;

		foreach ( array( 'contributor', 'author', 'editor' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertFalse( current_user_can( $cap ), "$role must not reach the $post_type list" );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( $cap ) );
	}

	/**
	 * @dataProvider post_types
	 *
	 * @param string $post_type Post type.
	 */
	public function test_editor_cannot_edit_or_delete_a_members_item( string $post_type ): void {
		$member  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_author' => $member,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( current_user_can( 'edit_post', $post_id ) );
		$this->assertFalse( current_user_can( 'delete_post', $post_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );
	}

	/**
	 * The cap is copied into core's primitive list for edit_post / publish_post and
	 * never re-mapped, so it must be a real primitive: an administrator who could
	 * open the screen still got "not allowed to edit this post" on Publish.
	 *
	 * @dataProvider post_types
	 *
	 * @param string $post_type Post type.
	 */
	public function test_administrator_can_edit_and_publish_a_new_item( string $post_type ): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'post_author' => $admin,
			)
		);

		$this->assertTrue( current_user_can( 'edit_post', $post_id ), 'administrator must edit a new draft' );
		$this->assertTrue( current_user_can( get_post_type_object( $post_type )->cap->publish_posts ) );
		$this->assertTrue( current_user_can( get_post_type_object( $post_type )->cap->create_posts ), 'administrator must create' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( current_user_can( get_post_type_object( $post_type )->cap->create_posts ), 'an author must not create in wp-admin' );
	}
}
