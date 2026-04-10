<?php
/**
 * CLI Tests: Collections — CRUD, Smart Rules, Permissions.
 *
 * Covers: /mvs/v1/collections CRUD, /rules, owner enforcement.
 *
 * @package WPMediaVerse
 */

function run_collections_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();

	wp_set_current_user( $data['admin_id'] );

	// ═══════════════════════════════════
	// LIST COLLECTIONS
	// ═══════════════════════════════════
	section( 'LIST COLLECTIONS' );

	$r = rest( 'GET', '/mvs/v1/collections' );
	assert_test( 'List collections returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Collections response is array', is_array( $r['data'] ), 'count:' . $r['count'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// CREATE COLLECTION
	// ═══════════════════════════════════
	section( 'CREATE COLLECTION' );

	$title       = 'CLI Test Collection ' . time();
	$description = 'Created by CLI test for collection lifecycle';
	$r           = rest( 'POST', '/mvs/v1/collections', array(
		'title'       => $title,
		'description' => $description,
	) );
	$new_id      = $r['data']['id'] ?? 0;

	assert_test( 'Create collection returns 201', $r['status'] === 201 && $new_id > 0, "id:$new_id status:{$r['status']}" ) ? $p++ : $f++;
	assert_test( 'Collection has correct title', ( $r['data']['title'] ?? '' ) === $title ) ? $p++ : $f++;
	assert_test( 'Collection has correct description', ( $r['data']['description'] ?? '' ) === $description ) ? $p++ : $f++;

	if ( ! $new_id ) {
		return array( $p, $f );
	}

	// ═══════════════════════════════════
	// GET SINGLE COLLECTION
	// ═══════════════════════════════════
	section( 'GET SINGLE COLLECTION' );

	$r = rest( 'GET', '/mvs/v1/collections/' . $new_id );
	assert_test( 'Get collection detail returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Collection has required fields', isset(
		$r['data']['id'],
		$r['data']['title'],
		$r['data']['description'],
		$r['data']['author'],
		$r['data']['type']
	), 'checking id, title, description, author, type' ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// UPDATE COLLECTION
	// ═══════════════════════════════════
	section( 'UPDATE COLLECTION' );

	$new_title = 'Updated CLI Collection ' . time();
	$new_desc  = 'Updated description from CLI test';
	$r         = rest( 'PUT', '/mvs/v1/collections/' . $new_id, array(
		'title'       => $new_title,
		'description' => $new_desc,
	) );
	assert_test( 'Update collection returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Title updated correctly', ( $r['data']['title'] ?? '' ) === $new_title ) ? $p++ : $f++;
	assert_test( 'Description updated correctly', ( $r['data']['description'] ?? '' ) === $new_desc ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// SET SMART COLLECTION RULES
	// ═══════════════════════════════════
	section( 'SMART COLLECTION RULES' );

	$rules = array(
		array(
			'key'      => 'media_type',
			'operator' => 'is',
			'value'    => 'image',
		),
	);
	$r = rest( 'PUT', '/mvs/v1/collections/' . $new_id . '/rules', array(
		'rules' => $rules,
	) );
	assert_test( 'Set rules returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Rules saved and returned', ! empty( $r['data']['rules'] ?? array() ), 'rules count:' . count( $r['data']['rules'] ?? array() ) ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// PERMISSION: Other user can't edit
	// ═══════════════════════════════════
	section( 'PERMISSION BOUNDARIES' );

	$other_id = $data['other_id'];
	if ( $other_id ) {
		wp_set_current_user( $other_id );
		$r = rest( 'PUT', '/mvs/v1/collections/' . $new_id, array(
			'title' => 'Hacked collection title',
		) );
		assert_test( 'Other user CANNOT edit admin collection', $r['status'] === 403, 'status:' . $r['status'] ) ? $p++ : $f++;
		wp_set_current_user( $data['admin_id'] );
	}

	// Anonymous can't create collection.
	wp_set_current_user( 0 );
	$r = rest( 'POST', '/mvs/v1/collections', array(
		'title' => 'Anonymous Collection',
	) );
	assert_test( 'Anonymous CANNOT create collection', in_array( $r['status'], array( 401, 403 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// DELETE COLLECTION
	// ═══════════════════════════════════
	section( 'DELETE COLLECTION' );

	wp_set_current_user( $data['admin_id'] );
	$r = rest( 'DELETE', '/mvs/v1/collections/' . $new_id );
	assert_test( 'Delete collection returns 200/204', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	// Verify it is gone.
	$r = rest( 'GET', '/mvs/v1/collections/' . $new_id );
	assert_test( 'Deleted collection returns 404', $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	return array( $p, $f );
}
