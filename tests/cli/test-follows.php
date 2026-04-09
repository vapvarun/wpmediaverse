<?php
/**
 * CLI Tests: Follow System — Real User Journeys.
 *
 * Tests what users expect when they follow/unfollow each other:
 * lists update, counts match, self-follow blocked, anonymous rejected,
 * idempotent re-follow, and Pro DM access integration.
 *
 * @package WPMediaVerse
 */

function run_follows_tests(): array {
	$p = 0;
	$f = 0;
	global $wpdb;

	$data     = get_test_data();
	$admin_id = $data['admin_id'];
	$other_id = $data['other_id'];

	if ( ! $other_id ) {
		echo '  ⚠️  Need at least 2 users with media. Skipping follows tests.' . PHP_EOL;
		return array( 0, 0 );
	}

	// Clean slate: ensure admin is NOT following other user.
	wp_set_current_user( $admin_id );
	rest( 'DELETE', "/mvs/v1/users/$other_id/follow" );

	// ═══════════════════════════════════
	// FOLLOW
	// ═══════════════════════════════════
	section( 'FOLLOW USER' );
	$r = rest( 'POST', "/mvs/v1/users/$other_id/follow" );
	assert_test( 'Follow returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Response confirms following=true', ( $r['data']['following'] ?? false ) === true ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// VERIFY: OTHER USER IN ADMIN'S FOLLOWING LIST
	// ═══════════════════════════════════
	section( 'FOLLOWING LIST' );
	$r        = rest( 'GET', "/mvs/v1/users/$admin_id/following" );
	$found_id = false;
	foreach ( $r['data'] as $u ) {
		if ( (int) ( $u['id'] ?? 0 ) === $other_id ) {
			$found_id = true;
			break;
		}
	}
	assert_test( 'Other user in admin following list', $found_id, $r['count'] . ' following' ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// VERIFY: ADMIN IN OTHER USER'S FOLLOWERS LIST
	// ═══════════════════════════════════
	section( 'FOLLOWERS LIST' );
	$r           = rest( 'GET', "/mvs/v1/users/$other_id/followers" );
	$admin_found = false;
	foreach ( $r['data'] as $u ) {
		if ( (int) ( $u['id'] ?? 0 ) === $admin_id ) {
			$admin_found = true;
			break;
		}
	}
	assert_test( 'Admin in other user followers list', $admin_found, $r['count'] . ' followers' ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// VERIFY COUNTS
	// ═══════════════════════════════════
	section( 'FOLLOW COUNTS' );
	$r              = rest( 'GET', "/mvs/v1/users/$admin_id/following" );
	$admin_following = (int) ( $r['data'][0] ?? false ? $r['count'] : 0 );
	// Use X-WP-Total header if available, otherwise use array count.
	assert_test( 'Admin following count >= 1', $r['count'] >= 1, 'count:' . $r['count'] ) ? $p++ : $f++;

	$r = rest( 'GET', "/mvs/v1/users/$other_id/followers" );
	assert_test( 'Other user follower count >= 1', $r['count'] >= 1, 'count:' . $r['count'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// /me/following AND /me/followers
	// ═══════════════════════════════════
	section( 'ME ENDPOINTS' );
	$r        = rest( 'GET', '/mvs/v1/me/following' );
	$found_me = false;
	foreach ( $r['data'] as $u ) {
		if ( (int) ( $u['id'] ?? 0 ) === $other_id ) {
			$found_me = true;
			break;
		}
	}
	assert_test( '/me/following includes followed user', $found_me ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/me/followers' );
	assert_test( '/me/followers returns OK', $r['ok'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// UNFOLLOW
	// ═══════════════════════════════════
	section( 'UNFOLLOW' );
	$r = rest( 'DELETE', "/mvs/v1/users/$other_id/follow" );
	assert_test( 'Unfollow returns success', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	// Verify removed from following list.
	$r     = rest( 'GET', "/mvs/v1/users/$admin_id/following" );
	$still = false;
	foreach ( $r['data'] as $u ) {
		if ( (int) ( $u['id'] ?? 0 ) === $other_id ) {
			$still = true;
			break;
		}
	}
	assert_test( 'Other user removed from following list', ! $still ) ? $p++ : $f++;

	// Verify removed from followers list.
	$r     = rest( 'GET', "/mvs/v1/users/$other_id/followers" );
	$still = false;
	foreach ( $r['data'] as $u ) {
		if ( (int) ( $u['id'] ?? 0 ) === $admin_id ) {
			$still = true;
			break;
		}
	}
	assert_test( 'Admin removed from followers list', ! $still ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// IDEMPOTENT RE-FOLLOW (double follow should not error)
	// ═══════════════════════════════════
	section( 'IDEMPOTENT RE-FOLLOW' );
	$r1 = rest( 'POST', "/mvs/v1/users/$other_id/follow" );
	$r2 = rest( 'POST', "/mvs/v1/users/$other_id/follow" );
	assert_test( 'First follow OK', $r1['ok'] ) ? $p++ : $f++;
	assert_test( 'Second follow (duplicate) does not error', $r2['ok'], 'status:' . $r2['status'] ) ? $p++ : $f++;

	// Cleanup double-follow.
	rest( 'DELETE', "/mvs/v1/users/$other_id/follow" );

	// ═══════════════════════════════════
	// SELF-FOLLOW (should fail or be prevented)
	// ═══════════════════════════════════
	section( 'SELF-FOLLOW PREVENTION' );
	$r = rest( 'POST', "/mvs/v1/users/$admin_id/follow" );
	assert_test( 'Self-follow prevented', $r['status'] >= 400 || ( $r['data']['following'] ?? null ) === false, 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// ANONYMOUS FOLLOW (should get 401)
	// ═══════════════════════════════════
	section( 'ANONYMOUS ACCESS' );
	wp_set_current_user( 0 );
	$r = rest( 'POST', "/mvs/v1/users/$other_id/follow" );
	assert_test( 'Anonymous follow returns 401', $r['status'] === 401, 'status:' . $r['status'] ) ? $p++ : $f++;

	// Verify anonymous CAN read public followers/following lists.
	$r = rest( 'GET', "/mvs/v1/users/$other_id/followers" );
	assert_test( 'Anonymous can read followers list', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', "/mvs/v1/users/$other_id/following" );
	assert_test( 'Anonymous can read following list', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	// Anonymous /me/following should fail.
	$r = rest( 'GET', '/mvs/v1/me/following' );
	assert_test( 'Anonymous /me/following returns 401', $r['status'] === 401, 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// PRO: FOLLOW AFFECTS DM ACCESS
	// ═══════════════════════════════════
	section( 'PRO: FOLLOW → DM ACCESS' );
	if ( is_pro_active() ) {
		wp_set_current_user( $admin_id );

		// Set other user's DM access to followers-only.
		update_user_meta( $other_id, '_mvs_dm_access', 'followers' );

		// Admin NOT following other → DM should be blocked.
		$r = rest( 'POST', '/mvs/v1/conversations', array(
			'recipient_id' => $other_id,
			'message'      => 'Should be blocked — not following',
		) );
		assert_test( 'Non-follower cannot DM (followers-only)', $r['status'] >= 400, 'status:' . $r['status'] ) ? $p++ : $f++;

		// Admin follows other → DM should work.
		rest( 'POST', "/mvs/v1/users/$other_id/follow" );
		$r = rest( 'POST', '/mvs/v1/conversations', array(
			'recipient_id' => $other_id,
			'message'      => 'Should work — now following',
		) );
		assert_test( 'Follower CAN DM (followers-only)', in_array( $r['status'], array( 200, 201 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		// Cleanup.
		rest( 'DELETE', "/mvs/v1/users/$other_id/follow" );
		delete_user_meta( $other_id, '_mvs_dm_access' );
	} else {
		echo '  ⏭️  Pro DM access test — skipped (Pro not active)' . PHP_EOL;
	}

	// Restore admin context.
	wp_set_current_user( $admin_id );

	return array( $p, $f );
}
