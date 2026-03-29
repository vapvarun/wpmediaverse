<?php
/**
 * Template loader.
 *
 * Serves media pages via rewrite rules + template_redirect.
 * Albums/Collections still use CPT template filters.
 * Themes can override by placing templates in a wpmediaverse/ directory.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Services\MediaMeta;

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
		// Rewrite rules for media pages (no CPT needed).
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

		// Serve media templates via template_redirect.
		add_action( 'template_redirect', array( $this, 'load_media_templates' ), 5 );

		// Prevent WordPress from redirecting our /media/{slug}/ URLs to
		// attachment file URLs when slug matches a WP attachment post.
		add_filter( 'redirect_canonical', array( $this, 'prevent_attachment_redirect' ), 10, 2 );

		// CPT template filters — albums and collections only.
		add_filter( 'single_template', array( $this, 'load_single_template' ) );
		add_filter( 'archive_template', array( $this, 'load_archive_template' ) );
		add_filter( 'taxonomy_template', array( $this, 'load_taxonomy_template' ) );

		// Body class signal for BuddyX and other themes.
		add_action( 'wp', array( $this, 'maybe_add_mvs_body_class' ) );
	}

	/**
	 * Register rewrite rules for media pages.
	 *
	 * Routes:
	 *   /media/                          — Explore (archive)
	 *   /media/page/{n}/                 — Explore paginated
	 *   /media/{slug}/                   — Single media item (by slug)
	 *   /media/{id}/                     — Single media item (by numeric ID)
	 *   /media/@{username}/              — User profile media
	 *   /media/@{username}/page/{n}/     — User profile paginated
	 *   /media/edit-profile/             — Profile edit
	 */
	public function register_rewrite_rules(): void {
		// Media archive (explore).
		add_rewrite_rule(
			'^media/page/([0-9]+)/?$',
			'index.php?mvs_media_archive=1&paged=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^media/?$',
			'index.php?mvs_media_archive=1',
			'top'
		);

		// Profile edit — must be before slug rule to avoid being caught as a media slug.
		add_rewrite_rule(
			'^media/edit-profile/?$',
			'index.php?mvs_edit_profile=1',
			'top'
		);

		// User profile: /media/@{username}/.
		add_rewrite_rule(
			'^media/@([^/]+)/page/([0-9]+)/?$',
			'index.php?mvs_profile_user=$matches[1]&paged=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^media/@([^/]+)/?$',
			'index.php?mvs_profile_user=$matches[1]',
			'top'
		);

		// Single media by slug (non-numeric) — must be LAST to avoid catching special routes.
		// Exclude reserved sub-paths (battles, challenges, tournaments, leaderboard, etc.)
		// so Pro and third-party plugins can register their own /media/{feature}/ pages.
		$reserved = apply_filters( 'mvs_reserved_media_paths', array(
			'battles', 'challenges', 'tournaments', 'leaderboard',
			'edit-profile', 'page',
		) );
		$exclude  = implode( '|', array_map( 'preg_quote', $reserved ) );
		add_rewrite_rule(
			'^media/(?!' . $exclude . '/)([a-z0-9][a-z0-9\-]*)/?$',
			'index.php?mvs_media_slug=$matches[1]',
			'top'
		);
	}

	/**
	 * Register custom query variables.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'mvs_media_archive';
		$vars[] = 'mvs_media_slug';
		$vars[] = 'mvs_profile_user';
		$vars[] = 'mvs_edit_profile';
		return $vars;
	}

	/**
	 * Serve media templates via template_redirect.
	 *
	 * Handles single media, explore archive, user profile, and profile edit.
	 */
	/**
	 * Prevent WordPress canonical redirect on MVS media URLs.
	 *
	 * When a media slug matches a WP attachment post, WordPress tries to
	 * redirect /media/{slug}/ to the raw file URL. This filter cancels
	 * that redirect when our custom query vars are active.
	 *
	 * @param string $redirect_url The canonical URL WordPress wants to redirect to.
	 * @param string $requested_url The original requested URL.
	 * @return string|false The redirect URL, or false to cancel.
	 */
	public function prevent_attachment_redirect( $redirect_url, $requested_url ) {
		if (
			get_query_var( 'mvs_media_slug' )
			|| get_query_var( 'mvs_media_archive' )
			|| get_query_var( 'mvs_profile_user' )
			|| get_query_var( 'mvs_edit_profile' )
		) {
			return false;
		}
		return $redirect_url;
	}

	public function load_media_templates(): void {
		// Single media by slug.
		$slug = get_query_var( 'mvs_media_slug' );
		if ( $slug ) {
			$this->serve_single_media( $slug );
			return;
		}

		// Media archive (explore).
		if ( get_query_var( 'mvs_media_archive' ) ) {
			$this->serve_media_archive();
			return;
		}

		// User profile.
		$profile_user = get_query_var( 'mvs_profile_user' );
		if ( $profile_user ) {
			$this->serve_user_profile( $profile_user );
			return;
		}

		// Profile edit.
		if ( get_query_var( 'mvs_edit_profile' ) ) {
			$this->serve_profile_edit();
			return;
		}
	}

	/**
	 * Serve a single media item page.
	 *
	 * @param string $slug Media slug (or numeric ID).
	 */
	private function serve_single_media( string $slug ): void {
		global $wpdb;

		// Look up by slug first, then by numeric ID.
		if ( ctype_digit( $slug ) ) {
			$media = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d AND status = 'publish'",
					(int) $slug
				),
				ARRAY_A
			);
		} else {
			$media = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE slug = %s AND status = 'publish'",
					$slug
				),
				ARRAY_A
			);
		}

		if ( ! $media ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		// Check privacy.
		$can_view = $this->can_view_media( $media );
		if ( ! $can_view ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		// Set globals for the template.
		$GLOBALS['mvs_current_media'] = $media;

		// Set page title.
		add_filter(
			'document_title_parts',
			function ( $title ) use ( $media ) {
				$title['title'] = $media['title'] ?: __( 'Media', 'wpmediaverse' );
				return $title;
			}
		);

		$template = self::locate( 'media-single.php' );
		if ( $template ) {
			include $template;
			exit;
		}
	}

	/**
	 * Serve the media archive (explore) page.
	 */
	private function serve_media_archive(): void {
		$GLOBALS['mvs_is_media_archive'] = true;

		add_filter(
			'document_title_parts',
			function ( $title ) {
				$title['title'] = __( 'Explore Media', 'wpmediaverse' );
				return $title;
			}
		);

		$template = self::locate( 'explore.php' );
		if ( $template ) {
			include $template;
			exit;
		}
	}

	/**
	 * Serve a user's media profile page.
	 *
	 * @param string $username Username (without @).
	 */
	private function serve_user_profile( string $username ): void {
		$user = get_user_by( 'login', sanitize_user( $username ) );
		if ( ! $user ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$GLOBALS['mvs_profile_user']     = $user;
		$GLOBALS['mvs_is_media_archive'] = true;

		add_filter(
			'document_title_parts',
			function ( $title ) use ( $user ) {
				/* translators: %s: user display name */
				$title['title'] = sprintf( __( '%s — Media', 'wpmediaverse' ), $user->display_name );
				return $title;
			}
		);

		// Try user-profile.php first (Pro provides Instagram-style version), fall back to explore.php.
		$template = self::locate( 'user-profile.php' );
		if ( ! $template ) {
			$template = self::locate( 'explore.php' );
		}
		if ( $template ) {
			include $template;
			exit;
		}
	}

	/**
	 * Serve the profile edit page.
	 */
	private function serve_profile_edit(): void {
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
	 * Check if the current user can view a media item based on privacy.
	 *
	 * @param array $media Media index row.
	 * @return bool
	 */
	private function can_view_media( array $media ): bool {
		$privacy = $media['privacy'] ?? 'public';

		if ( 'public' === $privacy ) {
			return true;
		}

		$current_user_id = get_current_user_id();

		// Owner can always view.
		if ( $current_user_id && (int) $media['post_author'] === $current_user_id ) {
			return true;
		}

		// Admins/moderators can view everything.
		if ( current_user_can( 'moderate_mvs_media' ) ) {
			return true;
		}

		if ( 'members' === $privacy && $current_user_id ) {
			return true;
		}

		return false;
	}

	/**
	 * Load single template for Album/Collection CPTs (NOT media).
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function load_single_template( string $template ): string {
		$post_type = get_post_type();

		$map = array(
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
	 * Load archive template for Album CPT.
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function load_archive_template( string $template ): string {
		if ( is_post_type_archive( 'mvs_album' ) ) {
			$found = self::locate( 'explore.php' );
			if ( $found ) {
				return $found;
			}
		}

		return $template;
	}

	/**
	 * Load explore template for MVS taxonomy archives (tags, categories).
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function load_taxonomy_template( string $template ): string {
		if ( is_tax( 'mvs_tag' ) || is_tax( 'mvs_category' ) ) {
			$found = self::locate( 'explore.php' );
			if ( $found ) {
				return $found;
			}
		}

		return $template;
	}

	/**
	 * Add mvs-page body class on all WPMediaVerse frontend pages.
	 */
	public function maybe_add_mvs_body_class(): void {
		$is_mvs_page = (
			is_singular( array( 'mvs_album', 'mvs_collection' ) )
			|| is_post_type_archive( 'mvs_album' )
			|| is_tax( 'mvs_tag' )
			|| is_tax( 'mvs_category' )
			|| (bool) get_query_var( 'mvs_edit_profile' )
			|| (bool) get_query_var( 'mvs_profile_user' )
			|| (bool) get_query_var( 'mvs_media_archive' )
			|| (bool) get_query_var( 'mvs_media_slug' )
		);

		// Shortcode pages (e.g. dashboard): match current page ID against any mvs_page_* option.
		if ( ! $is_mvs_page && is_singular() ) {
			$queried_id = (int) get_queried_object_id();
			if ( $queried_id > 0 ) {
				$all_options = wp_load_alloptions();
				foreach ( $all_options as $option_key => $option_value ) {
					if (
						str_starts_with( $option_key, 'mvs_page_' )
						&& (int) $option_value === $queried_id
					) {
						$is_mvs_page = true;
						break;
					}
				}
			}
		}

		if ( ! $is_mvs_page ) {
			return;
		}

		add_filter(
			'body_class',
			static function ( array $classes ): array {
				if ( ! in_array( 'mvs-page', $classes, true ) ) {
					$classes[] = 'mvs-page';
					$classes[] = 'no-sidebar';
				}
				return $classes;
			}
		);
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

		$template = locate_template(
			array(
				$theme_path . $template_name,
			)
		);

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

		if ( ! empty( $args ) ) {
			extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		}

		include $template;
	}
}
