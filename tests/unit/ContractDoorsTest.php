<?php
/**
 * Public doors other plugins call instead of reading MediaVerse tables or URLs.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\PrivacyService;

/**
 * Public doors other plugins call instead of reading MediaVerse tables or URLs.
 */
class ContractDoorsTest extends WP_UnitTestCase {

	public function test_permalink_round_trip_drive_move_and_spaces_route(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$repo  = Plugin::container()->get( 'media_repository' );
		$id    = $repo->insert( array( 'title' => 'Door photo', 'post_author' => $owner, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'private' ) );

		$this->assertSame( $id, $repo->id_from_permalink( $repo->get_permalink( $id ) ), 'A permalink resolves back to its item.' );
		$this->assertSame( $id, $repo->id_from_permalink( home_url( '/media/' . $id . '/' ) ) );
		$this->assertSame( 0, $repo->id_from_permalink( home_url( '/media/@someone/' ) ), 'A profile URL is not a media permalink.' );
		$this->assertSame( 0, $repo->id_from_permalink( 'https://elsewhere.test/page/' ) );

		$moved = array();
		$spy   = static function ( $media_id, $type, $drive ) use ( &$moved ) {
			$moved = array( $media_id, $type, $drive );
		};
		add_action( 'mvs_media_moved_to_drive', $spy, 10, 3 );
		$this->assertTrue( $repo->move_to_drive( $id, 'space', 77, 0 ) );
		$this->assertSame( array( $id, 'space', 77 ), $moved );
		$this->assertSame( 'space', (string) $repo->get( $id, 'drive_type' ) );
		$this->assertSame( 'private', (string) $repo->get( $id, 'privacy' ), 'Moving never changes privacy.' );
		remove_action( 'mvs_media_moved_to_drive', $spy, 10 );

		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/media/' . $id . '/spaces' ) );
		$this->assertContains( $response->get_status(), array( 401, 403, 404 ), 'A visitor gets nothing about a private item.' );
		wp_set_current_user( $owner );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/media/' . $id . '/spaces' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['space_ids'] );

		$this->assertSame( 'followers', PrivacyService::map_external_privacy( 'followers', 'buddynext' ) );
		$this->assertSame( 'friends', PrivacyService::map_external_privacy( 'connections', 'buddynext' ) );
		$this->assertSame( 'private', PrivacyService::map_external_privacy( 'something-new', 'buddynext' ), 'Unknown words fail closed.' );
	}
}
