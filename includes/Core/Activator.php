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

		// Flush rewrite rules on next load.
		set_transient( 'mvs_flush_rewrite', true );
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
