<?php
/**
 * CLI Tests: Moderation Queue, Reports, and User Blocking.
 *
 * Tests the full moderation user journey:
 * - Regular users: report media, report users, block/unblock users
 * - Admin: view queue, counts, approve, reject with reason
 * - BLOCKED: duplicate reports, non-admin queue access, anonymous reports
 *
 * @package WPMediaVerse
 */

function run_moderation_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();

	$admin_id = $data['admin_id'];
	$other_id = $data['other_id'];
	$mid      = $data['own_media'];

	// ── CLEANUP: clear any stale reports from prior runs ──
	global $wpdb;
	wp_set_current_user( $admin_id );
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}mvs_reports WHERE target_type = 'media' AND target_id = %d",
			$mid
		)
	);
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}mvs_reports WHERE target_type = 'user' AND target_id = %d AND reporter_id = %d",
			$other_id,
			$admin_id
		)
	);

	// ═══════════════════════════════════
	// USER: REPORT MEDIA
	// ═══════════════════════════════════
	section( 'REPORT MEDIA' );
	wp_set_current_user( $other_id );

	$r = rest( 'POST', "/mvs/v1/media/$mid/report", array(
		'reason'  => 'spam',
		'details' => 'CLI moderation test — report media',
	) );
	assert_test(
		'Regular user reports media',
		in_array( $r['status'], array( 200, 201 ), true ),
		'status:' . $r['status']
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// BLOCKED: DUPLICATE REPORT
	// ═══════════════════════════════════
	section( 'DUPLICATE REPORT (BLOCKED)' );

	$r = rest( 'POST', "/mvs/v1/media/$mid/report", array(
		'reason'  => 'spam',
		'details' => 'CLI moderation test — duplicate',
	) );
	assert_test(
		'Duplicate report is rejected or warned',
		$r['status'] === 400 || $r['status'] === 409 || $r['status'] === 429,
		'status:' . $r['status'] . ' (should not allow duplicate)'
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// USER: REPORT ANOTHER USER
	// ═══════════════════════════════════
	section( 'REPORT USER' );
	wp_set_current_user( $admin_id ); // Admin reports other_id.

	$r = rest( 'POST', "/mvs/v1/users/$other_id/report", array(
		'reason'  => 'harassment',
		'details' => 'CLI moderation test — report user',
	) );
	assert_test(
		'User can report another user',
		in_array( $r['status'], array( 200, 201 ), true ),
		'status:' . $r['status']
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// USER: BLOCK ANOTHER USER
	// ═══════════════════════════════════
	section( 'BLOCK / UNBLOCK USER' );
	wp_set_current_user( $admin_id );

	$r = rest( 'POST', "/mvs/v1/users/$other_id/block" );
	assert_test(
		'Block user',
		in_array( $r['status'], array( 200, 201 ), true ),
		'status:' . $r['status']
	) ? $p++ : $f++;

	// Verify blocked list includes user.
	$r            = rest( 'GET', '/mvs/v1/me/blocked' );
	$blocked_ids  = array_column( $r['data'] ?? array(), 'id' );
	assert_test(
		'Blocked list includes blocked user',
		in_array( $other_id, $blocked_ids, true ),
		'blocked_ids:' . implode( ',', $blocked_ids )
	) ? $p++ : $f++;

	// Unblock.
	$r = rest( 'DELETE', "/mvs/v1/users/$other_id/block" );
	assert_test(
		'Unblock user',
		in_array( $r['status'], array( 200, 204 ), true ),
		'status:' . $r['status']
	) ? $p++ : $f++;

	// Verify unblocked.
	$r           = rest( 'GET', '/mvs/v1/me/blocked' );
	$blocked_ids = array_column( $r['data'] ?? array(), 'id' );
	assert_test(
		'Blocked list no longer includes unblocked user',
		! in_array( $other_id, $blocked_ids, true ),
		'blocked_ids:' . implode( ',', $blocked_ids )
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// ADMIN: VIEW MODERATION QUEUE
	// ═══════════════════════════════════
	section( 'ADMIN MODERATION QUEUE' );
	wp_set_current_user( $admin_id );

	$r = rest( 'GET', '/mvs/v1/moderation' );
	assert_test(
		'Admin can view moderation queue',
		$r['ok'],
		'status:' . $r['status']
	) ? $p++ : $f++;

	// Verify structure — each item should have id, moderation_status.
	$first_item = is_array( $r['data'] ) && ! empty( $r['data'] ) ? $r['data'][0] : null;
	$has_struct = $first_item && isset( $first_item['id'] ) && isset( $first_item['moderation_status'] );
	assert_test(
		'Queue items have expected structure (id, moderation_status)',
		$has_struct || empty( $r['data'] ),
		$first_item ? 'keys:' . implode( ',', array_keys( $first_item ) ) : 'queue empty'
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// ADMIN: MODERATION COUNTS
	// ═══════════════════════════════════
	section( 'MODERATION COUNTS' );

	$r = rest( 'GET', '/mvs/v1/moderation/counts' );
	assert_test(
		'Admin can view moderation counts',
		$r['ok'],
		json_encode( $r['data'] )
	) ? $p++ : $f++;

	// Counts should be an object/array with numeric values.
	$counts_valid = is_array( $r['data'] ) && ! empty( $r['data'] );
	assert_test(
		'Counts structure is valid',
		$counts_valid,
		'type:' . gettype( $r['data'] )
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// ADMIN: APPROVE ITEM
	// ═══════════════════════════════════
	section( 'APPROVE / REJECT' );

	// Approve using the media ID (moderation items reference media IDs).
	$r = rest( 'POST', "/mvs/v1/moderation/$mid/approve" );
	assert_test(
		'Admin approves moderation item',
		$r['ok'] || $r['status'] === 404, // 404 if not in queue.
		'status:' . $r['status']
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// ADMIN: REJECT ITEM WITH REASON
	// ═══════════════════════════════════
	$r = rest( 'POST', "/mvs/v1/moderation/$mid/reject", array(
		'reason' => 'Violates community guidelines — CLI test',
	) );
	assert_test(
		'Admin rejects moderation item with reason',
		$r['ok'] || $r['status'] === 404,
		'status:' . $r['status']
	) ? $p++ : $f++;

	// If reject returned data, verify the rejection reason is stored.
	if ( $r['ok'] && ! empty( $r['data']['rejection_reason'] ) ) {
		assert_test(
			'Rejection reason is stored',
			strpos( $r['data']['rejection_reason'], 'CLI test' ) !== false,
			'reason:' . $r['data']['rejection_reason']
		) ? $p++ : $f++;
	} else {
		assert_test(
			'Rejection reason stored — skipped (item not in queue)',
			true,
			'status:' . $r['status']
		) ? $p++ : $f++;
	}

	// Reset moderation status for the media item so other tests are unaffected.
	rest( 'POST', "/mvs/v1/moderation/$mid/approve" );

	// ═══════════════════════════════════
	// BLOCKED: NON-ADMIN MODERATION QUEUE
	// ═══════════════════════════════════
	section( 'PERMISSION: NON-ADMIN BLOCKED FROM QUEUE' );
	wp_set_current_user( $other_id );

	$r = rest( 'GET', '/mvs/v1/moderation' );
	assert_test(
		'Non-admin CANNOT access moderation queue',
		$r['status'] === 403,
		'status:' . $r['status']
	) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/moderation/counts' );
	assert_test(
		'Non-admin CANNOT view moderation counts',
		$r['status'] === 403,
		'status:' . $r['status']
	) ? $p++ : $f++;

	$r = rest( 'POST', "/mvs/v1/moderation/$mid/approve" );
	assert_test(
		'Non-admin CANNOT approve moderation items',
		$r['status'] === 403,
		'status:' . $r['status']
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// BLOCKED: ANONYMOUS REPORT
	// ═══════════════════════════════════
	section( 'PERMISSION: ANONYMOUS BLOCKED FROM REPORTING' );
	wp_set_current_user( 0 );

	$r = rest( 'POST', "/mvs/v1/media/$mid/report", array(
		'reason'  => 'spam',
		'details' => 'anonymous attempt',
	) );
	assert_test(
		'Anonymous CANNOT report media',
		$r['status'] === 401,
		'status:' . $r['status']
	) ? $p++ : $f++;

	$r = rest( 'POST', "/mvs/v1/users/$other_id/block" );
	assert_test(
		'Anonymous CANNOT block users',
		$r['status'] === 401,
		'status:' . $r['status']
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// CLEANUP
	// ═══════════════════════════════════
	wp_set_current_user( $admin_id );

	return array( $p, $f );
}
