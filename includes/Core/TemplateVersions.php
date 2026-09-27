<?php
/**
 * Which theme copies of our templates are out of date.
 *
 * Every template a theme may override carries `@version` in its header. It
 * changes only when that template's markup or variables change, not every
 * release, so an owner is told about a stale copy exactly when it matters.
 * A theme copy with an older version, or none (copied before 2.6.0), is
 * outdated: it can keep rendering, but it misses fixes and may read request
 * data the plugin no longer sends (Basecamp 10344452624 / 10344471983).
 *
 * @package WPMediaVerse
 * @since   2.6.0
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Compares theme overrides against the plugin templates they replace.
 */
class TemplateVersions {

	/**
	 * Template folders a theme can override, as [ path => plugin name ].
	 *
	 * Pro adds its own folder through the `mvs_template_roots` filter, so
	 * Free never needs to know where Pro lives.
	 *
	 * @return array<string, string>
	 */
	public static function roots(): array {
		/**
		 * Template folders whose files a theme can override.
		 *
		 * @since 2.6.0
		 *
		 * @param array<string, string> $roots Absolute folder path (with trailing slash) => plugin name.
		 */
		return (array) apply_filters( 'mvs_template_roots', array( MVS_PLUGIN_DIR . 'templates/' => 'WP MediaVerse' ) );
	}

	/**
	 * The `@version` in a template's header, or '' when it has none.
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	public static function version_of( string $file ): string {
		if ( ! is_readable( $file ) ) {
			return '';
		}

		// The header is at the top; no need to read a whole template.
		$head = (string) file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local template file.

		return preg_match( '/^\s*\*\s*@version\s+([0-9][0-9A-Za-z.\-]*)/m', $head, $match ) ? $match[1] : '';
	}

	/**
	 * The theme folders WordPress would load our overrides from, child first.
	 *
	 * @return string[]
	 */
	public static function theme_dirs(): array {
		$dirs = array();
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $theme ) {
			$dir = trailingslashit( $theme ) . TemplateLoader::THEME_DIR . '/';
			if ( is_dir( $dir ) ) {
				$dirs[] = $dir;
			}
		}

		return $dirs;
	}

	/**
	 * Every theme copy that is older than the template it replaces.
	 *
	 * Only files a plugin template versions are compared: a theme file with
	 * no plugin counterpart is the theme's own business. When a child and a
	 * parent theme both override one file, only the child's copy loads, so
	 * only the child's is reported.
	 *
	 * @param string[]|null $theme_dirs Theme override folders; null = the active theme's.
	 * @return array<int, array{file:string, theme_file:string, theme_version:string, plugin_version:string, plugin:string}>
	 */
	public static function outdated( ?array $theme_dirs = null ): array {
		$roots = self::roots();
		$seen  = array();
		$rows  = array();

		foreach ( null === $theme_dirs ? self::theme_dirs() : $theme_dirs as $dir ) {
			$dir = trailingslashit( wp_normalize_path( $dir ) );
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $theme_file ) {
				if ( 'php' !== $theme_file->getExtension() ) {
					continue;
				}

				$rel = substr( wp_normalize_path( $theme_file->getPathname() ), strlen( $dir ) );
				if ( isset( $seen[ $rel ] ) ) {
					continue;
				}
				$seen[ $rel ] = true;

				foreach ( $roots as $root => $plugin ) {
					$plugin_version = self::version_of( trailingslashit( $root ) . $rel );
					if ( '' === $plugin_version ) {
						continue;
					}

					$theme_version = self::version_of( $theme_file->getPathname() );
					if ( '' === $theme_version || version_compare( $theme_version, $plugin_version, '<' ) ) {
						$rows[] = array(
							'file'           => $rel,
							'theme_file'     => wp_normalize_path( $theme_file->getPathname() ),
							'theme_version'  => $theme_version,
							'plugin_version' => $plugin_version,
							'plugin'         => (string) $plugin,
						);
					}
					break;
				}
			}
		}

		return $rows;
	}
}
