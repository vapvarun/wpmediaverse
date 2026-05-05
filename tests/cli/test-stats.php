<?php
/**
 * CLI Tests: Statistics — Media stats, view tracking, user stats, DB cross-checks.
 *
 * Verifies that REST API stats reflect actual data in mvs_media_stats
 * and mvs_reactions tables, and that view tracking increments correctly.
 *
 * @package WPMediaVerse
 */

function run_stats_tests(): array {
	$p    = 0;
	$f    = 0;
	$data = get_test_data();
	global $wpdb;

	$admin_id = $data['admin_id'];
	$mid      = $data['other_media'];

	wp_set_current_user( $admin_id );

	// ═══════════════════════════════════
	// MEDIA STATS STRUCTURE
	// ═══════════════════════════════════
	section( 'MEDIA STATS STRUCTURE' );

	// Ensure stats row exists for this media.
	$has_stats = (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT media_id FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
			$mid
		)
	);
	if ( ! $has_stats ) {
		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->init_stats( $mid );
	}

	$r = rest( 'GET', "/mvs/v1/media/$mid/stats" );
	assert_test( 'GET /media/{id}/stats returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'Stats has views field', isset( $r['data']['views'] ), 'views:' . ( $r['data']['views'] ?? 'missing' ) ) ? $p++ : $f++;
	assert_test( 'Stats has reactions field', isset( $r['data']['reactions'] ), 'reactions:' . ( $r['data']['reactions'] ?? 'missing' ) ) ? $p++ : $f++;
	assert_test( 'Stats has comments field', isset( $r['data']['comments'] ), 'comments:' . ( $r['data']['comments'] ?? 'missing' ) ) ? $p++ : $f++;
	assert_test( 'Stats has downloads field', isset( $r['data']['downloads'] ), 'downloads:' . ( $r['data']['downloads'] ?? 'missing' ) ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// VIEW TRACKING: record + verify increment
	// ═══════════════════════════════════
	section( 'VIEW TRACKING INCREMENT' );

	// Get current view count from DB.
	$views_before = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT views FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
			$mid
		)
	);

	// Record a view via REST.
	$r = rest( 'POST', "/mvs/v1/media/$mid/view" );
	assert_test( 'POST /media/{id}/view returns success', in_array( $r['status'], array( 200, 201, 204 ), true ), 'status:' . $r['status'] ) ? $p++ : $f++;

	// Check DB — view count should have incremented by 1.
	$views_after = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT views FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
			$mid
		)
	);
	assert_test(
		'DB view_count incremented by 1',
		$views_after === $views_before + 1,
		"before:$views_before after:$views_after"
	) ? $p++ : $f++;

	// Also verify a row was inserted into mvs_media_views.
	$view_event = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_views WHERE media_id = %d AND event_type = 'view' ORDER BY created_at DESC LIMIT 1",
			$mid
		)
	);
	assert_test( 'View event recorded in mvs_media_views', (int) $view_event > 0, 'events:' . $view_event ) ? $p++ : $f++;

	// ═══════════════════════════════════
	// STATS <-> DB CROSS-CHECK
	// ═══════════════════════════════════
	section( 'STATS DB CROSS-CHECK' );

	// Get stats via REST.
	$r = rest( 'GET', "/mvs/v1/media/$mid/stats" );
	$api_views     = $r['data']['views'] ?? -1;
	$api_reactions = $r['data']['reactions'] ?? -1;

	// Get stats directly from DB.
	$db_views = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT views FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
			$mid
		)
	);
	assert_test(
		'API view_count matches DB mvs_media_stats.views',
		(int) $api_views === $db_views,
		"api:$api_views db:$db_views"
	) ? $p++ : $f++;

	// Cross-check reaction_count: mvs_media_stats.reactions vs actual rows in mvs_reactions.
	$db_stat_reactions = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT reactions FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
			$mid
		)
	);
	$db_actual_reactions = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_reactions WHERE media_id = %d",
			$mid
		)
	);
	assert_test(
		'API reactions matches mvs_media_stats.reactions',
		(int) $api_reactions === $db_stat_reactions,
		"api:$api_reactions db_stats:$db_stat_reactions"
	) ? $p++ : $f++;

	// Note: stats table may be denormalized and lag behind. Document the difference.
	$reactions_match = $db_stat_reactions === $db_actual_reactions;
	assert_test(
		'mvs_media_stats.reactions matches actual mvs_reactions rows',
		$reactions_match,
		"stats:$db_stat_reactions actual_rows:$db_actual_reactions" . ( ! $reactions_match ? ' (denormalized lag)' : '' )
	) ? $p++ : $f++;

	// ═══════════════════════════════════
	// USER STATS
	// ═══════════════════════════════════
	section( 'USER STATS' );

	$r = rest( 'GET', '/mvs/v1/me/stats' );
	assert_test( 'GET /me/stats returns 200', $r['ok'], 'status:' . $r['status'] ) ? $p++ : $f++;
	assert_test( 'User stats has total_media', isset( $r['data']['total_media'] ), 'total_media:' . ( $r['data']['total_media'] ?? 'missing' ) ) ? $p++ : $f++;
	assert_test( 'User stats has total_views', isset( $r['data']['total_views'] ), 'total_views:' . ( $r['data']['total_views'] ?? 'missing' ) ) ? $p++ : $f++;
	assert_test( 'User stats has total_reactions', isset( $r['data']['total_reactions'] ), 'total_reactions:' . ( $r['data']['total_reactions'] ?? 'missing' ) ) ? $p++ : $f++;

	// Cross-check total_media against actual count in mvs_media_index.
	$db_media_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d AND status = 'publish'",
			$admin_id
		)
	);
	// Note: get_for_user uses INNER JOIN, so only media WITH stats rows are counted.
	// total_media from API may differ from raw mvs_media_index count.
	$api_total_media = (int) ( $r['data']['total_media'] ?? 0 );
	assert_test(
		'User total_media <= DB mvs_media_index count (INNER JOIN vs all)',
		$api_total_media <= $db_media_count,
		"api:$api_total_media db_index:$db_media_count"
	) ? $p++ : $f++;

	return array( $p, $f );
}
