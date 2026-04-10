<?php
/**
 * CLI Test Runner — WPMediaVerse
 *
 * Runs all test files in tests/cli/ directory.
 * Usage:
 *   php tests/cli/runner.php                # Run all tests
 *   php tests/cli/runner.php media          # Run only media tests
 *   php tests/cli/runner.php media social   # Run media + social tests
 *
 * Each test file must:
 *   1. Be named test-{feature}.php
 *   2. Define a function run_{feature}_tests() that returns [pass, fail]
 *
 * @package WPMediaVerse
 */

if ( php_sapi_name() !== 'cli' ) {
	die( 'CLI only.' );
}

require_once dirname( __DIR__, 5 ) . '/wp-load.php';
require_once __DIR__ . '/helpers.php';

wp_set_current_user( 1 );

// Parse args — filter to specific test files.
$filter = array_slice( $argv, 1 );

// Discover test files.
$test_dir   = __DIR__;
$test_files = glob( $test_dir . '/test-*.php' );
sort( $test_files );

if ( empty( $test_files ) ) {
	echo 'No test files found in ' . $test_dir . PHP_EOL;
	exit( 1 );
}

echo '╔══════════════════════════════════════════════╗' . PHP_EOL;
echo '║  WPMediaVerse CLI Test Runner                ║' . PHP_EOL;
echo '╠══════════════════════════════════════════════╣' . PHP_EOL;

$total_pass = 0;
$total_fail = 0;
$suites_run = 0;

foreach ( $test_files as $file ) {
	$basename = basename( $file, '.php' );
	$feature  = str_replace( 'test-', '', $basename );

	// Filter if args provided.
	if ( ! empty( $filter ) && ! in_array( $feature, $filter, true ) ) {
		continue;
	}

	require_once $file;

	$fn = 'run_' . str_replace( '-', '_', $feature ) . '_tests';
	if ( ! function_exists( $fn ) ) {
		echo "⚠️  $file: function $fn() not found, skipping." . PHP_EOL;
		continue;
	}

	echo PHP_EOL . "║ Suite: $feature" . PHP_EOL;
	echo '╟──────────────────────────────────────────────' . PHP_EOL;

	$result      = $fn();
	$total_pass += $result[0];
	$total_fail += $result[1];
	$suites_run++;

	echo "║ Result: {$result[0]} passed, {$result[1]} failed" . PHP_EOL;
}

echo PHP_EOL . '╠══════════════════════════════════════════════╣' . PHP_EOL;
echo "║ TOTAL: $total_pass passed, $total_fail failed ($suites_run suites)" . PHP_EOL;
echo '╚══════════════════════════════════════════════╝' . PHP_EOL;

if ( $total_fail > 0 ) {
	echo PHP_EOL . 'FAILED:' . PHP_EOL;
	foreach ( $GLOBALS['test_failures'] ?? array() as $f ) {
		echo '  ❌ ' . $f . PHP_EOL;
	}
}

exit( $total_fail > 0 ? 1 : 0 );
