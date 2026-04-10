<?php
/**
 * CLI Tests: Reaction lifecycle — full user journey with two users.
 *
 * Covers: POST/GET/DELETE /mvs/v1/media/{id}/reactions, reaction counts,
 *         user_reaction field, all 6 emoji types, cross-user coexistence,
 *         anonymous rejection, and stats sync on media index.
 *
 * @package WPMediaVerse
 */

function run_reactions_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();
	$mid  = $data['other_media'];
	$aid  = $data['admin_id'];
	$oid  = $data['other_id'];

	// ── CLEANUP: ensure no stale reactions from prior runs ──
	// Delete via API first to trigger proper hooks/stats updates.
	wp_set_current_user( $aid );
	rest( 'DELETE', "/mvs/v1/media/$mid/reactions" );
	wp_set_current_user( $oid );
	rest( 'DELETE', "/mvs/v1/media/$mid/reactions" );
	wp_set_current_user( $aid );

	// Also clean directly from DB as a safeguard against orphaned rows.
	global $wpdb;
	$wpdb->delete( $wpdb->prefix . 'mvs_reactions', array( 'media_id' => $mid ) );
	// Reset the reaction count in media stats to match.
	$wpdb->update(
		$wpdb->prefix . 'mvs_media_stats',
		array( 'reactions' => 0 ),
		array( 'media_id' => $mid )
	);

	// ── ADMIN ADDS 'LIKE' REACTION ──
	section( 'ADMIN ADDS REACTION' );

	$r = rest( 'POST', "/mvs/v1/media/$mid/reactions", array( 'reaction_type' => 'like' ) );
	assert_test( 'Admin adds like reaction', $r['ok'] && ( $r['data']['action'] ?? '' ) === 'added', 'action:' . ( $r['data']['action'] ?? 'n/a' ) ) ? $p++ : $f++;

	$r = rest( 'GET', "/mvs/v1/media/$mid/reactions" );
	assert_test( 'Reaction total is 1 after add', ( $r['data']['total'] ?? 0 ) === 1, 'total:' . ( $r['data']['total'] ?? 0 ) ) ? $p++ : $f++;
	assert_test( 'user_reaction shows like for admin', ( $r['data']['user_reaction'] ?? '' ) === 'like', 'got:' . ( $r['data']['user_reaction'] ?? 'null' ) ) ? $p++ : $f++;
	assert_test( 'counts.like is 1', ( $r['data']['counts']['like'] ?? 0 ) == 1, 'like:' . ( $r['data']['counts']['like'] ?? 0 ) ) ? $p++ : $f++;

	// ── ADMIN CHANGES REACTION TO 'LOVE' ──
	section( 'ADMIN CHANGES REACTION' );

	$r = rest( 'POST', "/mvs/v1/media/$mid/reactions", array( 'reaction_type' => 'love' ) );
	assert_test( 'Change reaction to love', $r['ok'] && ( $r['data']['action'] ?? '' ) === 'updated', 'action:' . ( $r['data']['action'] ?? 'n/a' ) ) ? $p++ : $f++;

	$r = rest( 'GET', "/mvs/v1/media/$mid/reactions" );
	assert_test( 'Old like count is 0', ( $r['data']['counts']['like'] ?? 0 ) == 0, 'like:' . ( $r['data']['counts']['like'] ?? 0 ) ) ? $p++ : $f++;
	assert_test( 'New love count is 1', ( $r['data']['counts']['love'] ?? 0 ) == 1, 'love:' . ( $r['data']['counts']['love'] ?? 0 ) ) ? $p++ : $f++;
	assert_test( 'Total still 1 after change', ( $r['data']['total'] ?? 0 ) === 1, 'total:' . ( $r['data']['total'] ?? 0 ) ) ? $p++ : $f++;

	// ── OTHER USER ADDS 'WOW' — BOTH COEXIST ──
	section( 'CROSS-USER REACTIONS' );

	wp_set_current_user( $oid );
	$r = rest( 'POST', "/mvs/v1/media/$mid/reactions", array( 'reaction_type' => 'wow' ) );
	assert_test( 'Other user adds wow', $r['ok'] && ( $r['data']['action'] ?? '' ) === 'added' ) ? $p++ : $f++;

	$r = rest( 'GET', "/mvs/v1/media/$mid/reactions" );
	assert_test( 'Total is 2 (both users)', ( $r['data']['total'] ?? 0 ) === 2, 'total:' . ( $r['data']['total'] ?? 0 ) ) ? $p++ : $f++;
	assert_test( 'user_reaction for other user is wow', ( $r['data']['user_reaction'] ?? '' ) === 'wow', 'got:' . ( $r['data']['user_reaction'] ?? 'null' ) ) ? $p++ : $f++;

	wp_set_current_user( $aid );
	$r = rest( 'GET', "/mvs/v1/media/$mid/reactions" );
	assert_test( 'user_reaction for admin still love', ( $r['data']['user_reaction'] ?? '' ) === 'love', 'got:' . ( $r['data']['user_reaction'] ?? 'null' ) ) ? $p++ : $f++;

	// ── ADMIN REMOVES REACTION ──
	section( 'REMOVE REACTION' );

	$r = rest( 'DELETE', "/mvs/v1/media/$mid/reactions" );
	assert_test( 'Admin removes reaction', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', "/mvs/v1/media/$mid/reactions" );
	assert_test( 'Total decremented to 1', ( $r['data']['total'] ?? 0 ) === 1, 'total:' . ( $r['data']['total'] ?? 0 ) ) ? $p++ : $f++;
	// After removing reaction, user_reaction may be null, empty string, or omitted entirely.
	$admin_reaction = $r['data']['user_reaction'] ?? null;
	assert_test( 'Admin user_reaction is falsy after removal', empty( $admin_reaction ), 'got:' . var_export( $admin_reaction, true ) ) ? $p++ : $f++;

	// ── ANONYMOUS USER TRIES TO REACT ──
	section( 'ANONYMOUS REJECTION' );

	wp_set_current_user( 0 );
	$r = rest( 'POST', "/mvs/v1/media/$mid/reactions", array( 'reaction_type' => 'like' ) );
	assert_test( 'Anonymous POST reaction returns 401', $r['status'] === 401, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'DELETE', "/mvs/v1/media/$mid/reactions" );
	assert_test( 'Anonymous DELETE reaction returns 401', $r['status'] === 401, 'status:' . $r['status'] ) ? $p++ : $f++;

	wp_set_current_user( $aid );

	// ── VERIFY STATS ON MEDIA INDEX ──
	section( 'STATS SYNC' );

	$r = rest( 'GET', "/mvs/v1/media/$mid" );
	$stats_reactions = $r['data']['stats']['reactions'] ?? -1;
	$r2 = rest( 'GET', "/mvs/v1/media/$mid/reactions" );
	$actual_total = $r2['data']['total'] ?? -2;
	assert_test( 'Media stats.reactions matches actual total', (int) $stats_reactions === (int) $actual_total, "stats:$stats_reactions actual:$actual_total" ) ? $p++ : $f++;

	// ── ALL 6 EMOJI TYPES ──
	section( 'ALL EMOJI TYPES' );

	$types = array( 'like', 'love', 'haha', 'wow', 'sad', 'angry' );
	foreach ( $types as $type ) {
		$r = rest( 'POST', "/mvs/v1/media/$mid/reactions", array( 'reaction_type' => $type ) );
		$ok = $r['ok'] && in_array( $r['data']['action'] ?? '', array( 'added', 'updated' ), true );
		assert_test( "Emoji type '$type' accepted", $ok, 'action:' . ( $r['data']['action'] ?? 'n/a' ) ) ? $p++ : $f++;
	}

	// ── CLEANUP ──
	rest( 'DELETE', "/mvs/v1/media/$mid/reactions" );
	wp_set_current_user( $oid );
	rest( 'DELETE', "/mvs/v1/media/$mid/reactions" );
	wp_set_current_user( $aid );

	return array( $p, $f );
}
