<?php
/**
 * Plugin Name: MediaVerse QA environment switches (dev only)
 * Description: Per-browser QA switches for the contrast crawler, set by cookie so the site stays as it is for everyone else.
 *
 * Copy to wp-content/mu-plugins/ on a dev site only. Cookies (any value "1" unless noted):
 * - mvs_qa_theme=<stylesheet>  render with that theme (e.g. twentytwentyfive).
 * - mvs_qa_stock               the active theme's customizer mods read as empty (stock look).
 * - mvs_qa_free_only           MediaVerse Pro is not loaded.
 * - mvs_qa_pro_modules         every Pro module switch reads as on.
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

$mvs_qa = static function ( string $name ): string {
	return isset( $_COOKIE[ $name ] ) ? sanitize_key( wp_unslash( $_COOKIE[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
};

$mvs_qa_theme = $mvs_qa( 'mvs_qa_theme' );
if ( '' !== $mvs_qa_theme ) {
	$mvs_qa_switch = static function () use ( $mvs_qa_theme ) {
		return $mvs_qa_theme;
	};
	add_filter( 'stylesheet', $mvs_qa_switch, 99 );
	add_filter( 'template', $mvs_qa_switch, 99 );
}

if ( '' !== $mvs_qa( 'mvs_qa_stock' ) ) {
	add_filter(
		'pre_option_theme_mods_' . ( '' !== $mvs_qa_theme ? $mvs_qa_theme : get_option( 'stylesheet' ) ),
		static function () {
			// Not get_theme_mod(): it reads this same option and would recurse.
			return array();
		}
	);
}

if ( '' !== $mvs_qa( 'mvs_qa_free_only' ) ) {
	add_filter(
		'option_active_plugins',
		static function ( $plugins ) {
			return array_values( array_filter( (array) $plugins, static fn( $p ) => 0 !== strpos( $p, 'wpmediaverse-pro/' ) ) );
		}
	);
}

if ( '' !== $mvs_qa( 'mvs_qa_pro_modules' ) ) {
	foreach ( array( 'competitions', 'battles', 'challenges', 'tournaments', 'boosts', 'streaks', 'stories', 'connectors' ) as $mvs_qa_module ) {
		add_filter( "pre_option_mvs_{$mvs_qa_module}_enabled", static fn() => '1' );
	}
}
