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
}
