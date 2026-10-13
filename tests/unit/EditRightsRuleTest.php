<?php
/**
 * One edit rule: the owner when their role edits media, anyone else with "edit others' media".
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Capabilities\MediaCapabilities;
use WPMediaVerse\Core\Plugin;

/**
 * One edit rule: the owner when their role edits media, anyone else with "edit others' media".
 */
class EditRightsRuleTest extends WP_UnitTestCase {

	public function test_owner_needs_the_edit_right_and_others_need_edit_others(): void {
		$owner  = self::factory()->user->create( array( 'role' => 'author' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		MediaCapabilities::add_caps();
		$media = Plugin::container()->get( 'media_repository' )->insert( array( 'title' => 'Mine', 'post_author' => $owner, 'status' => 'publish', 'media_type' => 'video', 'privacy' => 'public' ) );

		$this->assertTrue( MediaCapabilities::can_edit_media( $media, $owner ) );
		$this->assertFalse( MediaCapabilities::can_edit_media( $media, $other ), 'Another member may not edit it.' );
		$this->assertTrue( MediaCapabilities::can_edit_media( $media, $editor ), 'Edit-others holders may.' );

		$user = get_user_by( 'id', $owner );
		$user->add_cap( 'edit_mvs_medias', false );
		$this->assertFalse( MediaCapabilities::can_edit_media( $media, $owner ), 'An owner whose role lost the edit right may not edit.' );
	}
}
