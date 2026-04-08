<?php
/**
 * CLI Test Helpers.
 *
 * @package WPMediaVerse
 */

$GLOBALS['test_failures'] = array();

/**
 * Assert a test condition.
 */
function assert_test( string $name, bool $pass, string $detail = '' ): bool {
	echo ( $pass ? '  ✅' : '  ❌' ) . ' ' . $name;
	if ( $detail ) {
		echo ' — ' . $detail;
	}
	echo PHP_EOL;

	if ( ! $pass ) {
		$GLOBALS['test_failures'][] = $name . ( $detail ? " ($detail)" : '' );
	}

	return $pass;
}

/**
 * Print a section header.
 */
function section( string $title ): void {
	echo PHP_EOL . "  ── $title ──" . PHP_EOL;
}

/**
 * Make a REST request and return status + data.
 */
function rest( string $method, string $route, array $params = array() ): array {
	$req = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$res  = rest_do_request( $req );
	$data = $res->get_data();
	return array(
		'status' => $res->get_status(),
		'data'   => $data,
		'count'  => is_array( $data ) ? count( $data ) : 0,
		'ok'     => $res->get_status() >= 200 && $res->get_status() < 300,
	);
}

/**
 * Run a test suite function and return [pass, fail].
 */
function count_results( callable $fn ): array {
	$pass = 0;
	$fail = 0;
	// Override assert_test to count.
	// We use the global failures array to count.
	$before = count( $GLOBALS['test_failures'] );
	$fn();
	$after = count( $GLOBALS['test_failures'] );
	$fail  = $after - $before;
	// We can't easily count pass without modifying assert_test, so we track via a global.
	return array( $GLOBALS['_suite_pass'] ?? 0, $fail );
}

/**
 * Get test data IDs.
 */
function get_test_data(): array {
	global $wpdb;

	return array(
		'admin_id'    => 1,
		'other_id'    => (int) $wpdb->get_var(
			"SELECT DISTINCT post_author FROM {$wpdb->prefix}mvs_media_index WHERE post_author > 1 AND status = 'publish' LIMIT 1"
		),
		'own_media'   => (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d AND status = 'publish' LIMIT 1",
				1
			)
		),
		'other_media' => (int) $wpdb->get_var(
			"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author > 1 AND status = 'publish' LIMIT 1"
		),
		'album_id'    => (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'mvs_album' AND post_status = 'publish' AND post_author = %d LIMIT 1",
				1
			)
		),
	);
}
