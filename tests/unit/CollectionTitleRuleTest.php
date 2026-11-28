<?php
/**
 * Editing a collection follows the same title rule as creating one.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * An edit may not blank a collection's title.
 */
class CollectionTitleRuleTest extends WP_UnitTestCase {

	/**
	 * Send a request as the member and return the response.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $params Body params.
	 * @return \WP_REST_Response
	 */
	private function send( string $method, string $route, array $params ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_an_edit_cannot_blank_the_title_and_a_new_title_still_saves(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$created = $this->send( 'POST', '/mvs/v1/collections', array( 'title' => 'Summer' ) );
		$this->assertSame( 201, $created->get_status() );
		$id = (int) $created->get_data()['id'];

		$blank = $this->send( 'PUT', '/mvs/v1/collections/' . $id, array( 'title' => '   ' ) );
		$this->assertSame( 400, $blank->get_status() );
		$this->assertSame( 'mvs_missing_title', $blank->get_data()['code'] );
		$this->assertSame( 'Summer', get_the_title( $id ), 'The refused edit changed nothing.' );

		$renamed = $this->send( 'PUT', '/mvs/v1/collections/' . $id, array( 'title' => 'Winter' ) );
		$this->assertSame( 200, $renamed->get_status() );
		$this->assertSame( 'Winter', get_the_title( $id ) );
	}
}
