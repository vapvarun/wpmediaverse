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
	public const DEFAULT_ALLOWED_FILE_TYPES = 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg,application/pdf';

	/**
	 * Register all settings, sections, and fields.
	 */
	public function register_all(): void {
		$this->register_general_settings();
		$this->register_display_settings();
		$this->register_ai_settings();
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
				'default'           => 'large',
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

	}

	// -------------------------------------------------------------------------
	// AI & Moderation settings
	// -------------------------------------------------------------------------

	/**
	 * Register AI-tab settings.
	 */
	private function register_ai_settings(): void {
		add_settings_section( 'mvs_ai', __( 'AI Features', 'wpmediaverse' ), '__return_null', SettingsPage::PAGE_SLUG . '-ai' );

		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_ai_provider',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_ai_provider' ),
				'default'           => 'openai',
			)
		);
		if ( self::is_pro_active() ) {
			add_settings_field(
				'mvs_ai_provider',
				__( 'AI Provider', 'wpmediaverse' ),
				array( FieldRenderer::class, 'render_select_field' ),
				SettingsPage::PAGE_SLUG . '-ai',
				'mvs_ai',
				array(
					'option'      => 'mvs_ai_provider',
					'choices'     => array(
						'openai'      => __( 'OpenAI (GPT-4 Vision)', 'wpmediaverse' ),
						'google'      => __( 'Google Vision', 'wpmediaverse' ),
						'rekognition' => __( 'AWS Rekognition', 'wpmediaverse' ),
					),
					'description' => __( 'Which AI service to use for image analysis, tagging, and moderation.', 'wpmediaverse' ),
				)
			);
		} else {
			add_settings_field(
				'mvs_ai_provider',
				__( 'AI Provider', 'wpmediaverse' ),
				array( FieldRenderer::class, 'render_pro_select_field' ),
				SettingsPage::PAGE_SLUG . '-ai',
				'mvs_ai',
				array(
					'current' => __( 'OpenAI (GPT-4 Vision)', 'wpmediaverse' ),
					'pro'     => array(
						__( 'Google Vision', 'wpmediaverse' ),
						__( 'AWS Rekognition', 'wpmediaverse' ),
					),
				)
			);

		}

		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_openai_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_password_option' ),
				'default'           => '',
			)
		);
		add_settings_field(
			'mvs_openai_api_key',
			__( 'OpenAI API Key', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_password_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option'      => 'mvs_openai_api_key',
				'description' => __( 'Or define MVS_OPENAI_API_KEY constant in wp-config.php.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_openai_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_openai_model' ),
				'default'           => 'gpt-4o-mini',
			)
		);
		add_settings_field(
			'mvs_openai_model',
			__( 'OpenAI Model', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option'      => 'mvs_openai_model',
				'choices'     => array(
					'gpt-4o-mini' => __( 'GPT-4o Mini (cheaper)', 'wpmediaverse' ),
					'gpt-4o'      => __( 'GPT-4o (best quality)', 'wpmediaverse' ),
				),
				'description' => __( 'GPT-4o Mini is faster and cheaper. GPT-4o produces more accurate tags and descriptions.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_ai_auto_analyze',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		add_settings_field(
			'mvs_ai_auto_analyze',
			__( 'Auto-Analyze Uploads', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option' => 'mvs_ai_auto_analyze',
				'label'  => __( 'Automatically analyze media on upload (description + tags).', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_ai_auto_apply_tags',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		add_settings_field(
			'mvs_ai_auto_apply_tags',
			__( 'Auto-Apply Tags', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option' => 'mvs_ai_auto_apply_tags',
				'label'  => __( 'Automatically assign AI-generated tags to taxonomy.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_ai_auto_moderate',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		add_settings_field(
			'mvs_ai_auto_moderate',
			__( 'Auto-Moderate Uploads', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option' => 'mvs_ai_auto_moderate',
				'label'  => __( 'Check uploads for policy violations via AI.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_ai_monthly_budget',
			array(
				'type'              => 'number',
				'sanitize_callback' => 'floatval',
				'default'           => 0,
			)
		);
		add_settings_field(
			'mvs_ai_monthly_budget',
			__( 'Monthly AI Budget ($)', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_number_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option'      => 'mvs_ai_monthly_budget',
				'description' => __( 'Set to 0 for unlimited. AI calls will stop when budget is exceeded.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_ai_cost_per_call',
			array(
				'type'              => 'number',
				'sanitize_callback' => 'floatval',
				'default'           => 0.01,
			)
		);
		add_settings_field(
			'mvs_ai_cost_per_call',
			__( 'Estimated Cost per Call ($)', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_number_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option'      => 'mvs_ai_cost_per_call',
				'description' => __( 'Approximate cost per API call for budget tracking.', 'wpmediaverse' ),
			)
		);
	}

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
				'default'           => 'flag',
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
				),
				'description' => __( 'Action taken automatically when AI detects a policy violation in uploaded media.', 'wpmediaverse' ),
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
