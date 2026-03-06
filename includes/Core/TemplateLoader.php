<?php
/**
 * Template loader.
 *
 * Allows themes to override plugin templates by placing them in a wpmediaverse/ directory.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles template loading with theme override support.
 */
class TemplateLoader {

	/**
	 * Template directory name in themes.
	 *
	 * @var string
	 */
	const THEME_DIR = 'wpmediaverse';

	/**
	 * Initialize template hooks.
	 */
	public function init(): void {
		add_filter( 'single_template', array( $this, 'load_single_template' ) );
		add_filter( 'archive_template', array( $this, 'load_archive_template' ) );
		add_action( 'pre_get_posts', array( $this, 'unified_explore_query' ) );
	}

	/**
	 * Load single template for MVS post types.
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function load_single_template( string $template ): string {
		$post_type = get_post_type();

		$map = array(
			'mvs_media'      => 'media-single.php',
			'mvs_album'      => 'album.php',
			'mvs_collection' => 'collection.php',
		);

		if ( isset( $map[ $post_type ] ) ) {
			$found = self::locate( $map[ $post_type ] );
			if ( $found ) {
				return $found;
			}
		}

		return $template;
	}

	/**
	 * Load archive template for MVS post types.
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function load_archive_template( string $template ): string {
		if ( is_post_type_archive( 'mvs_media' ) || is_post_type_archive( 'mvs_album' ) ) {
			$found = self::locate( 'explore.php' );
			if ( $found ) {
				return $found;
			}
		}

		return $template;
	}

	/**
	 * Merge mvs_media and mvs_album into the explore archive query.
	 *
	 * @param \WP_Query $query The query object.
	 */
	public function unified_explore_query( $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( $query->is_post_type_archive( 'mvs_media' ) || $query->is_post_type_archive( 'mvs_album' ) ) {
			$query->set( 'post_type', array( 'mvs_media', 'mvs_album' ) );
			$query->set( 'posts_per_page', 18 );
			$query->set( 'orderby', 'date' );
			$query->set( 'order', 'DESC' );
		}
	}

	/**
	 * Locate a template file.
	 *
	 * Checks theme first, then plugin templates directory.
	 *
	 * @param string $template_name Template file name.
	 * @param string $template_path Optional subdirectory within the theme override folder.
	 * @return string Full path to template or empty string if not found.
	 */
	public static function locate( string $template_name, string $template_path = '' ): string {
		$theme_path = self::THEME_DIR . '/';
		if ( $template_path ) {
			$theme_path .= trailingslashit( $template_path );
		}

		// Check child theme first, then parent theme.
		$template = locate_template(
			array(
				$theme_path . $template_name,
			)
		);

		// Fall back to plugin templates.
		if ( ! $template ) {
			$plugin_path = MVS_PLUGIN_DIR . 'templates/';
			if ( $template_path ) {
				$plugin_path .= trailingslashit( $template_path );
			}

			$file = $plugin_path . $template_name;
			if ( file_exists( $file ) ) {
				$template = $file;
			}
		}

		/**
		 * Filters the located template path.
		 *
		 * @param string $template      Full template path.
		 * @param string $template_name Template file name.
		 * @param string $template_path Subdirectory path.
		 */
		return apply_filters( 'mvs_locate_template', $template, $template_name, $template_path );
	}

	/**
	 * Load a template part with data.
	 *
	 * @param string $template_name Template file name.
	 * @param array  $args          Data to pass to the template.
	 */
	public static function get_template( string $template_name, array $args = array() ): void {
		$template = self::locate( $template_name );

		if ( ! $template ) {
			return;
		}

		// Extract args for the template.
		if ( ! empty( $args ) ) {
			extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		}

		include $template;
	}
}
