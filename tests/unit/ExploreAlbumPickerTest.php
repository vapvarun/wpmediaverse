<?php
/**
 * The Explore/profile bulk bar's "Add to album" picker.
 *
 * The picker used to request `albums?per_page=100` with no author, so it listed
 * every album on the site. Two things were wrong with that and this file pins
 * both: every foreign choice died with 403 mvs_forbidden when the member
 * pressed Add, and the response shipped other members' album titles to the
 * browser whether or not anything was ever clicked. Basecamp 10320911477.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * @since 2.5.1
 */
class ExploreAlbumPickerTest extends WP_UnitTestCase {

	/**
	 * The contract the picker relies on: `author` narrows the album list to one
	 * member, so the payload itself never carries anybody else's album title.
	 */
	public function test_author_param_returns_only_that_members_albums(): void {
		$albums = Plugin::container()->get( 'albums' );
		$mine   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$theirs = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$my_album = $albums->create( $mine, array( 'title' => 'Tokyo Streets' ) );
		$albums->create( $theirs, array( 'title' => 'Mountain Escapes' ) );

		wp_set_current_user( $mine );

		$request = new WP_REST_Request( 'GET', '/mvs/v1/albums' );
		$request->set_param( 'author', $mine );
		$request->set_param( 'per_page', 100 );

		$data  = rest_do_request( $request )->get_data();
		$data  = is_array( $data ) && isset( $data['items'] ) ? $data['items'] : $data;
		$ids   = wp_list_pluck( $data, 'id' );
		$names = wp_list_pluck( $data, 'title' );

		$this->assertSame( array( $my_album ), array_values( $ids ), 'The picker was offered an album that is not the member\'s own.' );
		$this->assertNotContains( 'Mountain Escapes', $names, 'Another member\'s album title reached the response body.' );
	}

	/**
	 * The client half. The filter is only applied if the picker actually sends
	 * it, and it can only send it if the bulk bar's context carries the viewer
	 * id — dropping either line silently restores the leak, with no error
	 * anywhere to notice it by.
	 */
	public function test_picker_requests_its_own_author_and_the_bar_seeds_the_id(): void {
		$view = file_get_contents( MVS_PLUGIN_DIR . 'src/blocks/explore-view/view.js' );
		$tpl  = file_get_contents( MVS_PLUGIN_DIR . 'templates/explore.php' );

		$this->assertStringContainsString(
			"'albums?author=' + userId",
			$view,
			'ensureExploreAlbums() no longer scopes the album request to the member.'
		);
		$this->assertStringContainsString(
			"'userId'  => get_current_user_id()",
			$tpl,
			'The bulk bar context no longer seeds userId, so the picker cannot scope its request.'
		);
	}
}
