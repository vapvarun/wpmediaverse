<?php
/**
 * Lists and leveled stores (BuddyPress activity) follow the same audience as can_view().
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\PrivacyService;

/**
 * Lists and leveled stores (BuddyPress activity) follow the same audience as can_view().
 */
class CoreVisibilityContractTest extends WP_UnitTestCase {

	/**
	 * Run a level audience condition against one leveled row.
	 *
	 * @param int $level  Stored level.
	 * @param int $author Row author.
	 * @param int $viewer Viewer.
	 */
	private function level_visible( int $level, int $author, int $viewer ): bool {
		global $wpdb;
		$sql = Plugin::container()->get( 'privacy' )->level_audience_sql( 't.lvl', 't.author', $viewer );
		return (bool) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT '{$level}' AS lvl, {$author} AS author ) t WHERE {$sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public function test_legacy_loggedin_is_the_members_level(): void {
		$this->assertSame( PrivacyService::privacy_to_level( 'members' ), PrivacyService::privacy_to_level( 'loggedin' ) );
	}

	public function test_level_audience_matches_can_view(): void {
		$author   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$follower = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$friend   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::container()->get( 'follows' )->follow( $follower, $author );
		$friends = static function ( $ids, $user ) use ( $friend, $author ) {
			return $user === $friend ? array( $author ) : $ids;
		};
		add_filter( 'mvs_friend_ids', $friends, 10, 2 );

		$members   = PrivacyService::privacy_to_level( 'members' );
		$followers = PrivacyService::privacy_to_level( 'followers' );
		$friendsl  = PrivacyService::privacy_to_level( 'friends' );
		$private   = PrivacyService::privacy_to_level( 'private' );

		$this->assertTrue( $this->level_visible( 0, $author, 0 ), 'Public is public.' );
		$this->assertFalse( $this->level_visible( $members, $author, 0 ), 'Members-only is hidden from visitors.' );
		$this->assertTrue( $this->level_visible( $members, $author, $other ) );
		$this->assertTrue( $this->level_visible( $followers, $author, $follower ), 'A follower sees followers-only.' );
		$this->assertFalse( $this->level_visible( $followers, $author, $other ) );
		$this->assertTrue( $this->level_visible( $followers, $author, $author ), 'The author sees their own followers-only post.' );
		$this->assertTrue( $this->level_visible( $friendsl, $author, $friend ), 'A friend (via mvs_friend_ids) sees friends-only.' );
		$this->assertFalse( $this->level_visible( $friendsl, $author, $other ) );
		$this->assertFalse( $this->level_visible( $private, $author, $other ) );
		$this->assertTrue( $this->level_visible( $private, $author, $author ) );

		Plugin::container()->get( 'reports' )->block_user( $author, $other );
		$this->assertFalse( $this->level_visible( 0, $author, $other ), 'A block hides the author from lists, both ways.' );

		remove_filter( 'mvs_friend_ids', $friends, 10 );
	}

	public function test_member_list_includes_friends_followers_and_legacy_loggedin(): void {
		$author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$friend = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$repo   = Plugin::container()->get( 'media_repository' );
		$ids    = array();
		foreach ( array( 'friends', 'loggedin', 'private' ) as $privacy ) {
			$ids[ $privacy ] = $repo->insert( array( 'title' => 'Vis ' . $privacy, 'post_author' => $author, 'status' => 'publish', 'media_type' => 'image', 'privacy' => $privacy ) );
		}
		$friends = static function ( $list, $user ) use ( $friend, $author ) {
			return $user === $friend ? array( $author ) : $list;
		};
		add_filter( 'mvs_friend_ids', $friends, 10, 2 );
		add_filter( 'mvs_are_friends', '__return_true' );

		$listed = array_map( 'intval', array_column( $repo->query( array( 'privacy' => 'visible', 'viewer_id' => $friend, 'author_id' => $author, 'limit' => 20 ) ), 'media_id' ) );

		$this->assertContains( $ids['friends'], $listed, 'A friend lists friends-only media, as can_view() allows.' );
		$this->assertContains( $ids['loggedin'], $listed, 'Legacy logged-in media is listed to members.' );
		$this->assertNotContains( $ids['private'], $listed );
		$this->assertTrue( Plugin::container()->get( 'privacy' )->can_view( $ids['friends'], $friend ), 'can_view() asks the same friendship door.' );

		remove_filter( 'mvs_friend_ids', $friends, 10 );
		remove_filter( 'mvs_are_friends', '__return_true' );
	}

	public function test_album_lists_reach_followers_and_friends(): void {
		$author   = self::factory()->user->create( array( 'role' => 'author' ) );
		$follower = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::container()->get( 'follows' )->follow( $follower, $author );
		$album = self::factory()->post->create( array( 'post_type' => 'mvs_album', 'post_author' => $author, 'post_status' => 'publish' ) );
		update_post_meta( $album, \WPMediaVerse\Services\AlbumService::PRIVACY_META, 'followers' );

		$args = array( 'post_type' => 'mvs_album', 'fields' => 'ids', 'posts_per_page' => 50 );
		$this->assertContains( $album, PrivacyService::query_listable_spaces( $args, $follower )->posts, 'A follower lists a followers-only album.' );
		$this->assertNotContains( $album, PrivacyService::query_listable_spaces( $args, $other )->posts );
	}
}
