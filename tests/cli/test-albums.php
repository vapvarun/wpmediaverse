<?php
/**
 * CLI Tests: Albums — Full Lifecycle with Data Accuracy.
 *
 * Covers: /mvs/v1/albums CRUD, /items, /cover, /reorder, permissions.
 *
 * @package WPMediaVerse
 */

function run_albums_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();
	global $wpdb;

	wp_set_current_user( $data['admin_id'] );

	// ═══════════════════════════════════
	// LIST ALBUMS
	// ═══════════════════════════════════
	section( 'LIST ALBUMS' );

	$r        = rest( 'GET', '/mvs/v1/albums' );
	$db_count = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'mvs_album' AND post_status = 'publish'"
	);
	assert_test( 'List albums returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Album count matches DB', is_array( $r['data'] ) && $r['count'] <= $db_count, "api:{$r['count']} db:$db_count (privacy may filter)" ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// CREATE ALBUM
	// ═══════════════════════════════════
	section( 'CREATE ALBUM' );

	$title       = 'CLI Lifecycle Album ' . time();
	$description = 'Created by CLI test for full lifecycle validation';
	$r           = rest( 'POST', '/mvs/v1/albums', array(
		'title'       => $title,
		'description' => $description,
		'privacy'     => 'public',
	) );
	$new_id      = $r['data']['id'] ?? 0;

	assert_test( 'Create album returns 201', $r['status'] === 201 && $new_id > 0, "id:$new_id status:{$r['status']}" ) ? $p++ : $f++;
	assert_test( 'Created album has correct title', ( $r['data']['title'] ?? '' ) === $title, $r['data']['title'] ?? 'missing' ) ? $p++ : $f++;
	assert_test( 'Created album has correct description', ( $r['data']['description'] ?? '' ) === $description ) ? $p++ : $f++;
	assert_test( 'Created album has correct privacy', ( $r['data']['privacy'] ?? '' ) === 'public' ) ? $p++ : $f++;

	if ( ! $new_id ) {
		return array( $p, $f );
	}

	// ═══════════════════════════════════
	// GET SINGLE ALBUM
	// ═══════════════════════════════════
	section( 'GET SINGLE ALBUM' );

	$r = rest( 'GET', '/mvs/v1/albums/' . $new_id );
	assert_test( 'Get album detail returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Album has required fields', isset(
		$r['data']['id'],
		$r['data']['title'],
		$r['data']['description'],
		$r['data']['author'],
		$r['data']['privacy'],
		$r['data']['media_count']
	), 'checking id, title, description, author, privacy, media_count' ) ? $p++ : $f++;
	assert_test( 'Album media_count starts at 0', ( $r['data']['media_count'] ?? -1 ) === 0, 'count:' . ( $r['data']['media_count'] ?? 'null' ) ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// ADD MEDIA ITEMS TO ALBUM
	// ═══════════════════════════════════
	section( 'ADD ITEMS TO ALBUM' );

	$media_id = $data['own_media'];
	if ( $media_id ) {
		$r = rest( 'POST', '/mvs/v1/albums/' . $new_id . '/items', array(
			'media_ids' => array( $media_id ),
		) );
		assert_test( 'Add item to album succeeds', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

		// Verify item_count incremented.
		$r = rest( 'GET', '/mvs/v1/albums/' . $new_id );
		assert_test( 'Item count incremented to 1', ( $r['data']['media_count'] ?? 0 ) >= 1, 'count:' . ( $r['data']['media_count'] ?? '0' ) ) ? $p++ : $f++;
		assert_test( 'Items array contains added media', in_array( $media_id, $r['data']['items'] ?? array(), true ), 'looking for media_id:' . $media_id ) ? $p++ : $f++;

		// ═══════════════════════════════════
		// SET COVER IMAGE
		// ═══════════════════════════════════
		section( 'SET COVER IMAGE' );

		$r = rest( 'PUT', '/mvs/v1/albums/' . $new_id . '/cover', array(
			'media_id' => $media_id,
		) );
		assert_test( 'Set cover image succeeds', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
		assert_test( 'Cover URL returned', ! empty( $r['data']['cover_url'] ?? '' ), $r['data']['cover_url'] ?? 'empty' ) ? $p++ : $f++;

		// ═══════════════════════════════════
		// REORDER ITEMS
		// ═══════════════════════════════════
		section( 'REORDER ITEMS' );

		$r = rest( 'PUT', '/mvs/v1/albums/' . $new_id . '/reorder', array(
			'order' => array( $media_id ),
		) );
		assert_test( 'Reorder items succeeds', $r['ok'] && ( $r['data']['reordered'] ?? false ) === true, 'status:' . $r['status'] ) ? $p++ : $f++;

		// ═══════════════════════════════════
		// REMOVE ITEM FROM ALBUM
		// ═══════════════════════════════════
		section( 'REMOVE ITEM FROM ALBUM' );

		$r = rest( 'DELETE', '/mvs/v1/albums/' . $new_id . '/items/' . $media_id );
		assert_test( 'Remove item returns 204', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		// Verify count decremented.
		$r = rest( 'GET', '/mvs/v1/albums/' . $new_id );
		assert_test( 'Item count decremented to 0', ( $r['data']['media_count'] ?? -1 ) === 0, 'count:' . ( $r['data']['media_count'] ?? 'null' ) ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════
	// UPDATE ALBUM
	// ═══════════════════════════════════
	section( 'UPDATE ALBUM' );

	$new_title = 'Updated CLI Album ' . time();
	$new_desc  = 'Updated description from CLI test';
	$r         = rest( 'PUT', '/mvs/v1/albums/' . $new_id, array(
		'title'       => $new_title,
		'description' => $new_desc,
	) );
	assert_test( 'Update album returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Title updated correctly', ( $r['data']['title'] ?? '' ) === $new_title ) ? $p++ : $f++;
	assert_test( 'Description updated correctly', ( $r['data']['description'] ?? '' ) === $new_desc ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// PERMISSION: Other user can't edit
	// ═══════════════════════════════════
	section( 'PERMISSION BOUNDARIES' );

	$other_id = $data['other_id'];
	if ( $other_id ) {
		wp_set_current_user( $other_id );
		$r = rest( 'PUT', '/mvs/v1/albums/' . $new_id, array(
			'title' => 'Hacked title',
		) );
		assert_test( 'Other user CANNOT edit admin album', $r['status'] === 403, 'status:' . $r['status'] ) ? $p++ : $f++;
		wp_set_current_user( $data['admin_id'] );
	}

	// Anonymous can view public album.
	wp_set_current_user( 0 );
	$r = rest( 'GET', '/mvs/v1/albums/' . $new_id );
	assert_test( 'Anonymous CAN view public album', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	// Anonymous can't create album.
	$r = rest( 'POST', '/mvs/v1/albums', array(
		'title'   => 'Anonymous Album',
		'privacy' => 'public',
	) );
	assert_test( 'Anonymous CANNOT create album', in_array( $r['status'], array( 401, 403 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// DELETE ALBUM — media items survive
	// ═══════════════════════════════════
	section( 'DELETE ALBUM' );

	wp_set_current_user( $data['admin_id'] );
	$r = rest( 'DELETE', '/mvs/v1/albums/' . $new_id );
	assert_test( 'Delete album returns 200/204', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	// Verify the media item still exists (album deletion must not cascade to media).
	if ( $media_id ) {
		$media_exists = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
				$media_id
			)
		);
		assert_test( 'Media items NOT deleted with album', $media_exists, 'media_id:' . $media_id ) ? $p++ : $f++;
	}

	return array( $p, $f );
}
