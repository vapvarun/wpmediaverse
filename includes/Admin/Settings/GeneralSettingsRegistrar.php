<?php
/**
 * General settings registrar.
 *
 * Extracted from SettingsRegistrar (Known Debt: "next edit must extract a
 * group, not add one") so the General tab - upload limits, file types,
 * privacy defaults, duplicate detection, EXIF, who can upload and data removal -
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

		// Fair-use storage limit per member (2.6.0). 0 = no limit, the default.
		register_setting(
			SettingsPage::OPTION_GROUP . '_general',
			\WPMediaVerse\Services\StorageLimitService::OPTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		FieldRenderer::add_field(
			\WPMediaVerse\Services\StorageLimitService::OPTION,
			__( 'Fair-use storage limit per member (MB)', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_number_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'      => \WPMediaVerse\Services\StorageLimitService::OPTION,
				'description' => __( 'Stops one account from filling the server. Leave at 0 unless you need it. You can give one member a different limit on their user profile.', 'wpmediaverse' ),
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
				'label'       => __( 'Let members choose', 'wpmediaverse' ),
				'description' => __( 'Members choose who sees each upload. Off: every upload uses the default above.', 'wpmediaverse' ),
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
					'skip'  => __( 'Block the upload', 'wpmediaverse' ),
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
			__( 'Remove location from photos', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'      => 'mvs_strip_exif',
				'label'       => __( 'Remove GPS location from uploaded photos.', 'wpmediaverse' ),
				'description' => __( 'Only the GPS position is removed. Camera details and photo credits stay. Applies to new uploads.', 'wpmediaverse' ),
			)
		);

		// mvs_app_password_login moved to the Mobile App tab in 2.6.0.

		$this->register_upload_roles_setting();
		$this->register_delete_data_setting();
		$this->register_email_settings();
	}

	/**
	 * "Who can upload media": the one new control of the 2.6.0 simplification,
	 * replacing the Permissions matrix for the question owners actually ask.
	 *
	 * The mvs_upload_roles option is only the transport: the sanitizer writes the
	 * upload_mvs_media capability through MediaCapabilities, and the field reads
	 * it back from the roles, so nothing here can disagree with who can upload.
	 */
	private function register_upload_roles_setting(): void {
		register_setting(
			SettingsPage::OPTION_GROUP . '_general',
			'mvs_upload_roles',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Sanitizers::class, 'sanitize_upload_roles' ),
				'default'           => array(),
			)
		);
		FieldRenderer::add_field(
			'mvs_upload_roles',
			__( 'Who can upload media', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_upload_roles_field' ),
			SettingsPage::PAGE_SLUG . '-general',
			'mvs_general',
			array(
				'option'      => 'mvs_upload_roles',
				'description' => __( 'Members of the ticked roles can upload. Administrators can always upload. Unticking a role deletes nothing.', 'wpmediaverse' ),
			)
		);
	}

	/**
	 * Emails section: the few member emails the owner can switch on (2.6.0).
	 *
	 * Account deletion confirmations are always sent, so they have no box.
	 */
	private function register_email_settings(): void {
		add_settings_section(
			'mvs_emails',
			__( 'Emails', 'wpmediaverse' ),
			function () {
				echo '<p>' . esc_html__( 'Email members about things they would otherwise miss while away. Members can turn these off with one switch in their profile or the link in any email. Account deletion confirmations are always sent.', 'wpmediaverse' ) . '</p>';
			},
			SettingsPage::PAGE_SLUG . '-general'
		);

		$emails = array(
			\WPMediaVerse\Services\EmailService::TYPES['battle_invite']   => array(
				__( 'Photo battle invites', 'wpmediaverse' ),
				__( 'Someone challenged the member to a battle (MediaVerse Pro).', 'wpmediaverse' ),
			),
			\WPMediaVerse\Services\EmailService::TYPES['document_shared'] => array(
				__( 'Documents shared with a member', 'wpmediaverse' ),
				__( 'Someone shared a document with the member (MediaVerse Pro).', 'wpmediaverse' ),
			),
			\WPMediaVerse\Services\EmailService::TYPES['report_resolved'] => array(
				__( 'Report reviewed', 'wpmediaverse' ),
				__( 'A moderator resolved or dismissed a report the member filed. The email does not say what was decided.', 'wpmediaverse' ),
			),
		);

		foreach ( $emails as $option => $copy ) {
			register_setting(
				SettingsPage::OPTION_GROUP . '_general',
				$option,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'default'           => false,
				)
			);
			FieldRenderer::add_field(
				$option,
				$copy[0],
				array( FieldRenderer::class, 'render_checkbox_field' ),
				SettingsPage::PAGE_SLUG . '-general',
				'mvs_emails',
				array(
					'option'      => $option,
					'label'       => __( 'Send this email', 'wpmediaverse' ),
					'description' => $copy[1],
				)
			);
		}
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
