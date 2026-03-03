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
 */
class SettingsPage {

	const PAGE_SLUG    = 'mvs-settings';
	const OPTION_GROUP = 'mvs_settings';

	/**
	 * Constructor. Hooks admin menu and settings registration.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add settings page under Settings menu.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'WPMediaVerse Settings', 'wpmediaverse' ),
			__( 'WPMediaVerse', 'wpmediaverse' ),
			'manage_mvs_settings',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register all settings, sections, and fields.
	 */
	public function register_settings(): void {
		// General section.
		add_settings_section(
			'mvs_general',
			__( 'General', 'wpmediaverse' ),
			'__return_null',
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			'mvs_max_upload_size',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 104857600,
			)
		);

		add_settings_field(
			'mvs_max_upload_size',
			__( 'Max Upload Size (bytes)', 'wpmediaverse' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'mvs_general',
			array(
				'option'      => 'mvs_max_upload_size',
				'description' => __( 'Maximum file upload size in bytes. Default: 104857600 (100 MB).', 'wpmediaverse' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'mvs_allowed_file_types',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg',
			)
		);

		add_settings_field(
			'mvs_allowed_file_types',
			__( 'Allowed File Types', 'wpmediaverse' ),
			array( $this, 'render_textarea_field' ),
			self::PAGE_SLUG,
			'mvs_general',
			array(
				'option'      => 'mvs_allowed_file_types',
				'description' => __( 'Comma-separated MIME types.', 'wpmediaverse' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
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
			self::PAGE_SLUG,
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

		// Uploads section.
		add_settings_section(
			'mvs_uploads',
			__( 'Uploads', 'wpmediaverse' ),
			'__return_null',
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
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
			self::PAGE_SLUG,
			'mvs_uploads',
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
			self::OPTION_GROUP,
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
			self::PAGE_SLUG,
			'mvs_uploads',
			array(
				'option' => 'mvs_strip_exif',
				'label'  => __( 'Remove GPS and device data from uploaded images.', 'wpmediaverse' ),
			)
		);

		// Storage section.
		add_settings_section(
			'mvs_storage',
			__( 'Storage', 'wpmediaverse' ),
			'__return_null',
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			'mvs_storage_driver',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'local',
			)
		);

		add_settings_field(
			'mvs_storage_driver',
			__( 'Storage Driver', 'wpmediaverse' ),
			array( $this, 'render_select_field' ),
			self::PAGE_SLUG,
			'mvs_storage',
			array(
				'option'  => 'mvs_storage_driver',
				'choices' => array(
					'local'    => __( 'Local (WordPress uploads)', 'wpmediaverse' ),
					's3'       => __( 'Amazon S3 (Pro)', 'wpmediaverse' ),
					'bunnycdn' => __( 'BunnyCDN (Pro)', 'wpmediaverse' ),
				),
			)
		);

		// AI section.
		add_settings_section(
			'mvs_ai',
			__( 'AI Features', 'wpmediaverse' ),
			'__return_null',
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			'mvs_ai_provider',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'openai',
			)
		);

		add_settings_field(
			'mvs_ai_provider',
			__( 'AI Provider', 'wpmediaverse' ),
			array( $this, 'render_select_field' ),
			self::PAGE_SLUG,
			'mvs_ai',
			array(
				'option'  => 'mvs_ai_provider',
				'choices' => array(
					'openai' => __( 'OpenAI (GPT-4 Vision)', 'wpmediaverse' ),
				),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'mvs_openai_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_field(
			'mvs_openai_api_key',
			__( 'OpenAI API Key', 'wpmediaverse' ),
			array( $this, 'render_password_field' ),
			self::PAGE_SLUG,
			'mvs_ai',
			array(
				'option'      => 'mvs_openai_api_key',
				'description' => __( 'Or define MVS_OPENAI_API_KEY constant in wp-config.php.', 'wpmediaverse' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
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
			self::PAGE_SLUG,
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
			self::OPTION_GROUP,
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
			self::PAGE_SLUG,
			'mvs_ai',
			array(
				'option' => 'mvs_ai_auto_analyze',
				'label'  => __( 'Automatically analyze media on upload (description + tags).', 'wpmediaverse' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
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
			self::PAGE_SLUG,
			'mvs_ai',
			array(
				'option' => 'mvs_ai_auto_apply_tags',
				'label'  => __( 'Automatically assign AI-generated tags to taxonomy.', 'wpmediaverse' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
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
			self::PAGE_SLUG,
			'mvs_ai',
			array(
				'option' => 'mvs_ai_auto_moderate',
				'label'  => __( 'Check uploads for policy violations via AI.', 'wpmediaverse' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
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
			self::PAGE_SLUG,
			'mvs_ai',
			array(
				'option'      => 'mvs_ai_monthly_budget',
				'description' => __( 'Set to 0 for unlimited. AI calls will stop when budget is exceeded.', 'wpmediaverse' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
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
			self::PAGE_SLUG,
			'mvs_ai',
			array(
				'option'      => 'mvs_ai_cost_per_call',
				'description' => __( 'Approximate cost per API call for budget tracking.', 'wpmediaverse' ),
			)
		);

		// Moderation section.
		add_settings_section(
			'mvs_moderation',
			__( 'Moderation', 'wpmediaverse' ),
			'__return_null',
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
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
			self::PAGE_SLUG,
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
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_mvs_settings' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

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
		$value = get_option( $args['option'], '' );
		printf(
			'<input type="password" name="%s" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( $args['option'] ),
			esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
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
}
