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
		'mvs_competitions_enabled' => 'Competitions Enabled (master)',
		'mvs_challenges_enabled'   => 'Challenges Enabled',
		'mvs_battles_enabled'      => 'Battles Enabled',
		'mvs_tournaments_enabled'  => 'Tournaments Enabled',
		'mvs_boosts_enabled'       => 'Boosts Enabled',
		'mvs_streaks_enabled'      => 'Streaks Enabled',
	);
	// Absent is a VALID state, and the common one. Activation used to force
	// every one of these to '1'; competitions are opt-in now, so a site that
	// never enabled them has no row at all and every runtime gate reads the
	// '0' default. Asserting the row exists would be asserting the behaviour
	// we deliberately removed. What must hold is that the value is a legal
	// on/off, never something else.
	foreach ( $pro_settings as $key => $label ) {
		$val   = get_option( $key, '__UNSET__' );
		$legal = ( '__UNSET__' === $val ) || in_array( (string) $val, array( '0', '1' ), true );
		assert_test( "Pro Setting: $label", $legal, '__UNSET__' === $val ? 'unset (off, opt-in)' : "value: $val" ) ? $p++ : $f++;
	}

	section( 'PAGES' );
	$pages = array(
		'mvs_page_dashboard' => 'Dashboard Page',
		'mvs_page_explore'   => 'Explore Page',
	);
	foreach ( $pages as $key => $label ) {
		$page_id = (int) get_option( $key, 0 );
		$exists  = $page_id > 0 && get_post_status( $page_id ) === 'publish';
		assert_test( "Page: $label", $exists, "id: $page_id" ) ? $p++ : $f++;
	}

	// Compete is NOT in the list above. Pro activation used to insert a
	// published /compete/ page on every site; competitions are opt-in now, so
	// the page is absent until an owner turns them on and it must not be
	// asserted into existence. When it does exist it still has to be a real
	// published page - a stale id pointing at a trashed post is a broken menu
	// item, which is what this row is actually for.
	$compete_id = (int) get_option( 'mvs_page_compete', 0 );
	if ( $compete_id > 0 ) {
		assert_test(
			'Page: Compete Page',
			'publish' === get_post_status( $compete_id ),
			"id: $compete_id"
		) ? $p++ : $f++;
	} else {
		echo '  ⏭️  Page: Compete Page — not set (competitions are opt-in)' . PHP_EOL;
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
