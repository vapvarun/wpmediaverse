<?php
/**
 * CLI Tests: Social features — Reactions, Favorites, Comments, Follows.
 *
 * Covers: reactions, favorites, comments, follow/unfollow, report, block
 */

function run_social_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();
	$mid  = $data['other_media'];
	$uid  = $data['other_id'];

	// ── REACTIONS ──
	section( 'REACTIONS' );
	$r = rest( 'POST', "/mvs/v1/media/$mid/reactions", array( 'reaction_type' => 'like' ) );
	assert_test( 'Add reaction (like)', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', "/mvs/v1/media/$mid" );
	$ct = $r['data']['stats']['reactions'] ?? $r['data']['reaction_count'] ?? 0;
	assert_test( 'Reaction count incremented', $ct > 0, "count: $ct" ) ? $p++ : $f++;

	$r = rest( 'DELETE', "/mvs/v1/media/$mid/reactions" );
	assert_test( 'Remove reaction', in_array( $r['status'], array( 200, 204 ), true ) ) ? $p++ : $f++;

	// ── FAVORITES ──
	section( 'FAVORITES' );
	$r = rest( 'POST', "/mvs/v1/media/$mid/favorite" );
	assert_test( 'Add favorite', $r['ok'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/me/favorites' );
	assert_test( 'Favorites list has items', $r['ok'] && $r['count'] > 0, $r['count'] . ' favorites' ) ? $p++ : $f++;

	$r = rest( 'DELETE', "/mvs/v1/media/$mid/favorite" );
	assert_test( 'Remove favorite', in_array( $r['status'], array( 200, 204 ), true ) ) ? $p++ : $f++;

	// ── COMMENTS ──
	section( 'COMMENTS' );
	$txt = 'CLI test comment ' . time();
	$r   = rest( 'POST', "/mvs/v1/media/$mid/comments", array( 'content' => $txt ) );
	$cid = $r['data']['id'] ?? 0;
	assert_test( 'Post comment', in_array( $r['status'], array( 200, 201 ), true ), "id: $cid" ) ? $p++ : $f++;

	$r     = rest( 'GET', "/mvs/v1/media/$mid/comments" );
	$found = false;
	foreach ( $r['data'] as $c ) {
		if ( strpos( $c['content'] ?? '', 'CLI test comment' ) !== false ) {
			$found = true;
		}
	}
	assert_test( 'Comment appears in list', $found ) ? $p++ : $f++;

	if ( $cid ) {
		$r = rest( 'DELETE', "/mvs/v1/media/$mid/comments/$cid" );
		assert_test( 'Delete own comment', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
	}

	// ── FOLLOW / UNFOLLOW ──
	section( 'FOLLOW / UNFOLLOW' );
	if ( $uid ) {
		$r = rest( 'POST', "/mvs/v1/users/$uid/follow" );
		assert_test( 'Follow user', $r['ok'] ) ? $p++ : $f++;

		$r = rest( 'GET', '/mvs/v1/me/following' );
		assert_test( 'Appears in following list', $r['ok'] && $r['count'] > 0 ) ? $p++ : $f++;

		$r = rest( 'GET', "/mvs/v1/users/$uid/followers" );
		assert_test( 'User has follower', $r['ok'] && $r['count'] > 0 ) ? $p++ : $f++;

		$r = rest( 'GET', '/mvs/v1/me/followers' );
		assert_test( 'My followers endpoint works', $r['ok'] ) ? $p++ : $f++;

		$r = rest( 'DELETE', "/mvs/v1/users/$uid/follow" );
		assert_test( 'Unfollow', in_array( $r['status'], array( 200, 204 ), true ) ) ? $p++ : $f++;
	}

	// ── REPORT ──
	section( 'REPORT & BLOCK' );
	$r = rest( 'POST', "/mvs/v1/media/$mid/report", array(
		'reason'  => 'spam',
		'details' => 'CLI test',
	) );
	assert_test( 'Report media', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	if ( $uid ) {
		$r = rest( 'POST', "/mvs/v1/users/$uid/report", array(
			'reason'  => 'harassment',
			'details' => 'CLI test user report',
		) );
		assert_test( 'Report user', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		$r = rest( 'POST', "/mvs/v1/users/$uid/block" );
		assert_test( 'Block user', in_array( $r['status'], array( 200, 201 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		$r = rest( 'GET', '/mvs/v1/me/blocked' );
		assert_test( 'Blocked list', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

		$r = rest( 'DELETE', "/mvs/v1/users/$uid/block" );
		assert_test( 'Unblock user', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
	}

	return array( $p, $f );
}
