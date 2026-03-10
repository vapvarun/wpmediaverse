<?php
/**
 * PHPUnit bootstrap for WPMediaVerse.
 *
 * Loads WordPress from the local site and activates the plugin.
 *
 * @package WPMediaVerse
 */

// Detect the WP root.
$wp_root = dirname( dirname( dirname( dirname( __DIR__ ) ) ) );

if ( ! file_exists( $wp_root . '/wp-load.php' ) ) {
	die( 'Could not find wp-load.php. Expected at: ' . $wp_root . '/wp-load.php' . PHP_EOL );
}

// Define test mode.
define( 'WP_USE_THEMES', false );
define( 'MVS_RUNNING_TESTS', true );
define( 'ABSPATH', $wp_root . '/' );

// Load WP.
require_once $wp_root . '/wp-load.php';

// Ensure our plugin is loaded.
if ( ! defined( 'MVS_VERSION' ) ) {
	die( 'WPMediaVerse plugin is not active.' . PHP_EOL );
}

echo 'WPMediaVerse ' . MVS_VERSION . ' loaded for testing.' . PHP_EOL;
