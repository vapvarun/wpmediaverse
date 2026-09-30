<?php
/**
 * Member emails (2.6.0): a minimal set, owner switches, one member switch.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\EmailService;

class MemberEmailsTest extends WP_UnitTestCase {

	private int $member;
	private EmailService $emails;

	public function set_up(): void {
		parent::set_up();
		( new \WPMediaVerse\Core\Migrator() )->run();
		reset_phpmailer_instance();
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber', 'user_email' => 'member@example.org' ) );
		$this->emails = Plugin::container()->get( 'emails' );
		// Services init once per process and the framework restores hooks.
		if ( ! has_action( 'mvs_notification_created', array( $this->emails, 'on_notification' ) ) ) {
			$this->emails->init();
		}
	}

	private function sent(): array {
		$mailer = tests_retrieve_phpmailer_instance();
		return $mailer ? $mailer->mock_sent : array();
	}

	private function notify( string $type ): void {
		do_action( 'mvs_notification_created', 1, $this->member, $type, 2, 0, 'Mina challenged you to a photo battle', 'https://example.org/media/battles/9/', 9 );
	}

	public function test_only_switched_on_types_reach_members_who_want_them(): void {
		update_option( EmailService::TYPES['battle_invite'], '0' );
		$this->notify( 'battle_invite' );
		$this->assertCount( 0, $this->sent(), 'An email the owner left off was sent.' );

		update_option( EmailService::TYPES['battle_invite'], '1' );
		$this->notify( 'battle_invite' );
		$this->assertCount( 1, $this->sent() );
		$mail = $this->sent()[0];
		$this->assertSame( 'member@example.org', $mail['to'][0][0] );
		$this->assertStringContainsString( 'https://example.org/media/battles/9/', $mail['body'] );
		$this->assertStringContainsString( 'mvs_unsubscribe=' . $this->member, $mail['body'] );
		$this->assertStringContainsString( get_option( 'admin_email' ), $mail['header'] );

		update_user_meta( $this->member, EmailService::MEMBER_META, 'off' );
		$this->notify( 'battle_invite' );
		$this->assertCount( 1, $this->sent(), 'A member who switched emails off got one.' );

		$this->notify( 'media_reaction' );
		$this->assertCount( 1, $this->sent(), 'A type outside the minimal set was emailed.' );
	}

	public function test_unsubscribe_link_needs_its_signature(): void {
		$url = $this->emails->unsubscribe_url( $this->member );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$_GET = array( 'mvs_unsubscribe' => (string) $this->member, 'mvs_sig' => 'forged' );
		try {
			$this->emails->maybe_unsubscribe();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$this->assertSame( '', get_user_meta( $this->member, EmailService::MEMBER_META, true ), 'A forged link unsubscribed a member.' );

		$_GET = $query;
		try {
			$this->emails->maybe_unsubscribe();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$_GET = array();
		$this->assertSame( 'off', get_user_meta( $this->member, EmailService::MEMBER_META, true ) );
	}

	public function test_a_reviewed_report_emails_the_reporter(): void {
		global $wpdb;
		update_option( EmailService::TYPES['report_resolved'], '1' );
		// No REST request here: the listener must already be hooked, as it is
		// when an admin resolves a report from a wp-admin screen.
		$this->assertNotFalse( has_action( 'mvs_report_resolved' ), 'Nothing listens for a resolved report outside REST.' );
		$wpdb->insert( $wpdb->prefix . 'mvs_reports', array( 'reporter_id' => $this->member, 'target_type' => 'user', 'target_id' => 7, 'reason' => 'spam', 'status' => 'pending' ) ); // phpcs:ignore

		Plugin::container()->get( 'reports' )->update_status( (int) $wpdb->insert_id, 'resolved' );

		$this->assertCount( 1, $this->sent(), 'The reporter was not told their report was reviewed.' );
	}

	public function test_deletion_confirmation_is_always_sent(): void {
		update_user_meta( $this->member, EmailService::MEMBER_META, 'off' );
		do_action( 'mvs_account_deletion_requested', $this->member, time() + 7 * DAY_IN_SECONDS );

		$this->assertCount( 1, $this->sent(), 'The deletion confirmation was not sent.' );
		$this->assertStringContainsString( wp_login_url(), $this->sent()[0]['body'] );
		$this->assertStringNotContainsString( 'mvs_unsubscribe', $this->sent()[0]['body'], 'A security email carried an unsubscribe link.' );
	}

	public function test_upgrades_start_off_and_new_installs_start_on(): void {
		$migrate = new \ReflectionMethod( \WPMediaVerse\Core\Migrator::class, 'migrate_to_39' );
		if ( PHP_VERSION_ID < 80100 ) {
			$migrate->setAccessible( true );
		}

		foreach ( EmailService::TYPES as $option ) {
			delete_option( $option );
		}
		update_option( \WPMediaVerse\Core\Migrator::VERSION_OPTION, 38 );
		$migrate->invoke( new \WPMediaVerse\Core\Migrator() );
		$this->assertSame( '0', get_option( EmailService::TYPES['battle_invite'] ), 'An update switched member emails on.' );

		foreach ( EmailService::TYPES as $option ) {
			delete_option( $option );
		}
		update_option( \WPMediaVerse\Core\Migrator::VERSION_OPTION, 0 );
		$migrate->invoke( new \WPMediaVerse\Core\Migrator() );
		$this->assertSame( '1', get_option( EmailService::TYPES['battle_invite'] ), 'A new install did not start with emails on.' );
		update_option( \WPMediaVerse\Core\Migrator::VERSION_OPTION, \WPMediaVerse\Core\Migrator::CURRENT_VERSION );
	}
}
