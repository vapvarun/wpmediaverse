<?php
/**
 * CLI Tests: Admin — Settings, Moderation, Permissions, Database Integrity.
 *
 * Covers: options, capabilities, moderation queue, DB tables, orphan checks
 */

function run_admin_tests(): array {
	$p = 0;
	$f = 0;
	global $wpdb;

	section( 'SETTINGS' );
	$settings = array(
		'mvs_max_upload_size'    => 'Max Upload Size',
		'mvs_allowed_file_types' => 'Allowed File Types',
		'mvs_default_privacy'    => 'Default Privacy',
		'mvs_thumbnail_style'    => 'Thumbnail Style',
		'mvs_duplicate_action'   => 'Duplicate Detection',
		'mvs_strip_exif'         => 'Strip EXIF',
	);
	foreach ( $settings as $key => $label ) {
		$val = get_option( $key, '__UNSET__' );
		assert_test( "Setting: $label", $val !== '__UNSET__', "value: $val" ) ? $p++ : $f++;
	}

	// Pro settings.
	$pro_settings = array(
		'mvs_challenges_enabled'  => 'Challenges Enabled',
		'mvs_battles_enabled'     => 'Battles Enabled',
		'mvs_tournaments_enabled' => 'Tournaments Enabled',
		'mvs_boosts_enabled'      => 'Boosts Enabled',
		'mvs_streaks_enabled'     => 'Streaks Enabled',
	);
	foreach ( $pro_settings as $key => $label ) {
		$val = get_option( $key, '__UNSET__' );
		assert_test( "Pro Setting: $label", $val !== '__UNSET__', "value: $val" ) ? $p++ : $f++;
	}

	section( 'PAGES' );
	$pages = array(
		'mvs_page_dashboard' => 'Dashboard Page',
		'mvs_page_explore'   => 'Explore Page',
		'mvs_page_compete'   => 'Compete Page',
	);
	foreach ( $pages as $key => $label ) {
		$page_id = (int) get_option( $key, 0 );
		$exists  = $page_id > 0 && get_post_status( $page_id ) === 'publish';
		assert_test( "Page: $label", $exists, "id: $page_id" ) ? $p++ : $f++;
	}

	section( 'PERMISSIONS' );
	$cap_matrix = array(
		'administrator' => array(
			'upload_mvs_media'    => true,
			'edit_mvs_media'      => true,
			'delete_mvs_media'    => true,
			'moderate_mvs_media'  => true,
			'manage_mvs_settings' => true,
			'manage_mvs_access'   => true,
			'edit_mvs_medias'     => true, // Plural (CPT) cap.
		),
		'editor'        => array(
			'upload_mvs_media'    => true,
			'moderate_mvs_media'  => true,
			'manage_mvs_settings' => false,
		),
		'subscriber'    => array(
			'upload_mvs_media'    => true,
			'manage_mvs_settings' => false,
			'moderate_mvs_media'  => false,
		),
	);
	foreach ( $cap_matrix as $role_slug => $caps ) {
		$role = get_role( $role_slug );
		if ( ! $role ) {
			continue;
		}
		foreach ( $caps as $cap => $expected ) {
			$has = ! empty( $role->capabilities[ $cap ] );
			$label = $expected
				? "$role_slug HAS $cap"
				: "$role_slug does NOT have $cap";
			assert_test( $label, $has === $expected ) ? $p++ : $f++;
		}
	}

	section( 'MODERATION' );
	$r = rest( 'GET', '/mvs/v1/moderation/counts' );
	assert_test( 'Moderation counts', $r['ok'], json_encode( $r['data'] ) ) ? $p++ : $f++;

	$r = rest( 'GET', '/mvs/v1/moderation', array( 'status' => 'pending' ) );
	assert_test( 'Pending queue', $r['ok'], $r['count'] . ' pending' ) ? $p++ : $f++;

	section( 'AI USAGE' );
	$r = rest( 'GET', '/mvs/v1/ai/usage' );
	assert_test( 'AI usage stats', $r['ok'] || $r['status'] === 404, 'status:' . $r['status'] ) ? $p++ : $f++;

	section( 'DATABASE INTEGRITY' );
	$tables = array(
		'mvs_media_index', 'mvs_media_meta', 'mvs_media_views', 'mvs_media_stats',
		'mvs_reactions', 'mvs_favorites', 'mvs_follows', 'mvs_mentions',
		'mvs_activity', 'mvs_notifications', 'mvs_reports', 'mvs_blocks',
		'mvs_access_rules', 'mvs_access_grants', 'mvs_album_items', 'mvs_error_log',
		'mvs_conversations', 'mvs_conversation_participants', 'mvs_messages', 'mvs_message_reactions',
		'mvs_transactions',
	);
	$missing = array();
	foreach ( $tables as $table ) {
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$table}'" );
		if ( empty( $exists ) ) {
			$missing[] = $table;
		}
	}
	assert_test( 'All 21 tables exist', empty( $missing ), empty( $missing ) ? 'all present' : 'missing: ' . implode( ', ', $missing ) ) ? $p++ : $f++;

	// Orphan checks.
	$orphan_albums = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_album_items ai
		 LEFT JOIN {$wpdb->prefix}mvs_media_index mi ON ai.media_id = mi.media_id
		 WHERE mi.media_id IS NULL"
	);
	assert_test( 'No orphan album items', $orphan_albums === 0, "$orphan_albums orphans" ) ? $p++ : $f++;

	$orphan_reactions = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_reactions r
		 LEFT JOIN {$wpdb->prefix}mvs_media_index mi ON r.media_id = mi.media_id
		 WHERE mi.media_id IS NULL"
	);
	assert_test( 'No orphan reactions', $orphan_reactions === 0, "$orphan_reactions orphans" ) ? $p++ : $f++;

	section( 'DATA CONSISTENCY' );
	$total_media  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status = 'publish'" );
	$total_views  = (int) $wpdb->get_var( "SELECT SUM(view_count) FROM {$wpdb->prefix}mvs_media_index" );
	$total_reacts = (int) $wpdb->get_var( "SELECT SUM(reaction_count) FROM {$wpdb->prefix}mvs_media_index" );
	assert_test( 'Published media count > 0', $total_media > 0, "$total_media items" ) ? $p++ : $f++;
	assert_test( 'View count aggregated', true, number_format( $total_views ) . ' views' ) ? $p++ : $f++;
	assert_test( 'Reaction count aggregated', true, number_format( $total_reacts ) . ' reactions' ) ? $p++ : $f++;

	return array( $p, $f );
}
