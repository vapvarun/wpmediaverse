<?php
/**
 * The owner's "Allow Users to Set Privacy" switch holds after upload too.
 *
 * Basecamp 10320619418: with the switch off, only the upload surfaces obeyed
 * it. A member could upload at the site default and change the level one click
 * later through Edit, bulk actions or PATCH /mvs/v1/media/{id}. Every picker
 * and every write path now asks PrivacyService::user_may_choose_privacy().
 *
 * The edit screens hide the picker when locked but still send the item's
 * current level with every save, so an unchanged level must keep passing -
 * the lock refuses a CHANGE, never a save.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WPMediaVerse\Services\PrivacyService;

class PrivacyLockTest extends WP_UnitTestCase {

	/** @var int */
	private int $member;

	/** @var int */
	private int $admin;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$index_table = $wpdb->prefix . 'mvs_media_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index_table ) ) ) {
			( new \WPMediaVerse\Core\Migrator() )->run();
		}

		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		update_option( 'mvs_allow_user_privacy', '' );

		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		delete_option( 'mvs_allow_user_privacy' );
		parent::tear_down();
	}

	private function repo() {
		return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
	}

	private function make_media( int $author ): int {
		return (int) $this->repo()->insert(
			array(
				'title'             => 'Privacy lock probe',
				'post_author'       => $author,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
				'file_path'         => '2026/09/probe.jpg',
				'file_type'         => 'image/jpeg',
				'slug'              => 'privacy-lock-' . wp_generate_password( 10, false, false ),
			)
		);
	}

	private function patch_privacy( int $id, string $privacy ) {
		$req = new WP_REST_Request( 'POST', '/mvs/v1/media/' . $id );
		$req->set_param( 'id', $id );
		$req->set_param( 'privacy', $privacy );

		return rest_do_request( $req );
	}

	public function test_the_switch_decides_for_members_but_not_for_managers(): void {
		$this->assertFalse( PrivacyService::user_may_choose_privacy( $this->member ) );
		$this->assertTrue( PrivacyService::user_may_choose_privacy( $this->admin ) );

		update_option( 'mvs_allow_user_privacy', '1' );
		$this->assertTrue( PrivacyService::user_may_choose_privacy( $this->member ) );
	}

	public function test_a_locked_member_cannot_change_privacy_through_rest(): void {
		$id = $this->make_media( $this->member );
		wp_set_current_user( $this->member );

		$response = $this->patch_privacy( $id, 'private' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'mvs_privacy_locked', $response->get_data()['code'] );
		$this->assertSame( 'public', (string) $this->repo()->get( $id, 'privacy' ) );
	}

	public function test_a_locked_member_can_still_save_the_current_level(): void {
		$id = $this->make_media( $this->member );
		wp_set_current_user( $this->member );

		$this->assertSame( 200, $this->patch_privacy( $id, 'public' )->get_status() );
	}

	public function test_a_manager_can_change_privacy_while_locked(): void {
		$id = $this->make_media( $this->member );
		wp_set_current_user( $this->admin );

		$this->assertSame( 200, $this->patch_privacy( $id, 'private' )->get_status() );
		$this->assertSame( 'private', (string) $this->repo()->get( $id, 'privacy' ) );
	}

	public function test_bulk_privacy_is_locked_for_members(): void {
		$id = $this->make_media( $this->member );
		wp_set_current_user( $this->member );

		$req = new WP_REST_Request( 'POST', '/mvs/v1/media/bulk' );
		$req->set_param( 'action', 'change_privacy' );
		$req->set_param( 'privacy', 'private' );
		$req->set_param( 'media_ids', array( $id ) );
		$response = rest_do_request( $req );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'public', (string) $this->repo()->get( $id, 'privacy' ) );
	}
}
