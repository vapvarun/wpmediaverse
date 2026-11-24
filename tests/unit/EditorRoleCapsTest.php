<?php
/**
 * A plain WordPress Editor is a content role, not a media moderator.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Capabilities\MediaCapabilities;

class EditorRoleCapsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( MediaCapabilities::OVERRIDES_OPTION );
		MediaCapabilities::strip_editor_moderation();
		parent::tear_down();
	}

	public function test_the_default_editor_map_is_own_media_only(): void {
		$editor = MediaCapabilities::get_role_caps()['editor'];

		foreach ( MediaCapabilities::EDITOR_MODERATION_CAPS as $cap ) {
			$this->assertNotContains( $cap, $editor, "$cap must not be an Editor default." );
		}
		foreach ( array( 'upload_mvs_media', 'edit_mvs_media', 'delete_mvs_media', 'edit_published_mvs_medias', 'delete_published_mvs_medias' ) as $cap ) {
			$this->assertContains( $cap, $editor, "$cap is an own-media right an Editor keeps." );
		}
	}

	public function test_the_upgrade_takes_the_stored_editor_role_down_to_own_media(): void {
		$role = get_role( 'editor' );
		foreach ( MediaCapabilities::EDITOR_MODERATION_CAPS as $cap ) {
			$role->add_cap( $cap ); // The 2.6.4 state.
		}

		MediaCapabilities::strip_editor_moderation();

		foreach ( MediaCapabilities::EDITOR_MODERATION_CAPS as $cap ) {
			$this->assertFalse( get_role( 'editor' )->has_cap( $cap ), "$cap removed from the stored role." );
		}
		$this->assertTrue( get_role( 'editor' )->has_cap( 'upload_mvs_media' ) );
	}

	public function test_the_saved_matrix_cannot_hand_the_caps_back(): void {
		update_option(
			MediaCapabilities::OVERRIDES_OPTION,
			array(
				'editor' => array(
					'moderate_mvs_media'      => true,
					'delete_others_mvs_media' => true,
					'upload_mvs_media'        => true,
				),
				'author' => array( 'upload_mvs_media' => true ),
			)
		);

		MediaCapabilities::strip_editor_moderation();
		MediaCapabilities::add_caps(); // A later re-apply.

		$this->assertFalse( get_role( 'editor' )->has_cap( 'moderate_mvs_media' ) );
		$saved = get_option( MediaCapabilities::OVERRIDES_OPTION );
		$this->assertSame( array( 'upload_mvs_media' => true ), $saved['editor'], 'Unrelated Editor entries stay.' );
		$this->assertSame( array( 'upload_mvs_media' => true ), $saved['author'], 'Other roles are untouched.' );
	}

	public function test_an_owner_can_grant_moderation_again_and_it_sticks(): void {
		MediaCapabilities::strip_editor_moderation();

		MediaCapabilities::apply_role_caps( array( 'editor' => array( 'moderate_mvs_media' => true ) ) );
		MediaCapabilities::add_caps();

		$this->assertTrue( get_role( 'editor' )->has_cap( 'moderate_mvs_media' ) );
	}
}
