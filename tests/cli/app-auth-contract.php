<?php
/**
 * App-auth contract suite — the Wbcom App Auth standard, live.
 *
 * Proves on the running site that:
 *
 *  1. `GET /app/config` publishes the standard `auth` block the fleet-wide
 *     app reader (`@wbcom/mobile-core` sanitizeAuthBlock) parses, plus the
 *     `features.password_login` switch.
 *  2. The scheme seams work: `mediaverseapp` in the site's own allowlist,
 *     the `mvs_app_connect_schemes` sibling seam, and — combined topology —
 *     BuddyNext's allowlist accepting `mediaverseapp` (one door per site).
 *  3. Standalone, `connect_url` is EMPTY by decision: MediaVerse builds no
 *     bridge; core's repaired authorize screen is its interactive door. The
 *     `mvs_app_connect_bridge` filter can redirect the door.
 *  4. The kses repair is scoped: `mediaverseapp` passes esc_url ONLY on the
 *     authorize screen, never in ordinary content.
 *  5. `POST /auth/app-password` mints a working Basic credential for a good
 *     password, refuses a wrong one with the uniform 401, and refuses a
 *     SUSPENDED member (mvs_suspended meta) even with a correct password.
 *  6. Reconnect replaces, on EVERY door: two core-API mints with one app_id
 *     leave one row; a hand-made row (no app_id) is never touched.
 *
 * RUN:   wp eval-file wp-content/plugins/wpmediaverse/tests/cli/app-auth-contract.php
 * EXIT:  non-zero if any case fails (CI-friendly).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\CLI;

use WPMediaVerse\Auth\AppAuthorizeAccess;
use WPMediaVerse\Auth\AppConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$pass = 0;
$fail = 0;

$check = function ( string $id, bool $ok, string $detail = '' ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		echo "  PASS  {$id}\n";
	} else {
		++$fail;
		echo "  FAIL  {$id}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	}
};

echo "MediaVerse app-auth contract suite\n";

$bn_active = class_exists( '\BuddyNext\App\AppConnectService' );
echo $bn_active ? "  (topology: BuddyNext ACTIVE — combined)\n" : "  (topology: standalone — no BuddyNext)\n";

$base = untrailingslashit( home_url() ) . '/wp-json/mvs/v1';

/** Loopback request. Returns [status, body-array]. */
$request = function ( string $method, string $path, array $body = array(), string $basic = '' ) use ( $base ): array {
	$args = array(
		'method'    => $method,
		'timeout'   => 15,
		'sslverify' => false, // Local dev cert.
		'headers'   => array(),
	);
	if ( $body ) {
		$args['body'] = $body;
	}
	if ( '' !== $basic ) {
		$args['headers']['Authorization'] = 'Basic ' . base64_encode( $basic ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth encoding.
	}
	$response = wp_remote_request( $base . $path, $args );
	if ( is_wp_error( $response ) ) {
		return array( 0, array( 'error' => $response->get_error_message() ) );
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	return array( (int) wp_remote_retrieve_response_code( $response ), is_array( $decoded ) ? $decoded : array() );
};

// ── 1. The auth block + password_login flag on the live /app/config ──────
list( $status, $config ) = $request( 'GET', '/app/config' );
$auth                    = $config['auth'] ?? null;

$check( 'config.200', 200 === $status );
$check( 'config.auth-present', is_array( $auth ), 'no auth block on /app/config' );
$check( 'config.password-login-flag', true === ( $config['features']['password_login'] ?? null ) );

if ( is_array( $auth ) ) {
	foreach ( array( 'social_providers', 'twofactor', 'register', 'app_passwords_available', 'connect_url', 'connect_schemes' ) as $key ) {
		$check( "config.auth.{$key}", array_key_exists( $key, $auth ) );
	}
	$check( 'config.auth.social-empty', array() === $auth['social_providers'] );
	$check(
		'config.auth.schemes-carry-app',
		in_array( AppAuthorizeAccess::app_scheme(), (array) ( $auth['connect_schemes'] ?? array() ), true ),
		'mediaverseapp missing from connect_schemes'
	);
}

// ── 2. Scheme seams ──────────────────────────────────────────────────────
$check( 'schemes.own', in_array( 'mediaverseapp', AppConnect::schemes(), true ) );

$sibling = function ( $schemes ) {
	$schemes[] = 'siblingapp';
	return $schemes;
};
add_filter( 'mvs_app_connect_schemes', $sibling );
$check( 'schemes.sibling-seam', in_array( 'siblingapp', AppConnect::schemes(), true ) );
remove_filter( 'mvs_app_connect_schemes', $sibling );

// ── 3. One-door deference + override ─────────────────────────────────────
$bridge = AppConnect::bridge_info();

if ( $bn_active ) {
	$check( 'bridge.owner-bn', 'buddynext' === ( $bridge['owner'] ?? '' ) );
	$check( 'bridge.bn-url', '' !== (string) ( $bridge['connect_url'] ?? '' ) );
	$check(
		'bridge.bn-allowlist-accepts-us',
		in_array( 'mediaverseapp', (array) apply_filters( 'buddynext_app_connect_schemes', array() ), true ),
		'BN allowlist does not carry mediaverseapp — join filter not registered?'
	);
} else {
	$check( 'bridge.owner-self', 'wpmediaverse' === ( $bridge['owner'] ?? '' ) );
	$check( 'bridge.no-own-bridge', '' === (string) ( $bridge['connect_url'] ?? '' ), 'standalone MediaVerse must NOT advertise a bridge' );
}

$override = function () {
	return array(
		'owner'           => 'test',
		'connect_url'     => 'https://example.test/connect-app/',
		'connect_schemes' => array( 'mediaverseapp' ),
	);
};
add_filter( 'mvs_app_connect_bridge', $override );
$forced = AppConnect::bridge_info();
remove_filter( 'mvs_app_connect_bridge', $override );
$check( 'bridge.filter-override', 'https://example.test/connect-app/' === ( $forced['connect_url'] ?? '' ) );

// ── 4. kses repair is SCOPED to the authorize screen ─────────────────────
// esc_url() itself cannot be exercised both ways in-process:
// wp_allowed_protocols() freezes its static list at wp_loaded, long before
// this suite runs. On a REAL authorize request SCRIPT_FILENAME is the
// authorize screen from the first line of the request, so the filter fires
// during boot and the scheme enters the list — the sim walk proves that
// end-to-end. Here we prove OUR filter's scoping decision directly.
$deep_link = 'mediaverseapp://auth?site_url=x';
$check( 'kses.stripped-in-content', '' === esc_url( $deep_link ), 'app scheme must NOT be linkable in ordinary content' );

$prev_script                = $_SERVER['SCRIPT_FILENAME'] ?? '';
$_SERVER['SCRIPT_FILENAME'] = '/wp-admin/authorize-application.php';
$on_authorize               = AppAuthorizeAccess::allow_app_scheme( array( 'http', 'https' ) );
$_SERVER['SCRIPT_FILENAME'] = '/index.php';
$off_authorize              = AppAuthorizeAccess::allow_app_scheme( array( 'http', 'https' ) );
$_SERVER['SCRIPT_FILENAME'] = $prev_script;
$check( 'kses.filter-adds-on-authorize', in_array( 'mediaverseapp', $on_authorize, true ) );
$check( 'kses.filter-inert-elsewhere', ! in_array( 'mediaverseapp', $off_authorize, true ) );

// ── 5. The credentials exchange, live over loopback ──────────────────────
$login    = 'appauth_contract_user';
$password = wp_generate_password( 24 );
$user     = get_user_by( 'login', $login );
if ( $user ) {
	wp_delete_user( $user->ID );
}
$user_id = wp_insert_user(
	array(
		'user_login' => $login,
		'user_pass'  => $password,
		'user_email' => $login . '@example.test',
		'role'       => 'subscriber',
	)
);

if ( is_wp_error( $user_id ) ) {
	$check( 'exchange.fixture', false, $user_id->get_error_message() );
} else {
	$app_id = wp_generate_uuid4();

	list( $status, $body ) = $request(
		'POST',
		'/auth/app-password',
		array(
			'username' => $login,
			'password' => $password,
			'app_name' => 'MediaVerse contract',
			'app_id'   => $app_id,
		)
	);
	$minted                = (string) ( $body['password'] ?? '' );
	$check( 'exchange.mints', 200 === $status && '' !== $minted, "status {$status}" );

	if ( '' !== $minted ) {
		list( $status ) = $request( 'GET', '/app/config', array(), $login . ':' . $minted );
		$check( 'exchange.credential-authenticates', 200 === $status );
	}

	list( $status, $body ) = $request(
		'POST',
		'/auth/app-password',
		array(
			'username' => $login,
			'password' => 'definitely-wrong-password',
		)
	);
	$check( 'exchange.wrong-password-401', 401 === $status && 'mvs_login_failed' === ( $body['code'] ?? '' ), "status {$status} code " . ( $body['code'] ?? '-' ) );

	// A suspended member must not mint, even with the right password.
	update_user_meta( $user_id, 'mvs_suspended', 1 );
	list( $status, $body ) = $request(
		'POST',
		'/auth/app-password',
		array(
			'username' => $login,
			'password' => $password,
		)
	);
	$check( 'exchange.suspended-refused', 403 === $status && 'mvs_account_suspended' === ( $body['code'] ?? '' ), "status {$status} code " . ( $body['code'] ?? '-' ) );
	delete_user_meta( $user_id, 'mvs_suspended' );

	// ── 6. Reconnect replaces, on EVERY door ─────────────────────────────
	\WP_Application_Passwords::create_new_application_password(
		$user_id,
		array(
			'name'   => 'MediaVerse',
			'app_id' => $app_id,
		)
	);
	$rows = array_filter(
		\WP_Application_Passwords::get_user_application_passwords( $user_id ),
		static fn( $row ) => ( $row['app_id'] ?? '' ) === $app_id
	);
	$check( 'replace.one-row-per-install', 1 === count( $rows ), count( $rows ) . ' rows for one app_id' );

	\WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'Hand-made' ) );
	\WP_Application_Passwords::create_new_application_password(
		$user_id,
		array(
			'name'   => 'MediaVerse',
			'app_id' => $app_id,
		)
	);
	$all = \WP_Application_Passwords::get_user_application_passwords( $user_id );
	$check( 'replace.handmade-untouched', 2 === count( $all ), count( $all ) . ' rows total (want hand-made + one app row)' );

	wp_delete_user( $user_id );
}

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
