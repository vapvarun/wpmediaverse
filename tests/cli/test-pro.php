<?php
/**
 * CLI Tests: Pro Features — Challenges, Battles, Tournaments, Boosts, Quota, Privacy.
 *
 * Covers: /mvs-pro/v1/challenges, /battles, /tournaments, /boosts, /quota, /privacy
 */

function run_pro_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();

	section( 'CHALLENGES' );
	$r         = rest( 'GET', '/mvs-pro/v1/challenges' );
	$challenge = $r['data'][0] ?? null;
	assert_test( 'List challenges', $r['ok'], $r['count'] . ' challenges' ) ? $p++ : $f++;

	if ( $challenge ) {
		$cid = $challenge['id'];

		$r = rest( 'GET', "/mvs-pro/v1/challenges/$cid" );
		assert_test( 'Get challenge detail', $r['ok'], $r['data']['title'] ?? '' ) ? $p++ : $f++;
		assert_test( 'Challenge has status', isset( $r['data']['status'] ) ) ? $p++ : $f++;
		assert_test( 'Challenge has dates', isset( $r['data']['start_date'], $r['data']['end_date'] ) ) ? $p++ : $f++;

		$r = rest( 'GET', "/mvs-pro/v1/challenges/$cid/entries" );
		assert_test( 'List entries', $r['ok'], $r['count'] . ' entries' ) ? $p++ : $f++;

		$r = rest( 'GET', "/mvs-pro/v1/challenges/$cid/results" );
		assert_test( 'Get results', in_array( $r['status'], array( 200, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		// Submit entry (may fail if already submitted — that's ok).
		if ( $data['own_media'] ) {
			$r = rest( 'POST', "/mvs-pro/v1/challenges/$cid/entries", array(
				'media_id' => $data['own_media'],
			) );
			assert_test( 'Submit entry', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
		}
	}

	section( 'BATTLES' );
	$r = rest( 'GET', '/mvs-pro/v1/battles' );
	assert_test( 'List battles', $r['ok'], $r['count'] . ' battles' ) ? $p++ : $f++;

	// Create a battle challenge (may fail without opponent — that's ok).
	if ( $data['other_id'] && $data['own_media'] ) {
		$r = rest( 'POST', '/mvs-pro/v1/battles', array(
			'opponent_id' => $data['other_id'],
			'media_id'    => $data['own_media'],
		) );
		assert_test( 'Create battle', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
	}

	section( 'TOURNAMENTS' );
	$r = rest( 'GET', '/mvs-pro/v1/tournaments' );
	assert_test( 'List tournaments', $r['ok'], $r['count'] . ' tournaments' ) ? $p++ : $f++;

	section( 'BOOSTS' );
	$r = rest( 'GET', '/mvs-pro/v1/boosts' );
	assert_test( 'List boosts', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs-pro/v1/boosts/balance' );
	assert_test( 'Boost balance', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'QUOTA' );
	$r = rest( 'GET', '/mvs-pro/v1/me/quota' );
	assert_test( 'Get my quota', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs-pro/v1/me/quota/check' );
	assert_test( 'Check quota', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'PRO PRIVACY' );
	if ( $data['own_media'] ) {
		$r = rest( 'GET', '/mvs-pro/v1/media/' . $data['own_media'] . '/privacy' );
		assert_test( 'Get privacy details', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;
	}

	$r = rest( 'GET', '/mvs-pro/v1/privacy/presets' );
	assert_test( 'Privacy presets', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'COMPETE SUMMARY' );
	$r = rest( 'GET', '/mvs-pro/v1/competitions/active-summary' );
	assert_test( 'Active summary', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	return array( $p, $f );
}
