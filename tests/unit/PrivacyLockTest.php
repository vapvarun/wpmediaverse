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
		delete_option( 'mvs_default_privacy' );
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

	/*
	 * Albums. An album's privacy is carried down onto its items
	 * (AlbumService::set_privacy() / add_items()), so with the lock on a member
	 * could still make a photo private by creating a private album and adding
	 * the photo to it. Basecamp 10320619418, second bounce.
	 */

	private function albums(): \WPMediaVerse\Services\AlbumService {
		return \WPMediaVerse\Core\Plugin::container()->get( 'albums' );
	}

	/** An album the member made while they were still allowed to choose. */
	private function private_album_made_before_the_lock( int $author ): int {
		update_option( 'mvs_allow_user_privacy', '1' );
		$album = (int) $this->albums()->create(
			$author,
			array(
				'title'   => 'Before the lock',
				'privacy' => 'private',
			)
		);
		update_option( 'mvs_allow_user_privacy', '' );

		$this->assertSame( 'private', $this->albums()->get_privacy( $album ) );

		return $album;
	}

	public function test_a_locked_member_album_takes_the_site_default(): void {
		update_option( 'mvs_default_privacy', 'members' );
		wp_set_current_user( $this->member );

		$album = $this->albums()->create(
			$this->member,
			array(
				'title'   => 'Locked',
				'privacy' => 'private',
			)
		);

		$this->assertIsInt( $album );
		$this->assertSame( 'members', $this->albums()->get_privacy( $album ) );
	}

	public function test_adding_media_to_a_private_album_as_a_locked_member_keeps_its_privacy(): void {
		$album = $this->private_album_made_before_the_lock( $this->member );
		$id    = $this->make_media( $this->member );
		wp_set_current_user( $this->member );

		$this->assertSame( 1, $this->albums()->add_items( $album, array( $id ) ) );
		$this->assertSame( 'public', (string) $this->repo()->get( $id, 'privacy' ) );
		$this->assertSame( $album, (int) $this->repo()->get( $id, 'album_id' ) );
	}

	public function test_a_locked_member_cannot_change_album_privacy_through_rest(): void {
		$album = $this->albums()->create( $this->member, array( 'title' => 'Locked' ) );
		wp_set_current_user( $this->member );

		$req = new WP_REST_Request( 'PUT', '/mvs/v1/albums/' . $album );
		$req->set_param( 'id', $album );
		$req->set_param( 'title', 'Renamed' );
		$req->set_param( 'privacy', 'private' );
		$response = rest_do_request( $req );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'mvs_privacy_locked', $response->get_data()['code'] );
		$this->assertSame( 'public', $this->albums()->get_privacy( $album ) );
		$this->assertSame( 'Locked', get_the_title( $album ), 'A refused update writes nothing.' );

		// The edit screens re-send the current level: that still saves.
		$req->set_param( 'privacy', 'public' );
		$this->assertSame( 200, rest_do_request( $req )->get_status() );
		$this->assertSame( 'Renamed', get_the_title( $album ) );
	}

	public function test_a_manager_still_sets_album_privacy_and_it_cascades(): void {
		$id = $this->make_media( $this->member );
		wp_set_current_user( $this->admin );

		$album = (int) $this->albums()->create(
			$this->admin,
			array(
				'title'   => 'Managed',
				'privacy' => 'private',
			)
		);
		$this->albums()->add_items( $album, array( $id ) );

		$this->assertSame( 'private', $this->albums()->get_privacy( $album ) );
		$this->assertSame( 'private', (string) $this->repo()->get( $id, 'privacy' ) );
	}

	public function test_a_member_cannot_file_someone_elses_media_in_their_album(): void {
		update_option( 'mvs_allow_user_privacy', '1' );
		$album = (int) $this->albums()->create(
			$this->member,
			array(
				'title'   => 'Mine',
				'privacy' => 'private',
			)
		);
		$other = $this->make_media( $this->admin );
		wp_set_current_user( $this->member );

		$this->assertSame( 0, $this->albums()->add_items( $album, array( $other ) ) );
		$this->assertSame( 'public', (string) $this->repo()->get( $other, 'privacy' ) );
		$this->assertSame( 0, (int) $this->repo()->get( $other, 'album_id' ) );
	}

	public function test_leaving_an_album_releases_the_items_album_pointer(): void {
		wp_set_current_user( $this->admin );
		$id    = $this->make_media( $this->admin );
		$first = (int) $this->albums()->create( $this->admin, array( 'title' => 'First' ) );
		$last  = (int) $this->albums()->create( $this->admin, array( 'title' => 'Last' ) );
		$this->albums()->add_items( $first, array( $id ) );
		$this->albums()->add_items( $last, array( $id ) );
		$this->assertSame( $last, (int) $this->repo()->get( $id, 'album_id' ) );

		// Deleting the album it points at falls back to the album it is still in.
		$this->albums()->delete_all_items( $last );
		wp_delete_post( $last, true );
		$this->assertSame( $first, (int) $this->repo()->get( $id, 'album_id' ) );

		// Leaving its last album clears the pointer.
		$this->assertTrue( $this->albums()->remove_item( $first, $id ) );
		$this->assertSame( 0, (int) $this->repo()->get( $id, 'album_id' ) );
	}

	/**
	 * BuddyPress activity visibility read an album's privacy from
	 * mvs_media_index at media_id = <album post ID>. Where that integer is also
	 * a real photo's id, the photo's privacy decided the album's.
	 */
	public function test_activity_visibility_reads_album_privacy_from_the_album_not_a_colliding_photo(): void {
		global $wpdb;

		wp_set_current_user( $this->admin );
		$item = $this->make_media( $this->admin );

		foreach ( array(
			'public'  => 'private',
			'private' => 'public',
		) as $album_privacy => $colliding_photo_privacy ) {
			$album = (int) $this->albums()->create(
				$this->admin,
				array(
					'title'   => 'Collision ' . $album_privacy,
					'privacy' => $album_privacy,
				)
			);

			// A real photo whose media_id equals the album's post ID.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$wpdb->prefix . 'mvs_media_index',
				array(
					'media_id'    => $album,
					'title'       => 'Colliding photo',
					'post_author' => $this->admin,
					'media_type'  => 'image',
					'privacy'     => $colliding_photo_privacy,
					'slug'        => 'collision-' . $album,
				)
			);

			$this->repo()->set( $item, 'privacy', 'public' );
			$this->repo()->set( $item, 'album_id', $album );

			$expected = 'private' === $album_privacy;
			$this->assertSame( $expected, \WPMediaVerse\Integrations\BuddyPress\ActivitySyncIntegration::should_hide_for_media( $item ), "album {$album_privacy}" );
			$this->assertSame( $expected, \WPMediaVerse\Integrations\BuddyPress\ActivitySyncIntegration::should_hide_for_batch( array( $item ) ), "batch, album {$album_privacy}" );
		}
	}
}
