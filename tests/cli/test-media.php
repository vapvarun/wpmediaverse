<?php
/**
 * CLI Tests: Media CRUD + Browse + Search + Privacy.
 *
 * Covers: /mvs/v1/media, /mvs/v1/media/{id}, /mvs/v1/me/media
 */

function run_media_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();

	section( 'BROWSE' );
	$r = rest( 'GET', '/mvs/v1/media', array( 'per_page' => 5 ) );
	assert_test( 'List media (paginated)', $r['ok'] && $r['count'] > 0, $r['count'] . ' items' ) ? $p++ : $f++;

	$first = $r['data'][0] ?? array();
	$shape = isset( $first['id'], $first['title'], $first['file_url'], $first['thumbnail_url'], $first['privacy'], $first['media_type'] );
	assert_test( 'Response shape: id, title, file_url, thumbnail_url, privacy, media_type', $shape ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/media', array( 's' => 'mountain' ) );
	assert_test( 'Search: "mountain"', $r['ok'], $r['count'] . ' results' ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/media', array( 'media_type' => 'image' ) );
	assert_test( 'Filter: type=image', $r['ok'], $r['count'] . ' images' ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/media', array( 'author' => $data['other_id'] ) );
	assert_test( 'Filter: by author', $r['ok'], $r['count'] . ' items' ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/me/media' );
	assert_test( 'GET /mvs/v1/me/media', $r['ok'], $r['count'] . ' own items' ) ? $p++ : $f++;

	section( 'SINGLE MEDIA' );
	$r = rest( 'GET', '/mvs/v1/media/' . $data['other_media'] );
	assert_test( 'Get single media', $r['ok'], $r['data']['title'] ?? '' ) ? $p++ : $f++;
	assert_test( 'Has stats object', isset( $r['data']['stats'] ) ) ? $p++ : $f++;
	assert_test( 'Has author_data', isset( $r['data']['author_data'] ) ) ? $p++ : $f++;
	assert_test( 'Has tags array', isset( $r['data']['tags'] ) && is_array( $r['data']['tags'] ) ) ? $p++ : $f++;

	section( 'EDIT OWN MEDIA' );
	$r = rest( 'POST', '/mvs/v1/media/' . $data['own_media'], array( 'title' => 'CLI Test Edit' ) );
	assert_test( 'Edit title', $r['ok'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/media/' . $data['own_media'] );
	assert_test( 'Title persisted', ( $r['data']['title'] ?? '' ) === 'CLI Test Edit' ) ? $p++ : $f++;

	$r = rest( 'POST', '/mvs/v1/media/' . $data['own_media'], array( 'description' => 'Updated description' ) );
	assert_test( 'Edit description', $r['ok'] ) ? $p++ : $f++;

	section( 'PRIVACY CHANGES' );
	foreach ( array( 'private', 'members', 'public' ) as $privacy ) {
		$r = rest( 'POST', '/mvs/v1/media/' . $data['own_media'], array( 'privacy' => $privacy ) );
		assert_test( "Set privacy: $privacy", $r['ok'] ) ? $p++ : $f++;

		$r = rest( 'GET', '/mvs/v1/media/' . $data['own_media'] );
		assert_test( "Privacy reads back: $privacy", ( $r['data']['privacy'] ?? '' ) === $privacy ) ? $p++ : $f++;
	}

	// Reset.
	rest( 'POST', '/mvs/v1/media/' . $data['own_media'], array(
		'title'       => 'og-wp-career-board',
		'description' => '',
		'privacy'     => 'public',
	) );

	section( 'VIEW TRACKING' );
	$r = rest( 'POST', '/mvs/v1/media/' . $data['other_media'] . '/view' );
	assert_test( 'Record view', in_array( $r['status'], array( 200, 201, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'MEDIA STATS' );
	$r = rest( 'GET', '/mvs/v1/media/' . $data['other_media'] . '/stats' );
	assert_test( 'Get media stats', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/me/stats' );
	assert_test( 'Get my stats', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	return array( $p, $f );
}
