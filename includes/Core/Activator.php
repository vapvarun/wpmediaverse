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
		$pages = array(
			'mvs_page_explore'   => array(
				'title'     => __( 'Explore Media', 'wpmediaverse' ),
				'slug'      => 'explore-media',
				'shortcode' => '[mvs_gallery columns="3" count="24"]',
			),
			'mvs_page_dashboard' => array(
				'title'     => __( 'My Media', 'wpmediaverse' ),
				'slug'      => 'my-media',
				'shortcode' => '[mvs_dashboard]',
			),
			'mvs_page_upload'    => array(
				'title'     => __( 'Upload Media', 'wpmediaverse' ),
				'slug'      => 'upload-media',
				'shortcode' => '[mvs_upload]',
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
			'mvs_watermark_type'      => 'text',
			'mvs_watermark_text'      => get_bloginfo( 'name' ),
			'mvs_watermark_position'  => 'center',
			'mvs_watermark_opacity'   => 40,
			'mvs_watermark_font_size' => 24,
			'mvs_watermark_color'     => '#ffffff',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}
}
