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
		$full_path = $this->resolve( $path );
		if ( null === $full_path ) {
			LoggerService::warning( 'storage', 'Refused to delete a path outside the MediaVerse storage bases.', array( 'path' => $path ) );
			return false;
		}

		if ( file_exists( $full_path ) ) {
			wp_delete_file( $full_path );
			return ! file_exists( $full_path );
		}

		// Absent at the CORRECT base means already gone, or the bytes live on the
		// cloud tier — delete_everywhere() asks every tier and relies on each
		// no-opping when it holds nothing, so this must stay true. What used to
		// make it a lie was resolving a document path against the wrong base
		// (uploads/wpmediaverse/wpmediaverse-documents/…); resolve() now picks the
		// base by prefix, so "not here" means "not anywhere on this disk".
		return true;
	}

	/**
	 * Path prefixes stored relative to the uploads base directory — NOT under
	 * uploads/wpmediaverse/ — and never on a cloud driver.
	 *
	 * Pro's documents live at `uploads/wpmediaverse-documents/<segment>/…` and
	 * store that uploads-relative path in `file_path`. Free cannot know that
	 * directory (it is Pro's), so Pro declares it here. A declaration rather
	 * than "fall back to uploads/ when the file is missing": media paths are
	 * `YYYY/MM/<file>`, the same shape as WordPress attachments, so a blind
	 * fallback would let a media delete unlink a core attachment.
	 *
	 * @since 2.5.1
	 *
	 * @return string[] Prefixes without surrounding slashes.
	 */
	public static function local_only_prefixes(): array {
		/**
		 * Filter the path prefixes that resolve against the uploads base and
		 * never live on a cloud driver.
		 *
		 * @since 2.5.1
		 *
		 * @param string[] $prefixes e.g. array( 'wpmediaverse-documents' ).
		 */
		$prefixes = (array) apply_filters( 'mvs_local_only_path_prefixes', array() );

		// An empty prefix would reroute EVERY media path to the uploads root.
		return array_values(
			array_filter(
				array_map(
					static function ( $prefix ) {
						return trim( str_replace( '\\', '/', (string) $prefix ), '/' );
					},
					$prefixes
				),
				static function ( $prefix ) {
					return self::is_safe_relative( $prefix );
				}
			)
		);
	}

	/**
	 * The local-only prefix a relative path sits under, '' when none.
	 *
	 * @since 2.5.1
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	public static function local_only_prefix( string $path ): string {
		$path = ltrim( str_replace( '\\', '/', $path ), '/' );
		foreach ( self::local_only_prefixes() as $prefix ) {
			if ( 0 === strpos( $path, $prefix . '/' ) ) {
				return $prefix;
			}
		}
		return '';
	}

	/**
	 * Whether a path is a plain relative path: no `.`/`..` segment, no leading
	 * slash, no drive letter, no NUL byte.
	 *
	 * Not WordPress's validate_file(): that allows a trailing `../` (it guards
	 * template names, not deletes).
	 *
	 * @since 2.5.1
	 *
	 * @param string $path Candidate path.
	 * @return bool
	 */
	private static function is_safe_relative( string $path ): bool {
		return '' !== $path
			&& ! preg_match( '#(^|/)\.{1,2}(/|$)|^/|^[A-Za-z]:|\x00#', str_replace( '\\', '/', $path ) );
	}

	/**
	 * Resolve a relative path to an absolute one under its allowed root.
	 *
	 * This feeds a delete primitive, so it is a trust boundary: the path comes
	 * from a database row, and a `../` in a stored row must not reach unlink().
	 * Rejects traversal, absolute paths and NUL bytes, then — for a file that
	 * exists — requires its real location to sit inside the real root, so a
	 * symlink cannot carry the delete out of the tree either.
	 *
	 * @since 2.5.1
	 *
	 * @param string $path Relative path.
	 * @return string|null Absolute path, or null when the path is refused.
	 */
	private function resolve( string $path ): ?string {
		if ( ! self::is_safe_relative( $path ) ) {
			return null;
		}

		// A local-only path is uploads-relative and must stay inside ITS prefix
		// directory; anything else stays inside uploads/wpmediaverse/.
		$prefix  = self::local_only_prefix( $path );
		$uploads = trailingslashit( wp_upload_dir()['basedir'] );
		$root    = '' === $prefix ? $this->base_dir : $uploads . $prefix;
		$full    = ( '' === $prefix ? $this->base_dir : $uploads ) . $path;

		if ( file_exists( $full ) ) {
			$real      = realpath( $full );
			$real_base = realpath( $root );
			if ( false === $real || false === $real_base || 0 !== strpos( $real, trailingslashit( $real_base ) ) ) {
				return null;
			}
		}

		return $full;
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
	 * Download a stored file to a local destination path.
	 *
	 * For the local driver this is a copy from the canonical local path
	 * (`base_dir + $path`) to the requested `$local_dest`. Same-file
	 * short-circuits as a no-op so callers don't need to know they're on
	 * the local driver.
	 *
	 * @since 1.2.2
	 *
	 * @param string $path       Relative source path.
	 * @param string $local_dest Absolute destination path.
	 * @return bool
	 */
	public function download( string $path, string $local_dest ): bool {
		$source = $this->base_dir . $path;
		if ( ! file_exists( $source ) ) {
			return false;
		}
		// Same-file no-op (when caller is unaware of driver).
		if ( realpath( $source ) === realpath( $local_dest ) ) {
			return true;
		}
		$dest_dir = dirname( $local_dest );
		if ( ! wp_mkdir_p( $dest_dir ) ) {
			return false;
		}
		return copy( $source, $local_dest );
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
