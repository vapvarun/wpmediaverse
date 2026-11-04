<?php
/**
 * The test suite never reaches the live store.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

/**
 * Tests/bootstrap.php refuses both licence hosts. Without that guard, CI jobs and local runs send the preset
 * activation to the live store as http://example.org (signed-updates checklist step 6b). Pro's suite loads the
 * same bootstrap.
 *
 * @coversNothing
 */
class NoLiveStoreTest extends WP_UnitTestCase {

	public function test_store_hosts_are_refused(): void {
		foreach ( array( 'https://wbcomdesigns.com/', 'https://updates.wbcomdesigns.com/' ) as $url ) {
			$response = wp_remote_get( $url );
			$this->assertWPError( $response, $url );
			$this->assertSame( 'mvs_tests_no_store', $response->get_error_code(), $url );
		}
	}

	public function test_licence_request_gets_no_reply(): void {
		$this->assertSame( 'https://updates.wbcomdesigns.com', MVS_LICENCE_HOST );
		$this->assertFalse(
			mvs_licence_request(
				array(
					'edd_action' => 'check_license',
					'item_id'    => 1660826,
				)
			)
		);
	}

	public function test_both_products_are_registered_for_signature_checks(): void {
		$products = \Package_Signatures_Guard::products();
		foreach ( array( 'wpmediaverse' => 'plugin:' . MVS_PLUGIN_BASENAME, 'wpmediaverse-pro' => 'plugin:wpmediaverse-pro/wpmediaverse-pro.php' ) as $slug => $key ) {
			$this->assertArrayHasKey( $key, $products, "{$slug} is not registered for signature checks." );
			$this->assertSame( 'warn', $products[ $key ]['mode'] );
			$this->assertSame( array( 'wbcomdesigns.com', 'updates.wbcomdesigns.com' ), $products[ $key ]['hosts'] );
		}
	}
}
