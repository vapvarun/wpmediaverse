<?php
/**
 * A comments page loads the replies of its own threads only.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

class CommentRepliesPageScopeTest extends WP_UnitTestCase {

	public function test_page_one_does_not_fetch_page_two_replies_but_keeps_its_own(): void {
		$user  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$media = Plugin::container()->get( 'media_repository' )->insert( array( 'title' => 'Thread probe', 'post_author' => $user, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'public' ) );
		$svc   = Plugin::container()->get( 'comments' );

		add_filter( 'mvs_comment_rate_limit', static fn() => 1000 );
		// Newest thread is page 1, so the other thread goes in first.
		$second = $svc->add( $media, $user, 'Top two' );
		$other  = $svc->add( $media, $user, 'Reply to two', (int) ( $second['id'] ?? $second ) );
		$first  = $svc->add( $media, $user, 'Top one' );
		$reply  = $svc->add( $media, $user, 'Reply to one', (int) ( $first['id'] ?? $first ) );
		$id     = static fn( $c ) => (int) ( is_array( $c ) ? ( $c['id'] ?? 0 ) : $c );

		$fetched = array();
		$spy     = static function ( $comments ) use ( &$fetched ) {
			foreach ( (array) $comments as $c ) {
				$fetched[] = (int) ( is_object( $c ) ? $c->comment_ID : $c );
			}
			return $comments;
		};
		add_filter( 'the_comments', $spy );
		$page = $svc->get_for_media( $media, 1, 1 ); // One thread per page.
		remove_filter( 'the_comments', $spy );

		$json = wp_json_encode( $page );
		$this->assertStringContainsString( 'Reply to one', $json, 'This thread\'s replies must still load.' );
		$this->assertNotContains( $id( $other ), $fetched, 'A reply in another page\'s thread was fetched.' );
	}
}
