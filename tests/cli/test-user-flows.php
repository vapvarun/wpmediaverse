<?php
/**
 * CLI Tests: Real User Flows — Multi-User Interaction Scenarios.
 *
 * Tests what actually happens when users interact with each other.
 * This catches permission leaks, notification gaps, and broken social flows.
 */

function run_user_flows_tests(): array {
	$p = 0;
	$f = 0;
	global $wpdb;

	$data     = get_test_data();
	$admin_id = 1;
	$user_a   = $data['other_id']; // mina_aoki (ID 2)
	// Get a third user.
	$user_b = (int) $wpdb->get_var(
		"SELECT DISTINCT post_author FROM {$wpdb->prefix}mvs_media_index WHERE post_author > 2 AND status = 'publish' LIMIT 1"
	);

	if ( ! $user_a || ! $user_b ) {
		echo '  ⚠️  Need at least 3 users with media. Skipping multi-user tests.' . PHP_EOL;
		return array( 0, 0 );
	}

	// Get media owned by each user.
	$media_a = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d AND status = 'publish' LIMIT 1",
		$user_a
	) );
	$media_b = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d AND status = 'publish' LIMIT 1",
		$user_b
	) );

	// ═══════════════════════════════════
	// FLOW 1: User A follows User B → User B uploads → User A sees it
	// ═══════════════════════════════════
	section( 'FLOW: Follow → Upload → Feed' );
	wp_set_current_user( $user_a );

	$r = rest( 'POST', "/mvs/v1/users/$user_b/follow" );
	assert_test( 'A follows B', $r['ok'] ) ? $p++ : $f++;

	// B's media should be in A's feed.
	$r     = rest( 'GET', '/mvs/v1/media', array( 'per_page' => 50 ) );
	$found = false;
	foreach ( $r['data'] as $item ) {
		if ( (int) ( $item['author'] ?? 0 ) === $user_b ) {
			$found = true;
			break;
		}
	}
	assert_test( 'B media visible to A', $found ) ? $p++ : $f++;

	// Cleanup.
	rest( 'DELETE', "/mvs/v1/users/$user_b/follow" );

	// ═══════════════════════════════════
	// FLOW 2: User A blocks User B → B's content hidden from A
	// ═══════════════════════════════════
	section( 'FLOW: Block → Content Hidden' );
	wp_set_current_user( $user_a );

	$r = rest( 'POST', "/mvs/v1/users/$user_b/block" );
	assert_test( 'A blocks B', $r['ok'] ) ? $p++ : $f++;

	// A should NOT see B's media in listing.
	$r     = rest( 'GET', '/mvs/v1/media', array( 'author' => $user_b ) );
	$count = $r['count'];
	assert_test( 'B media hidden from A after block', $count === 0, "$count items visible" ) ? $p++ : $f++;

	// A should NOT be able to follow B while blocked.
	$r = rest( 'POST', "/mvs/v1/users/$user_b/follow" );
	assert_test( 'Cannot follow blocked user', $r['status'] >= 400, 'status:' . $r['status'] ) ? $p++ : $f++;

	// Unblock.
	rest( 'DELETE', "/mvs/v1/users/$user_b/block" );

	// After unblock, media visible again.
	$r = rest( 'GET', '/mvs/v1/media', array( 'author' => $user_b ) );
	assert_test( 'B media visible after unblock', $r['count'] > 0, $r['count'] . ' items' ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// FLOW 3: User A comments on B's media → B gets notification
	// ═══════════════════════════════════
	section( 'FLOW: Comment → Notification' );
	wp_set_current_user( $user_a );

	$r = rest( 'POST', "/mvs/v1/media/$media_b/comments", array(
		'content' => 'Great photo! Flow test.',
	) );
	assert_test( 'A comments on B media', in_array( $r['status'], array( 200, 201 ), true ) ) ? $p++ : $f++;

	// Check B's notifications.
	wp_set_current_user( $user_b );
	$r    = rest( 'GET', '/mvs/v1/me/notifications' );
	$has_comment_notif = false;
	foreach ( $r['data'] as $n ) {
		if ( ( $n['type'] ?? '' ) === 'comment' || strpos( $n['message'] ?? $n['content'] ?? '', 'comment' ) !== false ) {
			$has_comment_notif = true;
			break;
		}
	}
	assert_test( 'B received comment notification', $has_comment_notif, $r['count'] . ' total notifications' ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// FLOW 4: User A reacts to B's media → B gets notification
	// ═══════════════════════════════════
	section( 'FLOW: React → Notification' );
	wp_set_current_user( $user_a );

	$r = rest( 'POST', "/mvs/v1/media/$media_b/reactions", array( 'reaction_type' => 'like' ) );
	assert_test( 'A reacts to B media', $r['ok'] ) ? $p++ : $f++;

	wp_set_current_user( $user_b );
	$r = rest( 'GET', '/mvs/v1/me/notifications' );
	assert_test( 'B has notifications after reaction', $r['count'] > 0, $r['count'] . ' notifications' ) ? $p++ : $f++;

	// Cleanup reaction.
	wp_set_current_user( $user_a );
	rest( 'DELETE', "/mvs/v1/media/$media_b/reactions" );

	// ═══════════════════════════════════
	// FLOW 5: User A follows B → B gets notification
	// ═══════════════════════════════════
	section( 'FLOW: Follow → Notification' );
	wp_set_current_user( $user_a );

	$r = rest( 'POST', "/mvs/v1/users/$user_b/follow" );
	assert_test( 'A follows B', $r['ok'] ) ? $p++ : $f++;

	wp_set_current_user( $user_b );
	$r = rest( 'GET', '/mvs/v1/me/notifications' );
	$has_follow_notif = false;
	foreach ( $r['data'] as $n ) {
		if ( ( $n['type'] ?? '' ) === 'follow' || strpos( $n['message'] ?? $n['content'] ?? '', 'follow' ) !== false ) {
			$has_follow_notif = true;
			break;
		}
	}
	assert_test( 'B received follow notification', $has_follow_notif ) ? $p++ : $f++;

	// Cleanup.
	wp_set_current_user( $user_a );
	rest( 'DELETE', "/mvs/v1/users/$user_b/follow" );

	// ═══════════════════════════════════
	// FLOW 6: DM Privacy — B sets DM to followers-only, A not following
	// ═══════════════════════════════════
	section( 'FLOW: DM Privacy Enforcement' );
	if ( is_pro_active() ) {
		// Set B's DM access to followers-only.
		update_user_meta( $user_b, '_mvs_dm_access', 'followers' );

		wp_set_current_user( $user_a );
		// A is NOT following B → should not be able to DM.
		$r = rest( 'POST', '/mvs/v1/conversations', array(
			'recipient_id' => $user_b,
			'message'      => 'Should be blocked',
		) );
		assert_test( 'Non-follower blocked from DM', $r['status'] >= 400, 'status:' . $r['status'] ) ? $p++ : $f++;

		// A follows B → now DM should work.
		rest( 'POST', "/mvs/v1/users/$user_b/follow" );
		$r = rest( 'POST', '/mvs/v1/conversations', array(
			'recipient_id' => $user_b,
			'message'      => 'Now I can DM',
		) );
		assert_test( 'Follower CAN DM', in_array( $r['status'], array( 200, 201 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		// Cleanup.
		rest( 'DELETE', "/mvs/v1/users/$user_b/follow" );
		delete_user_meta( $user_b, '_mvs_dm_access' );
	} else {
		echo '  ⏭️  DM privacy test — skipped (Pro not active)' . PHP_EOL;
	}

	// Restore admin user.
	wp_set_current_user( $admin_id );

	return array( $p, $f );
}
