<?php
/**
 * AI Features settings registrar.
 *
 * Extracted from SettingsRegistrar so the AI settings group (provider,
 * per-feature toggles, budget, OpenAI config) lives in its own focused unit.
 * Pays back the debt-tax growth the 1.4.0 per-feature AI toggles added to
 * SettingsRegistrar. Same namespace, so FieldRenderer / SettingsPage resolve
 * without imports exactly as before.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the AI Features settings group (AI tab).
 */
class AiSettingsRegistrar {

	/**
	 * Register all AI-tab settings + fields.
	 */
	public function register(): void {
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
						'openai'        => __( 'OpenAI (GPT-4 Vision)', 'wpmediaverse' ),
						'google_vision' => __( 'Google Vision', 'wpmediaverse' ),
						'rekognition'   => __( 'AWS Rekognition', 'wpmediaverse' ),
						'anthropic'     => __( 'Claude (Anthropic)', 'wpmediaverse' ),
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
						__( 'Claude (Anthropic)', 'wpmediaverse' ),
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
				'class'       => 'mvs-ai-openai-field',
				'description' => sprintf(
					/* translators: %s: link to the OpenAI API keys page. */
					__( 'Get a key from %s (sign in, create a secret key, and make sure billing is enabled on the account). Or define the MVS_OPENAI_API_KEY constant in wp-config.php.', 'wpmediaverse' ),
					'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a>'
				),
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
				'class'       => 'mvs-ai-openai-field',
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
				'label'  => __( 'Automatically run AI on upload (master switch for the two options below).', 'wpmediaverse' ),
			)
		);

		// Per-feature control: when auto-analyze is on, the owner can still
		// choose which AI outputs to generate. Both default on so existing
		// installs keep their current behavior.
		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_ai_auto_describe',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		add_settings_field(
			'mvs_ai_auto_describe',
			__( 'Generate Descriptions', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option' => 'mvs_ai_auto_describe',
				'label'  => __( 'Use AI to generate a description / alt text for each upload.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_ai',
			'mvs_ai_auto_tag',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		add_settings_field(
			'mvs_ai_auto_tag',
			__( 'Generate Tags', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-ai',
			'mvs_ai',
			array(
				'option' => 'mvs_ai_auto_tag',
				'label'  => __( 'Use AI to generate tags for each upload. Apply them to the taxonomy with the option below.', 'wpmediaverse' ),
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
				// Default of 10 (USD) is a conservative cap so a fresh install
				// never silently runs against an unbounded OpenAI bill if the
				// owner enables auto-analyze / auto-tag without first picking
				// a budget. Set explicitly to 0 in the UI to opt back into
				// unlimited spend. Existing installs that already have a
				// stored value are untouched (Activator::set_defaults() and
				// the v1 budget migration both use add_option).
				'default'           => 10,
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
				'description' => __( 'Hard cap on OpenAI spend per calendar month. AI calls stop when this cap is reached and resume next month. Set to 0 to disable the cap (unlimited spend) — recommended only after you have a billing alert configured on the OpenAI account itself.', 'wpmediaverse' ),
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
	 * Whether the Pro plugin is active.
	 *
	 * @return bool
	 */
	private static function is_pro_active(): bool {
		return defined( 'MVS_PRO_VERSION' );
	}
}
