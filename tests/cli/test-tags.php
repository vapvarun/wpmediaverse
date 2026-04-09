<?php
/**
 * CLI Tests: Tags — List, Cloud, Search, Merge, Rename, Permissions.
 *
 * Covers: /mvs/v1/tags, /tags/cloud, /tags/merge, /tags/{id}, permissions.
 *
 * @package WPMediaVerse
 */

function run_tags_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();

	wp_set_current_user( $data['admin_id'] );

	// ═══════════════════════════════════
	// LIST ALL TAGS
	// ═══════════════════════════════════
	section( 'LIST TAGS' );

	$r = rest( 'GET', '/mvs/v1/tags' );
	assert_test( 'List tags returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Tags response is array', is_array( $r['data'] ), 'count:' . $r['count'] ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// TAG CLOUD
	// ═══════════════════════════════════
	section( 'TAG CLOUD' );

	$r = rest( 'GET', '/mvs/v1/tags/cloud', array( 'limit' => 10 ) );
	assert_test( 'Tag cloud returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	// Verify structure: each item should have id, name, slug, count.
	$valid_structure = true;
	if ( $r['count'] > 0 ) {
		$first = $r['data'][0];
		$valid_structure = isset( $first['id'], $first['name'], $first['slug'], $first['count'] );
	}
	assert_test( 'Cloud items have correct structure', $valid_structure, 'fields: id, name, slug, count' ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// CREATE TAGS FOR TESTING
	// ═══════════════════════════════════
	section( 'TAG CREATION + SEARCH' );

	// Create two test tags via WP API (taxonomy terms) for merge/rename tests.
	$suffix     = time();
	$source_tag = wp_insert_term( 'cli-source-' . $suffix, 'mvs_tag' );
	$target_tag = wp_insert_term( 'cli-target-' . $suffix, 'mvs_tag' );
	$source_id  = is_array( $source_tag ) ? $source_tag['term_id'] : 0;
	$target_id  = is_array( $target_tag ) ? $target_tag['term_id'] : 0;

	assert_test( 'Test tags created', $source_id > 0 && $target_id > 0, "source:$source_id target:$target_id" ) ? $p++ : $f++;

	// Assign source tag to a media item so merge has something to move.
	$media_id = $data['own_media'];
	if ( $media_id && $source_id ) {
		wp_set_object_terms( $media_id, $source_id, 'mvs_tag', true );
	}

	// Search for the tag we just created.
	$r = rest( 'GET', '/mvs/v1/tags', array( 'search' => 'cli-source-' . $suffix ) );
	assert_test( 'Tag search returns matching result', $r['ok'] && $r['count'] > 0, 'found:' . $r['count'] ) ? $p++ : $f++;

	// Verify the searched tag matches our created tag.
	$found_name = $r['data'][0]['name'] ?? '';
	assert_test( 'Search result has correct name', $found_name === 'cli-source-' . $suffix, "got: $found_name" ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// MERGE TAGS
	// ═══════════════════════════════════
	section( 'MERGE TAGS' );

	if ( $source_id && $target_id ) {
		$r = rest( 'POST', '/mvs/v1/tags/merge', array(
			'source_id' => $source_id,
			'target_id' => $target_id,
		) );
		assert_test( 'Merge tags returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
		assert_test( 'Merge confirms success', ( $r['data']['merged'] ?? false ) === true ) ? $p++ : $f++;

		// Verify source tag is gone.
		$source_check = get_term( $source_id, 'mvs_tag' );
		assert_test( 'Source tag deleted after merge', is_null( $source_check ) || is_wp_error( $source_check ), 'source term should not exist' ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════
	// RENAME TAG
	// ═══════════════════════════════════
	section( 'RENAME TAG' );

	if ( $target_id ) {
		$renamed = 'cli-renamed-' . $suffix;
		$r       = rest( 'PUT', '/mvs/v1/tags/' . $target_id, array(
			'name' => $renamed,
		) );
		assert_test( 'Rename tag returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
		assert_test( 'Tag name changed', ( $r['data']['name'] ?? '' ) === $renamed, 'name:' . ( $r['data']['name'] ?? 'null' ) ) ? $p++ : $f++;
	}

	// ═══════════════════════════════════
	// PERMISSION BOUNDARIES
	// ═══════════════════════════════════
	section( 'PERMISSION BOUNDARIES' );

	// Anonymous can list tags (public endpoint).
	wp_set_current_user( 0 );
	$r = rest( 'GET', '/mvs/v1/tags' );
	assert_test( 'Anonymous CAN list tags', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;

	// Non-admin can't merge tags.
	$other_id = $data['other_id'];
	if ( $other_id ) {
		wp_set_current_user( $other_id );
		$r = rest( 'POST', '/mvs/v1/tags/merge', array(
			'source_id' => 1,
			'target_id' => 2,
		) );
		assert_test( 'Non-admin CANNOT merge tags', $r['status'] === 403, 'status:' . $r['status'] ) ? $p++ : $f++;

		// Non-admin can't rename tags.
		if ( $target_id ) {
			$r = rest( 'PUT', '/mvs/v1/tags/' . $target_id, array(
				'name' => 'hacked-name',
			) );
			assert_test( 'Non-admin CANNOT rename tags', $r['status'] === 403, 'status:' . $r['status'] ) ? $p++ : $f++;
		}
	}

	// ═══════════════════════════════════
	// CLEANUP
	// ═══════════════════════════════════
	wp_set_current_user( $data['admin_id'] );
	if ( $target_id ) {
		wp_delete_term( $target_id, 'mvs_tag' );
	}
	// Remove tag association from media.
	if ( $media_id && $target_id ) {
		wp_remove_object_terms( $media_id, $target_id, 'mvs_tag' );
	}

	return array( $p, $f );
}
