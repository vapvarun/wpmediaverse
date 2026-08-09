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

		// Create frontend pages if they don't exist.
		self::create_pages();

		// Point those pages at the active theme's no-sidebar page template so a
		// member's media library never renders beside the theme's blog sidebar.
		TemplateLoader::sync_app_page_templates();
		update_option( 'mvs_app_page_templates_synced', 1, false );

		// Protect upload directory from direct access.
		self::protect_upload_directory();

		// Flush rewrite rules on next load.
		set_transient( 'mvs_flush_rewrite', true );

		// Signal that we should redirect to the overview on next admin load.
		set_transient( 'mvs_activation_redirect', true, 30 );
	}

	/**
	 * Create the default frontend pages for Explore, Dashboard, and Upload.
	 *
	 * Pages are only created when the stored option is missing or points to a
	 * non-existent page, so re-activation is safely idempotent.
	 *
	 * The three pages match the canonical slot map in
	 * `Core\SettingsHelper::PAGE_SLOT_OPTIONS` (`dashboard` / `explore` /
	 * `upload`). Site owners must NOT see blank/zero defaults that require
	 * manual configuration before the plugin's main UI surfaces work — these
	 * pages back the Explore feed, the per-user dashboard, and the front-end
	 * upload form respectively.
	 */
	private static function create_pages(): void {
		// Menus with "Automatically add new top-level pages" enabled
		// (nav_menu_options auto_add) grab every published page via
		// _wp_auto_add_pages_to_menu on transition_post_status. Detach it
		// while we create OUR pages so activating the plugin never edits the
		// site's navigation (reported: plugin "added pages directly in menu").
		// Re-attached after the loop; admins add the pages to menus themselves.
		$auto_add_detached = remove_action( 'transition_post_status', '_wp_auto_add_pages_to_menu', 10 );

		// Page titles stored in wp_posts.post_title at insert time; admins can
		// rename freely afterwards. NOT wrapped in __() because the activation
		// hook fires before `init` and translation lookups at that point trigger
		// the WP 6.7+ `_load_textdomain_just_in_time was called incorrectly`
		// notice. Titles are not re-translated on each page render anyway.
		$pages = array(
			'mvs_page_explore'   => array(
				'title'     => 'Explore Media',
				'slug'      => 'explore-media',
				'shortcode' => '[mvs_gallery columns="3" count="24"]',
			),
			'mvs_page_dashboard' => array(
				'title'     => 'My Media',
				'slug'      => 'my-media',
				'shortcode' => '[mvs_dashboard]',
			),
			'mvs_page_upload'    => array(
				'title'     => 'Upload Media',
				'slug'      => 'upload-media',
				'shortcode' => '[mvs_upload]',
			),
			// Documents get their OWN listing page, not a corner of Explore.
			// (Owner, 2026-08-09.) A media grid draws pictures; a PDF has none, so
			// a document in one renders as a broken tile — which is what a
			// screenshot of `/explore-media` showed. This page renders documents
			// as rows with a type chip, and it is also where a single document's
			// back link goes, so a member is never sent to a grid their item is
			// not in.
			//
			// Created here even on a Free-only site: after Migrator v27 those
			// sites can hold `legacy_document` rows, and this is the only surface
			// that lists them.
			'mvs_page_explore_documents' => array(
				'title'     => 'Explore Documents',
				'slug'      => 'explore-document',
				'shortcode' => '[mvs_documents]',
			),
		);

		foreach ( $pages as $option_key => $page_data ) {
			// 1. If the option already points to a live page with our shortcode, nothing to do.
			$existing_id = (int) get_option( $option_key );
			if ( $existing_id > 0 && 'publish' === get_post_status( $existing_id ) ) {
				$existing_content = get_post_field( 'post_content', $existing_id );
				if ( strpos( $existing_content, $page_data['shortcode'] ) !== false ) {
					continue;
				}
			}

			// 2. Try to find an existing published page with the expected slug.
			$found = get_page_by_path( $page_data['slug'], OBJECT, 'page' );
			if ( $found && 'publish' === $found->post_status ) {
				update_option( $option_key, $found->ID );
				continue;
			}

			// 3. Try to find an existing published page by title (handles slug variants like my-media-2).
			$by_title_query = new \WP_Query(
				array(
					'post_type'              => 'page',
					'title'                  => $page_data['title'],
					'post_status'            => 'publish',
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$by_title       = $by_title_query->have_posts() ? $by_title_query->posts[0] : null;
			if ( $by_title && 'publish' === $by_title->post_status ) {
				update_option( $option_key, $by_title->ID );
				continue;
			}

			// 4. No existing page found — create one with an explicit slug.
			$content = '<!-- wp:shortcode -->' . $page_data['shortcode'] . '<!-- /wp:shortcode -->';
			$page_id = wp_insert_post(
				array(
					'post_title'     => $page_data['title'],
					'post_name'      => $page_data['slug'],
					'post_content'   => $content,
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				)
			);

			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_option( $option_key, $page_id );
			}
		}

		if ( $auto_add_detached ) {
			add_action( 'transition_post_status', '_wp_auto_add_pages_to_menu', 10, 3 );
		}
	}

	/**
	 * Add .htaccess and index.php to the upload directory to prevent direct browsing.
	 */
	private static function protect_upload_directory(): void {
		$upload_dir = wp_upload_dir();
		$mvs_dir    = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/';

		if ( ! wp_mkdir_p( $mvs_dir ) ) {
			return;
		}

		$htaccess = $mvs_dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}

		$index = $mvs_dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Set default option values.
	 */
	private static function set_defaults(): void {
		$defaults = array(
			// General.
			'mvs_max_upload_size'     => 104857600, // 100MB in bytes.
			// PDF dropped from default in 1.2.3 — see SettingsRegistrar::DEFAULT_ALLOWED_FILE_TYPES note.
			'mvs_allowed_file_types'  => 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg',
			'mvs_default_privacy'     => 'public',
			'mvs_duplicate_action'    => 'warn',
			'mvs_strip_exif'          => true,
			'mvs_storage_driver'      => 'local',
			// AI cost guardrails.
			// Conservative monthly cap so a fresh install can never silently
			// run an unbounded OpenAI bill. Site owners who want unlimited
			// spend can set this to 0 in the AI settings tab — that intent is
			// then explicit and recorded in wp_options. add_option() below
			// only inserts when the row is missing, so existing installs that
			// already chose 0 (or any other value) are not overwritten.
			'mvs_ai_monthly_budget'   => 10,
			// Watermark (Pro controls the UI; free seeds defaults).
			'mvs_watermark_enabled'   => false,
			'mvs_watermark_apply'     => 'all',
			'mvs_watermark_type'      => 'text',
			'mvs_watermark_text'      => get_bloginfo( 'name' ),
			'mvs_watermark_image_id'  => 0,
			'mvs_watermark_position'  => 'bottom-right',
			'mvs_watermark_opacity'   => 40,
			'mvs_watermark_font_size' => 24,
			'mvs_watermark_color'     => '#ffffff',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}

		// Filename strategy — hashed is the default for NEW uploads on every
		// install since 1.6.0 (fresh AND upgrade). Original client filenames in
		// the media URL are an info-leak + enumeration vector (audit 2026-06-04,
		// #9962530792). Existing on-disk files are NEVER renamed; only the
		// strategy applied to new uploads changes. `add_option` no-ops on
		// existing keys, so a site that previously persisted
		// 'original_sanitized' keeps its explicit choice. Sites that want
		// readable filenames restore the old default with one line:
		// add_filter( 'mvs_filename_strategy_upgrade_default', fn() => 'original_sanitized' );
		// or force it for every upload via the 'mvs_filename_strategy' filter.
		if ( false === get_option( \WPMediaVerse\Services\FilenameStrategy::SETTING ) ) {
			add_option(
				\WPMediaVerse\Services\FilenameStrategy::SETTING,
				\WPMediaVerse\Services\FilenameStrategy::effective_default()
			);
		}
	}
}
