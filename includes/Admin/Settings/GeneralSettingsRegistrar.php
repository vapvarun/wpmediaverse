<?php
/**
 * General settings registrar.
 *
 * Extracted from SettingsRegistrar (Known Debt: "next edit must extract a
 * group, not add one") so the General tab - upload limits, file types,
 * privacy defaults, duplicate detection, EXIF, app sign-in and data removal -
 * lives in its own focused unit, the way AiSettingsRegistrar does. Same
 * namespace, so FieldRenderer / SettingsPage resolve without imports.
 *
 * @package WPMediaVerse
 * @since   2.6.0
 */

namespace WPMediaVerse\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the General settings section (General tab).
 */
class GeneralSettingsRegistrar {

	/**
	 * Option that opts in to deleting all MediaVerse data on uninstall.
	 *
	 * Read by both plugins' uninstall.php, which cannot autoload this class,
	 * so they spell the key themselves. Absent or anything but '1' = keep data.
	 */
	public const DELETE_DATA_OPTION = 'mvs_delete_data_on_uninstall';

	/**
	 * Register all General-section settings + fields.
	 */
	public function register(): void {
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
		FieldRenderer::add_field(
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
				'default'           => SettingsRegistrar::DEFAULT_ALLOWED_FILE_TYPES,
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
				if ( (string) $old_value === SettingsRegistrar::DEFAULT_ALLOWED_FILE_TYPES ) {
					delete_option( 'mvs_allowed_file_types' );
				}
				return $value;
			},
			10,
			2
		);

		FieldRenderer::add_field(
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
		FieldRenderer::add_field(
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
		FieldRenderer::add_field(
			'mvs_allow_user_privacy',
			__( 'Allow Users to Set Privacy', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'      => 'mvs_allow_user_privacy',
				'label'       => __( 'Allow users to choose the privacy level of their media.', 'wpmediaverse' ),
				'description' => __( 'When disabled, all uploads use the Default Privacy Level above and members cannot change it afterwards: the privacy selector is hidden when uploading, editing and in bulk actions. Users who can manage MediaVerse settings keep the control.', 'wpmediaverse' ),
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
		FieldRenderer::add_field(
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
		FieldRenderer::add_field(
			'mvs_strip_exif',
			__( 'Strip EXIF Data', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'      => 'mvs_strip_exif',
				'label'       => __( 'Remove GPS location from uploaded photos.', 'wpmediaverse' ),
				'description' => __( 'On by default. Only the coordinates are removed - camera, lens, exposure and the copyright/credit fields stay on the photo, so a photographer keeps their metadata and their byline. Applies to new uploads and to replaced files; photos already in the library are not changed.', 'wpmediaverse' ),
			)
		);

		// App sign-in. AppCredentials::is_enabled() has always READ this option
		// and its docblock calls it an owner switch — but nothing registered or
		// wrote it, so the only way to turn the exchange off was to write PHP
		// against the mvs_app_password_login_enabled filter. A switch that
		// controls whether members can trade their WordPress password for an
		// app credential belongs in the UI. Caught by the contract audit
		// (option-read-never-written) before the 2.3.0 release.
		register_setting(
			SettingsPage::OPTION_GROUP . '_general',
			'mvs_app_password_login',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		FieldRenderer::add_field(
			'mvs_app_password_login',
			__( 'App Sign-In', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'      => 'mvs_app_password_login',
				'label'       => __( 'Let members sign in to a mobile app with their WordPress password.', 'wpmediaverse' ),
				'description' => __( 'The site issues an Application Password behind the scenes. Turn this off if you require every member to go through the interactive login instead, for example when you enforce two-factor authentication.', 'wpmediaverse' ),
			)
		);

		$this->register_delete_data_setting();
	}

	/**
	 * The opt-in that lets uninstall delete member data.
	 *
	 * Off by default: premium plugins are routinely updated by deleting and
	 * re-uploading them, and before 2.6.0 that wiped every table. Uploaded
	 * files and the pages MediaVerse created are never deleted, and the help
	 * says so.
	 */
	private function register_delete_data_setting(): void {
		register_setting(
			SettingsPage::OPTION_GROUP . '_general',
			self::DELETE_DATA_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		FieldRenderer::add_field(
			self::DELETE_DATA_OPTION,
			__( 'Remove Data on Delete', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'      => self::DELETE_DATA_OPTION,
				'label'       => __( 'Delete all MediaVerse data when the plugin is deleted.', 'wpmediaverse' ),
				'description' => __( 'Removes media records, albums, messages, settings and permissions. Uploaded files and the pages MediaVerse created are kept. Leave this off if you might reinstall.', 'wpmediaverse' ),
			)
		);
	}
}
