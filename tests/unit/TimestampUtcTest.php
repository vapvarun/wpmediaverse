<?php
/**
 * Stored timestamps are UTC even when MySQL's session clock is not.
 *
 * MySQL fills `DEFAULT CURRENT_TIMESTAMP` and evaluates `NOW()` in the
 * connection's zone, which WordPress never sets, so on a host whose database
 * runs in local time every default-filled row was off by the server offset
 * (Basecamp 10343134036, sister of BuddyNext 10343133937). Each write here is
 * checked with the session clock pushed to +05:30: a row filled by MySQL lands
 * five and a half hours away from PHP's UTC.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Repository\MediaSpaceRepository;
use WPMediaVerse\Social\PushService;

/**
 * @since 2.6.0
 */
class TimestampUtcTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$wpdb->query( "SET time_zone = '+05:30'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// And the SITE clock off UTC too, so a write using current_time( 'mysql' )
		// without the gmt flag is caught as well as a DB default.
		update_option( 'timezone_string', 'America/New_York' );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( "SET time_zone = '+00:00'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		parent::tear_down();
	}

	/**
	 * The stored value must be within a minute of PHP's UTC clock.
	 *
	 * @param string $stored Stored datetime.
	 * @param string $what   Label for the failure message.
	 */
	private function assertUtc( string $stored, string $what ): void {
		$this->assertLessThan( 60, abs( strtotime( $stored . ' UTC' ) - time() ), "{$what} was stored as {$stored}, not UTC." );
	}

	private function column( string $sql ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_media_insert_stamps_utc(): void {
		global $wpdb;

		$id = (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'UTC probe',
				'post_author' => 1,
				'status'      => 'publish',
				'media_type'  => 'image',
			)
		);

		$this->assertUtc( $this->column( "SELECT created_at FROM {$wpdb->prefix}mvs_media_index WHERE media_id = {$id}" ), 'mvs_media_index.created_at' );
	}

	public function test_usage_ledger_stamps_utc(): void {
		global $wpdb;

		$id = Plugin::container()->get( 'transactions' )->record( 1, 'document', 1, 'upload' );

		$this->assertUtc( $this->column( "SELECT created_at FROM {$wpdb->prefix}mvs_transactions WHERE id = {$id}" ), 'mvs_transactions.created_at' );
	}

	public function test_space_link_stamps_utc(): void {
		global $wpdb;

		( new MediaSpaceRepository() )->link( 987654, 4321, 1 );

		$this->assertUtc( $this->column( "SELECT added_at FROM {$wpdb->prefix}mvs_media_spaces WHERE media_id = 987654 AND space_id = 4321" ), 'mvs_media_spaces.added_at' );
	}

	public function test_device_token_stamps_utc(): void {
		global $wpdb;

		$user = self::factory()->user->create();
		( new PushService() )->register_token( $user, 'ios', 'ExponentPushToken[utc-probe]' );

		$this->assertUtc( $this->column( "SELECT updated_at FROM {$wpdb->prefix}mvs_device_tokens WHERE user_id = {$user}" ), 'mvs_device_tokens.updated_at' );
	}
}
