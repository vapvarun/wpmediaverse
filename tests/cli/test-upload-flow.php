<?php
/**
 * CLI Tests: Complete Upload Flow.
 *
 * Tests the full lifecycle: upload → metadata → display → edit → delete → cascade cleanup.
 * This is the #1 user action — must be bulletproof.
 */

function run_upload_flow_tests(): array {
	$p = 0;
	$f = 0;
	global $wpdb;

	wp_set_current_user( 1 );
	$data = get_test_data();

	// ═══════════════════════════════════
	// UPLOAD (simulate — can't do multipart in REST internal dispatch)
	// ═══════════════════════════════════
	section( 'UPLOAD SIMULATION' );

	// Create a temp image file.
	$tmp = tempnam( sys_get_temp_dir(), 'mvs_test_' ) . '.jpg';
	$img = imagecreatetruecolor( 800, 600 );
	imagejpeg( $img, $tmp, 90 );
	imagedestroy( $img );
	assert_test( 'Test image created', file_exists( $tmp ), filesize( $tmp ) . ' bytes' ) ? $p++ : $f++;

	// Use UploadService directly.
	$upload_svc = \WPMediaVerse\Core\Plugin::container()->get( 'upload' );
	assert_test( 'UploadService available', $upload_svc !== null ) ? $p++ : $f++;

	if ( $upload_svc ) {
		$file_array = array(
			'name'     => 'cli-test-upload.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);

		$result = $upload_svc->handle( $file_array, 1, array(
			'title'       => 'CLI Test Upload',
			'description' => 'Automated test image',
			'privacy'     => 'public',
			'tags'        => 'test,automated',
		) );

		$uploaded_id = is_wp_error( $result ) ? 0 : ( is_array( $result ) ? ( $result['media_id'] ?? 0 ) : (int) $result );
		assert_test( 'Upload succeeds', $uploaded_id > 0, is_wp_error( $result ) ? $result->get_error_message() : "id: $uploaded_id" ) ? $p++ : $f++;

		if ( $uploaded_id ) {
			// ── VERIFY IN DATABASE ──
			section( 'VERIFY UPLOAD DATA' );
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
				$uploaded_id
			) );
			assert_test( 'Media in mvs_media_index', $row !== null ) ? $p++ : $f++;
			assert_test( 'Title saved', $row->title === 'CLI Test Upload' ) ? $p++ : $f++;
			assert_test( 'Privacy saved', $row->privacy === 'public' ) ? $p++ : $f++;
			assert_test( 'Author correct', (int) $row->post_author === 1 ) ? $p++ : $f++;
			assert_test( 'Media type = image', $row->media_type === 'image' ) ? $p++ : $f++;
			assert_test( 'File URL not empty', ! empty( $row->file_url ) ) ? $p++ : $f++;
			assert_test( 'Status = publish', $row->status === 'publish' ) ? $p++ : $f++;

			// ── VERIFY VIA REST ──
			section( 'VERIFY VIA REST' );
			$r = rest( 'GET', '/mvs/v1/media/' . $uploaded_id );
			assert_test( 'GET uploaded media via REST', $r['ok'] ) ? $p++ : $f++;
			assert_test( 'REST title matches', ( $r['data']['title'] ?? '' ) === 'CLI Test Upload' ) ? $p++ : $f++;
			assert_test( 'REST has thumbnail_url', ! empty( $r['data']['thumbnail_url'] ) ) ? $p++ : $f++;

			// ── EDIT ──
			section( 'EDIT UPLOADED MEDIA' );
			$r = rest( 'POST', '/mvs/v1/media/' . $uploaded_id, array(
				'title'       => 'CLI Test Updated',
				'description' => 'Updated description',
				'privacy'     => 'members',
			) );
			assert_test( 'Edit uploaded media', $r['ok'] ) ? $p++ : $f++;

			$r = rest( 'GET', '/mvs/v1/media/' . $uploaded_id );
			assert_test( 'Title updated', ( $r['data']['title'] ?? '' ) === 'CLI Test Updated' ) ? $p++ : $f++;
			assert_test( 'Privacy updated to members', ( $r['data']['privacy'] ?? '' ) === 'members' ) ? $p++ : $f++;

			// ── ADD SOCIAL DATA (to test cascade delete) ──
			section( 'ADD SOCIAL DATA' );
			$r = rest( 'POST', "/mvs/v1/media/$uploaded_id/reactions", array( 'reaction_type' => 'like' ) );
			assert_test( 'Add reaction to uploaded media', $r['ok'] ) ? $p++ : $f++;

			$r = rest( 'POST', "/mvs/v1/media/$uploaded_id/favorite" );
			assert_test( 'Favorite uploaded media', $r['ok'] ) ? $p++ : $f++;

			$r = rest( 'POST', "/mvs/v1/media/$uploaded_id/comments", array( 'content' => 'Test cascade comment' ) );
			assert_test( 'Comment on uploaded media', in_array( $r['status'], array( 200, 201 ), true ) ) ? $p++ : $f++;

			// Verify social data exists.
			$reaction_exists = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_reactions WHERE media_id = %d",
				$uploaded_id
			) );
			$fav_exists = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_favorites WHERE media_id = %d",
				$uploaded_id
			) );
			assert_test( 'Reaction in DB', $reaction_exists > 0 ) ? $p++ : $f++;
			assert_test( 'Favorite in DB', $fav_exists > 0 ) ? $p++ : $f++;

			// ── DELETE + CASCADE ──
			section( 'DELETE + CASCADE CLEANUP' );
			$r = rest( 'DELETE', '/mvs/v1/media/' . $uploaded_id );
			assert_test( 'Delete media', in_array( $r['status'], array( 200, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

			// Verify cascade cleanup.
			$media_gone = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
				$uploaded_id
			) );
			assert_test( 'Media removed from index', (int) $media_gone === 0 ) ? $p++ : $f++;

			$reactions_gone = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_reactions WHERE media_id = %d",
				$uploaded_id
			) );
			assert_test( 'Reactions cleaned up', $reactions_gone === 0 ) ? $p++ : $f++;

			$favs_gone = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_favorites WHERE media_id = %d",
				$uploaded_id
			) );
			assert_test( 'Favorites cleaned up', $favs_gone === 0 ) ? $p++ : $f++;

			// Verify REST returns 404 for deleted media.
			$r = rest( 'GET', '/mvs/v1/media/' . $uploaded_id );
			assert_test( 'Deleted media returns 404', $r['status'] === 404 ) ? $p++ : $f++;
		}
	}

	// Cleanup temp file.
	if ( file_exists( $tmp ) ) {
		unlink( $tmp );
	}

	return array( $p, $f );
}
