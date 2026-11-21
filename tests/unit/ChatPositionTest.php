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
}
