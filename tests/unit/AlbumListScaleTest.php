<?php
/**
 * The album list returns counts without loading every album's items.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10393276031 item 11.
 */
class AlbumListScaleTest extends WP_UnitTestCase {

	public function test_owner_list_has_counts_and_no_item_ids(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $owner );

		$create = new WP_REST_Request( 'POST', '/mvs/v1/albums' );
		$create->set_body_params( array( 'title' => 'Scale album', 'privacy' => 'public' ) );
		$album = (int) rest_do_request( $create )->get_data()['id'];

		$repo = Plugin::container()->get( 'media_repository' );
		$ids  = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = $repo->insert( array( 'title' => 'In album ' . $i, 'post_author' => $owner, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'public' ) );
		}
		Plugin::container()->get( 'albums' )->add_items( $album, $ids, array( 'actor_id' => $owner ) );

		$list = new WP_REST_Request( 'GET', '/mvs/v1/albums' );
		$list->set_query_params( array( 'author' => $owner ) );
		$row = rest_do_request( $list )->get_data()[0];

		$this->assertSame( 3, (int) $row['media_count'] );
		$this->assertArrayNotHasKey( 'items', $row );

		$list->set_query_params( array( 'author' => $owner, 'with_items' => 1 ) );
		$this->assertCount( 3, rest_do_request( $list )->get_data()[0]['items'] );
	}
}
