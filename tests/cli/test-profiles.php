<?php
/**
 * CLI Tests: Profiles & User Search — Real User Journeys.
 *
 * Tests what users expect from profile CRUD:
 * own profile fields, update bio/name, public profile data,
 * user media listing, search, anonymous access, and profile URL format.
 *
 * @package WPMediaVerse
 */

function run_profiles_tests(): array {
	$p = 0;
	$f = 0;
	global $wpdb;

	$data     = get_test_data();
	$admin_id = $data['admin_id'];
	$other_id = $data['other_id'];

	if ( ! $other_id ) {
		echo '  ⚠️  Need at least 2 users with media. Skipping profiles tests.' . PHP_EOL;
		return array( 0, 0 );
	}

	wp_set_current_user( $admin_id );

	// ═══════════════════════════════════
	// GET /me/profile — OWN PROFILE
	// ═══════════════════════════════════
	section( 'MY PROFILE (GET)' );
	$r = rest( 'GET', '/mvs/v1/me/profile' );
	assert_test( 'Get own profile returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Profile has display_name', isset( $r['data']['display_name'] ) ) ? $p++ : $f++;
	assert_test( 'Profile has avatar', isset( $r['data']['avatar'] ) ) ? $p++ : $f++;
	assert_test( 'Profile has username', isset( $r['data']['username'] ) ) ? $p++ : $f++;
	assert_test( 'Profile has bio field', array_key_exists( 'bio', $r['data'] ) ) ? $p++ : $f++;
	assert_test( 'Profile has email (own profile only)', isset( $r['data']['email'] ) ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// PUT /me/profile — UPDATE PROFILE
	// ═══════════════════════════════════
	section( 'UPDATE PROFILE (PUT)' );
	$original_name = $r['data']['display_name'] ?? '';
	$original_bio  = $r['data']['bio'] ?? '';
	$test_bio      = 'CLI test bio ' . time();
	$test_name     = 'CLI Test Name ' . time();

	$r = rest( 'PUT', '/mvs/v1/me/profile', array(
		'description'  => $test_bio,
		'display_name' => $test_name,
	) );
	assert_test( 'Update profile returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	// Verify the update stuck.
	$r = rest( 'GET', '/mvs/v1/me/profile' );
	assert_test( 'Bio updated correctly', ( $r['data']['bio'] ?? '' ) === $test_bio, 'got: ' . ( $r['data']['bio'] ?? 'null' ) ) ? $p++ : $f++;
	assert_test( 'Display name updated correctly', ( $r['data']['display_name'] ?? '' ) === $test_name ) ? $p++ : $f++;

	// Restore original values.
	rest( 'PUT', '/mvs/v1/me/profile', array(
		'description'  => $original_bio,
		'display_name' => $original_name ?: 'admin',
	) );

	// ═══════════════════════════════════
	// GET /users/{id} — PUBLIC PROFILE
	// ═══════════════════════════════════
	section( 'PUBLIC PROFILE' );
	$r = rest( 'GET', "/mvs/v1/users/$other_id" );
	assert_test( 'Get other user profile returns 200', $r['ok'] ) ? $p++ : $f++;
	assert_test( 'Public profile has name', isset( $r['data']['name'] ) ) ? $p++ : $f++;
	assert_test( 'Public profile has avatar', isset( $r['data']['avatar'] ) ) ? $p++ : $f++;
	assert_test( 'Public profile has media_count', isset( $r['data']['media_count'] ) ) ? $p++ : $f++;
	assert_test( 'Public profile has followers count', isset( $r['data']['followers'] ) ) ? $p++ : $f++;
	assert_test( 'Public profile has following count', isset( $r['data']['following'] ) ) ? $p++ : $f++;
	assert_test( 'Public profile has is_following flag', isset( $r['data']['is_following'] ) ) ? $p++ : $f++;
	assert_test( 'Public profile has username', isset( $r['data']['username'] ) ) ? $p++ : $f++;

	// Public profile should NOT expose email.
	assert_test( 'Public profile does NOT expose email', ! isset( $r['data']['email'] ) ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// GET /users/{id}/media — USER'S PUBLIC MEDIA
	// ═══════════════════════════════════
	section( 'USER MEDIA' );
	$r = rest( 'GET', "/mvs/v1/users/$other_id/media" );
	assert_test( 'Get user media returns 200', $r['ok'], $r['count'] . ' items' ) ? $p++ : $f++;

	// Verify all returned items belong to the user (no data leak).
	if ( $r['count'] > 0 ) {
		$all_owned = true;
		foreach ( $r['data'] as $item ) {
			$author = (int) ( $item['author'] ?? $item['user_id'] ?? 0 );
			if ( $author && $author !== $other_id ) {
				$all_owned = false;
				break;
			}
		}
		assert_test( 'All media belongs to requested user', $all_owned ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════
	// GET /users/search — USER SEARCH
	// ═══════════════════════════════════
	section( 'USER SEARCH' );
	// Search for a known user's display name.
	$other_user = get_userdata( $other_id );
	$search_q   = substr( $other_user->display_name, 0, 4 );
	$r          = rest( 'GET', '/mvs/v1/users/search', array( 'q' => $search_q ) );
	assert_test( 'Search users returns 200', $r['ok'], $r['count'] . ' results for "' . $search_q . '"' ) ? $p++ : $f++;

	// Verify searched user appears in results.
	$found_user = false;
	foreach ( $r['data'] as $u ) {
		if ( (int) ( $u['id'] ?? 0 ) === $other_id ) {
			$found_user = true;
			break;
		}
	}
	assert_test( 'Expected user in search results', $found_user ) ? $p++ : $f++;

	// Search for non-existent user returns empty.
	$r = rest( 'GET', '/mvs/v1/users/search', array( 'q' => 'zzzznonexistent999' ) );
	assert_test( 'Search non-existent user returns 0 results', $r['ok'] && $r['count'] === 0, $r['count'] . ' results' ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// ANONYMOUS ACCESS
	// ═══════════════════════════════════
	section( 'ANONYMOUS ACCESS' );
	wp_set_current_user( 0 );

	// Anonymous CAN view public profiles.
	$r = rest( 'GET', "/mvs/v1/users/$other_id" );
	assert_test( 'Anonymous can view public profile', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	// Anonymous CAN view user media.
	$r = rest( 'GET', "/mvs/v1/users/$other_id/media" );
	assert_test( 'Anonymous can view user media', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	// Anonymous CAN search users.
	$r = rest( 'GET', '/mvs/v1/users/search', array( 'q' => 'admin' ) );
	assert_test( 'Anonymous can search users', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	// Anonymous CANNOT access /me/profile.
	$r = rest( 'GET', '/mvs/v1/me/profile' );
	assert_test( 'Anonymous /me/profile returns 401', $r['status'] === 401, 'status:' . $r['status'] ) ? $p++ : $f++;

	// Anonymous CANNOT update profile.
	$r = rest( 'PUT', '/mvs/v1/me/profile', array( 'description' => 'hacked' ) );
	assert_test( 'Anonymous PUT /me/profile returns 401', $r['status'] === 401, 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// PROFILE URL FORMAT
	// ═══════════════════════════════════
	section( 'PROFILE URL FORMAT' );
	wp_set_current_user( $admin_id );

	$other_login = $other_user->user_login;
	$expected    = '/media/@' . $other_login . '/';
	$profile_url = \WPMediaVerse\Core\TemplateHelpers::get_user_profile_url( $other_id );
	assert_test(
		'Profile URL uses /media/@username/ format',
		strpos( $profile_url, $expected ) !== false,
		'url: ' . $profile_url
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// 404 FOR NON-EXISTENT USER
	// ═══════════════════════════════════
	section( 'NON-EXISTENT USER' );
	$r = rest( 'GET', '/mvs/v1/users/999999' );
	assert_test( 'Non-existent user returns 404', $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	return array( $p, $f );
}
