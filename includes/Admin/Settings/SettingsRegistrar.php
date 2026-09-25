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
		( new GeneralSettingsRegistrar() )->register();
		$this->register_storage_settings();
		( new DisplaySettingsRegistrar() )->register();
		( new AiSettingsRegistrar() )->register();
		( new AppSettingsRegistrar() )->register();
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
	 * here AND in the Display registrar. Two register_setting() calls
	 * for the same option silently overwrites the first sanitize_callback —
	 * the same class of bug that wiped dm_access values. Both are now owned
	 * solely by DisplaySettingsRegistrar. This method only registers
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
	// Storage settings (the General tab's own fields live in GeneralSettingsRegistrar)
	// -------------------------------------------------------------------------

	/**
	 * Where files can be stored, driver => plain label.
	 *
	 * Public so the "storage changed" notice names the place, not the slug.
	 *
	 * @return array<string, string>
	 */
	public static function storage_driver_choices(): array {
		return array(
			'local'    => __( 'This server (WordPress uploads)', 'wpmediaverse' ),
			's3'       => __( 'Amazon S3', 'wpmediaverse' ),
			'bunnycdn' => __( 'BunnyCDN', 'wpmediaverse' ),
			'r2'       => __( 'Cloudflare R2', 'wpmediaverse' ),
			'dospaces' => __( 'DigitalOcean Spaces', 'wpmediaverse' ),
		);
	}

	/**
	 * Register Storage-tab settings.
	 *
	 * Signed URL expiry, view retention, filename strategy and the WebP/AVIF
	 * copies are registered with no field since 2.6.0: each has one right
	 * value for almost every site. Stored values keep working, and the save
	 * guard never resets an option that is not on screen.
	 */
	private function register_storage_settings(): void {
		$page  = SettingsPage::PAGE_SLUG . '-storage';
		$group = SettingsPage::OPTION_GROUP . '_storage';
		$image = \WPMediaVerse\Services\ImageOptimizationService::class;

		add_settings_section( 'mvs_storage', __( 'Storage', 'wpmediaverse' ), '__return_null', $page );

		register_setting(
			$group,
			'mvs_storage_driver',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_storage_driver' ),
				'default'           => 'local',
			)
		);
		$choices = self::storage_driver_choices();
		if ( self::is_pro_active() ) {
			FieldRenderer::add_field(
				'mvs_storage_driver',
				__( 'Where files are stored', 'wpmediaverse' ),
				array( FieldRenderer::class, 'render_select_field' ),
				$page,
				'mvs_storage',
				array(
					'option'      => 'mvs_storage_driver',
					'choices'     => $choices,
					'description' => __( 'New uploads go here. Cloud storage needs the account details below.', 'wpmediaverse' ),
				)
			);
		} else {
			FieldRenderer::add_field(
				'mvs_storage_driver',
				__( 'Where files are stored', 'wpmediaverse' ),
				array( FieldRenderer::class, 'render_pro_select_field' ),
				$page,
				'mvs_storage',
				array(
					'current' => array_shift( $choices ),
					'pro'     => array_values( $choices ),
				)
			);
		}

		$fieldless = array(
			'mvs_signed_url_ttl'                                     => array( 'integer', 'absint', 3600 ),
			// Legacy: public cloud media is always served from its CDN URL;
			// per-request proxying is the `mvs_serve_public_cloud_direct` filter.
			// Inert since 1.4.0: kept registered for stored values, read by nothing.
			'mvs_cloud_direct_public_urls'                           => array( 'boolean', 'rest_sanitize_boolean', false ),
			\WPMediaVerse\Services\ViewRetentionService::SETTING => array( 'integer', array( \WPMediaVerse\Services\ViewRetentionService::class, 'sanitize_setting' ), \WPMediaVerse\Services\ViewRetentionService::DEFAULT_DAYS ),
			// Default ON when the editor can write WebP; AVIF stays opt-in.
			$image::SETTING_GENERATE_WEBP                            => array( 'boolean', 'rest_sanitize_boolean', true ),
			$image::SETTING_GENERATE_AVIF                            => array( 'boolean', 'rest_sanitize_boolean', false ),
			// Hashed by default since 1.6.0; matches FilenameStrategy's runtime
			// fallback so get_option() and resolve_strategy agree (#9962530792).
			\WPMediaVerse\Services\FilenameStrategy::SETTING     => array( 'string', array( Sanitizers::class, 'sanitize_filename_strategy' ), \WPMediaVerse\Services\FilenameStrategy::effective_default() ),
		);
		foreach ( $fieldless as $option => $spec ) {
			register_setting(
				$group,
				$option,
				array(
					'type'              => $spec[0],
					'sanitize_callback' => $spec[1],
					'default'           => $spec[2],
				)
			);
		}

		// Lossy for JPEG (about quality 92), so OFF by default: re-encoding every
		// upload can only lose detail (Basecamp #10073918955). Removing GPS is a
		// separate lossless step controlled by mvs_strip_exif.
		register_setting(
			$group,
			$image::SETTING_OPTIMIZE_ORIGINALS,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		FieldRenderer::add_field(
			$image::SETTING_OPTIMIZE_ORIGINALS,
			__( 'Compress uploaded images', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			$page,
			'mvs_storage',
			array(
				'option'      => $image::SETTING_OPTIMIZE_ORIGINALS,
				'description' => __( 'Makes new images 10-30% smaller. JPEGs lose a little quality.', 'wpmediaverse' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// AI & Moderation settings
	// -------------------------------------------------------------------------

	/**
	 * Register Moderation-related settings (Safety → Moderation sidebar).
	 */
	private function register_moderation_settings(): void {
		add_settings_section( 'mvs_moderation', __( 'Moderation', 'wpmediaverse' ), '__return_null', SettingsPage::PAGE_SLUG . '-moderation' );

		// What members may post. Shown in the app and beside the Report control.
		// The other legal links live on the Mobile App tab (AppSettingsRegistrar).
		register_setting(
			SettingsPage::OPTION_GROUP . '_moderation',
			'mvs_guidelines_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
		FieldRenderer::add_field(
			'mvs_guidelines_url',
			__( 'Community Guidelines URL', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_text_field' ),
			SettingsPage::PAGE_SLUG . '-moderation',
			'mvs_moderation',
			array(
				'option'      => 'mvs_guidelines_url',
				'description' => __( 'What members may and may not post. Shown in the app and alongside the Report control.', 'wpmediaverse' ),
				'placeholder' => 'https://',
			)
		);

		// Member abuse reporting. Defaults ON: a community whose members cannot
		// report abuse is not safe to run, and the mobile app cannot ship
		// without a working report path (App Store guideline 1.2). Reports land
		// in the User Reports screen. Owners may opt out, but they must choose
		// to.
		register_setting(
			SettingsPage::OPTION_GROUP . '_moderation',
			'mvs_enable_reports',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		FieldRenderer::add_field(
			'mvs_enable_reports',
			__( 'Member Reporting', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-moderation',
			'mvs_moderation',
			array(
				'option'      => 'mvs_enable_reports',
				'label'       => __( 'Allow members to report media and members', 'wpmediaverse' ),
				'description' => __( 'Members can flag content and people for review. Reports appear under User Reports. Turning this off hides every Report control and refuses incoming reports.', 'wpmediaverse' ),
			)
		);

		// Lived on the AI tab until 2.6.0; it is a moderation decision.
		register_setting(
			SettingsPage::OPTION_GROUP . '_moderation',
			'mvs_ai_auto_moderate',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		FieldRenderer::add_field(
			'mvs_ai_auto_moderate',
			__( 'AI Moderation', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-moderation',
			'mvs_moderation',
			array(
				'option' => 'mvs_ai_auto_moderate',
				'label'  => __( 'Check new uploads with AI and act on what it flags.', 'wpmediaverse' ),
			)
		);

		register_setting(
			SettingsPage::OPTION_GROUP . '_moderation',
			'mvs_moderation_auto_action',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_moderation_auto_action' ),
				// MUST match ModerationService's runtime default (get_option(..,
				// 'flag')) and the sanitizer's fallback ('flag'). This registered
				// default drives the settings field's displayed selection and the
				// REST schema, so 'delete' here made a fresh install SHOW "Delete
				// permanently" as the default and persist it on the first Save —
				// silently arming auto-deletion of flagged uploads. Auto-moderation
				// must default to the safe, reversible action. Basecamp 10074612454.
				'default'           => 'flag',
			)
		);
		FieldRenderer::add_field(
			'mvs_moderation_auto_action',
			__( 'When AI Flags Content', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-moderation',
			'mvs_moderation',
			array(
				'option'      => 'mvs_moderation_auto_action',
				'show_when'   => 'mvs_ai_auto_moderate',
				// Three choices since 2.6.0: "Flag for review" and "Hide" both hid
				// the item until review, so owners were choosing between two
				// names for one outcome. A stored 'hide' still works and is saved
				// back as 'flag' (Sanitizers::sanitize_moderation_auto_action).
				'choices'     => array(
					'flag'   => __( 'Hide until I review it', 'wpmediaverse' ),
					'reject' => __( 'Reject (move to draft)', 'wpmediaverse' ),
					'delete' => __( 'Delete permanently', 'wpmediaverse' ),
				),
				'description' => __( 'What happens when AI flags an upload. Hidden items stay visible to their author and appear in Moderation; approving one restores it. "Delete permanently" removes the files too and cannot be undone.', 'wpmediaverse' ),
			)
		);

		// AI flag-criteria rule — which content categories the AI flags. Stored
		// as an array of category keys; every category is enabled by default so
		// the rule is never blank.
		register_setting(
			SettingsPage::OPTION_GROUP . '_moderation',
			'mvs_ai_moderation_categories',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_ai_moderation_categories' ),
				'default'           => \WPMediaVerse\Services\AIService::MODERATION_CATEGORIES,
			)
		);
		FieldRenderer::add_field(
			'mvs_ai_moderation_categories',
			__( 'AI Flag Criteria', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_group_field' ),
			SettingsPage::PAGE_SLUG . '-moderation',
			'mvs_moderation',
			array(
				'option'      => 'mvs_ai_moderation_categories',
				'show_when'   => 'mvs_ai_auto_moderate',
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
			SettingsPage::OPTION_GROUP . '_moderation',
			'mvs_ai_moderation_custom_terms',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_ai_moderation_custom_terms' ),
				'default'           => '',
			)
		);
		FieldRenderer::add_field(
			'mvs_ai_moderation_custom_terms',
			__( 'Custom Flag Terms', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_textarea_field' ),
			SettingsPage::PAGE_SLUG . '-moderation',
			'mvs_moderation',
			array(
				'option'      => 'mvs_ai_moderation_custom_terms',
				'show_when'   => 'mvs_ai_auto_moderate',
				'description' => __( 'Optional. Comma-separated terms the AI should also flag, in addition to the categories above — e.g. weapons, gambling, political content, competitor logos. Leave blank to use only the built-in categories.', 'wpmediaverse' ),
			)
		);

		// Report auto-hide threshold.
		register_setting(
			SettingsPage::OPTION_GROUP . '_moderation',
			'mvs_report_auto_hide_threshold',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 3,
			)
		);
		FieldRenderer::add_field(
			'mvs_report_auto_hide_threshold',
			__( 'Auto-Hide Threshold', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_number_field' ),
			SettingsPage::PAGE_SLUG . '-moderation',
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
		FieldRenderer::add_field(
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
	 * Register Messages settings.
	 */
	private function register_messaging_settings(): void {
		add_settings_section(
			'mvs_messaging',
			__( 'Messages', 'wpmediaverse' ),
			'__return_null',
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
		FieldRenderer::add_field(
			'mvs_dm_access',
			__( 'Who can send messages', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-social',
			'mvs_messaging',
			array(
				'option'      => 'mvs_dm_access',
				'choices'     => array(
					'everyone'  => __( 'Everyone', 'wpmediaverse' ),
					'followers' => __( 'Followers only (others go to Requests)', 'wpmediaverse' ),
					'mutual'    => __( 'Mutual followers only', 'wpmediaverse' ),
					'nobody'    => __( 'Nobody (messages off)', 'wpmediaverse' ),
				),
				'description' => __( 'Who may start a conversation with another member.', 'wpmediaverse' ),
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
		FieldRenderer::add_field(
			'mvs_dm_min_age',
			__( 'Minimum Account Age (days)', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_number_field' ),
			SettingsPage::PAGE_SLUG . '-social',
			'mvs_messaging',
			array(
				'option'      => 'mvs_dm_min_age',
				'description' => __( 'Accounts younger than this cannot send messages. 0 turns this off.', 'wpmediaverse' ),
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
		FieldRenderer::add_field(
			'mvs_chat_panel_visibility',
			__( 'Chat Panel Visibility', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			SettingsPage::PAGE_SLUG . '-social',
			'mvs_messaging',
			array(
				'option'      => 'mvs_chat_panel_visibility',
				'choices'     => array(
					'everywhere' => __( 'Everywhere (default)', 'wpmediaverse' ),
					'mvs_pages'  => __( 'MediaVerse pages only (Explore, Dashboard, Albums, Member Profiles)', 'wpmediaverse' ),
					'bp_pages'   => __( 'BuddyPress pages only (member + group)', 'wpmediaverse' ),
					'disabled'   => __( 'Never show the slide-out (use only the dedicated /messages/ page)', 'wpmediaverse' ),
				),
				'description' => __( 'Where the floating chat icon appears for signed-in members.', 'wpmediaverse' ),
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
		FieldRenderer::add_field(
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
				'description' => __( 'Who can see that a member is online right now.', 'wpmediaverse' ),
			)
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
			SettingsPage::PAGE_SLUG . '-general'
		);

		$pages = array(
			'mvs_page_dashboard'         => __( 'My Media page', 'wpmediaverse' ),
			'mvs_page_explore'           => __( 'Explore Page', 'wpmediaverse' ),
			'mvs_page_upload'            => __( 'Upload Page', 'wpmediaverse' ),
			// Created on activation since 2.4.0 but never offered here, so the
			// owner could not point it anywhere — entry-point rule 18's backend
			// half, missing for a page the plugin makes on their behalf.
			'mvs_page_explore_documents' => __( 'Explore Documents Page', 'wpmediaverse' ),
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
			FieldRenderer::add_field(
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
