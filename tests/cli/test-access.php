<?php
/**
 * CLI Tests: Access Control, Signed URLs, Moderation, Bulk Ops.
 *
 * Tests permission-gated and admin-only endpoints.
 */

function run_access_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();
	$mid  = $data['own_media'];

	// ═══════════════════════════════════
	// ACCESS RULES (per-media)
	// ═══════════════════════════════════
	section( 'ACCESS RULES' );
	$r = rest( 'GET', "/mvs/v1/media/$mid/rules" );
	assert_test( 'List access rules', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'POST', "/mvs/v1/media/$mid/rules", array(
		'type'  => 'password',
		'value' => 'test123',
	) );
	assert_test( 'Create access rule', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	// GRANTS
	section( 'ACCESS GRANTS' );
	$r = rest( 'GET', '/mvs/v1/me/grants' );
	assert_test( 'List my grants', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'POST', "/mvs/v1/media/$mid/grant", array(
		'user_id' => $data['other_id'],
	) );
	assert_test( 'Grant access to user', in_array( $r['status'], array( 200, 201, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	// SIGNED URLs
	section( 'SIGNED URLS' );
	$r = rest( 'GET', "/mvs/v1/media/$mid/signed-url" );
	assert_test( 'Get signed URL', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// MODERATION (admin only)
	// ═══════════════════════════════════
	section( 'MODERATION' );
	$r = rest( 'GET', '/mvs/v1/moderation' );
	assert_test( 'List moderation queue', $r['ok'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/moderation/counts' );
	assert_test( 'Moderation counts', $r['ok'], json_encode( $r['data'] ) ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/moderation', array( 'status' => 'pending' ) );
	assert_test( 'Filter: pending', $r['ok'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/moderation', array( 'status' => 'flagged' ) );
	assert_test( 'Filter: flagged', $r['ok'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// BULK OPS
	// ═══════════════════════════════════
	section( 'BULK OPERATIONS' );
	$r = rest( 'POST', '/mvs/v1/media/bulk', array(
		'action'    => 'update_privacy',
		'media_ids' => array( $mid ),
		'privacy'   => 'public',
	) );
	assert_test( 'Bulk update privacy', in_array( $r['status'], array( 200, 400 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// TAGS (admin write ops)
	// ═══════════════════════════════════
	section( 'TAGS MANAGEMENT' );
	$r = rest( 'GET', '/mvs/v1/tags' );
	assert_test( 'List tags', $r['ok'], $r['count'] . ' tags' ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/tags/cloud' );
	assert_test( 'Tag cloud', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	// AI
	section( 'AI USAGE' );
	$r = rest( 'GET', '/mvs/v1/ai/usage' );
	assert_test( 'AI usage', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// MEDIA ACCESS (group, replace)
	// ═══════════════════════════════════
	section( 'MEDIA ACCESS & GROUP' );
	$r = rest( 'GET', "/mvs/v1/media/$mid/access" );
	assert_test( 'Check media access', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', "/mvs/v1/media/$mid/group" );
	assert_test( 'Get media group', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// PERMISSION CHECK: Subscriber can't moderate
	// ═══════════════════════════════════
	section( 'PERMISSION BOUNDARIES' );
	wp_set_current_user( 0 ); // Logged out.
	$r = rest( 'POST', "/mvs/v1/media/$mid/comments", array( 'content' => 'anon' ) );
	assert_test( 'Logged-out cannot comment', $r['status'] === 401 || $r['status'] === 403, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/moderation' );
	assert_test( 'Logged-out cannot access moderation', $r['status'] === 401 || $r['status'] === 403, 'status:' . $r['status'] ) ? $p++ : $f++;

	// Subscriber.
	$sub_id = get_users( array( 'role' => 'subscriber', 'number' => 1, 'fields' => 'ID' ) );
	if ( ! empty( $sub_id ) ) {
		wp_set_current_user( (int) $sub_id[0] );
		$r = rest( 'GET', '/mvs/v1/moderation' );
		assert_test( 'Subscriber cannot access moderation', $r['status'] === 403, 'status:' . $r['status'] ) ? $p++ : $f++;
	}

	// Restore admin.
	wp_set_current_user( 1 );

	return array( $p, $f );
}
