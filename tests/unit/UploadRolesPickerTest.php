<?php
/**
 * "Who can upload media" (2.6.0): the one new control of the simplification.
 *
 * The state is the upload_mvs_media capability, written through
 * MediaCapabilities, so it survives the version-bump re-grant in add_caps().
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\Settings\FieldRenderer;
use WPMediaVerse\Admin\Settings\Sanitizers;
use WPMediaVerse\Capabilities\MediaCapabilities;

class UploadRolesPickerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( MediaCapabilities::OVERRIDES_OPTION );
		MediaCapabilities::add_caps();
	}

	public function tear_down(): void {
		delete_option( MediaCapabilities::OVERRIDES_OPTION );
		get_role( 'subscriber' )->add_cap( 'upload_mvs_media' );
		parent::tear_down();
	}

	public function test_an_untouched_save_records_nothing(): void {
		$holders = MediaCapabilities::roles_with_cap( 'upload_mvs_media' );

		Sanitizers::sanitize_upload_roles( array_merge( array( '' ), $holders ) );

		$this->assertFalse( get_option( MediaCapabilities::OVERRIDES_OPTION ), 'A save that changed nothing wrote an override.' );
		$this->assertSame( $holders, MediaCapabilities::roles_with_cap( 'upload_mvs_media' ) );
	}

	public function test_a_missing_field_changes_nothing(): void {
		$holders = MediaCapabilities::roles_with_cap( 'upload_mvs_media' );
		Sanitizers::sanitize_upload_roles( null );
		$this->assertSame( $holders, MediaCapabilities::roles_with_cap( 'upload_mvs_media' ) );
		$this->assertFalse( get_option( MediaCapabilities::OVERRIDES_OPTION ) );
	}

	public function test_unticking_subscriber_revokes_only_upload_and_survives_add_caps(): void {
		$before  = get_role( 'subscriber' )->capabilities;
		$holders = array_diff( MediaCapabilities::roles_with_cap( 'upload_mvs_media' ), array( 'subscriber' ) );

		$result = Sanitizers::sanitize_upload_roles( array_values( $holders ) );

		$after = get_role( 'subscriber' )->capabilities;
		$this->assertNotContains( 'subscriber', $result );
		$this->assertArrayNotHasKey( 'upload_mvs_media', array_filter( $after ) );
		unset( $before['upload_mvs_media'], $after['upload_mvs_media'] );
		$this->assertSame( $before, $after, 'Unticking upload changed another capability.' );

		MediaCapabilities::add_caps();
		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'upload_mvs_media' ), 'A version-bump re-grant undid the owner\'s choice.' );

		// Ticking it again restores it, and that survives too.
		Sanitizers::sanitize_upload_roles( array_merge( array_values( $holders ), array( 'subscriber' ) ) );
		MediaCapabilities::add_caps();
		$this->assertTrue( get_role( 'subscriber' )->has_cap( 'upload_mvs_media' ) );
	}

	public function test_administrators_can_always_upload(): void {
		Sanitizers::sanitize_upload_roles( array( '' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'upload_mvs_media' ) );
		$this->assertSame( array( 'administrator' ), MediaCapabilities::roles_with_cap( 'upload_mvs_media' ) );
	}

	public function test_field_reads_the_live_capability(): void {
		get_role( 'subscriber' )->remove_cap( 'upload_mvs_media' );

		ob_start();
		FieldRenderer::render_upload_roles_field( array( 'option' => 'mvs_upload_roles' ) );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="subscriber"\s+\/>/', $html, 'Subscriber shown as able to upload.' );
		$this->assertMatchesRegularExpression( '/value="author"\s+checked=\'checked\'/', $html );
		$this->assertMatchesRegularExpression( '/value="administrator"\s+checked=\'checked\'\s+disabled=\'disabled\'/', $html );
		$this->assertStringContainsString( 'name="mvs_upload_roles[]" value=""', $html, 'No sentinel: an all-unticked list would not post.' );
	}
}
