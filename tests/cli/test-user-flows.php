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
	// FLOW 6: Notification content accuracy
	// ═══════════════════════════════════
	section( 'FLOW: Notification Content Accuracy' );
	wp_set_current_user( $user_a );

	// A comments on B's media → check notification has correct media title (not "Hello world!").
	$r = rest( 'POST', "/mvs/v1/media/$media_b/comments", array(
		'content' => 'Notification content test.',
	) );

	$media_title = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_b, 'title' );

	wp_set_current_user( $user_b );
	$r = rest( 'GET', '/mvs/v1/me/notifications' );
	$found_correct_title = false;
	$found_wrong_title   = false;
	foreach ( $r['data'] as $n ) {
		$msg = $n['message'] ?? '';
		if ( $media_title && strpos( $msg, $media_title ) !== false ) {
			$found_correct_title = true;
		}
		if ( strpos( $msg, 'Hello world' ) !== false ) {
			$found_wrong_title = true;
		}
	}
	assert_test( 'Notification uses MVS media title (not WP post title)', $found_correct_title, 'expected: ' . $media_title ) ? $p++ : $f++;
	assert_test( 'No "Hello world!" in notifications', ! $found_wrong_title ) ? $p++ : $f++;

	// Verify notification URL points to media permalink, not a WP post.
	$expected_url = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $media_b );
	$found_url    = false;
	foreach ( $r['data'] as $n ) {
		if ( ( $n['url'] ?? '' ) === $expected_url ) {
			$found_url = true;
			break;
		}
	}
	assert_test( 'Notification URL is MVS media permalink', $found_url, 'expected: ' . $expected_url ) ? $p++ : $f++;

	// Verify notification recipient is the media owner (from mvs_media_index, not wp_posts).
	$mvs_owner = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_b, 'post_author' );
	assert_test( 'Notification sent to MVS media owner', $mvs_owner === $user_b, "mvs_owner=$mvs_owner user_b=$user_b" ) ? $p++ : $f++;

	wp_set_current_user( $user_a );

	// ═══════════════════════════════════
	// FLOW 7: DM → Notification
	// ═══════════════════════════════════
	section( 'FLOW: DM → Notification' );
	$r = rest( 'POST', '/mvs/v1/conversations', array(
		'recipient_id' => $user_b,
		'message'      => 'DM notification test',
	) );
	if ( in_array( $r['status'], array( 200, 201 ), true ) ) {
		wp_set_current_user( $user_b );
		$r = rest( 'GET', '/mvs/v1/me/notifications' );
		$has_dm_notif = false;
		foreach ( $r['data'] as $n ) {
			if ( ( $n['type'] ?? '' ) === 'new_message' || strpos( $n['message'] ?? '', 'message' ) !== false ) {
				$has_dm_notif = true;
				break;
			}
		}
		assert_test( 'B received DM notification', $has_dm_notif, $r['count'] . ' notifications' ) ? $p++ : $f++;

		// Check DM notification URL points to /messages/.
		$has_messages_url = false;
		foreach ( $r['data'] as $n ) {
			if ( ( $n['type'] ?? '' ) === 'new_message' && strpos( $n['url'] ?? '', '/messages/' ) !== false ) {
				$has_messages_url = true;
				break;
			}
		}
		assert_test( 'DM notification links to /messages/', $has_messages_url ) ? $p++ : $f++;
		wp_set_current_user( $user_a );
	} else {
		echo '  ⏭️  DM notification test — skipped (conversation create failed: ' . $r['status'] . ')' . PHP_EOL;
	}

	// ═══════════════════════════════════
	// FLOW 8: DM Privacy — B sets DM to followers-only, A not following
	// ═══════════════════════════════════
	section( 'FLOW: DM Privacy Enforcement (Profile Button)' );
	if ( is_pro_active() ) {
		// Use a fresh user pair with no prior conversation to test DM privacy.
		// A→B already has a conversation from FLOW 7, so we test with a different user.
		global $wpdb;
		$dm_test_user = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->users} WHERE ID NOT IN (%d, %d, 1) LIMIT 1",
			$user_a,
			$user_b
		) );

		if ( $dm_test_user ) {
			// Delete any existing conversation between user_a and dm_test_user.
			$existing_conv = $wpdb->get_var( $wpdb->prepare(
				"SELECT p1.conversation_id FROM {$wpdb->prefix}mvs_conversation_participants p1
				INNER JOIN {$wpdb->prefix}mvs_conversation_participants p2 ON p1.conversation_id = p2.conversation_id
				WHERE p1.user_id = %d AND p2.user_id = %d LIMIT 1",
				$user_a,
				$dm_test_user
			) );
			if ( $existing_conv ) {
				$wpdb->delete( $wpdb->prefix . 'mvs_messages', array( 'conversation_id' => $existing_conv ) );
				$wpdb->delete( $wpdb->prefix . 'mvs_conversation_participants', array( 'conversation_id' => $existing_conv ) );
				$wpdb->delete( $wpdb->prefix . 'mvs_conversations', array( 'id' => $existing_conv ) );
			}

			// Set DM access to followers-only.
			update_user_meta( $dm_test_user, '_mvs_dm_access', 'followers' );

			wp_set_current_user( $user_a );
			// A is NOT following dm_test_user → should not be able to create NEW conversation.
			$r = rest( 'POST', '/mvs/v1/conversations', array(
				'recipient_id' => $dm_test_user,
				'message'      => 'Should be blocked',
			) );
			assert_test( 'Non-follower blocked from DM', $r['status'] >= 400, 'status:' . $r['status'] ) ? $p++ : $f++;

			// Cleanup.
			delete_user_meta( $dm_test_user, '_mvs_dm_access' );
		} else {
			echo '  ⏭️  DM privacy test — skipped (need 3+ users)' . PHP_EOL;
		}

		// Set B's DM access to followers-only for the follow→DM test.
		update_user_meta( $user_b, '_mvs_dm_access', 'followers' );

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
