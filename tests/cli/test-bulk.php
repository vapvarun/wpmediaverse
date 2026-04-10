<?php
/**
 * CLI Tests: Bulk Operations — delete, change_privacy, move_to_album.
 *
 * Verifies actual data changes, permission enforcement, and input validation.
 *
 * @package WPMediaVerse
 */

function run_bulk_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();
	global $wpdb;

	$admin_id = $data['admin_id'];
	$other_id = $data['other_id'];
	$album_id = $data['album_id'];

	wp_set_current_user( $admin_id );

	// ═══════════════════════════════════
	// SETUP: Create temporary test media
	// ═══════════════════════════════════
	section( 'SETUP TEST MEDIA FOR BULK OPS' );

	$test_media_ids = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$mid = \WPMediaVerse\Repository\MediaRepository::insert(
			array(
				'title'       => 'Bulk Test Media ' . $i . ' ' . time(),
				'post_author' => $admin_id,
				'status'      => 'publish',
				'privacy'     => 'public',
				'media_type'  => 'image',
				'file_url'    => 'https://example.com/bulk-test-' . $i . '.jpg',
				'file_type'   => 'image/jpeg',
			)
		);
		if ( $mid ) {
			$test_media_ids[] = $mid;
		}
	}
	assert_test( 'Created 3 test media items', count( $test_media_ids ) === 3, 'created: ' . count( $test_media_ids ) ) ? $p++ : $f++;

	if ( count( $test_media_ids ) < 3 ) {
		echo "  (aborting bulk tests — failed to create test media)" . PHP_EOL;
		return array( $p, $f );
	}

	// ═══════════════════════════════════
	// BULK CHANGE PRIVACY
	// ═══════════════════════════════════
	section( 'BULK CHANGE PRIVACY' );

	$r = rest( 'POST', '/mvs/v1/media/bulk', array(
		'action'    => 'change_privacy',
		'media_ids' => $test_media_ids,
		'privacy'   => 'private',
	) );
	assert_test( 'Bulk change_privacy returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'All 3 items processed', ( $r['data']['processed'] ?? 0 ) === 3, 'processed:' . ( $r['data']['processed'] ?? 0 ) ) ? $p++ : $f++;

	// Verify privacy was actually updated in mvs_media_index.
	$private_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_id IN (%d, %d, %d) AND privacy = 'private'",
			$test_media_ids[0],
			$test_media_ids[1],
			$test_media_ids[2]
		)
	);
	assert_test( 'DB confirms all 3 are now private', $private_count === 3, "private_count:$private_count" ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// BULK MOVE TO ALBUM
	// ═══════════════════════════════════
	section( 'BULK MOVE TO ALBUM' );

	if ( $album_id ) {
		// Count items before.
		$items_before = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d",
				$album_id
			)
		);

		$r = rest( 'POST', '/mvs/v1/media/bulk', array(
			'action'    => 'move_to_album',
			'media_ids' => $test_media_ids,
			'album_id'  => $album_id,
		) );
		assert_test( 'Bulk move_to_album returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
		assert_test( 'All 3 items moved', ( $r['data']['processed'] ?? 0 ) === 3, 'processed:' . ( $r['data']['processed'] ?? 0 ) ) ? $p++ : $f++;

		// Verify items are in mvs_album_items.
		$items_after = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d",
				$album_id
			)
		);
		assert_test( 'DB confirms items added to album', $items_after >= $items_before + 3, "before:$items_before after:$items_after" ) ? $p++ : $f++;

		// Cleanup album items.
		foreach ( $test_media_ids as $tid ) {
			$wpdb->delete(
				$wpdb->prefix . 'mvs_album_items',
				array( 'album_id' => $album_id, 'media_id' => $tid ),
				array( '%d', '%d' )
			);
		}
	} else {
		echo "  (no album found, skipping move_to_album tests)" . PHP_EOL;
		$f += 3;
	}

	// ═══════════════════════════════════
	// EMPTY MEDIA_IDS -> 400
	// ═══════════════════════════════════
	section( 'INPUT VALIDATION' );

	$r = rest( 'POST', '/mvs/v1/media/bulk', array(
		'action'    => 'delete',
		'media_ids' => array(),
	) );
	assert_test( 'Empty media_ids returns 400', $r['status'] === 400, 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// MAX 100 ITEMS LIMIT
	// ═══════════════════════════════════
	section( 'MAX ITEMS LIMIT' );

	$too_many = range( 1, 101 );
	$r        = rest( 'POST', '/mvs/v1/media/bulk', array(
		'action'    => 'change_privacy',
		'media_ids' => $too_many,
		'privacy'   => 'public',
	) );
	assert_test( 'Over 100 items returns 400', $r['status'] === 400, 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// NON-OWNER BULK DELETE -> filtered out
	// ═══════════════════════════════════
	section( 'PERMISSION: NON-OWNER BULK DELETE' );

	if ( $other_id ) {
		wp_set_current_user( $other_id );

		// Other user tries to bulk delete admin's media.
		$r = rest( 'POST', '/mvs/v1/media/bulk', array(
			'action'    => 'delete',
			'media_ids' => $test_media_ids,
		) );

		// The BulkController filters out non-owned items, so processed should be 0
		// (unless other_id has edit_others_mvs_medias capability).
		$can_edit_others = user_can( $other_id, 'edit_others_mvs_medias' );
		if ( $can_edit_others ) {
			// If user can edit others, operation will succeed but that's expected.
			assert_test( 'User with edit_others can bulk operate', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
		} else {
			// Other user either gets 403 (no edit_mvs_medias cap) or 200 with 0 processed.
			$processed = $r['data']['processed'] ?? -1;
			$no_effect = $r['status'] === 403 || $processed === 0;
			assert_test(
				'Non-owner bulk delete has no effect on admin media',
				$no_effect,
				'status:' . $r['status'] . ' processed:' . $processed
			) ? $p++ : $f++;
		}

		wp_set_current_user( $admin_id );

		// Verify admin's media still exists.
		$still_exists = \WPMediaVerse\Repository\MediaRepository::exists( $test_media_ids[0] );
		assert_test( 'Admin media NOT deleted by non-owner', $still_exists, 'media_id:' . $test_media_ids[0] ) ? $p++ : $f++;
	} else {
		$f += 2;
		echo "  (no other user, skipping permission tests)" . PHP_EOL;
	}

	// ═══════════════════════════════════
	// ANONYMOUS BULK -> 401/403
	// ═══════════════════════════════════
	section( 'ANONYMOUS BULK' );

	wp_set_current_user( 0 );
	$r = rest( 'POST', '/mvs/v1/media/bulk', array(
		'action'    => 'delete',
		'media_ids' => $test_media_ids,
	) );
	assert_test( 'Anonymous bulk returns 401 or 403', in_array( $r['status'], array( 401, 403 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// CLEANUP: Bulk delete test media
	// ═══════════════════════════════════
	section( 'BULK DELETE (cleanup)' );

	wp_set_current_user( $admin_id );
	$r = rest( 'POST', '/mvs/v1/media/bulk', array(
		'action'    => 'delete',
		'media_ids' => $test_media_ids,
	) );
	assert_test( 'Bulk delete returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'All 3 items deleted', ( $r['data']['processed'] ?? 0 ) === 3, 'processed:' . ( $r['data']['processed'] ?? 0 ) ) ? $p++ : $f++;

	// Verify media is actually gone from mvs_media_index.
	$remaining = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_id IN (%d, %d, %d)",
			$test_media_ids[0],
			$test_media_ids[1],
			$test_media_ids[2]
		)
	);
	assert_test( 'DB confirms all 3 deleted from mvs_media_index', $remaining === 0, "remaining:$remaining" ) ? $p++ : $f++;

	return array( $p, $f );
}
