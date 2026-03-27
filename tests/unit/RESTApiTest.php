<?php
/**
 * Test REST API endpoint registration.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class RESTApiTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	public function test_media_routes_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mvs/v1/media', $routes );
	}

	public function test_albums_routes_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mvs/v1/albums', $routes );
	}

	public function test_collections_routes_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mvs/v1/collections', $routes );
	}

	public function test_tags_routes_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mvs/v1/tags', $routes );
	}

	public function test_media_endpoint_methods(): void {
		$routes = rest_get_server()->get_routes();
		$media  = $routes['/mvs/v1/media'];

		$methods = array();
		foreach ( $media as $endpoint ) {
			$methods = array_merge( $methods, array_keys( $endpoint['methods'] ) );
		}

		$this->assertContains( 'GET', $methods );
		$this->assertContains( 'POST', $methods );
	}

	public function test_unauthenticated_upload_rejected(): void {
		wp_set_current_user( 0 );

		$request  = new \WP_REST_Request( 'POST', '/mvs/v1/media' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}
}
