<?php
/**
 * Test custom capabilities assignment.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class CapabilitiesTest extends WP_UnitTestCase {

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

	public function test_subscriber_can_upload(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );

		$this->assertTrue( current_user_can( 'upload_mvs_media' ), 'Subscribers should have upload capability in WPMediaVerse.' );
	}

	public function test_subscriber_cannot_manage_settings(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );

		$this->assertFalse( current_user_can( 'manage_mvs_settings' ) );
	}

	public function test_admin_has_moderate_capability(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue( current_user_can( 'moderate_mvs_media' ) );
	}

	public function test_subscriber_cannot_moderate(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );

		$this->assertFalse( current_user_can( 'moderate_mvs_media' ) );
	}

	public function test_contributor_can_upload(): void {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $contributor );

		// Since 1.1.0 all roles including contributors get upload capability on activation.
		$this->assertTrue( current_user_can( 'upload_mvs_media' ), 'Contributors should have upload capability since 1.1.0.' );
	}
}
