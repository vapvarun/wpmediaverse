<?php
/**
 * Display settings registrar.
 *
 * Extracted from SettingsRegistrar (Known Debt: the next edit extracts a
 * group) so the Display tab lives in its own unit, like General and AI.
 *
 * @package WPMediaVerse
 * @since   2.6.0
 */

namespace WPMediaVerse\Admin\Settings;

use WPMediaVerse\Core\SettingsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Display settings section.
 */
class DisplaySettingsRegistrar {

	/**
	 * Register all Display-tab settings + fields.
	 */
	public function register(): void {
		$page = SettingsPage::PAGE_SLUG . '-display';

		add_settings_section( 'mvs_display', __( 'Media Display', 'wpmediaverse' ), '__return_null', $page );

		// One "Layout" control. mvs_layout_choice is only its transport: the
		// sanitizer writes mvs_thumbnail_style (and Pro its feed layout), which
		// is what every grid reads.
		$this->setting( 'mvs_layout_choice', 'string', array( Sanitizers::class, 'sanitize_layout_choice' ), SettingsHelper::default_thumbnail_style() );
		FieldRenderer::add_field(
			'mvs_layout_choice',
			__( 'Layout', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			$page,
			'mvs_display',
			array(
				'option'      => 'mvs_layout_choice',
				'choices'     => SettingsHelper::layout_choices(),
				'value'       => SettingsHelper::selected_layout(),
				'description' => __( 'How media grids look across the site. A block or shortcode can still pick its own.', 'wpmediaverse' ),
			)
		);

		$this->setting( 'mvs_grid_columns', 'integer', array( Sanitizers::class, 'sanitize_grid_columns' ), 3 );
		FieldRenderer::add_field(
			'mvs_grid_columns',
			__( 'Grid Columns', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			$page,
			'mvs_display',
			array(
				'option'      => 'mvs_grid_columns',
				'show_when'   => 'mvs_layout_choice=square',
				'choices'     => array(
					2 => __( '2 columns', 'wpmediaverse' ),
					3 => __( '3 columns', 'wpmediaverse' ),
					4 => __( '4 columns', 'wpmediaverse' ),
					5 => __( '5 columns', 'wpmediaverse' ),
				),
				'description' => __( 'Columns in the square grid.', 'wpmediaverse' ) . apply_filters( 'mvs_grid_columns_scope_note', '' ),
			)
		);

		$this->setting( 'mvs_items_per_page', 'integer', array( Sanitizers::class, 'sanitize_items_per_page' ), 12 );
		FieldRenderer::add_field(
			'mvs_items_per_page',
			__( 'Items Per Page', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_select_field' ),
			$page,
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

		// Registered without a field since 2.6.0: set through Layout above, or
		// by code. A stored value keeps working; the save guard never resets it.
		$this->setting( 'mvs_thumbnail_style', 'string', array( Sanitizers::class, 'sanitize_thumbnail_style' ), SettingsHelper::default_thumbnail_style() );
		// 'large' is what grids have served since 1.8.0.
		$this->setting( 'mvs_thumbnail_size', 'string', array( Sanitizers::class, 'sanitize_thumbnail_size' ), 'large' );
		// 1024 keeps existing installs pixel-identical.
		$this->setting( 'mvs_large_image_size', 'integer', array( Sanitizers::class, 'sanitize_large_image_size' ), 1024 );
		// 'large', not 'original': the full file made the first view of a phone
		// photo slow. Basecamp 10171640247.
		$this->setting( 'mvs_lightbox_image_source', 'string', array( Sanitizers::class, 'sanitize_lightbox_image_source' ), 'large' );

		// When off, the Download button is hidden and the REST download route
		// refuses with 403 (hiding the button alone would not stop a caller).
		$this->setting( 'mvs_allow_downloads', 'boolean', 'rest_sanitize_boolean', true );
		FieldRenderer::add_field(
			'mvs_allow_downloads',
			__( 'Allow Downloads', 'wpmediaverse' ),
			array( FieldRenderer::class, 'render_checkbox_field' ),
			$page,
			'mvs_display',
			array(
				'option'      => 'mvs_allow_downloads',
				'description' => __( 'Shows a Download button on media. Members can turn it off for their own items.', 'wpmediaverse' ),
			)
		);
	}

	/**
	 * Register one Display option.
	 *
	 * @param string          $option   Option name.
	 * @param string          $type     Setting type.
	 * @param callable|string $sanitize Sanitize callback.
	 * @param mixed           $default  Default value.
	 */
	private function setting( string $option, string $type, $sanitize, $default ): void {
		register_setting(
			SettingsPage::OPTION_GROUP . '_display',
			$option,
			array(
				'type'              => $type,
				'sanitize_callback' => $sanitize,
				'default'           => $default,
			)
		);
	}
}
