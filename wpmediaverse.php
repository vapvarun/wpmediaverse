<?php
/**
 * Plugin Name: WPMediaVerse
 * Plugin URI:  https://wbcomdesigns.com/downloads/wpmediaverse/
 * Description: A general-purpose WordPress media platform plugin.
 * Version:     1.0.0
 * Author:      vapvarun, wbcomdesigns
 * Author URI:  https://wbcomdesigns.com/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpmediaverse
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

define( 'MVS_VERSION', '1.0.0' );
define( 'MVS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MVS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MVS_PLUGIN_FILE', __FILE__ );
define( 'MVS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Composer autoloader.
if ( file_exists( MVS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once MVS_PLUGIN_DIR . 'vendor/autoload.php';
}

// Activation.
register_activation_hook( __FILE__, array( 'WPMediaVerse\\Core\\Activator', 'activate' ) );

// Deactivation.
register_deactivation_hook( __FILE__, array( 'WPMediaVerse\\Core\\Deactivator', 'deactivate' ) );

// Bootstrap the plugin.
add_action( 'plugins_loaded', array( 'WPMediaVerse\\Core\\Plugin', 'init' ) );

// WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'mvs', 'WPMediaVerse\\CLI\\Commands' );
	WP_CLI::add_command( 'mvs import-rtmedia', 'WPMediaVerse\\CLI\\ImportRtMedia' );
}
