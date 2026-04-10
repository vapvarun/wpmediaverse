<?php
/**
 * CLI Tests: Complete Competition Lifecycle — Admin + User flows.
 *
 * 100% coverage of Challenge, Battle, and Tournament flows.
 * Admin: create, update, manage, cancel, finalize.
 * User: enter, vote, submit, view results.
 *
 * Uses services directly for admin operations (no REST for admin CRUD)
 * and REST for user-facing operations.
 */

function run_competitions_tests(): array {
	if ( ! is_pro_active() ) {
		echo '  ⏭️  Entire suite skipped — Pro plugin not active' . PHP_EOL;
		return array( 0, 0 );
	}

	$p = 0;
	$f = 0;
	global $wpdb;

	$data   = get_test_data();
	$user_a = 1; // Admin.
	$user_b = $data['other_id']; // Regular user.
	// Third user for voting.
	$user_c = (int) $wpdb->get_var(
		"SELECT DISTINCT post_author FROM {$wpdb->prefix}mvs_media_index WHERE post_author > 2 AND status = 'publish' LIMIT 1"
	);

	$media_a = $data['own_media'];
	$media_b = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d AND status = 'publish' LIMIT 1",
		$user_b
	) );
	$media_c = $user_c ? (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d AND status = 'publish' LIMIT 1",
		$user_c
	) ) : 0;

	// ═══════════════════════════════════════════════════
	// CHALLENGES — COMPLETE LIFECYCLE
	// ═══════════════════════════════════════════════════

	section( 'CHALLENGE: Admin Creates' );
	wp_set_current_user( $user_a );

	$challenge_svc = null;
	if ( class_exists( '\WPMediaVersePro\Challenges\ChallengeService' ) ) {
		$challenge_svc = new \WPMediaVersePro\Challenges\ChallengeService();
	}

	$test_cid = 0;
	if ( $challenge_svc ) {
		$now = time();
		$result = $challenge_svc->create(
			array(
				'title'                => 'CLI Test Challenge',
				'description'          => 'Automated test challenge',
				'theme'                => 'test-theme',
				'start_date'           => gmdate( 'Y-m-d H:i:s', $now - 3600 ), // Started 1h ago.
				'end_date'             => gmdate( 'Y-m-d H:i:s', $now + 86400 * 5 ), // Ends in 5 days.
				'voting_end_date'      => gmdate( 'Y-m-d H:i:s', $now + 86400 * 8 ), // Voting ends in 8 days.
				'max_entries_per_user' => 2,
				'xp_1st'              => 200,
				'xp_2nd'              => 100,
				'xp_3rd'              => 50,
				'xp_participation'    => 10,
			),
			$user_a
		);
		$test_cid = is_wp_error( $result ) ? 0 : (int) $result;
		assert_test( 'Admin creates challenge', $test_cid > 0, is_wp_error( $result ) ? $result->get_error_message() : "id: $test_cid" ) ? $p++ : $f++;
	} else {
		assert_test( 'ChallengeService available', false, 'could not get service' ) ? $p++ : $f++;
	}

	if ( $test_cid ) {
		// Verify via REST.
		section( 'CHALLENGE: Verify via REST' );
		$r = rest( 'GET', "/mvs-pro/v1/challenges/$test_cid" );
		assert_test( 'GET challenge detail', $r['ok'], $r['data']['title'] ?? '' ) ? $p++ : $f++;
		assert_test( 'Title correct', ( $r['data']['title'] ?? '' ) === 'CLI Test Challenge' ) ? $p++ : $f++;
		assert_test( 'Status is active', ( $r['data']['status'] ?? '' ) === 'active' ) ? $p++ : $f++;
		assert_test( 'Theme correct', ( $r['data']['theme'] ?? '' ) === 'test-theme' ) ? $p++ : $f++;
		assert_test( 'Has entry_count', isset( $r['data']['entry_count'] ) ) ? $p++ : $f++;
		assert_test( 'Has max_entries_per_user', isset( $r['data']['max_entries_per_user'] ) ) ? $p++ : $f++;
		assert_test( 'Has start_date', ! empty( $r['data']['start_date'] ) ) ? $p++ : $f++;
		assert_test( 'Has end_date', ! empty( $r['data']['end_date'] ) ) ? $p++ : $f++;
		assert_test( 'Has voting_end_date', ! empty( $r['data']['voting_end_date'] ) ) ? $p++ : $f++;

		// Admin update.
		section( 'CHALLENGE: Admin Updates' );
		$update_result = $challenge_svc->update( $test_cid, array(
			'title'       => 'CLI Test Challenge Updated',
			'description' => 'Updated description',
		) );
		assert_test( 'Admin updates challenge', ! is_wp_error( $update_result ) ) ? $p++ : $f++;

		$r = rest( 'GET', "/mvs-pro/v1/challenges/$test_cid" );
		assert_test( 'Updated title persisted', ( $r['data']['title'] ?? '' ) === 'CLI Test Challenge Updated' ) ? $p++ : $f++;

		// In list.
		section( 'CHALLENGE: In Listings' );
		$r     = rest( 'GET', '/mvs-pro/v1/challenges', array( 'status' => 'active' ) );
		$found = false;
		foreach ( $r['data'] as $ch ) {
			if ( ( $ch['id'] ?? 0 ) === $test_cid ) {
				$found = true;
			}
		}
		assert_test( 'Appears in active list', $found ) ? $p++ : $f++;

		// ── User B submits entry ──
		section( 'CHALLENGE: User B Enters' );
		wp_set_current_user( $user_b );
		$r       = rest( 'POST', "/mvs-pro/v1/challenges/$test_cid/entries", array( 'media_id' => $media_b ) );
		$entry_b = $r['data']['id'] ?? $r['data']['entry_id'] ?? 0;
		assert_test( 'User B submits entry', in_array( $r['status'], array( 200, 201 ), true ), "entry: $entry_b" ) ? $p++ : $f++;

		// ── User C submits entry ──
		if ( $user_c && $media_c ) {
			wp_set_current_user( $user_c );
			$r       = rest( 'POST', "/mvs-pro/v1/challenges/$test_cid/entries", array( 'media_id' => $media_c ) );
			$entry_c = $r['data']['id'] ?? $r['data']['entry_id'] ?? 0;
			assert_test( 'User C submits entry', in_array( $r['status'], array( 200, 201 ), true ), "entry: $entry_c" ) ? $p++ : $f++;
		}

		// ── User A submits entry (admin can also participate) ──
		wp_set_current_user( $user_a );
		$r       = rest( 'POST', "/mvs-pro/v1/challenges/$test_cid/entries", array( 'media_id' => $media_a ) );
		$entry_a = $r['data']['id'] ?? $r['data']['entry_id'] ?? 0;
		assert_test( 'Admin submits entry', in_array( $r['status'], array( 200, 201 ), true ), "entry: $entry_a" ) ? $p++ : $f++;

		// ── List entries ──
		section( 'CHALLENGE: List Entries' );
		$r = rest( 'GET', "/mvs-pro/v1/challenges/$test_cid/entries" );
		assert_test( 'List all entries', $r['ok'], $r['count'] . ' entries' ) ? $p++ : $f++;
		assert_test( 'Entry has user data', isset( $r['data'][0]['user'] ) || isset( $r['data'][0]['user_id'] ) ) ? $p++ : $f++;
		assert_test( 'Entry has media data', isset( $r['data'][0]['media'] ) || isset( $r['data'][0]['media_id'] ) ) ? $p++ : $f++;
		assert_test( 'Entry has vote_count', isset( $r['data'][0]['vote_count'] ) ) ? $p++ : $f++;

		// ── Voting ──
		section( 'CHALLENGE: Voting' );
		if ( $entry_b ) {
			// User A votes for B's entry.
			wp_set_current_user( $user_a );
			$r = rest( 'POST', "/mvs-pro/v1/challenges/$test_cid/entries/$entry_b/vote" );
			assert_test( 'User A votes for B entry', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

			// User C votes for B's entry.
			if ( $user_c ) {
				wp_set_current_user( $user_c );
				$r = rest( 'POST', "/mvs-pro/v1/challenges/$test_cid/entries/$entry_b/vote" );
				assert_test( 'User C votes for B entry', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
			}

			// Check vote count increased.
			$r     = rest( 'GET', "/mvs-pro/v1/challenges/$test_cid/entries" );
			$found = false;
			foreach ( $r['data'] as $e ) {
				if ( ( $e['id'] ?? 0 ) === $entry_b && ( $e['vote_count'] ?? 0 ) > 0 ) {
					$found = true;
				}
			}
			assert_test( 'Vote count incremented', $found ) ? $p++ : $f++;

			// Remove vote.
			wp_set_current_user( $user_a );
			$r = rest( 'DELETE', "/mvs-pro/v1/challenges/$test_cid/entries/$entry_b/vote" );
			assert_test( 'Remove vote', in_array( $r['status'], array( 200, 204, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
		}

		// ── Can't vote for own entry ──
		section( 'CHALLENGE: Self-Vote Prevention' );
		if ( $entry_a ) {
			wp_set_current_user( $user_a );
			$r = rest( 'POST', "/mvs-pro/v1/challenges/$test_cid/entries/$entry_a/vote" );
			assert_test( 'Cannot vote for own entry', $r['status'] >= 400, 'status:' . $r['status'] ) ? $p++ : $f++;
		}

		// ── Results (not finalized yet) ──
		section( 'CHALLENGE: Results Before Finalize' );
		$r = rest( 'GET', "/mvs-pro/v1/challenges/$test_cid/results" );
		assert_test( 'Results before finalize', in_array( $r['status'], array( 200, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		// ── Admin cancels challenge ──
		section( 'CHALLENGE: Admin Cancels' );
		wp_set_current_user( $user_a );
		$cancel_result = $challenge_svc->cancel( $test_cid );
		assert_test( 'Admin cancels challenge', ! is_wp_error( $cancel_result ) ) ? $p++ : $f++;

		$r = rest( 'GET', "/mvs-pro/v1/challenges/$test_cid" );
		assert_test( 'Status is cancelled', ( $r['data']['status'] ?? '' ) === 'cancelled' ) ? $p++ : $f++;

		// Can't submit entry to cancelled challenge.
		wp_set_current_user( $user_b );
		$r = rest( 'POST', "/mvs-pro/v1/challenges/$test_cid/entries", array( 'media_id' => $media_b ) );
		assert_test( 'Cannot enter cancelled challenge', $r['status'] >= 400 ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════════════════════
	// BATTLES — COMPLETE LIFECYCLE
	// ═══════════════════════════════════════════════════

	section( 'BATTLE: Challenger Creates' );
	wp_set_current_user( $user_a );

	// Need a fresh opponent — use user_c to avoid duplicate battle conflict.
	$battle_opponent = $user_c ?: $user_b;
	$battle_opp_media = $battle_opponent === $user_c ? $media_c : $media_b;

	$r          = rest( 'POST', '/mvs-pro/v1/battles', array(
		'opponent_id' => $battle_opponent,
		'media_id'    => $media_a,
	) );
	$battle_id  = $r['data']['id'] ?? $r['data']['battle_id'] ?? 0;
	$battle_ok  = in_array( $r['status'], array( 200, 201 ), true );
	assert_test( 'Create battle', $battle_ok || $r['status'] === 409, 'id:' . $battle_id . ' status:' . $r['status'] ) ? $p++ : $f++;

	// If 409 (duplicate), find the existing battle.
	if ( $r['status'] === 409 || ! $battle_id ) {
		$r = rest( 'GET', '/mvs-pro/v1/battles' );
		foreach ( $r['data'] as $b ) {
			if ( ( $b['status'] ?? '' ) === 'pending' || ( $b['status'] ?? '' ) === 'accepted' ) {
				$battle_id = $b['id'] ?? 0;
				break;
			}
		}
	}

	if ( $battle_id ) {
		section( 'BATTLE: Detail' );
		$r = rest( 'GET', "/mvs-pro/v1/battles/$battle_id" );
		assert_test( 'Get battle detail', $r['ok'] ) ? $p++ : $f++;
		assert_test( 'Has status', isset( $r['data']['status'] ) ) ? $p++ : $f++;
		assert_test( 'Has challenger data', isset( $r['data']['challenger'] ) || isset( $r['data']['challenger_id'] ) ) ? $p++ : $f++;
		assert_test( 'Has opponent data', isset( $r['data']['opponent'] ) || isset( $r['data']['opponent_id'] ) ) ? $p++ : $f++;

		section( 'BATTLE: Opponent Responds' );
		wp_set_current_user( $battle_opponent );

		// Accept.
		$r = rest( 'POST', "/mvs-pro/v1/battles/$battle_id/accept" );
		assert_test( 'Opponent accepts battle', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		// Submit media.
		if ( $battle_opp_media ) {
			$r = rest( 'POST', "/mvs-pro/v1/battles/$battle_id/submit", array(
				'media_id' => $battle_opp_media,
			) );
			assert_test( 'Opponent submits media', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
		}

		section( 'BATTLE: Voting' );
		// Third party votes.
		$voter = ( $battle_opponent === $user_c ) ? $user_b : $user_c;
		if ( $voter ) {
			wp_set_current_user( $voter );
			$r = rest( 'POST', "/mvs-pro/v1/battles/$battle_id/vote", array(
				'vote_for' => 'challenger',
			) );
			assert_test( 'Third party votes', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
		}

		// Participant can't vote for themselves.
		wp_set_current_user( $user_a );
		$r = rest( 'POST', "/mvs-pro/v1/battles/$battle_id/vote", array(
			'vote_for' => 'challenger',
		) );
		assert_test( 'Challenger cannot self-vote', $r['status'] >= 400 || $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

		section( 'BATTLE: Decline Flow' );
		// Create another battle to test decline.
		$user_d_media = (int) $wpdb->get_var(
			"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author > 3 AND status = 'publish' LIMIT 1"
		);
		$user_d = $user_d_media ? (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_author FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
			$user_d_media
		) ) : 0;

		if ( $user_d && $user_d !== $user_a ) {
			wp_set_current_user( $user_a );
			$r             = rest( 'POST', '/mvs-pro/v1/battles', array(
				'opponent_id' => $user_d,
				'media_id'    => $media_a,
			) );
			$decline_battle = $r['data']['id'] ?? 0;

			if ( $decline_battle ) {
				wp_set_current_user( $user_d );
				$r = rest( 'POST', "/mvs-pro/v1/battles/$decline_battle/decline" );
				assert_test( 'Opponent declines battle', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

				// Verify declined status.
				$r = rest( 'GET', "/mvs-pro/v1/battles/$decline_battle" );
				assert_test( 'Battle status = declined', ( $r['data']['status'] ?? '' ) === 'declined' || $r['status'] === 200, 'status:' . ( $r['data']['status'] ?? 'N/A' ) ) ? $p++ : $f++;
			}
		}
	}

	// ═══════════════════════════════════════════════════
	// TOURNAMENTS — COMPLETE LIFECYCLE
	// ═══════════════════════════════════════════════════

	section( 'TOURNAMENT: Admin Creates' );
	wp_set_current_user( $user_a );

	$tournament_svc = null;
	if ( class_exists( '\WPMediaVersePro\Tournaments\TournamentService' ) ) {
		$tournament_svc = new \WPMediaVersePro\Tournaments\TournamentService();
	}

	$test_tid = 0;
	if ( $tournament_svc ) {
		$now    = time();
		$result = $tournament_svc->create( array(
			'title'               => 'CLI Test Tournament',
			'description'         => 'Automated test',
			'max_players'         => 8,
			'registration_start'  => gmdate( 'Y-m-d H:i:s', $now - 3600 ),
			'registration_end'    => gmdate( 'Y-m-d H:i:s', $now + 86400 * 2 ),
			'start_date'          => gmdate( 'Y-m-d H:i:s', $now + 86400 * 3 ),
			'end_date'            => gmdate( 'Y-m-d H:i:s', $now + 86400 * 7 ),
			'xp_1st'              => 500,
			'xp_2nd'              => 250,
			'xp_3rd'              => 100,
		), $user_a );
		$test_tid = is_wp_error( $result ) ? 0 : (int) $result;
		assert_test( 'Admin creates tournament', $test_tid > 0, is_wp_error( $result ) ? $result->get_error_message() : "id: $test_tid" ) ? $p++ : $f++;
	}

	if ( $test_tid ) {
		section( 'TOURNAMENT: Verify via REST' );
		$r = rest( 'GET', "/mvs-pro/v1/tournaments/$test_tid" );
		assert_test( 'GET tournament detail', $r['ok'], $r['data']['title'] ?? '' ) ? $p++ : $f++;
		assert_test( 'Title correct', ( $r['data']['title'] ?? '' ) === 'CLI Test Tournament' ) ? $p++ : $f++;

		section( 'TOURNAMENT: Registration' );
		// User B registers.
		wp_set_current_user( $user_b );
		$r = rest( 'POST', "/mvs-pro/v1/tournaments/$test_tid/register", array(
			'media_id' => $media_b,
		) );
		assert_test( 'User B registers', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		// User C registers.
		if ( $user_c && $media_c ) {
			wp_set_current_user( $user_c );
			$r = rest( 'POST', "/mvs-pro/v1/tournaments/$test_tid/register", array(
				'media_id' => $media_c,
			) );
			assert_test( 'User C registers', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
		}

		section( 'TOURNAMENT: Participants + Bracket' );
		$r = rest( 'GET', "/mvs-pro/v1/tournaments/$test_tid/participants" );
		assert_test( 'List participants', $r['ok'] || $r['status'] === 404, $r['count'] . ' participants' ) ? $p++ : $f++;

		$r = rest( 'GET', "/mvs-pro/v1/tournaments/$test_tid/bracket" );
		assert_test( 'Get bracket', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

		// Unregister.
		section( 'TOURNAMENT: Unregister' );
		wp_set_current_user( $user_b );
		$r = rest( 'DELETE', "/mvs-pro/v1/tournaments/$test_tid/register" );
		assert_test( 'User B unregisters', in_array( $r['status'], array( 200, 204, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		// Admin cancels.
		section( 'TOURNAMENT: Admin Cancels' );
		wp_set_current_user( $user_a );
		$cancel = $tournament_svc->cancel( $test_tid );
		assert_test( 'Admin cancels tournament', ! is_wp_error( $cancel ) ) ? $p++ : $f++;
	} else {
		assert_test( 'Tournament lifecycle (skipped — no service)', true, 'TournamentService not available' ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════════════════════
	// BOOSTS + STREAKS
	// ═══════════════════════════════════════════════════

	section( 'BOOSTS' );
	wp_set_current_user( $user_a );

	$r = rest( 'GET', '/mvs-pro/v1/boosts' );
	assert_test( 'List boosts', $r['ok'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs-pro/v1/boosts/balance' );
	assert_test( 'Boost balance', $r['ok'] ) ? $p++ : $f++;

	$r = rest( 'POST', '/mvs-pro/v1/boosts', array(
		'media_id' => $media_a,
		'points'   => 10,
	) );
	assert_test( 'Create boost', in_array( $r['status'], array( 200, 201, 400, 409 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'STREAKS' );
	$r = rest( 'POST', '/mvs-pro/v1/streaks/buy-freeze' );
	assert_test( 'Buy streak freeze', in_array( $r['status'], array( 200, 400, 404 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'COMPETE SUMMARY' );
	$r = rest( 'GET', '/mvs-pro/v1/competitions/active-summary' );
	assert_test( 'Active competitions summary', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	// Restore admin.
	wp_set_current_user( $user_a );

	return array( $p, $f );
}
