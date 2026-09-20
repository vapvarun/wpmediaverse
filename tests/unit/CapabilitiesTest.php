<?php
/**
 * Test custom capabilities assignment.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Capabilities\MediaCapabilities;

class CapabilitiesTest extends WP_UnitTestCase {

	/**
	 * Put the roles back, whatever happened.
	 *
	 * `remove_caps()` below strips every role, and the framework does NOT undo
	 * it: WP_UnitTestCase_Base wraps each test in a DB transaction and restores
	 * hooks, but roles live in the `wp_user_roles` option AND in the hydrated
	 * `$wp_roles` global, and a ROLLBACK reaches neither. So a test that strips
	 * caps poisons every test that runs after it.
	 *
	 * Measured, not assumed: with the restore inline after the assertion (where
	 * a failure skips it), MediaTitleValidationTest's administrator lost
	 * `edit_mvs_media` and its four REST tests began returning 403. Restoring
	 * here runs on the failure path too.
	 */
	public function tear_down(): void {
		MediaCapabilities::add_caps();
		parent::tear_down();
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

	/**
	 * The invariant the two-list version could not hold.
	 *
	 * Fails the moment a capability is granted to a role but not covered by
	 * `all_caps()` - which is what uninstall removes. Without this, drift is
	 * only visible by inspecting a role table after uninstalling, and nobody
	 * does that (Basecamp 10296868773, 10298133325).
	 */
	public function test_every_granted_cap_is_removable(): void {
		$granted = array();
		foreach ( MediaCapabilities::get_role_caps() as $role_caps ) {
			$granted = array_merge( $granted, $role_caps );
		}
		$granted   = array_unique( $granted );
		$removable = MediaCapabilities::all_caps();

		$missing = array_diff( $granted, $removable );

		$this->assertSame(
			array(),
			array_values( $missing ),
			'Granted but not removable, so it would survive uninstall: ' . implode( ', ', $missing )
		);
	}

	/**
	 * Scoped to what Free GRANTS, not to every `mvs_` cap on the site.
	 *
	 * Pro's Migrator v13 grants `manage_mvs_documents` to administrators, and
	 * that is Pro's to remove - Free reaching across the boundary to strip it
	 * would violate the Pro boundary (Coding Rule 10). Asserting on every
	 * `mvs_` cap made this test fail on a Pro cap Free never granted, which is
	 * a true statement about the site and the wrong assertion for this class.
	 */
	public function test_remove_caps_leaves_no_granted_cap_on_any_role(): void {
		MediaCapabilities::add_caps();
		MediaCapabilities::remove_caps();

		$removable = MediaCapabilities::all_caps();

		$left = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			foreach ( array_keys( $role['capabilities'] ) as $cap ) {
				if ( in_array( $cap, $removable, true ) ) {
					$left[] = $slug . ':' . $cap;
				}
			}
		}

		$this->assertSame( array(), $left, 'Survived uninstall: ' . implode( ', ', $left ) );
	}
}
