<?php
/**
 * Mobile App settings registrar.
 *
 * Everything the mobile app reads about this community in one tab: whether
 * members may sign in with their password, and the legal/safety links the app
 * shows (handed over through /mvs/v1/app/config -> legal.*, because App Store
 * guideline 1.2 expects a UGC app to publish its terms and an abuse contact).
 *
 * @package WPMediaVerse
 * @since   2.6.0
 */

namespace WPMediaVerse\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Mobile App settings section.
 */
class AppSettingsRegistrar {

	/**
	 * Register all Mobile App settings + fields.
	 */
	public function register(): void {
		$page  = SettingsPage::PAGE_SLUG . '-app';
		$group = SettingsPage::OPTION_GROUP . '_app';

		add_settings_section( 'mvs_app', __( 'Mobile App', 'wpmediaverse' ), '__return_null', $page );

		// AppCredentials::is_enabled() reads this: the switch that lets members
		// trade their WordPress password for an app credential.
		register_setting(
			$group,
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
			$page,
			'mvs_app',
			array(
				'option'      => 'mvs_app_password_login',
				'label'       => __( 'Let members sign in to a mobile app with their WordPress password.', 'wpmediaverse' ),
				'description' => __( 'Turn this off if every member must use the full login, for example with two-factor sign-in.', 'wpmediaverse' ),
			)
		);

		// The privacy policy is not asked for: WordPress core already has it.
		// The EULA stays registered without a field - blank means the standard
		// Apple licence, which is what almost every site wants.
		$links = array(
			'mvs_terms_url' => array( __( 'Terms of Service URL', 'wpmediaverse' ), __( 'Shown in the mobile app under About. Leave blank if you have none.', 'wpmediaverse' ) ),
			'mvs_eula_url'  => null,
		);
		foreach ( $links as $option => $labels ) {
			register_setting(
				$group,
				$option,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'default'           => '',
				)
			);
			if ( $labels ) {
				FieldRenderer::add_field(
					$option,
					$labels[0],
					array( FieldRenderer::class, 'render_text_field' ),
					$page,
					'mvs_app',
					array(
						'option'      => $option,
						'description' => $labels[1],
						'placeholder' => 'https://',
					)
				);
			}
		}

		// Somebody must receive abuse reports. Defaults to the site admin.
		register_setting(
			$group,
			'mvs_abuse_contact_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => '',
			)
		);
		FieldRenderer::add_field(
			'mvs_abuse_contact_email',
			__( 'Abuse Contact Email', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_text_field' ),
			$page,
			'mvs_app',
			array(
				'option'      => 'mvs_abuse_contact_email',
				'description' => __( 'Where members can reach a person about abuse. Shown in the mobile app. Blank uses the site admin email.', 'wpmediaverse' ),
				'placeholder' => get_option( 'admin_email' ),
			)
		);
	}
}
