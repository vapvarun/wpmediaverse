<?php
/**
 * CLI Tests: Comment lifecycle — data accuracy, edit window, permissions.
 *
 * Covers: POST/GET/PUT/DELETE /mvs/v1/media/{id}/comments,
 *         content fidelity, author fields, edit within 15min,
 *         cross-user delete permissions, admin privilege, anonymous rejection,
 *         and comment_count stats sync.
 *
 * @package WPMediaVerse
 */

function run_comments_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();
	$mid  = $data['other_media'];
	$aid  = $data['admin_id'];
	$oid  = $data['other_id'];

	wp_set_current_user( $aid );

	// ── ADMIN POSTS COMMENT ON OTHER USER'S MEDIA ──
	section( 'POST COMMENT' );

	$txt = 'CLI reaction test comment ' . time();
	$r   = rest( 'POST', "/mvs/v1/media/$mid/comments", array( 'content' => $txt ) );
	$cid = $r['data']['id'] ?? 0;
	assert_test( 'Admin posts comment — 201', $r['status'] === 201, 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Comment ID returned', $cid > 0, "id:$cid" ) ? $p++ : $f++;

	// ── VERIFY CONTENT EXACT MATCH ──
	section( 'DATA ACCURACY' );

	assert_test( 'Content matches what was sent', ( $r['data']['content'] ?? '' ) === $txt, 'got:' . ( $r['data']['content'] ?? 'empty' ) ) ? $p++ : $f++;

	// Verify author fields.
	$admin_user = get_userdata( $aid );
	assert_test( 'author field is admin ID', ( $r['data']['author'] ?? 0 ) === $aid, 'author:' . ( $r['data']['author'] ?? 0 ) ) ? $p++ : $f++;
	assert_test( 'author_name matches admin display name', ( $r['data']['author_name'] ?? '' ) === $admin_user->display_name, 'name:' . ( $r['data']['author_name'] ?? '' ) ) ? $p++ : $f++;

	// Verify it appears in the GET list.
	$r     = rest( 'GET', "/mvs/v1/media/$mid/comments" );
	$found = false;
	foreach ( ( $r['data'] ?? array() ) as $c ) {
		if ( ( $c['id'] ?? 0 ) === $cid ) {
			$found = true;
			break;
		}
	}
	assert_test( 'Comment appears in GET list', $found ) ? $p++ : $f++;

	// ── EDIT COMMENT WITHIN 15-MIN WINDOW ──
	section( 'EDIT COMMENT' );

	$new_txt = 'Edited comment content ' . time();
	$r       = rest( 'PUT', "/mvs/v1/media/$mid/comments/$cid", array( 'content' => $new_txt ) );
	assert_test( 'Edit own comment — 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Edited content matches', ( $r['data']['content'] ?? '' ) === $new_txt ) ? $p++ : $f++;
	assert_test( 'Edit response has edited=true', ( $r['data']['edited'] ?? false ) === true ) ? $p++ : $f++;

	// ── OTHER USER POSTS COMMENT ──
	section( 'MULTI-USER COMMENTS' );

	wp_set_current_user( $oid );
	$txt2 = 'Other user comment ' . time();
	$r    = rest( 'POST', "/mvs/v1/media/$mid/comments", array( 'content' => $txt2 ) );
	$cid2 = $r['data']['id'] ?? 0;
	assert_test( 'Other user posts comment', $r['status'] === 201, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', "/mvs/v1/media/$mid/comments" );
	$ids_found = array();
	foreach ( ( $r['data'] ?? array() ) as $c ) {
		$ids_found[] = $c['id'] ?? 0;
	}
	assert_test( 'Both comments exist in list', in_array( $cid, $ids_found, true ) && in_array( $cid2, $ids_found, true ), 'ids:' . implode( ',', $ids_found ) ) ? $p++ : $f++;

	// ── DELETE OWN COMMENT ──
	section( 'DELETE COMMENTS' );

	$r = rest( 'DELETE', "/mvs/v1/media/$mid/comments/$cid2" );
	assert_test( 'Other user deletes own comment — 204', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	// Verify removed.
	$r         = rest( 'GET', "/mvs/v1/media/$mid/comments" );
	$still_has = false;
	foreach ( ( $r['data'] ?? array() ) as $c ) {
		if ( ( $c['id'] ?? 0 ) === $cid2 ) {
			$still_has = true;
		}
	}
	assert_test( 'Deleted comment removed from list', ! $still_has ) ? $p++ : $f++;

	// ── NON-ADMIN TRIES TO DELETE ANOTHER USER'S COMMENT ──
	section( 'PERMISSION ENFORCEMENT' );

	// $cid belongs to admin; other user should NOT be able to delete it.
	$r = rest( 'DELETE', "/mvs/v1/media/$mid/comments/$cid" );
	assert_test( 'Non-admin cannot delete other user comment — 403', $r['status'] === 403, 'status:' . $r['status'] ) ? $p++ : $f++;

	// ── ADMIN CAN DELETE ANY COMMENT ──
	wp_set_current_user( $aid );

	// Post a throwaway comment as other user first.
	wp_set_current_user( $oid );
	$r         = rest( 'POST', "/mvs/v1/media/$mid/comments", array( 'content' => 'Temp for admin delete test' ) );
	$cid_temp  = $r['data']['id'] ?? 0;
	wp_set_current_user( $aid );

	if ( $cid_temp ) {
		$r = rest( 'DELETE', "/mvs/v1/media/$mid/comments/$cid_temp" );
		assert_test( 'Admin deletes other user comment', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
	} else {
		assert_test( 'Admin deletes other user comment', false, 'could not create temp comment' ) ? $p++ : $f++;
	}

	// ── ANONYMOUS USER TRIES TO COMMENT ──
	section( 'ANONYMOUS REJECTION' );

	wp_set_current_user( 0 );
	$r = rest( 'POST', "/mvs/v1/media/$mid/comments", array( 'content' => 'anon test' ) );
	assert_test( 'Anonymous POST comment returns 401', $r['status'] === 401, 'status:' . $r['status'] ) ? $p++ : $f++;

	wp_set_current_user( $aid );

	// ── COMMENT COUNT IN STATS ──
	section( 'STATS SYNC' );

	$r     = rest( 'GET', "/mvs/v1/media/$mid" );
	$stats = $r['data']['stats']['comments'] ?? -1;
	$r2    = rest( 'GET', "/mvs/v1/media/$mid/comments" );
	// X-WP-Total header holds the authoritative count.
	$total_header = count( $r2['data'] ?? array() );
	assert_test( 'stats.comments matches actual count', (int) $stats === $total_header, "stats:$stats actual:$total_header" ) ? $p++ : $f++;

	// ── CLEANUP: remove the admin's edited comment ──
	if ( $cid ) {
		rest( 'DELETE', "/mvs/v1/media/$mid/comments/$cid" );
	}

	return array( $p, $f );
}
