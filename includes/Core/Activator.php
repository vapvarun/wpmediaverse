<?php
/**
 * Plugin activator.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Capabilities\MediaCapabilities;

/**
 * Plugin activation handler.
 */
class Activator {

	/**
	 * Run activation routines.
	 */
	public static function activate(): void {
		// Run database migrations.
		$migrator = new Migrator();
		$migrator->run();

		// Add capabilities to roles.
		MediaCapabilities::add_caps();

		// Set default options.
		self::set_defaults();

		// Protect upload directory from direct access.
		self::protect_upload_directory();

		// Flush rewrite rules on next load.
		set_transient( 'mvs_flush_rewrite', true );
	}

	/**
	 * Add .htaccess and index.php to the upload directory to prevent direct browsing.
	 */
	private static function protect_upload_directory(): void {
		$upload_dir = wp_upload_dir();
		$mvs_dir    = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/';

		if ( ! wp_mkdir_p( $mvs_dir ) ) {
			return;
		}

		$htaccess = $mvs_dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}

		$index = $mvs_dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Set default option values.
	 */
	private static function set_defaults(): void {
		$defaults = array(
			'mvs_max_upload_size'    => 104857600, // 100MB in bytes.
			'mvs_allowed_file_types' => 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg',
			'mvs_default_privacy'    => 'public',
			'mvs_duplicate_action'   => 'warn',
			'mvs_strip_exif'         => true,
			'mvs_storage_driver'     => 'local',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}
}
