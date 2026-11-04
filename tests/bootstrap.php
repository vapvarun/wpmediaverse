<?php
/**
 * PHPUnit bootstrap for WPMediaVerse.
 *
 * @package WPMediaVerse
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test library not found at {$_tests_dir}. Skipping.\n";
	define( 'MVS_TESTING', true );
	define( 'ABSPATH', '/tmp/wordpress/' );
	return;
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/' );

require_once $_tests_dir . '/includes/functions.php';

// Tests never reach the live store. Refuse both licence hosts so no test (or the admin_init preset activation)
// can send an activation as http://example.org. Pro's suite loads this bootstrap too. Tests that need a store
// reply add their own pre_http_request filter at a later priority, which overrides this one.
tests_add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return in_array( $host, array( 'wbcomdesigns.com', 'updates.wbcomdesigns.com' ), true )
			? new WP_Error( 'mvs_tests_no_store', 'Tests do not call the live store.' )
			: $pre;
	},
	1,
	3
);

tests_add_filter( 'muplugins_loaded', function () {
	require dirname( __DIR__ ) . '/wpmediaverse.php';

	// Load Pro if present.
	$pro_path = dirname( __DIR__, 2 ) . '/wpmediaverse-pro/wpmediaverse-pro.php';
	if ( file_exists( $pro_path ) ) {
		require $pro_path;
	}
} );

require $_tests_dir . '/includes/bootstrap.php';
