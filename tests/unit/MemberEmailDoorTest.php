<?php
/**
 * Plugins email members through EmailService: one list of types, one set of rules.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\EmailService;

/**
 * Plugins email members through EmailService: one list of types, one set of rules.
 */
class MemberEmailDoorTest extends WP_UnitTestCase {

	public function test_registered_type_follows_default_switch_and_unsubscribe(): void {
		$register = static function ( $types ) {
			$types['probe_email'] = array( 'option' => 'mvs_probe_email', 'label' => 'Probe', 'description' => 'Probe', 'default' => true );
			return $types;
		};
		add_filter( 'mvs_email_types', $register );
		$sent = array();
		$capture = static function ( $args ) use ( &$sent ) {
			$sent[] = $args;
			return $args;
		};
		add_filter( 'wp_mail', $capture );

		$user   = self::factory()->user->create( array( 'role' => 'subscriber', 'user_email' => 'probe@example.test' ) );
		$emails = Plugin::container()->get( 'emails' );

		$this->assertArrayHasKey( 'probe_email', EmailService::types() );
		$this->assertTrue( $emails->send_member_email( $user, 'probe_email', 'Hello', 'Body' ) || 1 === count( $sent ), 'A default-on type sends.' );
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'mvs_unsubscribe', $sent[0]['message'], 'The unsubscribe link is added.' );

		update_option( 'mvs_probe_email', '0' );
		$emails->send_member_email( $user, 'probe_email', 'Hello', 'Body' );
		$this->assertCount( 1, $sent, 'The owner switched it off.' );

		delete_option( 'mvs_probe_email' );
		update_user_meta( $user, EmailService::MEMBER_META, 'off' );
		$emails->send_member_email( $user, 'probe_email', 'Hello', 'Body' );
		$this->assertCount( 1, $sent, 'The member unsubscribed.' );

		$this->assertFalse( $emails->send_member_email( $user, 'not_registered', 'Hello', 'Body' ) );

		remove_filter( 'mvs_email_types', $register );
		remove_filter( 'wp_mail', $capture );
	}
}
