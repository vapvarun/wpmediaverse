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

use WPMediaVerse\Repository\MediaRepository;

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

		// Populate the client-nav deny-list with routes that require a full-load.
		add_filter( 'wpmediaverse_client_nav_deny_paths', array( $this, 'add_deny_paths' ), 10, 1 );
	}

	/**
	 * Append Free plugin routes to the client-nav deny-list.
	 *
	 * These are URL path prefixes. The navigate action prefix-matches
	 * `link.pathname` against this list and falls back to a full browser
	 * navigation for matched paths (needed for routes that use polling,
	 * typeahead, or file-upload on first load).
	 *
	 * Merged into the incoming $paths array; never overwrites it.
	 * Pro appends competition routes on top via a higher-priority callback.
	 *
	 * @param string[] $paths Existing deny-path prefixes.
	 * @return string[]
	 */
	public function add_deny_paths( array $paths ): array {
		// Fixed rewrite-based routes that must always full-load.
		$fixed = array(
			'/messages/',       // Messaging: polling, typeahead, file-upload.
			'/media/edit-profile/', // Profile-edit composer form.
			'/album/',          // Album CPT single (rewrite slug = 'album').
		);

		// Mapped-page routes: resolve the WP page permalink to a path so
		// admin-renamed slugs stay correct without touching the deny-list.
		$mapped_options = array(
			'mvs_page_upload',
			'mvs_page_dashboard',
		);

		$mapped = array();
		foreach ( $mapped_options as $option ) {
			$page_id = (int) get_option( $option, 0 );
			if ( $page_id <= 0 ) {
				continue;
			}
			$permalink = get_permalink( $page_id );
			if ( ! $permalink ) {
				continue;
			}
			$path = wp_parse_url( $permalink, PHP_URL_PATH );
			if ( $path && '/' !== $path ) {
				$mapped[] = $path;
			}
		}

		$merged = array_merge( $paths, $fixed, $mapped );
		// Deduplicate and remove empties.
		$merged = array_values( array_unique( array_filter( $merged ) ) );

		return $merged;
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
		$reserved = apply_filters(
			'mvs_reserved_media_paths',
			array(
				'battles',
				'challenges',
				'tournaments',
				'leaderboard',
				'edit-profile',
				'page',
			)
		);
		$exclude  = implode( '|', array_map( static fn( $p ) => preg_quote( $p, '#' ), $reserved ) );
		add_rewrite_rule(
			'^media/(?!' . $exclude . '/)([a-z0-9][a-z0-9_\-]*)/?$',
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

		// Media archive (explore) — via rewrite rule or mapped page.
		$explore_page_id = (int) get_option( 'mvs_page_explore', 0 );
		if ( get_query_var( 'mvs_media_archive' ) || ( $explore_page_id && is_page( $explore_page_id ) ) ) {
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
	 * Render a full MVS virtual page and stop.
	 *
	 * Our virtual pages (explore archive, profile, single media, profile edit)
	 * render real content, so they must return HTTP 200 even when WordPress's
	 * main query is empty - e.g. /media/page/2/ where paged > 1 leaves the core
	 * query with no posts and WP would otherwise emit a soft-404. A soft-404
	 * deindexes paginated archives and makes page caches / CDNs skip the page.
	 *
	 * @param string $template Absolute path to the located template file.
	 */
	private function render_template( string $template ): void {
		status_header( 200 );
		if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ) {
			$GLOBALS['wp_query']->is_404 = false;
		}
		include $template;
		exit;
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
			self::render_branded_404( 'media', $slug );
			return;
		}

		/**
		 * Let a host redirect single-media URLs somewhere else instead of rendering
		 * the standalone page. BuddyNext uses this to send /media/{slug}/ to the
		 * activity the media was posted in, so media lives in the community feed
		 * rather than as a separate public page. Return '' (the default, and always
		 * the case for standalone WPMediaVerse) to render the native single page.
		 *
		 * @param string $redirect_url Target URL, or '' to render the native page.
		 * @param int    $media_id     The media item's id.
		 * @param string $slug         The requested slug (or numeric id).
		 */
		$redirect_url = (string) apply_filters( 'mvs_single_media_redirect', '', (int) $media['media_id'], (string) $slug );
		if ( '' !== $redirect_url ) {
			wp_safe_redirect( $redirect_url, 301 );
			exit;
		}

		// Check privacy. A denied viewer gets the SAME single-media template and
		// container — the template swaps the media itself for a "log in to view"
		// message in the media slot and hides the social + comment sections. No
		// redirect, no separate 404/gate page. The file URL, poster, OG image and
		// download are never exposed to a denied viewer (see mvs_media_can_view;
		// MediaUrl::file()/get_thumb_url() already return '' when the gate denies).
		$can_view = $this->can_view_media( $media );

		// Set globals for the template.
		$GLOBALS['mvs_current_media']  = $media;
		$GLOBALS['mvs_media_can_view'] = $can_view;

		// Denied viewers: mark the page non-indexable and forbidden, but keep
		// rendering the media container so the message lands where the media
		// would have.
		if ( ! $can_view ) {
			status_header( 403 );
			nocache_headers();
			add_action(
				'wp_head',
				static function () {
					echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
				},
				1
			);
		}

		// Set page title.
		add_filter(
			'document_title_parts',
			function ( $title ) use ( $media ) {
				$title['title'] = $media['title'] ?: __( 'Media', 'wpmediaverse' );
				return $title;
			}
		);

		// Open Graph + Twitter Card meta — when this URL is shared on
		// Facebook / X / LinkedIn / Slack / etc., the platform scrapes
		// these tags to render a preview card with image + title +
		// description. Skipped entirely for denied viewers so a shared link
		// never leaks the poster or description of gated media.
		if ( $can_view ) {
			add_action(
				'wp_head',
				function () use ( $media ) {
					$title       = $media['title'] ? $media['title'] : __( 'Media', 'wpmediaverse' );
					$description = isset( $media['description'] ) ? wp_strip_all_tags( $media['description'] ) : '';
					if ( strlen( $description ) > 280 ) {
						$description = substr( $description, 0, 277 ) . '…';
					}

					// Use a thumbnail (signed if needed) so private-but-shareable
					// items still get a preview image. The signed_urls service
					// is registered unconditionally on plugin init so this
					// returns a usable instance in any normal request, but the
					// guard stays for the rare edge case where service
					// resolution failed (e.g. plugin half-activated).
					$signed    = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
					$thumb_url = $signed ? $signed->generate_thumbnail( (int) $media['media_id'], 0, 'large', 0, true ) : '';

					$permalink = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( (int) $media['media_id'] );
					$site_name = get_bloginfo( 'name' );
					$is_video  = isset( $media['media_type'] ) && 'video' === $media['media_type'];
					$is_audio  = isset( $media['media_type'] ) && 'audio' === $media['media_type'];

					echo "\n<!-- WPMediaVerse Open Graph -->\n";
					echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
					echo '<meta property="og:type" content="' . esc_attr( $is_video ? 'video.other' : ( $is_audio ? 'music.song' : 'article' ) ) . '" />' . "\n";
					echo '<meta property="og:url" content="' . esc_url( $permalink ) . '" />' . "\n";
					echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";
					if ( $description ) {
						echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
					}
					if ( $thumb_url ) {
						echo '<meta property="og:image" content="' . esc_url( $thumb_url ) . '" />' . "\n";
						echo '<meta property="og:image:alt" content="' . esc_attr( $title ) . '" />' . "\n";
					}

					echo '<meta name="twitter:card" content="' . esc_attr( $thumb_url ? 'summary_large_image' : 'summary' ) . '" />' . "\n";
					echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
					if ( $description ) {
						echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
					}
					if ( $thumb_url ) {
						echo '<meta name="twitter:image" content="' . esc_url( $thumb_url ) . '" />' . "\n";
					}
					echo "<!-- /WPMediaVerse Open Graph -->\n";
				},
				5 // run early so themes / SEO plugins can override below.
			);
		}

		$template = self::locate( 'media-single.php' );
		if ( $template ) {
			$this->render_template( $template );
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
			$this->render_template( $template );
		}
	}

	/**
	 * Serve a user's media profile page.
	 *
	 * @param string $username Username (without @).
	 */
	private function serve_user_profile( string $username ): void {
		// Canonical: new links are nicename-based (see TemplateHelpers::
		// get_user_profile_url()). sanitize_title() matches how WP derives/stores
		// user_nicename, so this is an exact-match lookup, not a fuzzy one.
		$user = get_user_by( 'slug', sanitize_title( $username ) );

		// Back-compat: pre-fix URLs (bookmarks, shared links, search-engine index,
		// emails already sent) were login-based. Keep resolving them so existing
		// links never 404. sanitize_user() mirrors the original lookup exactly.
		if ( ! $user ) {
			$user = get_user_by( 'login', sanitize_user( $username, true ) );
		}

		if ( ! $user ) {
			self::render_branded_404( 'profile', $username );
			return;
		}

		$GLOBALS['mvs_profile_user']     = $user;
		$GLOBALS['mvs_is_media_archive'] = true;

		add_filter(
			'document_title_parts',
			function ( $title ) use ( $user ) {
				/* translators: %s: user display name */
				$title['title'] = sprintf( __( '%s: Media', 'wpmediaverse' ), $user->display_name );
				return $title;
			}
		);

		// Try user-profile.php first (Pro provides Instagram-style version), fall back to explore.php.
		$template = self::locate( 'user-profile.php' );
		if ( ! $template ) {
			$template = self::locate( 'explore.php' );
		}
		if ( $template ) {
			$this->render_template( $template );
		}
	}

	/**
	 * Serve the profile edit page.
	 */
	private function serve_profile_edit(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( \WPMediaVerse\Core\TemplateHelpers::login_url( home_url( '/media/edit-profile/' ) ) );
			exit;
		}

		$template = self::locate( 'profile-edit.php' );
		if ( $template ) {
			$this->render_template( $template );
		}
	}

	/**
	 * Check if the current user can view a media item based on privacy.
	 *
	 * @param array $media Media index row.
	 * @return bool
	 */
	private function can_view_media( array $media ): bool {
		$media_id = (int) ( $media['media_id'] ?? 0 );
		if ( ! $media_id ) {
			return false;
		}

		// Delegate to the single canonical gate. This local check previously
		// handled only public/owner/moderator/members and returned false for
		// friends/group/custom — so a legitimate friend or group member was
		// wrongly 404'd on a friends-only/group media page (audit 2026-06-04),
		// and it was one of several privacy reimplementations. PrivacyService
		// covers every level (friends via friendship, group via membership,
		// custom via grants) in one place.
		return \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( $media_id, get_current_user_id() );
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
	 * Load archive template for Album and Collection CPTs.
	 *
	 * Both CPTs share the dedicated cpt-archive.php template which queries
	 * the correct post_type, paginates, and renders CPT-specific cards.
	 * explore.php remains the /media/ feed and is no longer used for archives.
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function load_archive_template( string $template ): string {
		$map = array(
			'mvs_album'      => 'cpt-archive.php',
			'mvs_collection' => 'cpt-archive.php',
		);

		foreach ( $map as $post_type => $tpl_file ) {
			if ( is_post_type_archive( $post_type ) ) {
				$found = self::locate( $tpl_file );
				if ( $found ) {
					return $found;
				}
				break;
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
			|| is_post_type_archive( 'mvs_collection' )
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
				/**
				 * Filters the body classes added to WPMediaVerse pages.
				 *
				 * @since 1.1.0
				 *
				 * @param string[] $mvs_classes MVS-specific body classes.
				 */
				$mvs_classes = apply_filters( 'mvs_body_classes', array( 'mvs-page', 'no-sidebar' ) );

				foreach ( $mvs_classes as $cls ) {
					if ( ! in_array( $cls, $classes, true ) ) {
						$classes[] = $cls;
					}
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
	/**
	 * Render the plugin-branded 404 for a plugin-owned URL and exit.
	 *
	 * Replaces the `set_404() + status_header(404) + return` pattern used
	 * throughout this loader. Loading the plugin template keeps the user
	 * inside the MediaVerse experience and offers a Browse-all CTA, instead
	 * of bouncing to the theme's generic 404.php.
	 *
	 * @param string $context    One of 'media' | 'profile' | 'album' | 'collection'.
	 * @param string $identifier Optional slug/username the user requested.
	 */
	public static function render_branded_404( string $context = 'media', string $identifier = '' ): void {
		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		$GLOBALS['mvs_404_context']    = $context;
		$GLOBALS['mvs_404_identifier'] = $identifier;

		// Ensure the 404 template renders with the plugin's design system.
		// `template_redirect` fires before `wp_enqueue_scripts`, so enqueuing
		// here lands in the normal dependency resolution window.
		if ( wp_style_is( 'mvs-frontend', 'registered' ) ) {
			wp_enqueue_style( 'mvs-frontend' );
		}

		// Lucide is registered via Plugin::register_lucide_script() on
		// `wp_enqueue_scripts@1`, which runs before `wp_head`. Even though
		// `template_redirect@5` is before wp_enqueue_scripts fires in the
		// request lifecycle, the 404 template calls `get_header()` which
		// triggers the asset-enqueue chain — so the script is registered by
		// the time any output happens. Just enqueue.
		if ( ! wp_script_is( 'mvs-lucide', 'registered' ) ) {
			\WPMediaVerse\Core\Plugin::register_lucide_script();
		}
		wp_enqueue_script( 'mvs-lucide' );

		$template = self::locate( '404.php' );
		if ( $template ) {
			include $template;
			exit;
		}
		// No plugin template — let WP fall through to the theme's 404.
	}

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

		/**
		 * Filters template variables before rendering.
		 *
		 * @since 1.1.0
		 *
		 * @param array  $args          Template variables.
		 * @param string $template_name Template file name.
		 */
		$args = apply_filters( 'mvs_template_variables', $args, $template_name );

		if ( ! empty( $args ) ) {
			extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		}

		/**
		 * Fires before a template part is rendered.
		 *
		 * @since 1.1.0
		 *
		 * @param string $template_name Template file name.
		 * @param array  $args          Template variables.
		 */
		do_action( 'mvs_before_template_render', $template_name, $args );

		include $template;

		/**
		 * Fires after a template part is rendered.
		 *
		 * @since 1.1.0
		 *
		 * @param string $template_name Template file name.
		 * @param array  $args          Template variables.
		 */
		do_action( 'mvs_after_template_render', $template_name, $args );
	}
}
