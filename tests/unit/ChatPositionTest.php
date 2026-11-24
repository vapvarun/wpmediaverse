<?php
/**
 * The chat button corner setting.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\Settings\Sanitizers;

class ChatPositionTest extends WP_UnitTestCase {

	/**
	 * Only the two corners are accepted; anything else is the default, the right.
	 */
	public function test_only_known_corners_are_accepted(): void {
		$this->assertSame( 'left', Sanitizers::sanitize_chat_position( 'left' ) );
		$this->assertSame( 'right', Sanitizers::sanitize_chat_position( 'right' ) );
		$this->assertSame( 'right', Sanitizers::sanitize_chat_position( 'top' ) );
		$this->assertSame( 'right', Sanitizers::sanitize_chat_position( '' ) );
		$this->assertSame( 'right', Sanitizers::sanitize_chat_position( array( 'left' ) ) );
		$this->assertSame( 'right', Sanitizers::sanitize_chat_position( null ) );
	}

	/**
	 * The panel markup carries the left modifier only when the site chose the left.
	 */
	public function test_panel_marks_the_left_corner_only_when_chosen(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );

		$render = static function (): string {
			ob_start();
			include MVS_PLUGIN_DIR . 'templates/partials/chat-panel.php';
			return (string) ob_get_clean();
		};

		delete_option( 'mvs_chat_position' );
		$this->assertStringNotContainsString( 'mvs-chat-root--left', $render(), 'The default is unchanged: no modifier.' );

		update_option( 'mvs_chat_position', 'left' );
		$this->assertStringContainsString( 'mvs-chat-root--left', $render() );

		update_option( 'mvs_chat_position', 'bogus' );
		$this->assertStringNotContainsString( 'mvs-chat-root--left', $render(), 'An unknown stored value is the right.' );

		delete_option( 'mvs_chat_position' );
	}

	/**
	 * The floating chat rows are offered only when MediaVerse owns the chat, not while BuddyNext does.
	 */
	public function test_chat_rows_are_hidden_while_buddynext_owns_messaging(): void {
		global $wp_settings_fields;
		$page = \WPMediaVerse\Admin\Settings\SettingsPage::PAGE_SLUG . '-social';
		$has  = static function () use ( $page ): array {
			global $wp_settings_fields;
			return array(
				'position'   => isset( $wp_settings_fields[ $page ]['mvs_messaging']['mvs_chat_position'] ),
				'visibility' => isset( $wp_settings_fields[ $page ]['mvs_messaging']['mvs_chat_panel_visibility'] ),
			);
		};

		add_filter( 'mvs_buddynext_active', '__return_true', 99 );
		$wp_settings_fields[ $page ] = array();
		( new \WPMediaVerse\Admin\Settings\MessagingSettingsRegistrar() )->register();
		$with_bn = $has();
		remove_filter( 'mvs_buddynext_active', '__return_true', 99 );

		add_filter( 'mvs_buddynext_active', '__return_false', 99 );
		$wp_settings_fields[ $page ] = array();
		( new \WPMediaVerse\Admin\Settings\MessagingSettingsRegistrar() )->register();
		$without_bn = $has();
		remove_filter( 'mvs_buddynext_active', '__return_false', 99 );

		$this->assertSame( array( 'position' => false, 'visibility' => false ), $with_bn );
		$this->assertSame( array( 'position' => true, 'visibility' => true ), $without_bn );
	}
}
