<?php
/**
 * Messages settings registrar.
 *
 * Extracted from SettingsRegistrar (Known Debt: "next edit must extract a
 * group, not add one") when the Messages master switch was added, the way
 * GeneralSettingsRegistrar and AiSettingsRegistrar were. Same namespace, so
 * FieldRenderer / SettingsPage / Sanitizers resolve without imports.
 *
 * @package WPMediaVerse
 * @since   2.6.0
 */

namespace WPMediaVerse\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Messages section of the Social tab.
 */
class MessagingSettingsRegistrar {

	/**
	 * Option holding the Messages master switch.
	 *
	 * @var string
	 */
	public const ENABLED_OPTION = 'mvs_messaging_enabled';

	/**
	 * Register the Messages section and its fields.
	 *
	 * @return void
	 */
	public function register(): void {
		add_settings_section(
			'mvs_messaging',
			__( 'Messages', 'wpmediaverse' ),
			'__return_null',
			SettingsPage::PAGE_SLUG . '-social'
		);
		// The master switch (owner decision 2026-09-26). Off, the messaging engine
		// never boots - no routes, chat panel, /messages/ page or Message buttons,
		// and no `messaging` service, so BuddyNext's Messages hides itself too.
		// Stored conversations are kept. Read through Plugin::messaging_enabled().
		register_setting(
			SettingsPage::OPTION_GROUP . '_social',
			self::ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		FieldRenderer::add_field(
			self::ENABLED_OPTION,
			__( 'Messages', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			SettingsPage::PAGE_SLUG . '-social',
			'mvs_messaging',
			array(
				'option'      => self::ENABLED_OPTION,
				'label'       => __( 'Turn on private messages', 'wpmediaverse' ),
				'description' => __( 'Off: messaging disappears everywhere, including the Messages pages of integrations built on MediaVerse. Existing conversations are kept and come back when you turn it on again.', 'wpmediaverse' ),
			)
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
					'nobody'    => __( 'Nobody (no new messages; existing conversations stay readable)', 'wpmediaverse' ),
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
}
