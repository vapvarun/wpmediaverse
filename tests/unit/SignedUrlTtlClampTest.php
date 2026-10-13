<?php
/**
 * A caller-chosen ttl never outlives the site's signed-URL setting.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * A caller-chosen ttl never outlives the site's signed-URL setting.
 */
class SignedUrlTtlClampTest extends WP_UnitTestCase {

	public function test_requested_ttl_is_clamped_to_the_setting(): void {
		$owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$id    = Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'TTL probe',
				'post_author' => $owner,
				'status'      => 'publish',
				'media_type'  => 'image',
				'privacy'     => 'private',
				'file_path'   => '2026/10/ttl-probe.jpg',
			)
		);
		wp_set_current_user( $owner );
		Plugin::container()->get( 'privacy' )->flush_cache();

		$request = new WP_REST_Request( 'GET', '/mvs/v1/media/' . $id . '/signed-url' );
		$request->set_query_params( array( 'ttl' => 10 * YEAR_IN_SECONDS ) );
		$data = rest_do_request( $request )->get_data();
		$url  = (string) ( $data['signed_url'] ?? '' );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertArrayHasKey( 'mvs_exp', $query, 'No signed URL came back: ' . wp_json_encode( $data ) );

		// The setting itself is capped at a week (SignedUrlService::get_ttl()).
		$this->assertLessThanOrEqual( time() + WEEK_IN_SECONDS + 5, (int) $query['mvs_exp'] );
	}
}
