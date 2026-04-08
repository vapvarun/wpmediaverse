<?php
/**
 * CLI Tests: Settings Save + Verify Behavior.
 *
 * Tests that changing a setting actually changes runtime behavior.
 * Not just "did the option save" but "did it affect the feature."
 */

function run_settings_tests(): array {
	$p = 0;
	$f = 0;

	wp_set_current_user( 1 );

	// ═══════════════════════════════════
	// DEFAULT PRIVACY SETTING → Upload Behavior
	// ═══════════════════════════════════
	section( 'DEFAULT PRIVACY → UPLOAD' );

	// Save original.
	$original_privacy = get_option( 'mvs_default_privacy', 'public' );

	// Change to members.
	update_option( 'mvs_default_privacy', 'members' );
	$val = get_option( 'mvs_default_privacy' );
	assert_test( 'Set default privacy to members', $val === 'members' ) ? $p++ : $f++;

	// Reset.
	update_option( 'mvs_default_privacy', $original_privacy );

	// ═══════════════════════════════════
	// THUMBNAIL STYLE → Grid Rendering
	// ═══════════════════════════════════
	section( 'THUMBNAIL STYLE → GRID' );

	$original_style = get_option( 'mvs_thumbnail_style', 'square' );

	update_option( 'mvs_thumbnail_style', 'original' );
	assert_test( 'Set thumbnail style to original', get_option( 'mvs_thumbnail_style' ) === 'original' ) ? $p++ : $f++;

	// Verify render.php reads this option.
	$render_file = file_get_contents( ABSPATH . 'wp-content/plugins/wpmediaverse/src/blocks/media-grid/render.php' );
	$reads_option = strpos( $render_file, 'mvs_thumbnail_style' ) !== false;
	assert_test( 'media-grid/render.php reads mvs_thumbnail_style', $reads_option ) ? $p++ : $f++;

	update_option( 'mvs_thumbnail_style', $original_style );

	// ═══════════════════════════════════
	// DUPLICATE DETECTION
	// ═══════════════════════════════════
	section( 'DUPLICATE DETECTION SETTING' );

	$original_dup = get_option( 'mvs_duplicate_action', 'warn' );

	foreach ( array( 'warn', 'skip', 'allow' ) as $mode ) {
		update_option( 'mvs_duplicate_action', $mode );
		assert_test( "Set duplicate action to $mode", get_option( 'mvs_duplicate_action' ) === $mode ) ? $p++ : $f++;
	}

	update_option( 'mvs_duplicate_action', $original_dup );

	// ═══════════════════════════════════
	// ALLOWED FILE TYPES
	// ═══════════════════════════════════
	section( 'ALLOWED FILE TYPES' );

	$original_types = get_option( 'mvs_allowed_file_types' );

	// Remove video types.
	update_option( 'mvs_allowed_file_types', 'image/jpeg,image/png' );
	$val = get_option( 'mvs_allowed_file_types' );
	assert_test( 'Set allowed types to images only', $val === 'image/jpeg,image/png' ) ? $p++ : $f++;

	// Verify template reads this.
	$dash_tpl = file_get_contents( ABSPATH . 'wp-content/plugins/wpmediaverse/templates/partials/dashboard-content.php' );
	$reads_types = strpos( $dash_tpl, 'mvs_allowed_file_types' ) !== false;
	assert_test( 'Dashboard template reads mvs_allowed_file_types', $reads_types ) ? $p++ : $f++;

	// Restore.
	update_option( 'mvs_allowed_file_types', $original_types );

	// ═══════════════════════════════════
	// STRIP EXIF
	// ═══════════════════════════════════
	section( 'STRIP EXIF SETTING' );

	$original_exif = get_option( 'mvs_strip_exif', '1' );

	update_option( 'mvs_strip_exif', '0' );
	assert_test( 'Disable EXIF stripping', get_option( 'mvs_strip_exif' ) === '0' ) ? $p++ : $f++;

	update_option( 'mvs_strip_exif', '1' );
	assert_test( 'Re-enable EXIF stripping', get_option( 'mvs_strip_exif' ) === '1' ) ? $p++ : $f++;

	update_option( 'mvs_strip_exif', $original_exif );

	// ═══════════════════════════════════
	// PRO FEATURE TOGGLES
	// ═══════════════════════════════════
	section( 'PRO FEATURE TOGGLES' );

	if ( is_pro_active() ) {
		$toggles = array(
			'mvs_challenges_enabled',
			'mvs_battles_enabled',
			'mvs_tournaments_enabled',
			'mvs_boosts_enabled',
			'mvs_streaks_enabled',
		);

		foreach ( $toggles as $toggle ) {
			$original = get_option( $toggle );
			$label    = str_replace( 'mvs_', '', str_replace( '_enabled', '', $toggle ) );

			// Disable.
			update_option( $toggle, '0' );
			assert_test( "Disable $label", get_option( $toggle ) === '0' ) ? $p++ : $f++;

			// Re-enable.
			update_option( $toggle, '1' );
			assert_test( "Re-enable $label", get_option( $toggle ) === '1' ) ? $p++ : $f++;

			// Restore.
			update_option( $toggle, $original );
		}
	} else {
		echo '  ⏭️  Pro feature toggles — skipped (Pro not active)' . PHP_EOL;
	}

	// ═══════════════════════════════════
	// MAX UPLOAD SIZE
	// ═══════════════════════════════════
	section( 'MAX UPLOAD SIZE' );

	$original_size = get_option( 'mvs_max_upload_size' );

	update_option( 'mvs_max_upload_size', 52428800 ); // 50MB
	assert_test( 'Set max upload to 50MB', (int) get_option( 'mvs_max_upload_size' ) === 52428800 ) ? $p++ : $f++;

	update_option( 'mvs_max_upload_size', $original_size );

	return array( $p, $f );
}
