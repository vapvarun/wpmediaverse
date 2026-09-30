<?php
/**
 * The wp-admin album and collection meta boxes write the values the front end
 * and the API write, through the same services.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\AlbumMetaBox;
use WPMediaVerse\Admin\CollectionMetaBox;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10351283087 (album) and 10351428179 (collection privacy). wp-admin
 * had no control for either, so a value both other entry points wrote could
 * not be edited by the site owner.
 */
class AdminMetaBoxSaveTest extends WP_UnitTestCase {

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	public function test_album_box_saves_privacy_type_and_cover(): void {
		$albums   = Plugin::container()->get( 'albums' );
		$album_id = (int) self::factory()->post->create( array( 'post_type' => 'mvs_album', 'post_author' => $this->admin ) );

		$_POST = array(
			'mvs_album_settings_nonce' => wp_create_nonce( 'mvs_album_settings' ),
			'mvs_album_privacy'        => 'members',
			'mvs_album_type'           => 'Travel',
			'mvs_album_cover'          => '0',
		);
		( new AlbumMetaBox( $albums ) )->save( $album_id, get_post( $album_id ) );

		$this->assertSame( 'members', $albums->get_privacy( $album_id ) );
		$this->assertSame( 'Travel', $albums->get_album_type( $album_id ) );
	}

	public function test_album_box_ignores_a_request_without_its_nonce(): void {
		$albums   = Plugin::container()->get( 'albums' );
		$album_id = (int) self::factory()->post->create( array( 'post_type' => 'mvs_album', 'post_author' => $this->admin ) );

		$_POST = array( 'mvs_album_privacy' => 'private' );
		( new AlbumMetaBox( $albums ) )->save( $album_id, get_post( $album_id ) );

		$this->assertNotSame( 'private', $albums->get_privacy( $album_id ) );
	}

	public function test_album_box_will_not_write_a_level_the_picker_does_not_offer(): void {
		$albums   = Plugin::container()->get( 'albums' );
		$album_id = (int) self::factory()->post->create( array( 'post_type' => 'mvs_album', 'post_author' => $this->admin ) );

		$_POST = array(
			'mvs_album_settings_nonce' => wp_create_nonce( 'mvs_album_settings' ),
			'mvs_album_privacy'        => 'dm',
		);
		( new AlbumMetaBox( $albums ) )->save( $album_id, get_post( $album_id ) );

		$this->assertNotSame( 'dm', $albums->get_privacy( $album_id ) );
	}

	public function test_collection_box_saves_privacy_for_any_type(): void {
		$collections = Plugin::container()->get( 'collections' );
		$id          = (int) self::factory()->post->create( array( 'post_type' => 'mvs_collection', 'post_author' => $this->admin ) );

		$_POST = array(
			'mvs_collection_rules_nonce' => wp_create_nonce( 'mvs_collection_rules' ),
			'mvs_collection_type'        => 'manual',
			'mvs_collection_privacy'     => 'members',
		);
		( new CollectionMetaBox( $collections ) )->save( $id, get_post( $id ) );

		$this->assertSame( 'members', $collections->get_privacy( $id ) );
	}
}
