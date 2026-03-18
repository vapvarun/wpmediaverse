<?php
/**
 * Plugin Name: WPMediaVerse
 * Plugin URI:  https://wbcomdesigns.com/downloads/wpmediaverse/
 * Description: Complete media platform for WordPress with albums, social features, AI moderation, and BuddyPress integration.
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

// EDD SL SDK — registers plugin for updates from wbcomdesigns.com (free, no license).
$mvs_sdk_file = MVS_PLUGIN_DIR . 'vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php';
if ( file_exists( $mvs_sdk_file ) ) {
	require_once $mvs_sdk_file;
}
add_action(
	'edd_sl_sdk_registry',
	function ( $registry ) {
		$item_id = 0; // Placeholder — replace with real EDD product ID.
		if ( $item_id ) {
			$registry->register(
				array(
					'id'      => 'wpmediaverse',
					'url'     => 'https://wbcomdesigns.com',
					'item_id' => $item_id,
					'version' => MVS_VERSION,
					'file'    => MVS_PLUGIN_FILE,
				)
			);
		}
	}
);

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
