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

tests_add_filter( 'muplugins_loaded', function () {
	require dirname( __DIR__ ) . '/wpmediaverse.php';

	// Load Pro if present.
	$pro_path = dirname( __DIR__, 2 ) . '/wpmediaverse-pro/wpmediaverse-pro.php';
	if ( file_exists( $pro_path ) ) {
		require $pro_path;
	}
} );

require $_tests_dir . '/includes/bootstrap.php';
