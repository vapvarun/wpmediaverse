<?php
/**
 * Storage driver interface.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for storage drivers.
 */
interface StorageDriverInterface {

	/**
	 * Store a file.
	 *
	 * @param string $source_path Local file path.
	 * @param string $dest_path   Relative destination path.
	 * @return bool True on success.
	 */
	public function store( string $source_path, string $dest_path ): bool;

	/**
	 * Delete a file.
	 *
	 * @param string $path Relative path.
	 * @return bool True on success.
	 */
	public function delete( string $path ): bool;

	/**
	 * Get the public URL for a file.
	 *
	 * @param string $path Relative path.
	 * @return string Full URL.
	 */
	public function url( string $path ): string;

	/**
	 * Check if a file exists.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	public function exists( string $path ): bool;

	/**
	 * Get the absolute filesystem path for a stored file.
	 *
	 * @since 1.1.0
	 *
	 * @param string $path Relative path.
	 * @return string Absolute file path.
	 */
	public function get_full_path( string $path ): string;

	/**
	 * Download a stored file to a local destination path.
	 *
	 * REQUIRED for two flows:
	 *   1. migrate-storage CLI — copy media between drivers without losing files.
	 *   2. Thumbnail generation in cloud mode — images stored on S3/BunnyCDN
	 *      must be pulled to local temp before wp_get_image_editor can resize.
	 *
	 * Local driver returns true immediately if `$path` and `$local_dest`
	 * resolve to the same file (no-op). Cloud drivers stream the remote
	 * object into `$local_dest`. Returns false on any download failure
	 * (caller is responsible for retries / cleanup of partial writes).
	 *
	 * @since 1.2.2
	 *
	 * @param string $path       Relative path of the source file.
	 * @param string $local_dest Absolute local filesystem path to write to.
	 * @return bool True on success.
	 */
	public function download( string $path, string $local_dest ): bool;
}
