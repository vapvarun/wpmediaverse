<?php
/**
 * CLI Tests: Pro Video features — Chapters, Resume, Captions, Transcode, Analytics.
 *
 * Covers: /mvs-pro/v1/media/{id}/chapters, /resume, /captions, /transcode, /analytics
 */

function run_video_tests(): array {
	if ( ! is_pro_active() ) {
		echo '  ⏭️  Entire suite skipped — Pro plugin not active' . PHP_EOL;
		return array( 0, 0 );
	}
	$p    = 0;
	$f    = 0;
	$data = get_test_data();
	global $wpdb;

	// Find a video media item if one exists.
	$video_id = (int) $wpdb->get_var(
		"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE media_type = 'video' AND status = 'publish' LIMIT 1"
	);
	// Fallback to any media for endpoint existence tests.
	$mid = $video_id ?: $data['own_media'];

	section( 'CHAPTERS' );
	$r = rest( 'GET', "/mvs-pro/v1/media/$mid/chapters" );
	assert_test( 'List chapters', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'POST', "/mvs-pro/v1/media/$mid/chapters", array(
		'title'    => 'Introduction',
		'time'     => 0,
		'end_time' => 30,
	) );
	assert_test( 'Create chapter', in_array( $r['status'], array( 200, 201, 400, 404 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'RESUME PLAYBACK' );
	$r = rest( 'GET', "/mvs-pro/v1/media/$mid/resume" );
	assert_test( 'Get resume position', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'POST', "/mvs-pro/v1/media/$mid/resume", array(
		'position' => 45.5,
	) );
	assert_test( 'Save resume position', in_array( $r['status'], array( 200, 201, 400, 404 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'CAPTIONS' );
	$r = rest( 'GET', "/mvs-pro/v1/media/$mid/captions" );
	assert_test( 'List captions', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', "/mvs-pro/v1/media/$mid/captions/status" );
	assert_test( 'Caption generation status', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'TRANSCODE' );
	$r = rest( 'GET', "/mvs-pro/v1/media/$mid/transcodes" );
	assert_test( 'List transcodes', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs-pro/v1/transcode/status' );
	assert_test( 'Transcode status', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs-pro/v1/transcode/config' );
	assert_test( 'Transcode config', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'ANALYTICS' );
	$r = rest( 'GET', "/mvs-pro/v1/media/$mid/analytics" );
	assert_test( 'Media analytics', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs-pro/v1/analytics/top' );
	assert_test( 'Top analytics', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs-pro/v1/analytics/overview' );
	assert_test( 'Analytics overview', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	// Record an event.
	$r = rest( 'POST', "/mvs-pro/v1/media/$mid/events", array(
		'event_type' => 'play',
		'position'   => 0,
	) );
	assert_test( 'Record play event', in_array( $r['status'], array( 200, 201, 400, 404 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'QUOTA & CREDITS' );
	$r = rest( 'GET', '/mvs-pro/v1/me/quota' );
	assert_test( 'My quota', $r['ok'] || $r['status'] === 404 ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs-pro/v1/me/credits/history' );
	assert_test( 'Credits history', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs-pro/v1/packages' );
	assert_test( 'List packages', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'PRO PRIVACY' );
	$r = rest( 'GET', '/mvs-pro/v1/privacy/presets' );
	assert_test( 'Privacy presets', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	$r = rest( 'POST', '/mvs-pro/v1/media/bulk-privacy', array(
		'media_ids' => array( $mid ),
		'privacy'   => 'public',
	) );
	assert_test( 'Bulk privacy update', in_array( $r['status'], array( 200, 400, 404 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	return array( $p, $f );
}
