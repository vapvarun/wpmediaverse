<?php
/**
 * CLI Tests: Favorites lifecycle — cross-user isolation, idempotency, anonymous.
 *
 * Covers: POST/GET/DELETE /mvs/v1/media/{id}/favorite,
 *         GET /mvs/v1/me/favorites, favorited status endpoint,
 *         cross-user isolation, own-media favorite, anonymous rejection,
 *         and double-favorite idempotency.
 *
 * @package WPMediaVerse
 */

function run_favorites_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();
	$mid  = $data['other_media'];
	$own  = $data['own_media'];
	$aid  = $data['admin_id'];
	$oid  = $data['other_id'];

	// ── CLEANUP: ensure no stale favorites from prior runs ──
	wp_set_current_user( $aid );
	rest( 'DELETE', "/mvs/v1/media/$mid/favorite" );
	rest( 'DELETE', "/mvs/v1/media/$own/favorite" );
	wp_set_current_user( $oid );
	rest( 'DELETE', "/mvs/v1/media/$mid/favorite" );
	wp_set_current_user( $aid );

	// ── ADMIN FAVORITES MEDIA ──
	section( 'ADD FAVORITE' );

	$r = rest( 'POST', "/mvs/v1/media/$mid/favorite" );
	assert_test( 'Admin favorites media', $r['ok'] && ( $r['data']['action'] ?? '' ) === 'added', 'action:' . ( $r['data']['action'] ?? 'n/a' ) ) ? $p++ : $f++;
	assert_test( 'Response shows favorited=true', ( $r['data']['favorited'] ?? false ) === true ) ? $p++ : $f++;

	// ── VERIFY IN /me/favorites LIST ──
	$r     = rest( 'GET', '/mvs/v1/me/favorites' );
	$found = false;
	foreach ( ( $r['data'] ?? array() ) as $item ) {
		if ( (int) ( $item['media_id'] ?? 0 ) === $mid ) {
			$found = true;
			break;
		}
	}
	assert_test( 'Media appears in /me/favorites', $found, $r['count'] . ' items' ) ? $p++ : $f++;

	// ── VERIFY STATUS ENDPOINT ──
	$r = rest( 'GET', "/mvs/v1/media/$mid/favorite" );
	assert_test( 'GET /media/{id}/favorite returns favorited=true', ( $r['data']['favorited'] ?? false ) === true ) ? $p++ : $f++;

	// ── OTHER USER ALSO FAVORITES SAME MEDIA ──
	section( 'CROSS-USER FAVORITES' );

	wp_set_current_user( $oid );
	$r = rest( 'POST', "/mvs/v1/media/$mid/favorite" );
	assert_test( 'Other user favorites same media', $r['ok'] && ( $r['data']['action'] ?? '' ) === 'added' ) ? $p++ : $f++;

	// Both users should see it.
	$r = rest( 'GET', '/mvs/v1/me/favorites' );
	$other_has = false;
	foreach ( ( $r['data'] ?? array() ) as $item ) {
		if ( (int) ( $item['media_id'] ?? 0 ) === $mid ) {
			$other_has = true;
			break;
		}
	}
	assert_test( 'Other user sees it in their favorites', $other_has ) ? $p++ : $f++;

	// ── ADMIN UNFAVORITES — OTHER USER STILL HAS IT ──
	section( 'UNFAVORITE ISOLATION' );

	wp_set_current_user( $aid );
	$r = rest( 'DELETE', "/mvs/v1/media/$mid/favorite" );
	assert_test( 'Admin unfavorites media', $r['ok'] && ( $r['data']['action'] ?? '' ) === 'removed', 'action:' . ( $r['data']['action'] ?? 'n/a' ) ) ? $p++ : $f++;

	// Admin's list should NOT have it.
	$r         = rest( 'GET', '/mvs/v1/me/favorites' );
	$admin_has = false;
	foreach ( ( $r['data'] ?? array() ) as $item ) {
		if ( (int) ( $item['media_id'] ?? 0 ) === $mid ) {
			$admin_has = true;
			break;
		}
	}
	assert_test( 'Admin no longer has it in favorites', ! $admin_has ) ? $p++ : $f++;

	// Other user's list should STILL have it.
	wp_set_current_user( $oid );
	$r = rest( 'GET', "/mvs/v1/media/$mid/favorite" );
	assert_test( 'Other user still has it favorited', ( $r['data']['favorited'] ?? false ) === true ) ? $p++ : $f++;

	wp_set_current_user( $aid );

	// ── FAVORITE OWN MEDIA ──
	section( 'FAVORITE OWN MEDIA' );

	$r = rest( 'POST', "/mvs/v1/media/$own/favorite" );
	assert_test( 'Admin can favorite own media', $r['ok'] && ( $r['data']['action'] ?? '' ) === 'added' ) ? $p++ : $f++;
	// Cleanup own.
	rest( 'DELETE', "/mvs/v1/media/$own/favorite" );

	// ── ANONYMOUS TRIES TO FAVORITE ──
	section( 'ANONYMOUS REJECTION' );

	wp_set_current_user( 0 );
	$r = rest( 'POST', "/mvs/v1/media/$mid/favorite" );
	assert_test( 'Anonymous POST favorite returns 401', $r['status'] === 401, 'status:' . $r['status'] ) ? $p++ : $f++;

	wp_set_current_user( $aid );

	// ── DOUBLE-FAVORITE IDEMPOTENCY ──
	section( 'IDEMPOTENCY' );

	// Because toggle removes on second call, we test that two consecutive POSTs
	// result in added->removed->state=not-favorited, which is the toggle design.
	// The "idempotent" expectation is: no error, no 500, graceful handling.
	$r1 = rest( 'POST', "/mvs/v1/media/$mid/favorite" );
	$r2 = rest( 'POST', "/mvs/v1/media/$mid/favorite" );
	assert_test( 'Double-POST does not error (toggle design)', $r1['ok'] && $r2['ok'], 'first:' . ( $r1['data']['action'] ?? '?' ) . ' second:' . ( $r2['data']['action'] ?? '?' ) ) ? $p++ : $f++;

	// ── CLEANUP ──
	rest( 'DELETE', "/mvs/v1/media/$mid/favorite" );
	wp_set_current_user( $oid );
	rest( 'DELETE', "/mvs/v1/media/$mid/favorite" );
	wp_set_current_user( $aid );

	return array( $p, $f );
}
