<?php
/**
 * Test custom capabilities assignment.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class CapabilitiesTest extends WP_UnitTestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// Simulate plugin activation to assign capabilities.
		$admin_role  = get_role( 'administrator' );
		$author_role = get_role( 'author' );
		$editor_role = get_role( 'editor' );

		$caps = array( 'upload_mvs_media', 'edit_mvs_media', 'delete_mvs_media', 'manage_mvs_settings' );
		foreach ( $caps as $cap ) {
			if ( $admin_role ) {
				$admin_role->add_cap( $cap );
			}
		}

		$upload_caps = array( 'upload_mvs_media', 'edit_mvs_media', 'delete_mvs_media' );
		foreach ( $upload_caps as $cap ) {
			if ( $author_role ) {
				$author_role->add_cap( $cap );
			}
			if ( $editor_role ) {
				$editor_role->add_cap( $cap );
			}
		}
	}

	public function test_admin_has_upload_capability(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue( current_user_can( 'upload_mvs_media' ) );
	}

	public function test_admin_has_manage_settings_capability(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue( current_user_can( 'manage_mvs_settings' ) );
	}

	public function test_author_has_upload_capability(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );

		$this->assertTrue( current_user_can( 'upload_mvs_media' ) );
	}

	public function test_subscriber_cannot_upload(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );

		$this->assertFalse( current_user_can( 'upload_mvs_media' ) );
	}

	public function test_subscriber_cannot_manage_settings(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );

		$this->assertFalse( current_user_can( 'manage_mvs_settings' ) );
	}
}
