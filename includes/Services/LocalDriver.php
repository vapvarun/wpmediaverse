<?php
/**
 * Local filesystem storage driver.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Local filesystem storage driver.
 */
class LocalDriver implements StorageDriverInterface {

	/**
	 * Base directory for stored files.
	 *
	 * @var string
	 */
	private $base_dir;

	/**
	 * Base URL for stored files.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$upload_dir     = wp_upload_dir();
		$this->base_dir = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/';
		$base_url       = trailingslashit( $upload_dir['baseurl'] ) . 'wpmediaverse/';
		// Ensure URL scheme matches site URL (fixes mixed content on HTTPS sites).
		$this->base_url = set_url_scheme( $base_url );
	}

	/**
	 * Store a file at the given destination path.
	 *
	 * @param string $source_path Source file path.
	 * @param string $dest_path   Destination relative path.
	 * @return bool
	 */
	public function store( string $source_path, string $dest_path ): bool {
		$full_path = $this->base_dir . $dest_path;
		$dir       = dirname( $full_path );

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Ensure base directory has protection files.
		$this->ensure_protection_files();

		return copy( $source_path, $full_path );
	}

	/**
	 * Delete a file at the given path.
	 *
	 * @param string $path Relative file path.
	 * @return bool
	 */
	public function delete( string $path ): bool {
		$full_path = $this->base_dir . $path;
		if ( file_exists( $full_path ) ) {
			wp_delete_file( $full_path );
			return ! file_exists( $full_path );
		}
		return true;
	}

	/**
	 * Get the public URL for a file.
	 *
	 * @param string $path Relative file path.
	 * @return string
	 */
	public function url( string $path ): string {
		return $this->base_url . $path;
	}

	/**
	 * Check if a file exists at the given path.
	 *
	 * @param string $path Relative file path.
	 * @return bool
	 */
	public function exists( string $path ): bool {
		return file_exists( $this->base_dir . $path );
	}

	/**
	 * Get the absolute filesystem path for a stored file.
	 *
	 * @since 1.1.0
	 *
	 * @param string $path Relative path.
	 * @return string Absolute file path.
	 */
	public function get_full_path( string $path ): string {
		return $this->base_dir . $path;
	}

	/**
	 * Ensure the base upload directory has .htaccess and index.php protection.
	 */
	private function ensure_protection_files(): void {
		$htaccess = $this->base_dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}

		$index = $this->base_dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}
}
