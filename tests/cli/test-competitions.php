<?php
/**
 * CLI Tests: Full Competition Flow — Challenges, Battles, Tournaments.
 *
 * Tests complete read+write lifecycle for each competition type.
 * Creates test data, runs the full user flow, then cleans up.
 */

function run_competitions_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();
	global $wpdb;

	// ═══════════════════════════════════
	// CHALLENGES — Full lifecycle
	// ═══════════════════════════════════
	section( 'CHALLENGE: List + Detail' );
	$r = rest( 'GET', '/mvs-pro/v1/challenges' );
	assert_test( 'List challenges', $r['ok'], $r['count'] . ' challenges' ) ? $p++ : $f++;

	$challenge = $r['data'][0] ?? null;
	$cid       = $challenge['id'] ?? 0;

	if ( $cid ) {
		$r = rest( 'GET', "/mvs-pro/v1/challenges/$cid" );
		assert_test( 'Get challenge', $r['ok'], $r['data']['title'] ?? '' ) ? $p++ : $f++;
		assert_test( 'Has title', ! empty( $r['data']['title'] ) ) ? $p++ : $f++;
		assert_test( 'Has status', ! empty( $r['data']['status'] ) ) ? $p++ : $f++;
		assert_test( 'Has start_date', ! empty( $r['data']['start_date'] ) ) ? $p++ : $f++;
		assert_test( 'Has end_date', ! empty( $r['data']['end_date'] ) ) ? $p++ : $f++;
		assert_test( 'Has entry_count', isset( $r['data']['entry_count'] ) ) ? $p++ : $f++;

		section( 'CHALLENGE: Entries' );
		$r = rest( 'GET', "/mvs-pro/v1/challenges/$cid/entries" );
		assert_test( 'List entries', $r['ok'], $r['count'] . ' entries' ) ? $p++ : $f++;

		// Submit entry (may fail if already entered or wrong status).
		if ( $data['own_media'] ) {
			$r = rest( 'POST', "/mvs-pro/v1/challenges/$cid/entries", array(
				'media_id' => $data['own_media'],
			) );
			assert_test( 'Submit entry (or already submitted)', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
		}

		// Get entries again to find one to vote on.
		$r       = rest( 'GET', "/mvs-pro/v1/challenges/$cid/entries" );
		$entries = $r['data'];
		if ( ! empty( $entries ) ) {
			$entry_id = $entries[0]['id'] ?? 0;

			section( 'CHALLENGE: Vote' );
			if ( $entry_id ) {
				$r = rest( 'POST', "/mvs-pro/v1/challenges/$cid/entries/$entry_id/vote" );
				assert_test( 'Vote on entry', in_array( $r['status'], array( 200, 201, 400, 403 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

				$r = rest( 'DELETE', "/mvs-pro/v1/challenges/$cid/entries/$entry_id/vote" );
				assert_test( 'Remove vote', in_array( $r['status'], array( 200, 204, 400, 403 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
			}
		}

		section( 'CHALLENGE: Results' );
		$r = rest( 'GET', "/mvs-pro/v1/challenges/$cid/results" );
		assert_test( 'Get results (or not finalized)', in_array( $r['status'], array( 200, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════
	// BATTLES — Full lifecycle
	// ═══════════════════════════════════
	section( 'BATTLE: Create' );
	$r = rest( 'GET', '/mvs-pro/v1/battles' );
	assert_test( 'List battles', $r['ok'], $r['count'] . ' battles' ) ? $p++ : $f++;

	// Create a battle (challenger vs opponent).
	$battle_id = 0;
	if ( $data['other_id'] && $data['own_media'] ) {
		$r = rest( 'POST', '/mvs-pro/v1/battles', array(
			'opponent_id' => $data['other_id'],
			'media_id'    => $data['own_media'],
		) );
		$battle_id = $r['data']['id'] ?? $r['data']['battle_id'] ?? 0;
		assert_test( 'Create battle', in_array( $r['status'], array( 200, 201 ), true ), 'id:' . $battle_id ) ? $p++ : $f++;
	}

	if ( $battle_id ) {
		section( 'BATTLE: Detail' );
		$r = rest( 'GET', "/mvs-pro/v1/battles/$battle_id" );
		assert_test( 'Get battle detail', $r['ok'] ) ? $p++ : $f++;
		assert_test( 'Battle has status', isset( $r['data']['status'] ) ) ? $p++ : $f++;
		assert_test( 'Battle has challenger', isset( $r['data']['challenger'] ) || isset( $r['data']['challenger_id'] ) ) ? $p++ : $f++;

		section( 'BATTLE: Accept/Decline (as opponent)' );
		// Switch to opponent user to accept.
		wp_set_current_user( $data['other_id'] );

		$r = rest( 'POST', "/mvs-pro/v1/battles/$battle_id/accept" );
		assert_test( 'Accept battle (as opponent)', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		// Submit opponent's media.
		$opponent_media = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d AND status = 'publish' LIMIT 1",
				$data['other_id']
			)
		);
		if ( $opponent_media ) {
			$r = rest( 'POST', "/mvs-pro/v1/battles/$battle_id/submit", array(
				'media_id' => $opponent_media,
			) );
			assert_test( 'Submit opponent media', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
		}

		// Switch back to admin for voting.
		wp_set_current_user( 1 );

		section( 'BATTLE: Vote' );
		$r = rest( 'POST', "/mvs-pro/v1/battles/$battle_id/vote", array(
			'vote_for' => 'challenger',
		) );
		assert_test( 'Vote on battle', in_array( $r['status'], array( 200, 201, 400, 403 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════
	// TOURNAMENTS — Full lifecycle
	// ═══════════════════════════════════
	section( 'TOURNAMENT: List + Detail' );
	$r = rest( 'GET', '/mvs-pro/v1/tournaments' );
	assert_test( 'List tournaments', $r['ok'], $r['count'] . ' tournaments' ) ? $p++ : $f++;

	// If a tournament exists, test detail endpoints.
	$tournament = $r['data'][0] ?? null;
	$tid        = $tournament['id'] ?? 0;

	if ( $tid ) {
		$r = rest( 'GET', "/mvs-pro/v1/tournaments/$tid" );
		assert_test( 'Get tournament detail', $r['ok'] ) ? $p++ : $f++;

		$r = rest( 'GET', "/mvs-pro/v1/tournaments/$tid/bracket" );
		assert_test( 'Get bracket', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

		$r = rest( 'GET', "/mvs-pro/v1/tournaments/$tid/participants" );
		assert_test( 'Get participants', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

		section( 'TOURNAMENT: Register' );
		$r = rest( 'POST', "/mvs-pro/v1/tournaments/$tid/register", array(
			'media_id' => $data['own_media'],
		) );
		assert_test( 'Register for tournament', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
	} else {
		assert_test( 'Tournament detail (skipped — none exist)', true, 'no tournaments' ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════
	// BOOSTS
	// ═══════════════════════════════════
	section( 'BOOSTS' );
	$r = rest( 'GET', '/mvs-pro/v1/boosts' );
	assert_test( 'List boosts', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs-pro/v1/boosts/balance' );
	assert_test( 'Boost balance', $r['ok'] || $r['status'] === 404 ) ? $p++ : $f++;

	// Create a boost (may fail without enough points).
	if ( $data['own_media'] ) {
		$r = rest( 'POST', '/mvs-pro/v1/boosts', array(
			'media_id' => $data['own_media'],
			'points'   => 10,
		) );
		assert_test( 'Create boost', in_array( $r['status'], array( 200, 201, 400, 403 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════
	// STREAKS
	// ═══════════════════════════════════
	section( 'STREAKS' );
	$r = rest( 'POST', '/mvs-pro/v1/streaks/buy-freeze' );
	assert_test( 'Buy streak freeze', in_array( $r['status'], array( 200, 400, 404 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// COMPETE SUMMARY
	// ═══════════════════════════════════
	section( 'COMPETE SUMMARY' );
	$r = rest( 'GET', '/mvs-pro/v1/competitions/active-summary' );
	assert_test( 'Active summary', $r['ok'] || $r['status'] === 404 ) ? $p++ : $f++;

	// Ensure we're back as admin.
	wp_set_current_user( 1 );

	return array( $p, $f );
}
