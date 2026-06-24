<?php
/**
 * Settings registration (sections, fields, sanitize callbacks).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers all WP Settings API sections, settings, and fields.
 */
class SettingsRegistrar {

	/**
	 * Single source of truth for the default allowed MIME types.
	 *
	 * Referenced by register_setting() (as the stored default) and by
	 * FieldRenderer/Sanitizers so a fresh install never sees an empty list
	 * and an accidental "save with no checkboxes" does not blow the default away.
	 *
	 * @var string
	 */
	// PDF (application/pdf) dropped from the default in 1.2.3 — focus is
	// images + videos. Existing installs keep PDF in their stored
	// mvs_allowed_file_types until they re-save the option; new installs
	// reject PDF uploads out of the box. The 'document' media_type and
	// the PDF-serve content-type whitelist stay in place so historical
	// PDF rows still display + download.
	public const DEFAULT_ALLOWED_FILE_TYPES = 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg';

	// The Allowed File Types picker universe + presence sentinel live on
	// Sanitizers (Sanitizers::KNOWN_FILE_TYPES / ::FILE_TYPES_PRESENT_FIELD),
	// the class that reconciles the submission.

	/**
	 * Register all settings, sections, and fields.
	 */
	public function register_all(): void {
		$this->register_general_settings();
		$this->register_display_settings();
		( new AiSettingsRegistrar() )->register();
		$this->register_moderation_settings();
		$this->register_webhook_settings();
		$this->register_messaging_settings();
		$this->register_pages_settings();
		$this->register_undocumented_settings();
	}

	/**
	 * Declare settings that are referenced by `get_option()` callers and
	 * documented in `qa/WHAT-TO-CHECK.md` but lack a tab UI of their own.
	 *
	 * Without `register_setting()` WP's Settings API cannot sanitize or REST-
	 * expose these keys, and a `get_option()` call returns `false` (not the
	 * documented default) until the option is manually written somewhere.
	 *
	 * Note: `mvs_grid_columns` and `mvs_thumbnail_style` USED to be registered
	 * here AND in register_display_settings(). Two register_setting() calls
	 * for the same option silently overwrites the first sanitize_callback —
	 * the same class of bug that wiped dm_access values. Both are now owned
	 * solely by register_display_settings(). This method only registers
	 * options without a settings-page UI.
	 *
	 * See qa/runs/FINDINGS-HISTORY.md (E3, F13) for background.
	 */
	private function register_undocumented_settings(): void {
		register_setting(
			SettingsPage::OPTION_GROUP . '_general',
			'mvs_comment_edit_window',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_comment_edit_window' ),
				'default'           => 15 * MINUTE_IN_SECONDS,
			)
		);
	}

	/**
	 * Whether WPMediaVerse Pro is active.
	 *
	 * @return bool
	 */
	private static function is_pro_active(): bool {
		return defined( 'MVS_PRO_VERSION' );
	}

	// -------------------------------------------------------------------------
	// General settings (General + Uploads + Storage sections)
	// -------------------------------------------------------------------------

	/**
	 * Register General-tab settings.
	 */
	private function register_general_settings(): void {
		// General section.
		add_settings_section(
			'mvs_general',
			__( 'General', 'wpmediaverse' ),
			function () {
				echo '<p>' . esc_html__( 'Upload limits, file types, privacy defaults, and duplicate detection.', 'wpmediaverse' ) . '</p>';
			},
			SettingsPage::PAGE_SLUG . '-general'
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_general',
			'mvs_max_upload_size',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_size_mb' ),
				'default'           => 104857600,
			)
		);
		add_settings_field(
			'mvs_max_upload_size',
			__( 'Max Upload Size', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_size_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option' => 'mvs_max_upload_size',
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_general',
			'mvs_allowed_file_types',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_file_types' ),
				'default'           => self::DEFAULT_ALLOWED_FILE_TYPES,
			)
		);

		// Defeat WP's update_option->add_option no-op: when the stored value
		// equals the registered default, update_option() routes the write through
		// add_option(), which bails on an existing row — so the FIRST change is
		// silently dropped. When the value is genuinely changing away from the
		// default, delete the row first so add_option() INSERTs cleanly. Never
		// fires on a no-op; never touches the default or the filter, so
		// fresh-install seeding is preserved. (Sanitizers::sanitize_file_types is
		// idempotent, so add_option's second sanitize pass on the CSV is safe.)
		add_filter(
			'pre_update_option_mvs_allowed_file_types',
			static function ( $value, $old_value ) {
				if ( $value === $old_value ) {
					return $value;
				}
				if ( (string) $old_value === self::DEFAULT_ALLOWED_FILE_TYPES ) {
					delete_option( 'mvs_allowed_file_types' );
				}
				return $value;
			},
			10,
			2
		);

		add_settings_field(
			'mvs_allowed_file_types',
			__( 'Allowed File Types', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_file_types_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'      => 'mvs_allowed_file_types',
				'description' => __( 'Select which file formats users can upload.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_general',
			'mvs_default_privacy',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_default_privacy' ),
				'default'           => 'public',
			)
		);
		add_settings_field(
			'mvs_default_privacy',
			__( 'Default Privacy Level', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'      => 'mvs_default_privacy',
				'choices'     => array(
					'public'  => __( 'Public', 'wpmediaverse' ),
					'members' => __( 'Members Only', 'wpmediaverse' ),
					'private' => __( 'Private', 'wpmediaverse' ),
				),
				'description' => __( 'New uploads default to this privacy level. Users can change per upload.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_general',
			'mvs_allow_user_privacy',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		add_settings_field(
			'mvs_allow_user_privacy',
			__( 'Allow Users to Set Privacy', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'      => 'mvs_allow_user_privacy',
				'label'       => __( 'Allow users to choose privacy level when uploading media.', 'wpmediaverse' ),
				'description' => __( 'When disabled, all uploads use the Default Privacy Level above. The privacy selector is hidden from users.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_general',
			'mvs_duplicate_action',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_duplicate_action' ),
				'default'           => 'warn',
			)
		);
		add_settings_field(
			'mvs_duplicate_action',
			__( 'Duplicate Detection', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'      => 'mvs_duplicate_action',
				'choices'     => array(
					'warn'  => __( 'Warn (allow upload)', 'wpmediaverse' ),
					'skip'  => __( 'Skip (reject duplicate)', 'wpmediaverse' ),
					'allow' => __( 'Allow (no check)', 'wpmediaverse' ),
				),
				'description' => __( 'Controls what happens when a user uploads a file that already exists.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_general',
			'mvs_strip_exif',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		add_settings_field(
			'mvs_strip_exif',
			__( 'Strip EXIF Data', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option' => 'mvs_strip_exif',
				'label'  => __( 'Remove GPS and device data from uploaded images.', 'wpmediaverse' ),
			)
		);

		// Storage section.
		add_settings_section( 'mvs_storage', __( 'Storage', 'wpmediaverse' ), '__return_null', SettingsPage::PAGE_SLUG . '-storage' );

		register_setting(
			SettingsPage::OPTION_GROUP . '_storage',
			'mvs_storage_driver',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_storage_driver' ),
				'default'           => 'local',
			)
		);
		if ( self::is_pro_active() ) {
			add_settings_field(
				'mvs_storage_driver',
				__( 'Storage Driver', 'wpmediaverse' ),
				array( FieldRenderer::class, 'render_select_field' ),
				SettingsPage::PAGE_SLUG . '-storage',
				'mvs_storage',
				array(
					'option'      => 'mvs_storage_driver',
					'choices'     => array(
						'local'    => __( 'Local (WordPress uploads)', 'wpmediaverse' ),
						's3'       => __( 'Amazon S3', 'wpmediaverse' ),
						'bunnycdn' => __( 'BunnyCDN', 'wpmediaverse' ),
						'r2'       => __( 'Cloudflare R2', 'wpmediaverse' ),
						'dospaces' => __( 'DigitalOcean Spaces', 'wpmediaverse' ),
					),
					'description' => __( 'Where uploaded media files are stored. Cloud drivers require API credentials.', 'wpmediaverse' ),
				)
			);
		} else {
			add_settings_field(
				'mvs_storage_driver',
				__( 'Storage Driver', 'wpmediaverse' ),
				array( FieldRenderer::class, 'render_pro_select_field' ),
				SettingsPage::PAGE_SLUG . '-storage',
				'mvs_storage',
				array(
					'current' => __( 'Local (WordPress uploads)', 'wpmediaverse' ),
					'pro'     => array(
						__( 'Amazon S3', 'wpmediaverse' ),
						__( 'BunnyCDN', 'wpmediaverse' ),
						__( 'Cloudflare R2', 'wpmediaverse' ),
						__( 'DigitalOcean Spaces', 'wpmediaverse' ),
					),
				)
			);
		}

		// Signed URL TTL.
		register_setting(
			SettingsPage::OPTION_GROUP . '_storage',
			'mvs_signed_url_ttl',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 3600,
			)
		);
		add_settings_field(
			'mvs_signed_url_ttl',
			__( 'Signed URL Expiry (seconds)', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_number_field' ),
			SettingsPage::PAGE_SLUG . '-storage',
			'mvs_storage',
			array(
				'option'      => 'mvs_signed_url_ttl',
				'description' => __( 'How long signed URLs remain valid for private media files. Default: 3600 (1 hour).', 'wpmediaverse' ),
			)
		);

		// Legacy option, retained for back-compat only. Public media whose file
		// lives on cloud is now ALWAYS served directly from its CDN URL — the
		// /serve proxy can only stream local files, so a stored cloud URL has no
		// working alternative. The display decision is driven by each media's
		// actual stored location + privacy (SignedUrlService::is_cloud_hosted_url
		// / public_cloud_direct_allowed), not by this toggle, so the checkbox was
		// removed. The option key stays registered so existing stored values do
		// not error; operators who need per-request proxying can use the
		// `mvs_serve_public_cloud_direct` filter instead.
		register_setting(
			SettingsPage::OPTION_GROUP . '_storage',
			'mvs_cloud_direct_public_urls',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		// View-event retention. Daily cron drops rows from mvs_media_views
		// older than this window — keeps the table bounded as traffic grows.
		// Aggregates in mvs_media_stats (counts, totals) are unaffected.
		register_setting(
			SettingsPage::OPTION_GROUP . '_storage',
			\WPMediaVerse\Services\ViewRetentionService::SETTING,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( \WPMediaVerse\Services\ViewRetentionService::class, 'sanitize_setting' ),
				'default'           => \WPMediaVerse\Services\ViewRetentionService::DEFAULT_DAYS,
			)
		);
		add_settings_field(
			\WPMediaVerse\Services\ViewRetentionService::SETTING,
			__( 'View Event Retention (days)', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_number_field' ),
			SettingsPage::PAGE_SLUG . '-storage',
			'mvs_storage',
			array(
				'option'      => \WPMediaVerse\Services\ViewRetentionService::SETTING,
				/* translators: %d: maximum allowed retention in days. */
				'description' => sprintf( __( 'How long raw view events are kept in the database. Aggregated counts (Total Views, etc.) are NOT affected. Default: 90 days. Set to 0 to retain forever. Maximum: %d.', 'wpmediaverse' ), \WPMediaVerse\Services\ViewRetentionService::MAX_DAYS ),
			)
		);

		// Image optimization — lossless re-encode of originals. PNG/GIF are
		// re-encoded; JPEG is re-encoded at quality 92 (filterable via
		// `mvs_optimize_jpeg_quality`) with metadata stripped. Default on.
		register_setting(
			SettingsPage::OPTION_GROUP . '_storage',
			\WPMediaVerse\Services\ImageOptimizationService::SETTING_OPTIMIZE_ORIGINALS,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		add_settings_field(
			\WPMediaVerse\Services\ImageOptimizationService::SETTING_OPTIMIZE_ORIGINALS,
			__( 'Compress uploaded images', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-storage',
			'mvs_storage',
			array(
				'option'      => \WPMediaVerse\Services\ImageOptimizationService::SETTING_OPTIMIZE_ORIGINALS,
				'description' => __( 'Shrink each uploaded image automatically by removing hidden camera data and re-saving with stronger compression. Works on JPEG, PNG, and GIF. Most uploads end up 10 to 30 percent smaller with no visible quality change. If you already use EWWW, Imagify, Smush, or ShortPixel, leave this on; they will run alongside.', 'wpmediaverse' ),
			)
		);

		// WebP variants — sibling WebP file generated for the original and
		// every thumbnail size. Default on when the active editor (Imagick or
		// GD) can write image/webp.
		register_setting(
			SettingsPage::OPTION_GROUP . '_storage',
			\WPMediaVerse\Services\ImageOptimizationService::SETTING_GENERATE_WEBP,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		add_settings_field(
			\WPMediaVerse\Services\ImageOptimizationService::SETTING_GENERATE_WEBP,
			__( 'Create WebP copies for faster loading', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-storage',
			'mvs_storage',
			array(
				'option'      => \WPMediaVerse\Services\ImageOptimizationService::SETTING_GENERATE_WEBP,
				'description' => __( 'Save a second copy of every image in WebP format. WebP files are about 25 to 35 percent smaller than JPEG, so pages load faster for your visitors. Browsers that support WebP use the smaller file; older browsers keep using the original. Already have older uploads to optimize? Visit a media item and use Re-optimize, or ask your developer to run the bulk optimizer command.', 'wpmediaverse' ),
			)
		);

		// AVIF variants — sibling AVIF file for the original and every thumb
		// size. AVIF compresses 30 to 50 percent smaller than WebP but takes
		// noticeably longer to encode and depends on the host PHP image editor
		// supporting it (Imagick with libheif, or GD on PHP 8.1+ with libavif).
		// Default OFF so the slower encode is opt-in.
		register_setting(
			SettingsPage::OPTION_GROUP . '_storage',
			\WPMediaVerse\Services\ImageOptimizationService::SETTING_GENERATE_AVIF,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		add_settings_field(
			\WPMediaVerse\Services\ImageOptimizationService::SETTING_GENERATE_AVIF,
			__( 'Create AVIF copies for the smallest possible files', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-storage',
			'mvs_storage',
			array(
				'option'      => \WPMediaVerse\Services\ImageOptimizationService::SETTING_GENERATE_AVIF,
				'description' => __( 'Save a third copy of every image in the newer AVIF format. AVIF is around 30 to 50 percent smaller than WebP, so pages load even faster on modern browsers (Chrome, Firefox, Safari 16.4+, Edge). Older browsers fall back to WebP, then the original. Encoding AVIF is much slower than WebP so uploads will take longer. Requires a host with AVIF-capable Imagick or GD; the option will silently no-op if the server cannot encode AVIF.', 'wpmediaverse' ),
			)
		);

		// Anonymous opt-in usage telemetry. Counter-only, local-only — no
		// data leaves the customer's site. Helps the plugin team know
		// which hooks / routes / commands are actually used across the
		// fleet, so cleanup decisions in future versions can be data-driven
		// instead of guesswork. Disabled by default.
		register_setting(
			SettingsPage::OPTION_GROUP . '_storage',
			\WPMediaVerse\Services\TelemetryService::SETTING_KEY,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		add_settings_field(
			\WPMediaVerse\Services\TelemetryService::SETTING_KEY,
			__( 'Help improve WPMediaVerse', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-storage',
			'mvs_storage',
			array(
				'option'      => \WPMediaVerse\Services\TelemetryService::SETTING_KEY,
				'description' => __( 'Allow this site to record anonymous usage counters locally. Only event names are counted (e.g. "thumbnail_generated", "rest:media:GET") — never user IDs, URLs, file paths, or media content. The counters stay on this site; nothing is transmitted. The plugin team will ask you to share the counter report manually when they need data to make a backwards-compatibility decision. Disabled by default.', 'wpmediaverse' ),
			)
		);

		// Filename strategy. Controls how new uploads are named on disk.
		// Existing media is NEVER renamed — this only affects future uploads.
		// Sanitizer lives in Sanitizers (canonical home for every select-field
		// whitelist sanitizer) so SettingsContractTest can introspect it.
		register_setting(
			SettingsPage::OPTION_GROUP . '_storage',
			\WPMediaVerse\Services\FilenameStrategy::SETTING,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_filename_strategy' ),
				// Hashed by default since 1.6.0 (filterable via
				// mvs_filename_strategy_upgrade_default). Matches the runtime
				// fallback in FilenameStrategy so REST / programmatic get_option
				// reads agree with resolve_strategy (audit 2026-06-04, #9962530792).
				'default'           => \WPMediaVerse\Services\FilenameStrategy::effective_default(),
			)
		);
		add_settings_field(
			\WPMediaVerse\Services\FilenameStrategy::SETTING,
			__( 'Stored Filenames', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-storage',
			'mvs_storage',
			array(
				'option'      => \WPMediaVerse\Services\FilenameStrategy::SETTING,
				'choices'     => array(
					'hashed'             => __( 'Hashed (recommended) — random 16-char filename, original kept as metadata', 'wpmediaverse' ),
					'original_sanitized' => __( 'Original (sanitized) — keeps the user filename, capped at 100 chars', 'wpmediaverse' ),
				),
				'description' => __( 'How new uploads are named on disk. "Hashed" is more secure (no enumeration) and avoids long-filename / emoji edge cases. Existing media is never renamed.', 'wpmediaverse' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Display settings (new tab)
	// -------------------------------------------------------------------------

	/**
	 * Register Display-tab settings.
	 */
	private function register_display_settings(): void {
		add_settings_section( 'mvs_display', __( 'Media Display', 'wpmediaverse' ), '__return_null', SettingsPage::PAGE_SLUG . '-display' );

		register_setting(
			SettingsPage::OPTION_GROUP . '_display',
			'mvs_grid_columns',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_grid_columns' ),
				'default'           => 3,
			)
		);
		add_settings_field(
			'mvs_grid_columns',
			__( 'Grid Columns', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-display',
			'mvs_display',
			array(
				'option'      => 'mvs_grid_columns',
				'choices'     => array(
					2 => __( '2 columns', 'wpmediaverse' ),
					3 => __( '3 columns', 'wpmediaverse' ),
					4 => __( '4 columns', 'wpmediaverse' ),
					5 => __( '5 columns', 'wpmediaverse' ),
				),
				'description' => __( 'Number of columns in the media grid on the Explore page, single album view, collections, and dashboard grids.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_display',
			'mvs_items_per_page',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_items_per_page' ),
				'default'           => 12,
			)
		);
		add_settings_field(
			'mvs_items_per_page',
			__( 'Items Per Page', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-display',
			'mvs_display',
			array(
				'option'      => 'mvs_items_per_page',
				'choices'     => array(
					12 => __( '12', 'wpmediaverse' ),
					24 => __( '24', 'wpmediaverse' ),
					48 => __( '48', 'wpmediaverse' ),
				),
				'description' => __( 'How many media items to show before pagination.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_display',
			'mvs_thumbnail_style',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_thumbnail_style' ),
				'default'           => 'square',
			)
		);
		add_settings_field(
			'mvs_thumbnail_style',
			__( 'Thumbnail Style', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-display',
			'mvs_display',
			array(
				'option'      => 'mvs_thumbnail_style',
				'choices'     => array(
					'square'   => __( 'Square (cropped)', 'wpmediaverse' ),
					'original' => __( 'Original proportions', 'wpmediaverse' ),
				),
				'description' => __( 'Square crops images uniformly. Original preserves aspect ratios.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_display',
			'mvs_thumbnail_size',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_thumbnail_size' ),
				// Default 'medium' (300px): grid/feed tiles render at ~150-300px,
				// so shipping the 1024px 'large' on every tile was 5-10x the bytes
				// for no visible gain. Owners who want retina-crisp grids can pick
				// 'large'/'full'; the lightbox always uses the original. (1.7.0)
				'default'           => 'medium',
			)
		);
		add_settings_field(
			'mvs_thumbnail_size',
			__( 'Thumbnail Quality', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-display',
			'mvs_display',
			array(
				'option'      => 'mvs_thumbnail_size',
				'choices'     => array(
					'medium' => __( 'Medium (300px, faster loading)', 'wpmediaverse' ),
					'large'  => __( 'Large (1024px, retina crisp)', 'wpmediaverse' ),
					'full'   => __( 'Full (original, highest quality)', 'wpmediaverse' ),
				),
				'description' => __( 'Controls image quality on grids and feeds. Larger sizes look sharper on retina displays but load slower.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_display',
			'mvs_lightbox_image_source',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_lightbox_image_source' ),
				'default'           => 'original',
			)
		);
		add_settings_field(
			'mvs_lightbox_image_source',
			__( 'Lightbox Image Size', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-display',
			'mvs_display',
			array(
				'option'      => 'mvs_lightbox_image_source',
				'choices'     => array(
					'original' => __( 'Original (highest quality, best for full-size viewing)', 'wpmediaverse' ),
					'large'    => __( 'Large (1024px, faster to load)', 'wpmediaverse' ),
					'medium'   => __( 'Medium (300px, fastest — small gallery)', 'wpmediaverse' ),
					'auto'     => __( 'Auto (original on desktop, large on mobile)', 'wpmediaverse' ),
				),
				'description' => __( 'Which image size opens in the lightbox. Users can always tap "View Original" to open the full file in a new tab.', 'wpmediaverse' ),
			)
		);

		// Allow downloads — global toggle. When off, the lightbox Download
		// button is hidden and the /mvs/v1/media/{id}/download REST endpoint
		// rejects with 403 (defense-in-depth: hiding the button alone won't
		// stop a determined caller hitting the endpoint directly).
		register_setting(
			SettingsPage::OPTION_GROUP . '_display',
			'mvs_allow_downloads',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		add_settings_field(
			'mvs_allow_downloads',
			__( 'Allow Downloads', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-display',
			'mvs_display',
			array(
				'option'      => 'mvs_allow_downloads',
				'description' => __( 'Show the Download button in the lightbox and accept download events. When off, customers can still view media but the download button is hidden everywhere.', 'wpmediaverse' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// AI & Moderation settings
	// -------------------------------------------------------------------------

	/**
	 * Register Moderation-related settings (shown on AI tab).
	 */
	private function register_moderation_settings(): void {
		add_settings_section( 'mvs_moderation', __( 'Moderation', 'wpmediaverse' ), '__return_null', SettingsPage::PAGE_SLUG . '-ai' );

		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_moderation_auto_action',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_moderation_auto_action' ),
				'default'           => 'delete',
			)
		);
		add_settings_field(
			'mvs_moderation_auto_action',
			__( 'When AI Flags Content', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_moderation',
			array(
				'option'      => 'mvs_moderation_auto_action',
				'choices'     => array(
					'flag'   => __( 'Flag for review (keep visible)', 'wpmediaverse' ),
					'hide'   => __( 'Hide (set to private)', 'wpmediaverse' ),
					'reject' => __( 'Reject (move to draft)', 'wpmediaverse' ),
					'delete' => __( 'Delete permanently (removes file from storage/cloud)', 'wpmediaverse' ),
				),
				'description' => __( 'Action taken automatically when AI detects a policy violation in uploaded media. "Delete permanently" removes the media and its files from local and cloud storage and cannot be undone.', 'wpmediaverse' ),
			)
		);

		// AI flag-criteria rule — which content categories the AI flags. Stored
		// as an array of category keys; every category is enabled by default so
		// the rule is never blank.
		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_ai_moderation_categories',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_ai_moderation_categories' ),
				'default'           => \WPMediaVerse\Services\AIService::MODERATION_CATEGORIES,
			)
		);
		add_settings_field(
			'mvs_ai_moderation_categories',
			__( 'AI Flag Criteria', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_group_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_moderation',
			array(
				'option'      => 'mvs_ai_moderation_categories',
				'choices'     => array(
					'nudity'    => __( 'Nudity / sexual content', 'wpmediaverse' ),
					'violence'  => __( 'Violence / gore', 'wpmediaverse' ),
					'hate'      => __( 'Hate / harassment', 'wpmediaverse' ),
					'self-harm' => __( 'Self-harm', 'wpmediaverse' ),
					'drugs'     => __( 'Drugs', 'wpmediaverse' ),
					'spam'      => __( 'Spam', 'wpmediaverse' ),
				),
				'description' => __( 'Which content categories the AI flags. At least one stays enabled — unchecking all restores every category.', 'wpmediaverse' ),
			)
		);

		// Owner-defined extra flag terms — anything beyond the built-in
		// categories the community does not want (e.g. weapons, political
		// content, competitor logos). Narrated to the AI alongside the
		// categories above.
		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_ai_moderation_custom_terms',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_ai_moderation_custom_terms' ),
				'default'           => '',
			)
		);
		add_settings_field(
			'mvs_ai_moderation_custom_terms',
			__( 'Custom Flag Terms', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_textarea_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_moderation',
			array(
				'option'      => 'mvs_ai_moderation_custom_terms',
				'description' => __( 'Optional. Comma-separated terms the AI should also flag, in addition to the categories above — e.g. weapons, gambling, political content, competitor logos. Leave blank to use only the built-in categories.', 'wpmediaverse' ),
			)
		);

		// Report auto-hide threshold.
		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_report_auto_hide_threshold',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 3,
			)
		);
		add_settings_field(
			'mvs_report_auto_hide_threshold',
			__( 'Auto-Hide Threshold', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_number_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_moderation',
			array(
				'option'      => 'mvs_report_auto_hide_threshold',
				'description' => __( 'Number of reports before media is automatically hidden. Set to 0 to disable.', 'wpmediaverse' ),
			)
		);
	}

	/**
	 * Register Webhook settings.
	 */
	private function register_webhook_settings(): void {
		add_settings_section( 'mvs_webhooks', __( 'Webhooks', 'wpmediaverse' ), '__return_null', SettingsPage::PAGE_SLUG . '-webhooks' );

		register_setting(
			SettingsPage::OPTION_GROUP . '_webhooks',
			'mvs_webhooks',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_webhooks' ),
				'default'           => array(),
			)
		);
		add_settings_field(
			'mvs_webhooks',
			__( 'Webhook Configuration', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_webhook_field' ),
			SettingsPage::PAGE_SLUG . '-webhooks',
			'mvs_webhooks'
		);
	}

	// -------------------------------------------------------------------------
	// Messaging settings (DM section on General tab)
	// -------------------------------------------------------------------------

	/**
	 * Register Messaging (DM) settings on the General tab.
	 */
	private function register_messaging_settings(): void {
		add_settings_section(
			'mvs_messaging',
			__( 'Direct Messages', 'wpmediaverse' ),
			function () {
				printf(
					'<p class="description">%s</p>',
					esc_html__( 'Configure direct messaging privacy and spam prevention.', 'wpmediaverse' )
				);
			},
			SettingsPage::PAGE_SLUG . '-social'
		);

		// DM access level.
		register_setting(
			SettingsPage::OPTION_GROUP . '_social',
			'mvs_dm_access',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_dm_access' ),
				'default'           => 'everyone',
			)
		);
		add_settings_field(
			'mvs_dm_access',
			__( 'Who Can Send DMs', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-social',
			'mvs_messaging',
			array(
				'option'      => 'mvs_dm_access',
				'choices'     => array(
					'everyone'  => __( 'Everyone', 'wpmediaverse' ),
					'followers' => __( 'Followers only (others go to Requests)', 'wpmediaverse' ),
					'mutual'    => __( 'Mutual followers only', 'wpmediaverse' ),
					'nobody'    => __( 'Nobody (DMs disabled)', 'wpmediaverse' ),
				),
				'description' => __( 'Controls who is allowed to send direct messages to other users on the site.', 'wpmediaverse' ),
			)
		);

		// Min account age.
		register_setting(
			SettingsPage::OPTION_GROUP . '_social',
			'mvs_dm_min_age',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		add_settings_field(
			'mvs_dm_min_age',
			__( 'Minimum Account Age (days)', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_number_field' ),
			SettingsPage::PAGE_SLUG . '-social',
			'mvs_messaging',
			array(
				'option'      => 'mvs_dm_min_age',
				'description' => __( 'Accounts younger than this cannot send DMs. Set 0 to disable.', 'wpmediaverse' ),
			)
		);

		// Chat-panel visibility — controls where the slide-out chat icon
		// appears for logged-in users. Defaults to 'everywhere' to preserve
		// 1.1.x behavior; sites that want a quieter chrome can scope it.
		register_setting(
			SettingsPage::OPTION_GROUP . '_social',
			'mvs_chat_panel_visibility',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_chat_panel_visibility' ),
				'default'           => 'everywhere',
			)
		);
		add_settings_field(
			'mvs_chat_panel_visibility',
			__( 'Chat Panel Visibility', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-social',
			'mvs_messaging',
			array(
				'option'      => 'mvs_chat_panel_visibility',
				'choices'     => array(
					'everywhere' => __( 'Everywhere (default)', 'wpmediaverse' ),
					'mvs_pages'  => __( 'WPMediaVerse pages only (Explore, Dashboard, Albums, Member Profiles)', 'wpmediaverse' ),
					'bp_pages'   => __( 'BuddyPress pages only (member + group)', 'wpmediaverse' ),
					'disabled'   => __( 'Never show the slide-out (use only the dedicated /messages/ page)', 'wpmediaverse' ),
				),
				'description' => __( 'Controls where the floating chat icon appears for logged-in users. Themes and other plugins can override per-page via the <code>mvs_should_render_chat_panel</code> filter.', 'wpmediaverse' ),
			)
		);

		// Online status visibility.
		register_setting(
			SettingsPage::OPTION_GROUP . '_social',
			'mvs_show_online_status',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_show_online_status' ),
				'default'           => 'everyone',
			)
		);
		add_settings_field(
			'mvs_show_online_status',
			__( 'Online Status Visibility', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-social',
			'mvs_messaging',
			array(
				'option'      => 'mvs_show_online_status',
				'choices'     => array(
					'everyone'  => __( 'Everyone', 'wpmediaverse' ),
					'followers' => __( 'Followers only', 'wpmediaverse' ),
					'nobody'    => __( 'Nobody', 'wpmediaverse' ),
				),
				'description' => __( 'Who can see when a user is currently online in the messaging interface.', 'wpmediaverse' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Watermark settings (on General tab)
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Page assignment settings (on General tab)
	// -------------------------------------------------------------------------

	/**
	 * Register page assignment settings on the General tab.
	 */
	private function register_pages_settings(): void {
		add_settings_section(
			'mvs_pages',
			__( 'Pages', 'wpmediaverse' ),
			function () {
				printf(
					'<p class="description">%s</p>',
					esc_html__( 'Assign pages for plugin features. Pages are auto-created during setup but can be changed here.', 'wpmediaverse' )
				);
			},
			SettingsPage::PAGE_SLUG . '-general'
		);

		$pages = array(
			'mvs_page_dashboard' => __( 'Dashboard Page', 'wpmediaverse' ),
			'mvs_page_explore'   => __( 'Explore Page', 'wpmediaverse' ),
			'mvs_page_upload'    => __( 'Upload Page', 'wpmediaverse' ),
		);

		foreach ( $pages as $option => $label ) {
			register_setting(
				SettingsPage::OPTION_GROUP . '_general',
				$option,
				array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 0,
				)
			);
			add_settings_field(
				$option,
				$label,
				array( FieldRenderer::class, 'render_page_dropdown_field' ),
				SettingsPage::PAGE_SLUG . '-general',
				'mvs_pages',
				array( 'option' => $option )
			);
		}
	}
}
