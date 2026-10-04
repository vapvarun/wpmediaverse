<?php
/**
 * Followers-only stories reach followers (card 10355187431).
 *
 * @package WPMediaVerse\Tests
 */

declare( strict_types=1 );

namespace WPMediaVerse\Tests\Unit;

use WPMediaVerse\Core\Plugin;

/**
 * The stories bar (media_with_meta) used its own privacy rule with no followers
 * case, so a followers-only story never showed to a follower while every other
 * list and the item page admitted them. It now uses the shared member rule,
 * without the moderator override.
 */
class StoriesFollowersPrivacyTest extends \WP_UnitTestCase {

	/**
	 * Story ids a viewer sees from one author.
	 *
	 * @param int $author Author.
	 * @param int $viewer Viewer.
	 * @return int[]
	 */
	private function visible( int $author, int $viewer ): array {
		$result = Plugin::container()->get( 'media_repository' )->media_with_meta(
			array(
				'meta'      => array(
					array(
						'key'     => 'is_story',
						'compare' => '=',
						'value'   => '1',
					),
				),
				'authors'   => array( $author ),
				'viewer_id' => $viewer,
			)
		);
		return array_map( 'intval', wp_list_pluck( $result['items'], 'media_id' ) );
	}

	/**
	 * Follower sees it; a non-follower and a moderator who does not follow do not.
	 *
	 * @return void
	 */
	public function test_followers_story_reaches_followers_only(): void {
		$repo      = Plugin::container()->get( 'media_repository' );
		$author    = self::factory()->user->create();
		$follower  = self::factory()->user->create();
		$stranger  = self::factory()->user->create();
		$moderator = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$story = (int) $repo->insert(
			array(
				'title'       => 'Followers story',
				'post_author' => $author,
				'media_type'  => 'image',
				'status'      => 'publish',
				'privacy'     => 'followers',
			)
		);
		$repo->set( $story, 'is_story', '1' );
		Plugin::container()->get( 'follows' )->follow( $follower, $author );

		$this->assertSame( array( $story ), $this->visible( $author, $follower ), 'A follower must see a followers-only story.' );
		$this->assertSame( array(), $this->visible( $author, $stranger ) );
		$this->assertSame( array(), $this->visible( $author, $moderator ), 'A moderator\'s stories bar is a member\'s stories bar.' );
		$this->assertSame( array( $story ), $this->visible( $author, $author ) );
	}
}
