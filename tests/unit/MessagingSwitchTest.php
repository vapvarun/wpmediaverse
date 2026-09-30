<?php
/**
 * The Messages master switch, and messaging yourself.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\Settings\MessagingSettingsRegistrar;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Messaging\MessagingService;

/**
 * @since 2.6.0
 */
class MessagingSwitchTest extends WP_UnitTestCase {

	public function test_messaging_is_on_until_the_owner_turns_it_off(): void {
		delete_option( MessagingSettingsRegistrar::ENABLED_OPTION );
		$this->assertTrue( Plugin::messaging_enabled(), 'A site that never saved the switch keeps messaging, as before 2.6.0.' );

		// What a Settings save stores for an unticked box (rest_sanitize_boolean).
		update_option( MessagingSettingsRegistrar::ENABLED_OPTION, '' );
		$this->assertFalse( Plugin::messaging_enabled() );

		add_filter( 'mvs_messaging_enabled', '__return_true' );
		$this->assertTrue( Plugin::messaging_enabled(), 'The filter has the last word.' );
		remove_filter( 'mvs_messaging_enabled', '__return_true' );

		delete_option( MessagingSettingsRegistrar::ENABLED_OPTION );
	}

	public function test_messaging_yourself_is_refused_even_with_other_conversations(): void {
		$service = new MessagingService();
		$me      = self::factory()->user->create();
		$friend  = self::factory()->user->create();

		$existing = $service->find_or_create_conversation( $me, $friend );
		$this->assertGreaterThan( 0, $existing['conversation_id'] );

		$self = $service->find_or_create_conversation( $me, $me );

		$this->assertSame( 0, $self['conversation_id'], 'It must not hand back the conversation with somebody else.' );
		$this->assertSame( 'cannot_message_self', $self['status'] );
	}
}
