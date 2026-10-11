<?php
/**
 * Public member search does not match login names.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Basecamp 10393276031 (login-name search).
 */
class UserSearchNoLoginTest extends WP_UnitTestCase {

	public function test_search_by_login_finds_nothing_but_display_name_works(): void {
		$id = self::factory()->user->create( array( 'user_login' => 'zqlogin77', 'display_name' => 'Visible Person', 'user_nicename' => 'visible-person' ) );

		$search = function ( string $q ): array {
			$r = new WP_REST_Request( 'GET', '/mvs/v1/users/search' );
			$r->set_query_params( array( 'q' => $q, 'search' => $q ) );
			return array_map( static fn( $u ) => (int) ( $u['id'] ?? 0 ), (array) rest_do_request( $r )->get_data() );
		};

		$this->assertNotContains( $id, $search( 'zqlogin77' ) );
		$this->assertContains( $id, $search( 'Visible' ) );
	}
}
