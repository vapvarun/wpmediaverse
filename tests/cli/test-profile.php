<?php
/**
 * CLI Tests: Profile, Users, Notifications, Activity, Tags, Collections.
 *
 * Covers: /me/profile, /users, /me/notifications, /activity, /tags, /collections, /feed
 */

function run_profile_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();

	section( 'MY PROFILE' );
	$r = rest( 'GET', '/mvs/v1/me/profile' );
	assert_test( 'Get own profile', $r['ok'], 'name:' . ( $r['data']['display_name'] ?? '' ) ) ? $p++ : $f++;
	assert_test( 'Profile has display_name', isset( $r['data']['display_name'] ) ) ? $p++ : $f++;
	assert_test( 'Profile has avatar', isset( $r['data']['avatar'] ) ) ? $p++ : $f++;

	section( 'OTHER USER' );
	$uid = $data['other_id'];
	$r   = rest( 'GET', "/mvs/v1/users/$uid" );
	assert_test( 'Get other user profile', $r['ok'] ) ? $p++ : $f++;

	$r = rest( 'GET', "/mvs/v1/users/$uid/media" );
	assert_test( 'Get user media', $r['ok'], $r['count'] . ' items' ) ? $p++ : $f++;

	section( 'USER SEARCH' );
	$r = rest( 'GET', '/mvs/v1/users/search', array( 'q' => 'oliver' ) );
	assert_test( 'Search users: "oliver"', $r['ok'], $r['count'] . ' results' ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/users/search', array( 'q' => 'zzzznonexistent' ) );
	assert_test( 'Search non-existent user', $r['ok'] && $r['count'] === 0, '0 results' ) ? $p++ : $f++;

	section( 'NOTIFICATIONS' );
	$r = rest( 'GET', '/mvs/v1/me/notifications' );
	assert_test( 'List notifications', $r['ok'], $r['count'] . ' notifications' ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/me/notifications/count' );
	assert_test( 'Notification count', $r['ok'], 'count:' . ( $r['data']['count'] ?? 'N/A' ) ) ? $p++ : $f++;

	$r = rest( 'POST', '/mvs/v1/me/notifications/read' );
	assert_test( 'Mark all read', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'ACTIVITY' );
	$r = rest( 'GET', '/mvs/v1/users/1/activity' );
	assert_test( 'Activity feed', $r['ok'], $r['count'] . ' entries' ) ? $p++ : $f++;

	section( 'FEED' );
	$r = rest( 'GET', '/mvs/v1/feed' );
	assert_test( 'Media feed', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'TAGS' );
	$r = rest( 'GET', '/mvs/v1/tags' );
	assert_test( 'List tags', $r['ok'], $r['count'] . ' tags' ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/tags/cloud' );
	assert_test( 'Tag cloud', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'COLLECTIONS' );
	$r = rest( 'GET', '/mvs/v1/collections' );
	assert_test( 'List collections', $r['ok'], $r['count'] . ' collections' ) ? $p++ : $f++;

	return array( $p, $f );
}
