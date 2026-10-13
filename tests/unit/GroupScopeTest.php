<?php
/**
 * A group's media list holds only that group's items.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Repository\MediaRepository;

/**
 * MediaRepository::group_scope_clause() through query() and GET /media?group=.
 */
class GroupScopeTest extends WP_UnitTestCase {

	private int $owner;

	/**
	 * A member allowed to list any group (bp_moderate); BuddyPress is not loaded here.
	 */
	private int $moderator;

	public function set_up(): void {
		parent::set_up();
		$this->owner     = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->moderator = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $this->moderator )->add_cap( 'bp_moderate' );
		MediaRepository::reset_test_cache();
	}

	private function repo(): MediaRepository {
		return Plugin::container()->get( 'media_repository' );
	}

	/**
	 * One item, optionally in a group and a gallery.
	 *
	 * @param int    $group   Group id (0 = none).
	 * @param string $privacy Privacy.
	 * @param string $gallery Gallery id ('' = none).
	 * @param int    $pos     Gallery position.
	 */
	private function item( int $group, string $privacy = 'public', string $gallery = '', int $pos = 0 ): int {
		$id = $this->repo()->insert(
			array(
				'title'             => 'G',
				'post_author'       => $this->owner,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => $privacy,
			)
		);
		if ( $group ) {
			$this->repo()->set( $id, 'group_id', (string) $group );
		}
		if ( '' !== $gallery ) {
			$this->repo()->set( $id, 'media_group', $gallery );
			$this->repo()->set( $id, 'group_position', (string) $pos );
		}
		return $id;
	}

	/**
	 * Listed ids for group 7.
	 *
	 * @param int|null $viewer Viewer; null for the moderator.
	 * @return int[]
	 */
	private function listed( ?int $viewer = null ): array {
		MediaRepository::reset_test_cache();
		$rows = $this->repo()->query(
			array(
				'group_id'                => 7,
				'privacy'                 => 'explore',
				'viewer_id'               => $viewer ?? $this->moderator,
				'exclude_non_cover_group' => true,
				'per_page'                => 50,
			)
		);
		$ids  = array_map( static fn( $r ) => (int) ( is_object( $r ) ? $r->media_id : $r['media_id'] ), (array) $rows );
		sort( $ids );
		return $ids;
	}

	public function test_lists_only_the_groups_items(): void {
		$in    = $this->item( 7 );
		$other = $this->item( 8 );
		$none  = $this->item( 0 );

		$this->assertSame( array( $in ), $this->listed() );
		$this->assertNotContains( $other, $this->listed() );
		$this->assertNotContains( $none, $this->listed() );
	}

	public function test_a_group_not_confirmed_public_lists_nothing_for_a_visitor(): void {
		$this->item( 7 );

		$this->assertSame( array(), $this->listed( 0 ), 'Without BuddyPress the group cannot be shown to be public.' );
	}

	public function test_group_only_items_stay_hidden_from_a_non_member(): void {
		$public = $this->item( 7 );
		$this->item( 7, 'group' );

		$this->assertSame( array( $public ), $this->listed() );
	}

	public function test_a_gallery_in_the_group_is_one_tile(): void {
		$cover = $this->item( 7, 'public', 'grp_g7', 0 );
		$this->item( 7, 'public', 'grp_g7', 1 );

		$this->assertSame( array( $cover ), $this->listed() );
	}

	public function test_rest_feed_filters_by_group(): void {
		$in = $this->item( 7 );
		$this->item( 8 );
		$this->item( 0 );
		MediaRepository::reset_test_cache();

		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		wp_set_current_user( $this->moderator );
		$request = new \WP_REST_Request( 'GET', '/mvs/v1/media' );
		$request->set_param( 'group', 7 );
		$request->set_param( 'group_covers', 1 );
		$listed = array_map( static fn( $i ) => (int) $i['id'], (array) rest_do_request( $request )->get_data() );

		$this->assertSame( array( $in ), $listed );
	}
}
