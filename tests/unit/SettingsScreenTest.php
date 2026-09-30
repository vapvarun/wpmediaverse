<?php
/**
 * 2.6.0 admin simplification: the settings screen shows less, changes nothing.
 *
 * - Controls taken off the screen keep their register_setting() (same group,
 *   same default), so a stored value still works and a Save never resets it.
 * - The Permissions tab is gone; its save handler is not.
 * - show_when rules reach the markup as data-mvs-show-when.
 * - The storage notice names the place, not the driver slug.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\Settings\SettingsPage;
use WPMediaVerse\Admin\Settings\SettingsRegistrar;
use WPMediaVerse\Admin\Settings\SettingsSaveGuard;

class SettingsScreenTest extends WP_UnitTestCase {

	/**
	 * Settings page under test.
	 *
	 * @var SettingsPage
	 */
	private $page;

	public function set_up(): void {
		parent::set_up();
		global $wp_registered_settings, $wp_settings_fields, $wp_settings_sections;
		$wp_registered_settings = array();
		$wp_settings_fields     = array();
		$wp_settings_sections   = array();
		( new SettingsRegistrar() )->register_all();
		$this->page = new SettingsPage();
	}

	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Option => [ group suffix, registered default ].
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	private function removed_controls(): array {
		return array(
			'mvs_thumbnail_size'                                   => array( '_display', 'large' ),
			'mvs_large_image_size'                                 => array( '_display', 1024 ),
			'mvs_lightbox_image_source'                            => array( '_display', 'large' ),
			'mvs_thumbnail_style'                                  => array( '_display', 'original' ),
			'mvs_signed_url_ttl'                                   => array( '_storage', 3600 ),
			\WPMediaVerse\Services\ViewRetentionService::SETTING => array( '_storage', \WPMediaVerse\Services\ViewRetentionService::DEFAULT_DAYS ),
			\WPMediaVerse\Services\FilenameStrategy::SETTING     => array( '_storage', \WPMediaVerse\Services\FilenameStrategy::effective_default() ),
			'mvs_generate_webp'                                    => array( '_storage', true ),
			'mvs_generate_avif'                                    => array( '_storage', false ),
			'mvs_openai_model'                                     => array( '_ai', 'gpt-4o-mini' ),
			'mvs_eula_url'                                         => array( '_app', '' ),
		);
	}

	public function test_removed_controls_are_off_screen_but_still_registered(): void {
		global $wp_settings_fields, $wp_registered_settings;

		$on_screen = array();
		foreach ( $wp_settings_fields as $sections ) {
			foreach ( $sections as $fields ) {
				foreach ( $fields as $field ) {
					$on_screen[] = $field['args']['option'] ?? $field['id'];
				}
			}
		}

		foreach ( $this->removed_controls() as $option => $spec ) {
			$this->assertNotContains( $option, $on_screen, "$option still has a control." );
			$this->assertArrayHasKey( $option, $wp_registered_settings, "$option lost its register_setting()." );
			$this->assertSame( SettingsPage::OPTION_GROUP . $spec[0], $wp_registered_settings[ $option ]['group'], "$option moved group." );
			$this->assertSame( $spec[1], $wp_registered_settings[ $option ]['default'], "$option changed default." );
		}
	}

	public function test_webp_and_avif_defaults_are_unchanged(): void {
		// Owner decision: AVIF stays opt-in, WebP stays on. Only the boxes left.
		$this->assertSame( true, get_registered_settings()['mvs_generate_webp']['default'] );
		$this->assertSame( false, get_registered_settings()['mvs_generate_avif']['default'] );
	}

	public function test_a_save_never_touches_an_option_that_is_not_on_screen(): void {
		foreach ( array( 'display', 'storage', 'ai', 'app' ) as $tab ) {
			$rendered = $this->render_manifest( SettingsPage::PAGE_SLUG . '-' . $tab );
			$group    = SettingsPage::OPTION_GROUP . '_' . $tab;
			$allowed  = array( $group => array_keys( wp_list_filter( get_registered_settings(), array( 'group' => $group ) ) ) );

			$_POST = array(
				'option_page'            => $group,
				SettingsSaveGuard::FIELD => implode( ',', $rendered ),
			);
			$saved = SettingsSaveGuard::filter_allowed_options( $allowed )[ $group ];

			foreach ( $this->removed_controls() as $option => $spec ) {
				if ( '_' . $tab === $spec[0] ) {
					$this->assertNotContains( $option, $saved, "A Save on $tab would reset $option." );
				}
			}
		}
	}

	public function test_permissions_tab_is_gone_but_its_save_handler_stays(): void {
		$sections = $this->call_private( 'get_registered_sections' );

		$this->assertArrayNotHasKey( 'permissions', $sections );
		$this->assertArrayHasKey( 'app', $sections );
		$this->assertSame( SettingsPage::OPTION_GROUP . '_app', $sections['app']['option_group'] );
		$this->assertNotFalse( has_action( 'admin_post_mvs_save_role_caps' ), 'The matrix save handler was unhooked.' );
	}

	public function test_show_when_reaches_rows_and_cards(): void {
		ob_start();
		$this->call_private( 'render_section_fields', SettingsPage::PAGE_SLUG . '-display', array( 'mvs_display' ) );
		$rows = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-mvs-show-when="mvs_layout_choice=square"', $rows );

		add_settings_section( 'mvs_test_card', 'Card', '__return_null', 'mvs-test-page', array( 'show_when' => 'mvs_x=y' ) );
		add_settings_field( 'mvs_test_field', 'Field', '__return_null', 'mvs-test-page', 'mvs_test_card' );
		ob_start();
		$this->call_private(
			'render_section_cards',
			array(
				'page_slug'   => 'mvs-test-page',
				'section_ids' => array( 'mvs_test_card' ),
				'label'       => 'Card',
				'description' => '',
			),
			'test'
		);
		$this->assertStringContainsString( 'data-mvs-show-when="mvs_x=y"', (string) ob_get_clean() );
	}

	public function test_storage_notice_names_the_place(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_transient( 'mvs_old_storage_driver_' . get_current_user_id(), 'local', 30 );
		update_option( 'mvs_storage_driver', 's3' );
		$_GET['settings-updated'] = 'true';
		set_current_screen( 'mvs-settings' );

		ob_start();
		$this->page->handle_settings_notices();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<strong>Amazon S3</strong>', $html );
		delete_option( 'mvs_storage_driver' );
	}

	/**
	 * Names of the options a tab's form would post.
	 *
	 * @param string $page Settings page slug.
	 * @return string[]
	 */
	private function render_manifest( string $page ): array {
		global $wp_settings_fields;
		ob_start();
		SettingsSaveGuard::render_with_manifest(
			function () use ( $page, $wp_settings_fields ) {
				$this->call_private( 'render_section_fields', $page, array_keys( $wp_settings_fields[ $page ] ?? array() ) );
			}
		);
		$html = (string) ob_get_clean();
		preg_match( '/name="' . SettingsSaveGuard::FIELD . '" value="([^"]*)"/', $html, $m );
		return explode( ',', $m[1] ?? '' );
	}

	/**
	 * Call a private SettingsPage method.
	 *
	 * @param string $method Method.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function call_private( string $method, ...$args ) {
		$ref = new \ReflectionMethod( SettingsPage::class, $method );
		return $ref->invoke( $this->page, ...$args );
	}
}
