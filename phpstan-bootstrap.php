<?php
/**
 * PHPStan bootstrap - define the plugin constants the analyser can't discover
 * from wpmediaverse.php. scanFiles collects classes and functions, but not the
 * define() calls, so every MVS_PLUGIN_* use was pinned in phpstan-baseline.neon
 * with per-file counts that broke on each new occurrence. Same fix as Pro.
 *
 * Values are placeholders - PHPStan only needs the symbols to exist.
 *
 * @package WPMediaVerse
 */

foreach ( array( 'MVS_PLUGIN_DIR', 'MVS_PLUGIN_URL', 'MVS_PLUGIN_FILE', 'MVS_PLUGIN_BASENAME', 'MVS_LICENCE_HOST' ) as $mvs_const ) {
	if ( ! defined( $mvs_const ) ) {
		define( $mvs_const, '' );
	}
}
