<?php
/**
 * Admin settings page.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page for WPMediaVerse.
 *
 * Registered as a submenu under the mvs_media CPT menu. Provides tabbed
 * navigation: General | Display | Permissions | AI & Moderation | Webhooks.
 */
class SettingsPage {

	const PAGE_SLUG    = 'mvs-settings';
	const OPTION_GROUP = 'mvs_settings';

	/**
	 * All supported tabs with labels.
	 *
	 * @var array<string,string>
	 */
	private static $tabs = array();

	/**
	 * Constructor. Hooks admin menu and settings registration.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'track_settings_changes' ) );
		add_action( 'admin_post_mvs_save_role_caps', array( $this, 'save_role_caps' ) );
		add_action( 'admin_notices', array( $this, 'render_contextual_notices' ) );
	}

	/**
	 * Track setting changes before they are saved (for contextual notices).
	 */
	public function track_settings_changes(): void {
		// Only on settings save.
		if ( empty( $_POST ) || ! isset( $_POST['option_page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$option_page = sanitize_text_field( wp_unslash( $_POST['option_page'] ) );
		if ( strpos( $option_page, 'mvs_settings' ) !== 0 ) {
			return;
		}

		$old_driver = get_option( 'mvs_storage_driver', 'local' );
		set_transient( 'mvs_old_storage_driver_' . get_current_user_id(), $old_driver, 30 );
	}

	/**
	 * Show contextual success notices after settings save.
	 */
	public function render_contextual_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'mvs-settings' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['settings-updated'] ) || 'true' !== $_GET['settings-updated'] ) {
			return;
		}

		$user_id    = get_current_user_id();
		$old_driver = get_transient( 'mvs_old_storage_driver_' . $user_id );
		$new_driver = get_option( 'mvs_storage_driver', 'local' );

		if ( false !== $old_driver && $old_driver !== $new_driver ) {
			delete_transient( 'mvs_old_storage_driver_' . $user_id );
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				sprintf(
					/* translators: %s: new storage driver name */
					esc_html__( 'Storage driver changed to %s. New uploads will use this driver.', 'wpmediaverse' ),
					'<strong>' . esc_html( ucfirst( $new_driver ) ) . '</strong>'
				)
			);
		} else {
			delete_transient( 'mvs_old_storage_driver_' . $user_id );
		}

		// Permissions save notice.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$perms_saved = isset( $_GET['permissions-saved'] ) ? absint( $_GET['permissions-saved'] ) : -1;
		if ( $perms_saved >= 0 ) {
			if ( $perms_saved > 0 ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					sprintf(
						/* translators: %d: number of roles */
						esc_html(
							_n(
								'Permissions updated for %d role.',
								'Permissions updated for %d roles.',
								$perms_saved,
								'wpmediaverse'
							)
						),
						esc_html( $perms_saved )
					)
				);
			} else {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html__( 'Permissions saved. No changes were needed.', 'wpmediaverse' )
				);
			}
		}
	}

	/**
	 * Add settings page as submenu under WPMediaVerse (mvs_media CPT menu).
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=mvs_media',
			__( 'WPMediaVerse Settings', 'wpmediaverse' ),
			__( 'Settings', 'wpmediaverse' ),
			'manage_mvs_settings',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Return the active tab slug, defaulting to 'general'.
	 *
	 * @return string
	 */
	private function get_active_tab(): string {
		$allowed = array( 'general', 'display', 'permissions', 'ai', 'webhooks' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		return in_array( $tab, $allowed, true ) ? $tab : 'general';
	}

	/**
	 * Whether WPMediaVerse Pro is active.
	 *
	 * @return bool
	 */
	private function is_pro_active(): bool {
		return defined( 'MVS_PRO_VERSION' );
	}

	/**
	 * Register all settings, sections, and fields.
	 */
	public function register_settings(): void {
		$this->register_general_settings();
		$this->register_display_settings();
		$this->register_ai_settings();
		$this->register_moderation_settings();
		$this->register_webhook_settings();
		$this->register_messaging_settings();
		$this->register_watermark_settings();
		$this->register_pages_settings();
	}

	// -------------------------------------------------------------------------
	// General settings (General + Uploads + Storage sections)
	// -------------------------------------------------------------------------

	/**
	 * Register General-tab settings.
	 */
	private function register_general_settings(): void {
		// General section.
		add_settings_section( 'mvs_general', __( 'General', 'wpmediaverse' ), '__return_null', self::PAGE_SLUG . '-general' );

		register_setting(
			self::OPTION_GROUP . '_general',
			'mvs_max_upload_size',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_size_mb' ),
				'default'           => 104857600,
			)
		);
		add_settings_field(
			'mvs_max_upload_size',
			__( 'Max Upload Size', 'wpmediaverse' ),
			array( $this, 'render_size_field' ),
			self::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option' => 'mvs_max_upload_size',
			)
		);

		register_setting(
			self::OPTION_GROUP . '_general',
			'mvs_allowed_file_types',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_file_types' ),
				'default'           => 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg',
			)
		);
		add_settings_field(
			'mvs_allowed_file_types',
			__( 'Allowed File Types', 'wpmediaverse' ),
			array( $this, 'render_file_types_field' ),
			self::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option' => 'mvs_allowed_file_types',
			)
		);

		register_setting(
			self::OPTION_GROUP . '_general',
			'mvs_default_privacy',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'public',
			)
		);
		add_settings_field(
			'mvs_default_privacy',
			__( 'Default Privacy Level', 'wpmediaverse' ),
			array( $this, 'render_select_field' ),
			self::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'  => 'mvs_default_privacy',
				'choices' => array(
					'public'  => __( 'Public', 'wpmediaverse' ),
					'members' => __( 'Members Only', 'wpmediaverse' ),
					'private' => __( 'Private', 'wpmediaverse' ),
				),
			)
		);

		register_setting(
			self::OPTION_GROUP . '_general',
			'mvs_duplicate_action',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'warn',
			)
		);
		add_settings_field(
			'mvs_duplicate_action',
			__( 'Duplicate Detection', 'wpmediaverse' ),
			array( $this, 'render_select_field' ),
			self::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'  => 'mvs_duplicate_action',
				'choices' => array(
					'warn'  => __( 'Warn (allow upload)', 'wpmediaverse' ),
					'skip'  => __( 'Skip (reject duplicate)', 'wpmediaverse' ),
					'allow' => __( 'Allow (no check)', 'wpmediaverse' ),
				),
			)
		);

		register_setting(
			self::OPTION_GROUP . '_general',
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
			array( $this, 'render_checkbox_field' ),
			self::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option' => 'mvs_strip_exif',
				'label'  => __( 'Remove GPS and device data from uploaded images.', 'wpmediaverse' ),
			)
		);

		// Storage section.
		add_settings_section( 'mvs_storage', __( 'Storage', 'wpmediaverse' ), '__return_null', self::PAGE_SLUG . '-general' );

		register_setting(
			self::OPTION_GROUP . '_general',
			'mvs_storage_driver',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'local',
			)
		);
		if ( $this->is_pro_active() ) {
			add_settings_field(
				'mvs_storage_driver',
				__( 'Storage Driver', 'wpmediaverse' ),
				array( $this, 'render_select_field' ),
				self::PAGE_SLUG . '-general',
				'mvs_storage',
				array(
					'option'  => 'mvs_storage_driver',
					'choices' => array(
						'local'    => __( 'Local (WordPress uploads)', 'wpmediaverse' ),
						's3'       => __( 'Amazon S3', 'wpmediaverse' ),
						'bunnycdn' => __( 'BunnyCDN', 'wpmediaverse' ),
					),
				)
			);
		} else {
			add_settings_field(
				'mvs_storage_driver',
				__( 'Storage Driver', 'wpmediaverse' ),
				array( $this, 'render_pro_select_field' ),
				self::PAGE_SLUG . '-general',
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
			self::OPTION_GROUP . '_general',
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
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG . '-general',
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
		add_settings_section( 'mvs_display', __( 'Media Display', 'wpmediaverse' ), '__return_null', self::PAGE_SLUG . '-display' );

		register_setting(
			self::OPTION_GROUP . '_display',
			'mvs_grid_columns',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 3,
			)
		);
		add_settings_field(
			'mvs_grid_columns',
			__( 'Grid Columns', 'wpmediaverse' ),
			array( $this, 'render_select_field' ),
			self::PAGE_SLUG . '-display',
			'mvs_display',
			array(
				'option'  => 'mvs_grid_columns',
				'choices' => array(
					2 => __( '2 columns', 'wpmediaverse' ),
					3 => __( '3 columns', 'wpmediaverse' ),
					4 => __( '4 columns', 'wpmediaverse' ),
				),
			)
		);

		register_setting(
			self::OPTION_GROUP . '_display',
			'mvs_items_per_page',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 12,
			)
		);
		add_settings_field(
			'mvs_items_per_page',
			__( 'Items Per Page', 'wpmediaverse' ),
			array( $this, 'render_select_field' ),
			self::PAGE_SLUG . '-display',
			'mvs_display',
			array(
				'option'  => 'mvs_items_per_page',
				'choices' => array(
					12 => __( '12', 'wpmediaverse' ),
					24 => __( '24', 'wpmediaverse' ),
					48 => __( '48', 'wpmediaverse' ),
				),
			)
		);

		register_setting(
			self::OPTION_GROUP . '_display',
			'mvs_thumbnail_style',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'square',
			)
		);
		add_settings_field(
			'mvs_thumbnail_style',
			__( 'Thumbnail Style', 'wpmediaverse' ),
			array( $this, 'render_select_field' ),
			self::PAGE_SLUG . '-display',
			'mvs_display',
			array(
				'option'  => 'mvs_thumbnail_style',
				'choices' => array(
					'square'   => __( 'Square (cropped)', 'wpmediaverse' ),
					'original' => __( 'Original proportions', 'wpmediaverse' ),
				),
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
		add_settings_section( 'mvs_ai', __( 'AI Features', 'wpmediaverse' ), '__return_null', self::PAGE_SLUG . '-ai' );

		register_setting(
			self::OPTION_GROUP . '_ai',
			'mvs_ai_provider',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'openai',
			)
		);
		if ( $this->is_pro_active() ) {
			add_settings_field(
				'mvs_ai_provider',
				__( 'AI Provider', 'wpmediaverse' ),
				array( $this, 'render_select_field' ),
				self::PAGE_SLUG . '-ai',
				'mvs_ai',
				array(
					'option'  => 'mvs_ai_provider',
					'choices' => array(
						'openai'      => __( 'OpenAI (GPT-4 Vision)', 'wpmediaverse' ),
						'google'      => __( 'Google Vision', 'wpmediaverse' ),
						'rekognition' => __( 'AWS Rekognition', 'wpmediaverse' ),
					),
				)
			);
		} else {
			add_settings_field(
				'mvs_ai_provider',
				__( 'AI Provider', 'wpmediaverse' ),
				array( $this, 'render_pro_select_field' ),
				self::PAGE_SLUG . '-ai',
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
			self::OPTION_GROUP . '_ai',
			'mvs_openai_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_password_option' ),
				'default'           => '',
			)
		);
		add_settings_field(
			'mvs_openai_api_key',
			__( 'OpenAI API Key', 'wpmediaverse' ),
			array( $this, 'render_password_field' ),
			self::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option'      => 'mvs_openai_api_key',
				'description' => __( 'Or define MVS_OPENAI_API_KEY constant in wp-config.php.', 'wpmediaverse' ),
			)
		);

		register_setting(
			self::OPTION_GROUP . '_ai',
			'mvs_openai_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'gpt-4o-mini',
			)
		);
		add_settings_field(
			'mvs_openai_model',
			__( 'OpenAI Model', 'wpmediaverse' ),
			array( $this, 'render_select_field' ),
			self::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option'  => 'mvs_openai_model',
				'choices' => array(
					'gpt-4o-mini' => __( 'GPT-4o Mini (cheaper)', 'wpmediaverse' ),
					'gpt-4o'      => __( 'GPT-4o (best quality)', 'wpmediaverse' ),
				),
			)
		);

		register_setting(
			self::OPTION_GROUP . '_ai',
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
			array( $this, 'render_checkbox_field' ),
			self::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option' => 'mvs_ai_auto_analyze',
				'label'  => __( 'Automatically analyze media on upload (description + tags).', 'wpmediaverse' ),
			)
		);

		register_setting(
			self::OPTION_GROUP . '_ai',
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
			array( $this, 'render_checkbox_field' ),
			self::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option' => 'mvs_ai_auto_apply_tags',
				'label'  => __( 'Automatically assign AI-generated tags to taxonomy.', 'wpmediaverse' ),
			)
		);

		register_setting(
			self::OPTION_GROUP . '_ai',
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
			array( $this, 'render_checkbox_field' ),
			self::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option' => 'mvs_ai_auto_moderate',
				'label'  => __( 'Check uploads for policy violations via AI.', 'wpmediaverse' ),
			)
		);

		register_setting(
			self::OPTION_GROUP . '_ai',
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
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option'      => 'mvs_ai_monthly_budget',
				'description' => __( 'Set to 0 for unlimited. AI calls will stop when budget is exceeded.', 'wpmediaverse' ),
			)
		);

		register_setting(
			self::OPTION_GROUP . '_ai',
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
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG . '-ai',
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
		add_settings_section( 'mvs_moderation', __( 'Moderation', 'wpmediaverse' ), '__return_null', self::PAGE_SLUG . '-ai' );

		register_setting(
			self::OPTION_GROUP . '_ai',
			'mvs_moderation_auto_action',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'flag',
			)
		);
		add_settings_field(
			'mvs_moderation_auto_action',
			__( 'When AI Flags Content', 'wpmediaverse' ),
			array( $this, 'render_select_field' ),
			self::PAGE_SLUG . '-ai',
			'mvs_moderation',
			array(
				'option'  => 'mvs_moderation_auto_action',
				'choices' => array(
					'flag'   => __( 'Flag for review (keep visible)', 'wpmediaverse' ),
					'hide'   => __( 'Hide (set to private)', 'wpmediaverse' ),
					'reject' => __( 'Reject (move to draft)', 'wpmediaverse' ),
				),
			)
		);

		// Report auto-hide threshold.
		register_setting(
			self::OPTION_GROUP . '_ai',
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
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG . '-ai',
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
		add_settings_section( 'mvs_webhooks', __( 'Webhooks', 'wpmediaverse' ), '__return_null', self::PAGE_SLUG . '-webhooks' );

		register_setting(
			self::OPTION_GROUP . '_webhooks',
			'mvs_webhooks',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_webhooks' ),
				'default'           => array(),
			)
		);
		add_settings_field(
			'mvs_webhooks',
			__( 'Webhook Configuration', 'wpmediaverse' ),
			array( $this, 'render_webhook_field' ),
			self::PAGE_SLUG . '-webhooks',
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
			self::PAGE_SLUG . '-general'
		);

		// DM access level.
		register_setting(
			self::OPTION_GROUP . '_general',
			'mvs_dm_access',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'everyone',
			)
		);
		add_settings_field(
			'mvs_dm_access',
			__( 'Who Can Send DMs', 'wpmediaverse' ),
			array( $this, 'render_select_field' ),
			self::PAGE_SLUG . '-general',
			'mvs_messaging',
			array(
				'option'  => 'mvs_dm_access',
				'choices' => array(
					'everyone'  => __( 'Everyone', 'wpmediaverse' ),
					'followers' => __( 'Followers only (others go to Requests)', 'wpmediaverse' ),
					'mutual'    => __( 'Mutual followers only', 'wpmediaverse' ),
					'nobody'    => __( 'Nobody (DMs disabled)', 'wpmediaverse' ),
				),
			)
		);

		// Min account age.
		register_setting(
			self::OPTION_GROUP . '_general',
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
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG . '-general',
			'mvs_messaging',
			array(
				'option'      => 'mvs_dm_min_age',
				'description' => __( 'Accounts younger than this cannot send DMs. Set 0 to disable.', 'wpmediaverse' ),
			)
		);

		// Online status visibility.
		register_setting(
			self::OPTION_GROUP . '_general',
			'mvs_show_online_status',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'everyone',
			)
		);
		add_settings_field(
			'mvs_show_online_status',
			__( 'Online Status Visibility', 'wpmediaverse' ),
			array( $this, 'render_select_field' ),
			self::PAGE_SLUG . '-general',
			'mvs_messaging',
			array(
				'option'  => 'mvs_show_online_status',
				'choices' => array(
					'everyone'  => __( 'Everyone', 'wpmediaverse' ),
					'followers' => __( 'Followers only', 'wpmediaverse' ),
					'nobody'    => __( 'Nobody', 'wpmediaverse' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Watermark settings (on General tab)
	// -------------------------------------------------------------------------

	/**
	 * Register Watermark settings on the General tab.
	 */
	private function register_watermark_settings(): void {
		add_settings_section(
			'mvs_watermark',
			__( 'Watermark', 'wpmediaverse' ),
			function () {
				printf(
					'<p class="description">%s</p>',
					esc_html__( 'Add a text or image watermark to uploaded images.', 'wpmediaverse' )
				);
			},
			self::PAGE_SLUG . '-general'
		);

		register_setting( self::OPTION_GROUP . '_general', 'mvs_watermark_type', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'text',
		) );
		add_settings_field( 'mvs_watermark_type', __( 'Watermark Type', 'wpmediaverse' ),
			array( $this, 'render_select_field' ), self::PAGE_SLUG . '-general', 'mvs_watermark',
			array(
				'option'  => 'mvs_watermark_type',
				'choices' => array(
					'text'  => __( 'Text', 'wpmediaverse' ),
					'image' => __( 'Image', 'wpmediaverse' ),
				),
			)
		);

		register_setting( self::OPTION_GROUP . '_general', 'mvs_watermark_text', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => get_bloginfo( 'name' ),
		) );
		add_settings_field( 'mvs_watermark_text', __( 'Watermark Text', 'wpmediaverse' ),
			array( $this, 'render_text_field' ), self::PAGE_SLUG . '-general', 'mvs_watermark',
			array(
				'option'      => 'mvs_watermark_text',
				'description' => __( 'Text to overlay on images. Used when type is "Text".', 'wpmediaverse' ),
			)
		);

		register_setting( self::OPTION_GROUP . '_general', 'mvs_watermark_position', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'center',
		) );
		add_settings_field( 'mvs_watermark_position', __( 'Position', 'wpmediaverse' ),
			array( $this, 'render_select_field' ), self::PAGE_SLUG . '-general', 'mvs_watermark',
			array(
				'option'  => 'mvs_watermark_position',
				'choices' => array(
					'center'       => __( 'Center', 'wpmediaverse' ),
					'bottom-right' => __( 'Bottom Right', 'wpmediaverse' ),
					'bottom-left'  => __( 'Bottom Left', 'wpmediaverse' ),
					'top-right'    => __( 'Top Right', 'wpmediaverse' ),
					'top-left'     => __( 'Top Left', 'wpmediaverse' ),
					'tile'         => __( 'Tile (repeat)', 'wpmediaverse' ),
				),
			)
		);

		register_setting( self::OPTION_GROUP . '_general', 'mvs_watermark_opacity', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 40,
		) );
		add_settings_field( 'mvs_watermark_opacity', __( 'Opacity (%)', 'wpmediaverse' ),
			array( $this, 'render_number_field' ), self::PAGE_SLUG . '-general', 'mvs_watermark',
			array(
				'option'      => 'mvs_watermark_opacity',
				'description' => __( '0 = transparent, 100 = fully opaque. Default: 40.', 'wpmediaverse' ),
			)
		);

		register_setting( self::OPTION_GROUP . '_general', 'mvs_watermark_font_size', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 24,
		) );
		add_settings_field( 'mvs_watermark_font_size', __( 'Font Size (px)', 'wpmediaverse' ),
			array( $this, 'render_number_field' ), self::PAGE_SLUG . '-general', 'mvs_watermark',
			array(
				'option'      => 'mvs_watermark_font_size',
				'description' => __( 'Font size for text watermarks in pixels.', 'wpmediaverse' ),
			)
		);

		register_setting( self::OPTION_GROUP . '_general', 'mvs_watermark_color', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_hex_color',
			'default'           => '#ffffff',
		) );
		add_settings_field( 'mvs_watermark_color', __( 'Text Color', 'wpmediaverse' ),
			array( $this, 'render_color_field' ), self::PAGE_SLUG . '-general', 'mvs_watermark',
			array( 'option' => 'mvs_watermark_color' )
		);
	}

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
			self::PAGE_SLUG . '-general'
		);

		$pages = array(
			'mvs_page_dashboard' => __( 'Dashboard Page', 'wpmediaverse' ),
			'mvs_page_explore'   => __( 'Explore Page', 'wpmediaverse' ),
			'mvs_page_upload'    => __( 'Upload Page', 'wpmediaverse' ),
		);

		foreach ( $pages as $option => $label ) {
			register_setting( self::OPTION_GROUP . '_general', $option, array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			) );
			add_settings_field( $option, $label,
				array( $this, 'render_page_dropdown_field' ), self::PAGE_SLUG . '-general', 'mvs_pages',
				array( 'option' => $option )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Page renderer
	// -------------------------------------------------------------------------

	/**
	 * Render the settings page with tabbed navigation.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_mvs_settings' ) ) {
			return;
		}

		$active_tab = $this->get_active_tab();
		$base_url   = admin_url( 'edit.php?post_type=mvs_media&page=' . self::PAGE_SLUG );

		$tabs = array(
			'general'     => __( 'General', 'wpmediaverse' ),
			'display'     => __( 'Display', 'wpmediaverse' ),
			'permissions' => __( 'Permissions', 'wpmediaverse' ),
			'ai'          => __( 'AI & Moderation', 'wpmediaverse' ),
			'webhooks'    => __( 'Webhooks', 'wpmediaverse' ),
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'WPMediaVerse Settings', 'wpmediaverse' ); ?>
				<span class="mvs-version"><?php echo esc_html( 'v' . MVS_VERSION ); ?></span>
			</h1>
			<hr class="wp-header-end">
			<p class="mvs-page-subtitle"><?php esc_html_e( 'Configure your media platform settings.', 'wpmediaverse' ); ?></p>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Settings tabs', 'wpmediaverse' ); ?>">
				<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, $base_url ) ); ?>"
						class="nav-tab<?php echo ( $tab_slug === $active_tab ) ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'permissions' === $active_tab ) : ?>
				<?php $this->render_permissions_tab(); ?>
			<?php else : ?>
				<?php
				$option_group_map = array(
					'general'  => self::OPTION_GROUP . '_general',
					'display'  => self::OPTION_GROUP . '_display',
					'ai'       => self::OPTION_GROUP . '_ai',
					'webhooks' => self::OPTION_GROUP . '_webhooks',
				);
				$page_slug_map    = array(
					'general'  => self::PAGE_SLUG . '-general',
					'display'  => self::PAGE_SLUG . '-display',
					'ai'       => self::PAGE_SLUG . '-ai',
					'webhooks' => self::PAGE_SLUG . '-webhooks',
				);
				$option_group     = $option_group_map[ $active_tab ] ?? ( self::OPTION_GROUP . '_general' );
				$page_slug        = $page_slug_map[ $active_tab ] ?? ( self::PAGE_SLUG . '-general' );
				?>
				<div class="mvs-settings-card">
					<form action="options.php" method="post">
						<?php
						settings_fields( $option_group );
						if ( 'general' === $active_tab ) {
							$this->render_settings_panels( $page_slug );
						} else {
							do_settings_sections( $page_slug );
						}
						submit_button( __( 'Save Settings', 'wpmediaverse' ) );
						?>
					</form>
				</div>
				<?php
				if ( 'general' === $active_tab && $this->is_pro_active() ) {
					$this->render_storage_toggle_script();
				}
				?>
				<?php
				if ( ! $this->is_pro_active() ) {
					$this->render_pro_upsell( $active_tab );
				}
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Settings Panels Renderer (General tab)
	// -------------------------------------------------------------------------

	/**
	 * Panel metadata for each settings section on the General tab.
	 *
	 * @return array<string,array{icon:string,desc:string,class:string,driver:string}>
	 */
	private function get_panel_meta(): array {
		return array(
			'mvs_general'       => array(
				'icon'   => 'dashicons-upload',
				'desc'   => __( 'Upload limits, file types, and privacy defaults.', 'wpmediaverse' ),
				'class'  => '',
				'driver' => '',
			),
			'mvs_storage'       => array(
				'icon'   => 'dashicons-database',
				'desc'   => __( 'Choose where media files are stored.', 'wpmediaverse' ),
				'class'  => '',
				'driver' => '',
			),
			'mvs_pro_license'   => array(
				'icon'   => 'dashicons-admin-network',
				'desc'   => __( 'Activate your license to receive updates and support.', 'wpmediaverse' ),
				'class'  => 'mvs-settings-panel--pro',
				'driver' => '',
			),
			'mvs_pro_s3'        => array(
				'icon'   => 'dashicons-cloud',
				'desc'   => __( 'Connect to Amazon S3 for cloud storage.', 'wpmediaverse' ),
				'class'  => 'mvs-settings-panel--pro',
				'driver' => 's3',
			),
			'mvs_pro_bunny'     => array(
				'icon'   => 'dashicons-performance',
				'desc'   => __( 'Connect to BunnyCDN for global content delivery.', 'wpmediaverse' ),
				'class'  => 'mvs-settings-panel--pro',
				'driver' => 'bunnycdn',
			),
			'mvs_pro_transcode' => array(
				'icon'   => 'dashicons-video-alt3',
				'desc'   => __( 'Convert videos to multiple quality levels with FFmpeg.', 'wpmediaverse' ),
				'class'  => 'mvs-settings-panel--pro',
				'driver' => '',
			),
			'mvs_pro_webhook'   => array(
				'icon'   => 'dashicons-rest-api',
				'desc'   => __( 'Accept credits from external systems via HMAC-signed webhooks.', 'wpmediaverse' ),
				'class'  => 'mvs-settings-panel--pro',
				'driver' => '',
			),
		);
	}

	/**
	 * Render settings as styled panels instead of flat do_settings_sections().
	 *
	 * @param string $page_slug The settings page slug.
	 */
	private function render_settings_panels( string $page_slug ): void {
		global $wp_settings_sections, $wp_settings_fields;

		if ( empty( $wp_settings_sections[ $page_slug ] ) ) {
			return;
		}

		$panel_meta     = $this->get_panel_meta();
		$current_driver = get_option( 'mvs_storage_driver', 'local' );

		foreach ( (array) $wp_settings_sections[ $page_slug ] as $section ) {
			$section_id = $section['id'];
			$meta       = $panel_meta[ $section_id ] ?? null;

			// Fallback: sections without panel metadata render normally.
			if ( ! $meta ) {
				if ( $section['title'] ) {
					echo '<h2>' . esc_html( $section['title'] ) . '</h2>';
				}
				if ( $section['callback'] ) {
					call_user_func( $section['callback'], $section );
				}
				if ( ! empty( $wp_settings_fields[ $page_slug ][ $section_id ] ) ) {
					echo '<table class="form-table" role="presentation">';
					do_settings_fields( $page_slug, $section_id );
					echo '</table>';
				}
				continue;
			}

			// Determine hidden state for driver-specific panels.
			$hidden = '';
			if ( $meta['driver'] && $meta['driver'] !== $current_driver ) {
				$hidden = ' hidden';
			}

			$classes = 'mvs-settings-panel';
			if ( $meta['class'] ) {
				$classes .= ' ' . $meta['class'];
			}

			$driver_attr = '';
			if ( $meta['driver'] ) {
				$driver_attr = sprintf( ' data-mvs-driver="%s"', esc_attr( $meta['driver'] ) );
			}

			printf( '<div class="%s"%s%s>', esc_attr( $classes ), $driver_attr, $hidden );

			// Panel header.
			echo '<div class="mvs-settings-panel__header">';
			printf( '<span class="dashicons %s mvs-settings-panel__icon"></span>', esc_attr( $meta['icon'] ) );
			echo '<div>';
			printf( '<h2 class="mvs-settings-panel__title">%s', esc_html( $section['title'] ) );
			if ( $meta['class'] ) {
				echo ' <span class="mvs-pro-badge">' . esc_html__( 'Pro', 'wpmediaverse' ) . '</span>';
			}
			echo '</h2>';
			if ( $meta['desc'] ) {
				printf( '<p class="mvs-settings-panel__desc">%s</p>', esc_html( $meta['desc'] ) );
			}
			echo '</div>';
			echo '</div>';

			// Panel body.
			echo '<div class="mvs-settings-panel__body">';
			if ( $section['callback'] ) {
				call_user_func( $section['callback'], $section );
			}
			if ( ! empty( $wp_settings_fields[ $page_slug ][ $section_id ] ) ) {
				echo '<table class="form-table" role="presentation">';
				do_settings_fields( $page_slug, $section_id );
				echo '</table>';
			}
			echo '</div>';

			echo '</div>'; // .mvs-settings-panel
		}
	}

	/**
	 * Render inline JS for toggling storage driver panels.
	 */
	private function render_storage_toggle_script(): void {
		?>
		<script>
		(function(){
			var sel = document.querySelector('select[name="mvs_storage_driver"]');
			if (!sel) return;
			function toggle() {
				var panels = document.querySelectorAll('[data-mvs-driver]');
				for (var i = 0; i < panels.length; i++) {
					if (panels[i].getAttribute('data-mvs-driver') === sel.value) {
						panels[i].removeAttribute('hidden');
					} else {
						panels[i].setAttribute('hidden', '');
					}
				}
			}
			sel.addEventListener('change', toggle);
			toggle();
		})();
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Permissions tab
	// -------------------------------------------------------------------------

	/**
	 * Render the Permissions tab — role × capability matrix with checkboxes.
	 */
	private function render_permissions_tab(): void {
		// Handle save.
		if (
			isset( $_POST['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'mvs_save_role_caps' ) &&
			current_user_can( 'manage_mvs_settings' )
		) {
			$this->process_role_caps_save();
		}

		$roles = array(
			'administrator' => __( 'Administrator', 'wpmediaverse' ),
			'editor'        => __( 'Editor', 'wpmediaverse' ),
			'author'        => __( 'Author', 'wpmediaverse' ),
			'contributor'   => __( 'Contributor', 'wpmediaverse' ),
			'subscriber'    => __( 'Subscriber', 'wpmediaverse' ),
		);

		$caps = array(
			'upload_mvs_media'        => __( 'Upload', 'wpmediaverse' ),
			'edit_mvs_media'          => __( 'Edit Own', 'wpmediaverse' ),
			'edit_others_mvs_media'   => __( 'Edit Others', 'wpmediaverse' ),
			'delete_mvs_media'        => __( 'Delete Own', 'wpmediaverse' ),
			'delete_others_mvs_media' => __( 'Delete Others', 'wpmediaverse' ),
			'moderate_mvs_media'      => __( 'Moderate', 'wpmediaverse' ),
			'manage_mvs_settings'     => __( 'Manage Settings', 'wpmediaverse' ),
		);

		$nonce_field_html = wp_nonce_field( 'mvs_save_role_caps', '_wpnonce', true, false );
		?>
		<div class="mvs-permissions-tab">
			<?php settings_errors( 'mvs_role_caps' ); ?>
			<form method="post" action="">
				<?php echo $nonce_field_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field output is safe. ?>
				<input type="hidden" name="mvs_permissions_submit" value="1" />

				<p class="description">
					<?php esc_html_e( 'Control which user roles can perform each media action. Uncheck to revoke a capability.', 'wpmediaverse' ); ?>
				</p>

				<table class="wp-list-table widefat fixed striped mvs-caps-table" style="margin-top:1em;">
					<thead>
						<tr>
							<th scope="col" style="width:140px;"><?php esc_html_e( 'Role', 'wpmediaverse' ); ?></th>
							<?php foreach ( $caps as $cap_key => $cap_label ) : ?>
								<th scope="col" style="text-align:center;"><?php echo esc_html( $cap_label ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $roles as $role_slug => $role_label ) : ?>
							<?php $role_obj = get_role( $role_slug ); ?>
							<tr>
								<td><strong><?php echo esc_html( $role_label ); ?></strong></td>
								<?php foreach ( $caps as $cap_key => $cap_label ) : ?>
									<td style="text-align:center;">
										<?php
										$has_cap = $role_obj && ! empty( $role_obj->capabilities[ $cap_key ] );
										printf(
											'<input type="checkbox" name="mvs_role_caps[%s][%s]" value="1" %s aria-label="%s" />',
											esc_attr( $role_slug ),
											esc_attr( $cap_key ),
											checked( $has_cap, true, false ),
											esc_attr(
												sprintf(
													/* translators: 1: capability label, 2: role label */
													__( '%1$s for %2$s', 'wpmediaverse' ),
													$cap_label,
													$role_label
												)
											)
										);
										?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Permissions', 'wpmediaverse' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Process the role-capability matrix save from the Permissions tab form.
	 */
	/**
	 * @return int Number of roles updated.
	 */
	private function process_role_caps_save(): int {
		$roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
		$caps  = array(
			'upload_mvs_media',
			'edit_mvs_media',
			'edit_others_mvs_media',
			'delete_mvs_media',
			'delete_others_mvs_media',
			'moderate_mvs_media',
			'manage_mvs_settings',
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in render_permissions_tab().
		$raw_caps  = isset( $_POST['mvs_role_caps'] ) && is_array( $_POST['mvs_role_caps'] )
			? wp_unslash( $_POST['mvs_role_caps'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();
		$submitted = array();
		foreach ( $raw_caps as $role_key => $cap_arr ) {
			if ( ! is_array( $cap_arr ) ) {
				continue;
			}
			$clean_role = sanitize_key( $role_key );
			foreach ( $cap_arr as $cap_key => $cap_val ) {
				$submitted[ $clean_role ][ sanitize_key( $cap_key ) ] = sanitize_text_field( $cap_val );
			}
		}

		$updated_count = 0;
		foreach ( $roles as $role_slug ) {
			$role_obj = get_role( $role_slug );
			if ( ! $role_obj ) {
				continue;
			}
			$changed = false;
			foreach ( $caps as $cap ) {
				$granted = ! empty( $submitted[ $role_slug ][ $cap ] );
				$current = $role_obj->has_cap( $cap );
				if ( $granted !== $current ) {
					$changed = true;
				}
				if ( $granted ) {
					$role_obj->add_cap( $cap );
				} else {
					$role_obj->remove_cap( $cap );
				}
			}
			if ( $changed ) {
				++$updated_count;
			}
		}

		add_settings_error( 'mvs_role_caps', 'mvs_role_caps_saved', __( 'Permissions saved.', 'wpmediaverse' ), 'updated' );

		return $updated_count;
	}

	/**
	 * Handle the admin-post action for role cap saves (fallback / direct POST).
	 */
	public function save_role_caps(): void {
		if (
			! isset( $_POST['_wpnonce'] ) || // phpcs:ignore WordPress.Security.NonceVerification.Missing
			! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'mvs_save_role_caps' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		) {
			wp_die( esc_html__( 'Security check failed.', 'wpmediaverse' ) );
		}

		if ( ! current_user_can( 'manage_mvs_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'wpmediaverse' ) );
		}

		$roles_updated = $this->process_role_caps_save();

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'               => 'permissions',
					'permissions-saved' => $roles_updated,
				),
				admin_url( 'edit.php?post_type=mvs_media&page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	/**
	 * Render a number input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_number_field( array $args ): void {
		$value = get_option( $args['option'], '' );
		printf(
			'<input type="number" name="%s" value="%s" class="regular-text" min="0" />',
			esc_attr( $args['option'] ),
			esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a size field with MB suffix and server limit hint.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_size_field( array $args ): void {
		$bytes      = (int) get_option( $args['option'], 104857600 );
		$mb_value   = round( $bytes / MB_IN_BYTES );
		$server_max = wp_max_upload_size();
		$server_mb  = round( $server_max / MB_IN_BYTES );

		printf(
			'<input type="number" name="%s" value="%s" class="small-text" min="1" max="%s" step="1" /> <strong>MB</strong>',
			esc_attr( $args['option'] ),
			esc_attr( $mb_value ),
			esc_attr( $server_mb )
		);
		printf(
			'<p class="description">%s</p>',
			sprintf(
				/* translators: %s: server upload limit in MB */
				esc_html__( 'Maximum file size per upload. Server limit: %s MB.', 'wpmediaverse' ),
				esc_html( $server_mb )
			)
		);
	}

	/**
	 * Sanitize size field — converts MB input to bytes.
	 *
	 * @param mixed $value Input value in MB.
	 * @return int Value in bytes.
	 */
	public function sanitize_size_mb( $value ): int {
		$mb = absint( $value );
		if ( $mb < 1 ) {
			$mb = 100;
		}
		return $mb * MB_IN_BYTES;
	}

	/**
	 * Render the file types checkbox grid.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_file_types_field( array $args ): void {
		$current  = get_option( $args['option'], '' );
		$selected = array_map( 'trim', explode( ',', $current ) );

		$groups = array(
			__( 'Images', 'wpmediaverse' )    => array(
				'image/jpeg' => 'JPEG',
				'image/png'  => 'PNG',
				'image/gif'  => 'GIF',
				'image/webp' => 'WebP',
			),
			__( 'Video', 'wpmediaverse' )     => array(
				'video/mp4'  => 'MP4',
				'video/webm' => 'WebM',
			),
			__( 'Audio', 'wpmediaverse' )     => array(
				'audio/mpeg' => 'MP3',
				'audio/ogg'  => 'OGG',
			),
			__( 'Documents', 'wpmediaverse' ) => array(
				'application/pdf' => 'PDF',
			),
		);

		$known_mimes = array();
		foreach ( $groups as $mime_map ) {
			$known_mimes = array_merge( $known_mimes, array_keys( $mime_map ) );
		}
		$custom_types = array_diff( $selected, $known_mimes, array( '' ) );

		echo '<div class="mvs-file-types-grid">';
		foreach ( $groups as $group_label => $mimes ) {
			printf( '<div class="mvs-file-types-group"><strong>%s</strong>', esc_html( $group_label ) );
			foreach ( $mimes as $mime => $label ) {
				$checked = in_array( $mime, $selected, true ) ? ' checked' : '';
				printf(
					'<label><input type="checkbox" name="%s[]" value="%s"%s /> %s</label>',
					esc_attr( $args['option'] ),
					esc_attr( $mime ),
					$checked,
					esc_html( $label )
				);
			}
			echo '</div>';
		}
		echo '</div>';

		printf(
			'<details class="mvs-custom-types"><summary>%s</summary>',
			esc_html__( 'Custom MIME types', 'wpmediaverse' )
		);
		printf(
			'<textarea name="%s_custom" rows="2" class="large-text" placeholder="%s">%s</textarea>',
			esc_attr( $args['option'] ),
			esc_attr__( 'e.g. image/svg+xml,video/quicktime', 'wpmediaverse' ),
			esc_textarea( implode( ',', $custom_types ) )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Additional comma-separated MIME types for advanced users.', 'wpmediaverse' )
		);
		echo '</details>';
	}

	/**
	 * Sanitize file types — merge checkbox values with custom textarea.
	 *
	 * @param mixed $value Input (array from checkboxes).
	 * @return string Comma-separated MIME types.
	 */
	public function sanitize_file_types( $value ): string {
		$types = array();

		if ( is_array( $value ) ) {
			$types = array_map( 'sanitize_mime_type', $value );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['mvs_allowed_file_types_custom'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$custom     = sanitize_text_field( wp_unslash( $_POST['mvs_allowed_file_types_custom'] ) );
			$custom_arr = array_map( 'trim', explode( ',', $custom ) );
			$custom_arr = array_map( 'sanitize_mime_type', $custom_arr );
			$types      = array_merge( $types, $custom_arr );
		}

		$types = array_unique( array_filter( $types ) );
		return implode( ',', $types );
	}

	/**
	 * Render a textarea field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_textarea_field( array $args ): void {
		$value = get_option( $args['option'], '' );
		printf(
			'<textarea name="%s" rows="3" class="large-text">%s</textarea>',
			esc_attr( $args['option'] ),
			esc_textarea( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a select field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_select_field( array $args ): void {
		$value   = get_option( $args['option'], '' );
		$choices = $args['choices'] ?? array();

		printf( '<select name="%s">', esc_attr( $args['option'] ) );
		foreach ( $choices as $key => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $value, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Render a password input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_password_field( array $args ): void {
		$value   = get_option( $args['option'], '' );
		$display = '';
		if ( $value ) {
			$display = str_repeat( '*', max( 0, strlen( $value ) - 4 ) ) . substr( $value, -4 );
		}

		printf(
			'<input type="password" name="%s" value="%s" class="regular-text" autocomplete="off" placeholder="%s" />',
			esc_attr( $args['option'] ),
			'',
			esc_attr( $display ? sprintf( 'Current: %s', $display ) : '' )
		);
		if ( $value ) {
			echo '<p class="description">' . esc_html__( 'Leave empty to keep the current key.', 'wpmediaverse' ) . '</p>';
		}
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Sanitize a password/API key option — preserve existing value when empty.
	 *
	 * @param string $value New value.
	 * @return string
	 */
	public function sanitize_password_option( $value ): string {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			$option = str_replace( 'sanitize_option_', '', current_filter() );
			return get_option( $option, '' );
		}
		return $value;
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_checkbox_field( array $args ): void {
		$value = get_option( $args['option'], false );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( $args['option'] ),
			checked( $value, true, false ),
			esc_html( $args['label'] ?? '' )
		);
	}

	/**
	 * Render the webhook configuration field.
	 */
	public function render_webhook_field(): void {
		$webhooks = get_option( 'mvs_webhooks', array() );
		$webhook  = ! empty( $webhooks[0] ) ? $webhooks[0] : array(
			'url'    => '',
			'secret' => '',
			'events' => array( '*' ),
		);

		$all_events = \WPMediaVerse\Integrations\WebhookService::EVENTS;
		?>
		<fieldset>
			<p>
				<label><?php esc_html_e( 'URL:', 'wpmediaverse' ); ?></label><br />
				<input type="url" name="mvs_webhooks[0][url]" class="regular-text"
					value="<?php echo esc_attr( $webhook['url'] ?? '' ); ?>"
					placeholder="https://example.com/webhook"
				/>
			</p>
			<p>
				<label><?php esc_html_e( 'Secret:', 'wpmediaverse' ); ?></label><br />
				<?php
				$wh_secret  = $webhook['secret'] ?? '';
				$wh_display = $wh_secret ? str_repeat( '*', max( 0, strlen( $wh_secret ) - 4 ) ) . substr( $wh_secret, -4 ) : '';
				?>
				<input type="password" name="mvs_webhooks[0][secret]" class="regular-text" autocomplete="off"
					value=""
					placeholder="<?php echo esc_attr( $wh_display ? sprintf( 'Current: %s', $wh_display ) : esc_attr__( 'Shared secret for HMAC signing', 'wpmediaverse' ) ); ?>"
				/>
				<?php if ( $wh_secret ) : ?>
					<span class="description"><?php esc_html_e( 'Leave empty to keep the current secret.', 'wpmediaverse' ); ?></span>
				<?php endif; ?>
			</p>
			<p>
				<label><?php esc_html_e( 'Events:', 'wpmediaverse' ); ?></label><br />
				<?php $selected_events = $webhook['events'] ?? array( '*' ); ?>
				<label>
					<input type="checkbox" name="mvs_webhooks[0][events][]" value="*"
						<?php checked( in_array( '*', $selected_events, true ) ); ?>
					/> <?php esc_html_e( 'All events', 'wpmediaverse' ); ?>
				</label><br />
				<?php foreach ( $all_events as $event ) : ?>
					<label>
						<input type="checkbox" name="mvs_webhooks[0][events][]" value="<?php echo esc_attr( $event ); ?>"
							<?php checked( in_array( $event, $selected_events, true ) ); ?>
						/> <code><?php echo esc_html( $event ); ?></code>
					</label><br />
				<?php endforeach; ?>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Render a select field with Pro-locked options shown as disabled.
	 *
	 * @param array $args Field arguments: 'current' (active label), 'pro' (locked labels).
	 */
	public function render_pro_select_field( array $args ): void {
		$current_label = $args['current'] ?? '';
		$pro_options   = $args['pro'] ?? array();

		echo '<select disabled>';
		printf( '<option selected>%s</option>', esc_html( $current_label ) );
		foreach ( $pro_options as $label ) {
			printf( '<option disabled>%s</option>', esc_html( $label ) );
		}
		echo '</select>';
		echo '<span class="mvs-pro-badge">' . esc_html__( 'Pro', 'wpmediaverse' ) . '</span>';
	}

	/**
	 * Render a disabled Pro-locked checkbox field.
	 *
	 * @param array $args Field arguments: 'label'.
	 */
	public function render_pro_checkbox_field( array $args ): void {
		printf(
			'<div class="mvs-pro-field"><label><input type="checkbox" disabled /> %s</label></div>',
			esc_html( $args['label'] ?? '' )
		);
		echo '<span class="mvs-pro-badge">' . esc_html__( 'Pro', 'wpmediaverse' ) . '</span>';
	}

	/**
	 * Render a text input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_text_field( array $args ): void {
		$value = get_option( $args['option'], '' );
		printf(
			'<input type="text" name="%s" value="%s" class="regular-text" />',
			esc_attr( $args['option'] ),
			esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a color picker field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_color_field( array $args ): void {
		$value = get_option( $args['option'], '#ffffff' );
		printf(
			'<input type="color" name="%s" value="%s" />',
			esc_attr( $args['option'] ),
			esc_attr( $value )
		);
	}

	/**
	 * Render a page dropdown field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_page_dropdown_field( array $args ): void {
		$selected = (int) get_option( $args['option'], 0 );
		wp_dropdown_pages( array(
			'name'              => $args['option'],
			'selected'          => $selected,
			'show_option_none'  => __( '— Select —', 'wpmediaverse' ),
			'option_none_value' => 0,
		) );
	}

	/**
	 * Render the Pro upsell section below settings, tailored to the active tab.
	 *
	 * @param string $active_tab Current tab slug.
	 */
	private function render_pro_upsell( string $active_tab ): void {
		$pro_url  = 'https://wbcomdesigns.com/downloads/wpmediaverse-pro/';
		$features = $this->get_pro_features_for_tab( $active_tab );

		if ( empty( $features ) ) {
			return;
		}
		?>
		<div class="mvs-pro-section">
			<h3>
				<?php esc_html_e( 'Unlock More with WPMediaVerse Pro', 'wpmediaverse' ); ?>
				<span class="mvs-pro-badge"><?php esc_html_e( 'Pro', 'wpmediaverse' ); ?></span>
			</h3>
			<ul>
				<?php foreach ( $features as $feature ) : ?>
					<li><?php echo esc_html( $feature ); ?></li>
				<?php endforeach; ?>
			</ul>
			<a href="<?php echo esc_url( $pro_url ); ?>" class="mvs-pro-cta" target="_blank" rel="noopener">
				<?php esc_html_e( 'Upgrade to Pro', 'wpmediaverse' ); ?> &rarr;
			</a>
		</div>
		<?php
	}

	/**
	 * Get Pro feature list for a specific settings tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string[] Feature descriptions.
	 */
	private function get_pro_features_for_tab( string $tab ): array {
		$features = array(
			'general'  => array(
				__( 'Amazon S3 cloud storage for unlimited scalability', 'wpmediaverse' ),
				__( 'BunnyCDN integration for global content delivery', 'wpmediaverse' ),
				__( 'Image watermarking with custom position and opacity', 'wpmediaverse' ),
				__( 'Advanced privacy controls per media item', 'wpmediaverse' ),
			),
			'display'  => array(
				__( 'Custom player skins and branding', 'wpmediaverse' ),
				__( 'Video chapters and resume playback', 'wpmediaverse' ),
				__( 'Auto-generated video captions (Whisper AI)', 'wpmediaverse' ),
			),
			'ai'       => array(
				__( 'Google Vision AI provider', 'wpmediaverse' ),
				__( 'AWS Rekognition AI provider', 'wpmediaverse' ),
				__( 'AI provider fallback chains and comparison mode', 'wpmediaverse' ),
				__( 'Per-category moderation thresholds', 'wpmediaverse' ),
			),
			'webhooks' => array(
				__( 'Multiple webhook endpoints', 'wpmediaverse' ),
				__( 'Webhook delivery logs and retry management', 'wpmediaverse' ),
				__( 'Custom webhook event filters', 'wpmediaverse' ),
			),
		);

		return $features[ $tab ] ?? array();
	}

	/**
	 * Sanitize webhook settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array Sanitized webhooks.
	 */
	public function sanitize_webhooks( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $input as $webhook ) {
			$url = isset( $webhook['url'] ) ? esc_url_raw( $webhook['url'] ) : '';
			if ( empty( $url ) ) {
				continue;
			}

			$events = isset( $webhook['events'] ) && is_array( $webhook['events'] )
				? array_map( 'sanitize_text_field', $webhook['events'] )
				: array( '*' );

			$sanitized[] = array(
				'url'    => $url,
				'secret' => isset( $webhook['secret'] ) ? sanitize_text_field( $webhook['secret'] ) : '',
				'events' => $events,
			);
		}

		return $sanitized;
	}
}
