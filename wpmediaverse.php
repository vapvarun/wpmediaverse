<?php
/**
 * Plugin Name: WPMediaVerse
 * Plugin URI:  https://store.wbcomdesigns.com/wpmediaverse/
 * Description: Complete media platform for WordPress with albums, social features, AI moderation, and BuddyPress integration.
 * Version:     2.4.0
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

define( 'MVS_VERSION', '2.4.0' );
// Kept in step with the `Requires PHP` header above by `BootGuardTest`. WordPress
// enforces that header at activation and update time; this constant is what the
// runtime guard below uses, for the case the header cannot cover — a host moving
// an already-active site to an older PHP.
define( 'MVS_MIN_PHP', '7.4' );
// Minimum Pro version compatible with this free build. Free and Pro release in
// lockstep; bump this together with MVS_VERSION. Free works standalone, so an
// older Pro is only warned (not gated) — Pro carries its own hard requirement
// on the free plugin in the other direction.
define( 'MVS_MIN_PRO', '2.1.0' );
define( 'MVS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MVS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MVS_PLUGIN_FILE', __FILE__ );
define( 'MVS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Explain why the plugin did not boot, once, on the admin screens.
 *
 * Everything in here runs BEFORE the autoloader, so it may not touch a single
 * line of this plugin's own code: nothing namespaced, nothing from includes/.
 * That constraint is the reason this is a plain function in the entry file
 * rather than a class, and the reason it is duplicated in Pro rather than
 * shared — the shared copy would live behind the autoloader that is missing.
 *
 * @since 2.4.0
 *
 * @param string $reason Which failure to describe: 'php' or 'autoload'.
 * @return void
 */
function mvs_boot_failure( $reason ) {
	add_action(
		'admin_notices',
		function () use ( $reason ) {
			// Only the person who can act on it. A subscriber cannot re-upload
			// the plugin or change the site's PHP version.
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			if ( 'php' === $reason ) {
				$message = sprintf(
					/* translators: 1: required PHP version, 2: PHP version this site runs. */
					esc_html__( 'WPMediaVerse needs PHP %1$s or later, and this site is running PHP %2$s. The plugin has stopped itself rather than take the site down. Ask your host to update PHP — your media and settings are untouched.', 'wpmediaverse' ),
					esc_html( MVS_MIN_PHP ),
					esc_html( PHP_VERSION )
				);
			} else {
				$message = esc_html__( 'WPMediaVerse could not load: its vendor folder is missing, which usually means an upload or update did not finish. Re-install the plugin from a complete copy. Nothing has been deleted — your media, settings and members are untouched.', 'wpmediaverse' );
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				$message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at assignment above; sprintf() of escaped parts.
			);
		}
	);
}

// THE TWO WAYS THIS PLUGIN CAN FAIL TO BOOT AT ALL. Both stop the file with
// `return` instead of carrying on, because every line below this point names a
// class the autoloader provides.
//
// This used to be soft — `file_exists()` skipped the require and execution
// continued — which meant `plugins_loaded` called `WPMediaVerse\Core\Plugin::init`
// when that class did not exist. Replicated 2026-08-13 by moving vendor/ aside:
// HTTP 500 on the front page, HTTP 500 on wp-admin/plugins.php (the screen an
// owner would use to switch it off), and WP-CLI refusing to run at all
// ("Callable WPMediaVerse\CLI\Commands does not exist"). Every recovery route
// through WordPress was closed; the only way back was FTP.
//
// A plugin that cannot run must not stop the site from running. The realistic
// way to arrive here is a build or transfer that drops vendor/ — a partial FTP
// upload, a scanner quarantining a file, or a release zip built with an ignore
// rule that excludes it.
if ( version_compare( PHP_VERSION, MVS_MIN_PHP, '<' ) ) {
	mvs_boot_failure( 'php' );
	return;
}

// Composer autoloader.
if ( ! file_exists( MVS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	mvs_boot_failure( 'autoload' );
	return;
}

require_once MVS_PLUGIN_DIR . 'vendor/autoload.php';

// EDD SL SDK — free plugin auto-updates with preset key.
$mvs_sdk_file = MVS_PLUGIN_DIR . 'vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php';
if ( file_exists( $mvs_sdk_file ) ) {
	require_once $mvs_sdk_file;
}
add_action(
	'edd_sl_sdk_registry',
	function ( $registry ) {
		$registry->register(
			array(
				'id'      => 'wpmediaverse',
				'url'     => 'https://wbcomdesigns.com',
				'item_id' => 1660826,
				'version' => MVS_VERSION,
				'file'    => MVS_PLUGIN_FILE,
				'license' => 'wbcomfree7a9c2e5d1f8b4c6a3e0d9b2f7c1a8e44',
			)
		);
	}
);

// Lockstep compatibility check: warn when an OLDER WPMediaVerse Pro is active
// next to this newer free build. Free keeps working; the notice nudges the admin
// to update Pro so the cross-plugin APIs line up. Pro carries the reciprocal
// hard requirement on the free plugin (it gates instead of warns).
add_action(
	'admin_notices',
	function () {
		if ( ! defined( 'MVS_PRO_VERSION' ) ) {
			return;
		}
		// Strip any pre-release suffix (e.g. "1.4.0-dev") so dev builds and patch
		// releases on the same minor line are treated as compatible.
		if ( ! version_compare( strtok( MVS_PRO_VERSION, '-' ), MVS_MIN_PRO, '<' ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			sprintf(
				/* translators: 1: free version, 2: Pro version present, 3: required Pro version. */
				esc_html__( 'WPMediaVerse %1$s works best with WPMediaVerse Pro %3$s or later (you have Pro %2$s). Please update WPMediaVerse Pro for full compatibility.', 'wpmediaverse' ),
				esc_html( MVS_VERSION ),
				esc_html( MVS_PRO_VERSION ),
				esc_html( MVS_MIN_PRO )
			)
		);
	}
);

// Auto-activate the preset license key on first load so downloads work.
add_action(
	'admin_init',
	function () {
		$preset_key = 'wbcomfree7a9c2e5d1f8b4c6a3e0d9b2f7c1a8e44';
		$option     = 'wpmediaverse_license_key';
		$activated  = 'wpmediaverse_preset_activated';

		if ( get_option( $activated ) ) {
			return;
		}

		update_option( $option, $preset_key, false );

		$response = wp_remote_post(
			'https://wbcomdesigns.com',
			array(
				'timeout' => 15,
				'body'    => array(
					'edd_action' => 'activate_license',
					'license'    => $preset_key,
					'item_id'    => 1660826,
					'url'        => home_url(),
				),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 'valid' === ( $body['license'] ?? '' ) ) {
				update_option( $activated, 1, false );
				update_option(
					$option . '_allow_tracking',
					array(
						'allowed'   => true,
						'timestamp' => time(),
					),
					false
				);
			}
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
	WP_CLI::add_command( 'mvs cert', new \WPMediaVerse\Cert\CertCommand() );
}
