<?php
/**
 * 2.6.0 settings guards.
 *
 * - SettingsSaveGuard: a save only touches options the form rendered, so
 *   options.php can no longer reset a setting that was not on screen.
 * - Allowed file types: nothing ticked is refused, not stored as ''.
 * - A saved key is cleared only when the owner asked to remove it.
 * - AI uses only the selected provider; no silent fallback.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\Settings\SettingsSaveGuard;
use WPMediaVerse\Admin\Settings\Sanitizers;
use WPMediaVerse\Core\SettingsHelper;

class SettingsSaveGuardTest extends WP_UnitTestCase {

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	public function test_guard_saves_only_rendered_options(): void {
		$_POST = array(
			'option_page'            => 'mvs_settings_gamification',
			SettingsSaveGuard::FIELD => 'mvs_competitions_enabled,mvs_pro_boost_cost_per_100',
		);
		$allowed = array(
			'mvs_settings_gamification' => array( 'mvs_competitions_enabled', 'mvs_battles_enabled', 'mvs_pro_boost_cost_per_100' ),
			'general'                   => array( 'blogname' ),
		);

		$out = SettingsSaveGuard::filter_allowed_options( $allowed );

		$this->assertSame( array( 'mvs_competitions_enabled', 'mvs_pro_boost_cost_per_100' ), $out['mvs_settings_gamification'], 'An option not on screen would be reset by the save.' );
		$this->assertSame( array( 'blogname' ), $out['general'], 'Another group was touched.' );
	}

	public function test_guard_ignores_forms_that_are_not_ours(): void {
		$_POST   = array(
			'option_page'            => 'general',
			SettingsSaveGuard::FIELD => 'blogname',
		);
		$allowed = array( 'general' => array( 'blogname', 'blogdescription' ) );

		$this->assertSame( $allowed, SettingsSaveGuard::filter_allowed_options( $allowed ) );
	}

	public function test_render_manifest_lists_single_quoted_names_and_skips_disabled(): void {
		ob_start();
		SettingsSaveGuard::render_with_manifest(
			static function () {
				echo '<input class="x-disabled" name="mvs_a" /><select name=\'mvs_b\'></select><select disabled name="mvs_c"></select><input name="mvs_d[x]" />';
			}
		);
		$html = ob_get_clean();

		preg_match( '/name="' . SettingsSaveGuard::FIELD . '" value="([^"]*)"/', $html, $m );
		$this->assertSame( 'mvs_a,mvs_b,mvs_d', $m[1] );
	}

	public function test_file_types_with_nothing_ticked_keep_the_previous_choice(): void {
		update_option( 'mvs_allowed_file_types', 'image/jpeg,image/png' );
		$_POST = array( Sanitizers::FILE_TYPES_PRESENT_FIELD => '1' );

		$this->assertSame( 'image/jpeg,image/png', Sanitizers::sanitize_file_types( array() ) );
	}

	public function test_saved_key_is_cleared_only_on_request(): void {
		update_option( 'mvs_openai_api_key', 'sk-keep-1234' );
		$sanitize = static function ( $value ) {
			return Sanitizers::sanitize_password_option( $value );
		};
		add_filter( 'sanitize_option_mvs_openai_api_key', $sanitize );

		$this->assertSame( 'sk-keep-1234', sanitize_option( 'mvs_openai_api_key', '' ), 'An empty save must keep the key.' );

		$_POST[ SettingsHelper::REMOVE_SECRETS_FIELD ] = array( 'mvs_openai_api_key' );
		$this->assertSame( '', sanitize_option( 'mvs_openai_api_key', '' ), 'Remove did not clear the key.' );

		remove_filter( 'sanitize_option_mvs_openai_api_key', $sanitize );
	}

	public function test_ai_does_not_fall_back_to_another_provider(): void {
		update_option( 'mvs_ai_provider', 'anthropic' );
		$ai = new \WPMediaVerse\Services\AIService();
		$ai->register_provider(
			new class() implements \WPMediaVerse\Services\AIProviderInterface {
				public function analyze_image( string $image_url ): ?array {
					return array();
				}
				public function generate_tags( string $image_url, string $description = '' ): array {
					return array();
				}
				public function moderate_content( string $image_url ): array {
					return array();
				}
				public function is_available(): bool {
					return true;
				}
				public function get_id(): string {
					return 'openai';
				}
			}
		);

		$this->assertNull( $ai->get_active_provider(), 'A provider the owner did not choose was used.' );
		delete_option( 'mvs_ai_provider' );
	}
}
