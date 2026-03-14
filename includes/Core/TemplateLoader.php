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

		// Profile edit endpoint: /media/edit-profile/
		add_action( 'init', array( $this, 'register_profile_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_profile_query_var' ) );
		add_action( 'template_redirect', array( $this, 'load_profile_template' ) );
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

			// Search filter.
			if ( ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$query->set( 's', sanitize_text_field( wp_unslash( $_GET['s'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}

			// Tag filter.
			if ( ! empty( $_GET['mvs_tag'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$query->set(
					'tax_query',
					array(
						array(
							'taxonomy' => 'mvs_tag',
							'field'    => 'slug',
							'terms'    => sanitize_text_field( wp_unslash( $_GET['mvs_tag'] ) ), // phpcs:ignore WordPress.Security.NonceVerification
						),
					)
				);
			}
		}
	}

	/**
	 * Register rewrite rule for /media/edit-profile/.
	 *
	 * @since 1.1.0
	 */
	public function register_profile_rewrite(): void {
		add_rewrite_rule(
			'^media/edit-profile/?$',
			'index.php?mvs_edit_profile=1',
			'top'
		);
	}

	/**
	 * Add mvs_edit_profile query var.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function add_profile_query_var( array $vars ): array {
		$vars[] = 'mvs_edit_profile';
		return $vars;
	}

	/**
	 * Load the profile edit template when the endpoint is hit.
	 *
	 * @since 1.1.0
	 */
	public function load_profile_template(): void {
		if ( ! get_query_var( 'mvs_edit_profile' ) ) {
			return;
		}

		// Must be logged in.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/media/edit-profile/' ) ) );
			exit;
		}

		$template = self::locate( 'profile-edit.php' );
		if ( $template ) {
			include $template;
			exit;
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
