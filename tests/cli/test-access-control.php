<?php
/**
 * CLI Tests: Access Control — Rules, Grants, and Permission Enforcement.
 *
 * Tests the full access-control user journey:
 * - Creating and deleting access rules
 * - Granting and revoking access to specific users
 * - Verifying non-owners are BLOCKED from managing rules
 * - Verifying anonymous users are BLOCKED from all endpoints
 * - Verifying grant-based media visibility
 *
 * @package WPMediaVerse
 */

function run_access_control_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();

	$admin_id = $data['admin_id'];
	$other_id = $data['other_id'];
	$mid      = $data['own_media'];

	// ═══════════════════════════════════
	// ADMIN: LIST ACCESS RULES
	// ═══════════════════════════════════
	section( 'ADMIN LISTS ACCESS RULES' );
	wp_set_current_user( $admin_id );

	$r = rest( 'GET', "/mvs/v1/media/$mid/rules" );
	assert_test(
		'Admin can list access rules',
		$r['ok'],
		'status:' . $r['status']
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// ADMIN: CREATE ACCESS RULE
	// ═══════════════════════════════════
	section( 'CREATE ACCESS RULE' );
	$r = rest( 'POST', "/mvs/v1/media/$mid/rules", array(
		'rules' => array(
			array(
				'rule_type'  => 'role',
				'rule_value' => 'subscriber',
			),
		),
	) );
	assert_test(
		'Admin creates access rule',
		in_array( $r['status'], array( 200, 201 ), true ),
		'status:' . $r['status']
	) ? $p++ : $f++;

	// Capture rule_id for later deletion.
	$rules   = $r['data']['rules'] ?? array();
	$rule_id = ! empty( $rules ) ? (int) $rules[ count( $rules ) - 1 ]['id'] : 0;
	assert_test(
		'Rule response contains rules array',
		! empty( $rules ),
		'count:' . count( $rules )
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// ADMIN: GRANT ACCESS TO USER
	// ═══════════════════════════════════
	section( 'GRANT ACCESS TO SPECIFIC USER' );
	$r = rest( 'POST', "/mvs/v1/media/$mid/grant", array(
		'user_id' => $other_id,
	) );
	$grant_ok = in_array( $r['status'], array( 200, 201 ), true );
	assert_test(
		'Admin grants access to other user',
		$grant_ok,
		'status:' . $r['status']
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// GRANTEE: SEE THEIR GRANTS
	// ═══════════════════════════════════
	section( 'GRANTEE VIEWS OWN GRANTS' );
	wp_set_current_user( $other_id );

	$r = rest( 'GET', '/mvs/v1/me/grants' );
	assert_test(
		'Grantee can list own grants',
		$r['ok'],
		'status:' . $r['status']
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// ADMIN: REVOKE GRANT
	// ═══════════════════════════════════
	section( 'REVOKE GRANT' );
	wp_set_current_user( $admin_id );

	$r = rest( 'DELETE', "/mvs/v1/media/$mid/grant/$other_id" );
	assert_test(
		'Admin revokes access grant',
		in_array( $r['status'], array( 200, 204 ), true ),
		'status:' . $r['status']
	) ? $p++ : $f++;

	// Verify grant is actually gone — grantee should now have fewer grants.
	wp_set_current_user( $other_id );
	$r_after = rest( 'GET', '/mvs/v1/me/grants' );
	// After revoke the specific grant for $mid should not appear.
	$still_granted = false;
	if ( is_array( $r_after['data'] ) ) {
		foreach ( $r_after['data'] as $grant ) {
			if ( ( $grant['media_id'] ?? 0 ) === $mid ) {
				$still_granted = true;
			}
		}
	}
	assert_test(
		'Revoked grant no longer in grantee list',
		! $still_granted,
		'media_id:' . $mid
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// ADMIN: DELETE RULE
	// ═══════════════════════════════════
	section( 'DELETE ACCESS RULE' );
	wp_set_current_user( $admin_id );

	if ( $rule_id ) {
		$r = rest( 'DELETE', "/mvs/v1/media/$mid/rules/$rule_id" );
		assert_test(
			'Admin deletes access rule',
			in_array( $r['status'], array( 200, 204 ), true ),
			'status:' . $r['status']
		) ? $p++ : $f++;

		// Verify rule is gone.
		$r = rest( 'GET', "/mvs/v1/media/$mid/rules" );
		$remaining_ids = array_column( $r['data']['rules'] ?? array(), 'id' );
		assert_test(
			'Deleted rule no longer in rules list',
			! in_array( $rule_id, $remaining_ids, true ),
			'rule_id:' . $rule_id
		) ? $p++ : $f++;
	} else {
		assert_test( 'Delete rule — skipped (no rule_id captured)', false, 'rule_id:0' ) ? $p++ : $f++;
		assert_test( 'Verify rule deleted — skipped', false, 'depends on above' ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════
	// BLOCKED: NON-OWNER CANNOT SET RULES
	// ═══════════════════════════════════
	section( 'PERMISSION: NON-OWNER BLOCKED' );
	wp_set_current_user( $other_id );

	$r = rest( 'POST', "/mvs/v1/media/$mid/rules", array(
		'rules' => array(
			array(
				'rule_type'  => 'role',
				'rule_value' => 'editor',
			),
		),
	) );
	assert_test(
		'Non-owner CANNOT set access rules',
		$r['status'] === 403,
		'status:' . $r['status']
	) ? $p++ : $f++;

	$r = rest( 'POST', "/mvs/v1/media/$mid/grant", array(
		'user_id' => $admin_id,
	) );
	assert_test(
		'Non-owner CANNOT grant access',
		$r['status'] === 403,
		'status:' . $r['status']
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// BLOCKED: ANONYMOUS ACCESS
	// ═══════════════════════════════════
	section( 'PERMISSION: ANONYMOUS BLOCKED' );
	wp_set_current_user( 0 );

	$r = rest( 'GET', "/mvs/v1/media/$mid/rules" );
	assert_test(
		'Anonymous CANNOT list rules',
		$r['status'] === 401,
		'status:' . $r['status']
	) ? $p++ : $f++;

	$r = rest( 'POST', "/mvs/v1/media/$mid/grant", array( 'user_id' => $other_id ) );
	assert_test(
		'Anonymous CANNOT grant access',
		$r['status'] === 401,
		'status:' . $r['status']
	) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/me/grants' );
	assert_test(
		'Anonymous CANNOT view grants',
		$r['status'] === 401,
		'status:' . $r['status']
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// GRANT-BASED VISIBILITY
	// ═══════════════════════════════════
	section( 'GRANT-BASED MEDIA VISIBILITY' );
	wp_set_current_user( $admin_id );

	// Set media to private, then grant access to other_id.
	rest( 'POST', "/mvs/v1/media/$mid", array( 'privacy' => 'private' ) );
	rest( 'POST', "/mvs/v1/media/$mid/grant", array( 'user_id' => $other_id ) );

	// User WITH grant can view.
	wp_set_current_user( $other_id );
	$r = rest( 'GET', "/mvs/v1/media/$mid" );
	assert_test(
		'User WITH grant can view private media',
		$r['ok'],
		'status:' . $r['status']
	) ? $p++ : $f++;

	// Find a user WITHOUT grant.
	$subs = get_users( array(
		'role'    => 'subscriber',
		'number'  => 1,
		'fields'  => 'ID',
		'exclude' => array( $admin_id, $other_id ),
	) );
	if ( ! empty( $subs ) ) {
		wp_set_current_user( (int) $subs[0] );
		$r = rest( 'GET', "/mvs/v1/media/$mid" );
		assert_test(
			'User WITHOUT grant CANNOT view private media',
			$r['status'] === 403 || $r['status'] === 404,
			'status:' . $r['status']
		) ? $p++ : $f++;
	} else {
		assert_test( 'No subscriber found for no-grant test — skipped', true, 'no subscriber' ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════
	// CLEANUP
	// ═══════════════════════════════════
	wp_set_current_user( $admin_id );
	rest( 'POST', "/mvs/v1/media/$mid", array( 'privacy' => 'public' ) );
	rest( 'DELETE', "/mvs/v1/media/$mid/grant/$other_id" );

	return array( $p, $f );
}
