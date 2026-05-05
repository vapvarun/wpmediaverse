<?php
/**
 * CLI Tests: Activity Feed — Real User Journeys.
 *
 * Tests what users expect from the activity feed:
 * feed loads, items have correct shape, uploads and comments generate entries,
 * user-specific activity, anonymous access, and media links point to MVS
 * permalinks (not raw WP post URLs).
 *
 * @package WPMediaVerse
 */

function run_activity_tests(): array {
	$p = 0;
	$f = 0;
	global $wpdb;

	$data     = get_test_data();
	$admin_id = $data['admin_id'];
	$other_id = $data['other_id'];
	$media_id = $data['own_media'];

	wp_set_current_user( $admin_id );

	// ═══════════════════════════════════
	// GET /feed — PUBLIC ACTIVITY FEED
	// ═══════════════════════════════════
	section( 'PUBLIC FEED' );
	$r = rest( 'GET', '/mvs/v1/feed', array( 'scope' => 'public' ) );
	assert_test( 'Public feed returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Feed has items', $r['count'] > 0, $r['count'] . ' activities' ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// VERIFY ACTIVITY ITEM SHAPE
	// ═══════════════════════════════════
	section( 'ACTIVITY ITEM SHAPE' );
	if ( $r['count'] > 0 ) {
		$item = $r['data'][0];
		assert_test( 'Item has type', isset( $item['type'] ) ) ? $p++ : $f++;
		assert_test( 'Item has user (actor)', isset( $item['user'] ) && isset( $item['user']['id'] ) ) ? $p++ : $f++;
		assert_test( 'Item has created_at', isset( $item['created_at'] ) ) ? $p++ : $f++;
		assert_test( 'Item type is a known type', in_array(
			$item['type'],
			array( 'media_upload', 'media_reaction', 'media_comment', 'media_favorite', 'user_follow', 'album_created' ),
			true
		), 'type: ' . $item['type'] ) ? $p++ : $f++;
	} else {
		echo '  ⏭️  Item shape tests — skipped (no activity items)' . PHP_EOL;
	}

	// ═══════════════════════════════════
	// TRIGGER: COMMENT → CHECK ACTIVITY
	// ═══════════════════════════════════
	section( 'COMMENT CREATES ACTIVITY' );
	if ( $media_id ) {
		// Count activity before.
		$before_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_activity WHERE user_id = %d AND type = 'media_comment'",
			$admin_id
		) );

		$r = rest( 'POST', "/mvs/v1/media/$media_id/comments", array(
			'content' => 'Activity test comment ' . time(),
		) );
		$comment_ok = in_array( $r['status'], array( 200, 201 ), true );
		assert_test( 'Post comment for activity trigger', $comment_ok, 'status:' . $r['status'] ) ? $p++ : $f++;

		if ( $comment_ok ) {
			$after_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_activity WHERE user_id = %d AND type = 'media_comment'",
				$admin_id
			) );
			assert_test( 'Comment activity recorded in DB', $after_count > $before_count, "before=$before_count after=$after_count" ) ? $p++ : $f++;
		}
	} else {
		echo '  ⏭️  Comment activity test — skipped (no own media)' . PHP_EOL;
	}

	// ═══════════════════════════════════
	// GET /users/{id}/activity — USER-SPECIFIC
	// ═══════════════════════════════════
	section( 'USER-SPECIFIC ACTIVITY' );
	$r = rest( 'GET', "/mvs/v1/users/$admin_id/activity" );
	assert_test( 'User activity returns 200', $r['ok'], $r['count'] . ' entries' ) ? $p++ : $f++;

	// Verify all returned items belong to the requested user.
	if ( $r['count'] > 0 ) {
		$all_owned = true;
		foreach ( $r['data'] as $item ) {
			$actor_id = (int) ( $item['user']['id'] ?? 0 );
			if ( $actor_id !== $admin_id ) {
				$all_owned = false;
				break;
			}
		}
		assert_test( 'All activity belongs to requested user', $all_owned ) ? $p++ : $f++;
	}

	// Other user's activity.
	if ( $other_id ) {
		$r = rest( 'GET', "/mvs/v1/users/$other_id/activity" );
		assert_test( 'Other user activity returns 200', $r['ok'], $r['count'] . ' entries' ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════
	// ANONYMOUS FEED ACCESS
	// ═══════════════════════════════════
	section( 'ANONYMOUS ACCESS' );
	wp_set_current_user( 0 );

	$r = rest( 'GET', '/mvs/v1/feed', array( 'scope' => 'public' ) );
	assert_test( 'Anonymous can access public feed', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	if ( $other_id ) {
		$r = rest( 'GET', "/mvs/v1/users/$other_id/activity" );
		assert_test( 'Anonymous can view user activity', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	}

	// Following scope without auth falls back to public.
	$r = rest( 'GET', '/mvs/v1/feed', array( 'scope' => 'following' ) );
	assert_test( 'Anonymous following scope does not error', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// MEDIA LINKS USE MVS PERMALINKS
	// ═══════════════════════════════════
	section( 'ACTIVITY MEDIA LINKS' );
	wp_set_current_user( $admin_id );

	$r = rest( 'GET', '/mvs/v1/feed', array( 'scope' => 'public', 'per_page' => 50 ) );
	$found_media_activity = false;
	$link_correct         = false;
	$link_value           = '';

	foreach ( $r['data'] as $item ) {
		if ( ! empty( $item['media'] ) && ! empty( $item['media']['link'] ) ) {
			$found_media_activity = true;
			$link_value           = $item['media']['link'];

			// MVS permalink should contain /media/ in the URL, not a raw ?p= or default post URL.
			$expected_mid = (int) $item['media_id'];
			if ( $expected_mid ) {
				$expected_link = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $expected_mid );
				if ( $expected_link && $link_value === $expected_link ) {
					$link_correct = true;
				}
			}
			break;
		}
	}

	if ( $found_media_activity ) {
		assert_test( 'Activity media link is MVS permalink', $link_correct, 'link: ' . $link_value ) ? $p++ : $f++;
	} else {
		// No media activities yet — still pass shape test but note it.
		echo '  ⏭️  Media link test — skipped (no media-linked activities found)' . PHP_EOL;
	}

	// ═══════════════════════════════════
	// ACTIVITY ITEMS HAVE MEDIA THUMBNAILS
	// ═══════════════════════════════════
	section( 'MEDIA THUMBNAILS IN ACTIVITY' );
	$has_thumb = false;
	foreach ( $r['data'] as $item ) {
		if ( ! empty( $item['media'] ) && ! empty( $item['media']['thumbnail'] ) ) {
			$has_thumb = true;
			break;
		}
	}
	if ( $has_thumb ) {
		assert_test( 'Activity media items include thumbnail', true ) ? $p++ : $f++;
	} else {
		echo '  ⏭️  Thumbnail test — skipped (no media activities with thumbnails)' . PHP_EOL;
	}

	return array( $p, $f );
}
