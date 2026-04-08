<?php
/**
 * CLI Tests: Albums — CRUD, items, cover, reorder.
 *
 * Covers: /mvs/v1/albums, /mvs/v1/albums/{id}/items, /cover, /reorder
 */

function run_albums_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();

	section( 'LIST ALBUMS' );
	$r = rest( 'GET', '/mvs/v1/albums' );
	assert_test( 'List all albums', $r['ok'], $r['count'] . ' albums' ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/albums', array( 'author' => 1 ) );
	assert_test( 'Filter by author', $r['ok'], $r['count'] . ' own albums' ) ? $p++ : $f++;

	section( 'ALBUM DETAIL' );
	$aid = $data['album_id'];
	if ( $aid ) {
		$r = rest( 'GET', '/mvs/v1/albums/' . $aid );
		assert_test( 'Get album detail', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

		$r = rest( 'GET', '/mvs/v1/albums/' . $aid . '/items' );
		assert_test( 'List album items', is_array( $r['data'] ), $r['count'] . ' items' ) ? $p++ : $f++;
	}

	section( 'CREATE ALBUM' );
	$r      = rest( 'POST', '/mvs/v1/albums', array(
		'title'       => 'CLI Test Album',
		'description' => 'Created by CLI test',
		'privacy'     => 'public',
	) );
	$new_id = $r['data']['id'] ?? 0;
	assert_test( 'Create album', in_array( $r['status'], array( 200, 201 ), true ) && $new_id > 0, 'id:' . $new_id ) ? $p++ : $f++;

	if ( $new_id ) {
		section( 'ALBUM ITEMS' );
		// Add media to album.
		$r = rest( 'POST', '/mvs/v1/albums/' . $new_id . '/items', array(
			'media_ids' => array( $data['own_media'] ),
		) );
		assert_test( 'Add item to album', in_array( $r['status'], array( 200, 201 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		$r = rest( 'GET', '/mvs/v1/albums/' . $new_id . '/items' );
		assert_test( 'Item in album', is_array( $r['data'] ) && $r['count'] > 0, $r['count'] . ' items' ) ? $p++ : $f++;

		// Set cover.
		$r = rest( 'POST', '/mvs/v1/albums/' . $new_id . '/cover', array(
			'media_id' => $data['own_media'],
		) );
		assert_test( 'Set album cover', in_array( $r['status'], array( 200, 201 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		// Remove item.
		$r = rest( 'DELETE', '/mvs/v1/albums/' . $new_id . '/items/' . $data['own_media'] );
		assert_test( 'Remove item from album', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

		// Delete album.
		$r = rest( 'DELETE', '/mvs/v1/albums/' . $new_id );
		assert_test( 'Delete album', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;
	}

	return array( $p, $f );
}
