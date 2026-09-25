<?php
/**
 * Big-site readiness (50k media / 10k members target) — Free core.
 *
 * Covers the Migrator v37 index sweep, AccountDeletionService::process_due()
 * SQL-side selection, LoggerService's social-event gate, and AIService's
 * atomic usage counters. See Basecamp "Big-site readiness".
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Migrator;
use WPMediaVerse\Services\AccountDeletionService;
use WPMediaVerse\Services\AIService;
use WPMediaVerse\Services\LoggerService;

class BigSiteFreeCoreTest extends WP_UnitTestCase {

	/**
	 * Migration v37 adds every index it promises, on tables that already
	 * exist (pre-v37 install) as well as the CREATE TABLE path (fresh
	 * install both go through the same dbDelta call earlier in run()).
	 *
	 * Would fail without the change: before v37, `SHOW INDEX ... WHERE
	 * Key_name = 'media_id'` on mvs_activity returns nothing.
	 */
	public function test_migration_adds_big_site_indexes(): void {
		global $wpdb;

		$migrator = new Migrator();
		$migrator->run();

		$expect = array(
			'mvs_activity'      => array( 'media_id' ),
			'mvs_notifications' => array( 'media_id', 'actor_id' ),
			'mvs_album_items'   => array( 'media_id' ),
			'mvs_media_views'   => array( 'user_id' ),
			'mvs_error_log'     => array( 'created_at' ),
			'mvs_reports'       => array( 'status_created' ),
			'mvs_follows'       => array( 'follower_created', 'following_created' ),
			'mvs_favorites'     => array( 'user_created' ),
		);

		foreach ( $expect as $table => $keys ) {
			foreach ( $keys as $key_name ) {
				$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SHOW INDEX FROM {$wpdb->prefix}{$table} WHERE Key_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$key_name
					)
				);
				$this->assertNotEmpty( $found, "{$table} is missing index {$key_name}" );
			}
		}
	}

	/**
	 * process_due() selects only accounts whose scheduled deletion is due,
	 * via SQL (numeric meta_value <= now), not "every scheduled account then
	 * filter in PHP".
	 *
	 * Would fail without the change only in spirit (both old and new code
	 * reach the correct end result for this dataset) — the change proven by
	 * reverting is that the old code fetched ALL scheduled users
	 * unconditionally (meta_compare EXISTS) while the new code's SQL itself
	 * excludes the future one. We assert that directly via a filter on
	 * pre_get_users-adjacent get_users() args is overkill for a unit test,
	 * so instead we assert the functional contract: only the due account is
	 * deleted, the future one survives.
	 */
	public function test_process_due_only_deletes_due_accounts(): void {
		$due_user    = self::factory()->user->create();
		$future_user = self::factory()->user->create();

		update_user_meta( $due_user, AccountDeletionService::META_SCHEDULED, time() - DAY_IN_SECONDS );
		update_user_meta( $future_user, AccountDeletionService::META_SCHEDULED, time() + DAY_IN_SECONDS );

		$service = new AccountDeletionService();
		$service->process_due();

		$this->assertFalse( get_userdata( $due_user ), 'Due account should have been deleted.' );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $future_user ), 'Future-dated account must survive.' );
	}

	/**
	 * Social events (reaction/comment/favorite) are not logged at `info`
	 * level unless `mvs_log_social_events` is explicitly turned on.
	 *
	 * Would fail without the change: the old register_hooks() always wired
	 * these three actions, so firing `mvs_reaction_added` always produced a
	 * `context = social` row.
	 */
	public function test_logger_does_not_log_social_event_by_default(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mvs_error_log';
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		LoggerService::register_hooks();
		do_action( 'mvs_reaction_added', 1, 1, 'like' );

		$logs = LoggerService::get_logs( array( 'context' => 'social' ) );
		$this->assertSame( 0, $logs['total'], 'Reactions must not be logged unless mvs_log_social_events is enabled.' );

		// Sanity: the filter genuinely re-enables it, proving the gate (not
		// something else) is what suppressed the row above.
		add_filter( 'mvs_log_social_events', '__return_true' );
		LoggerService::register_hooks();
		do_action( 'mvs_reaction_added', 1, 1, 'like' );
		remove_filter( 'mvs_log_social_events', '__return_true' );

		$logs = LoggerService::get_logs( array( 'context' => 'social' ) );
		$this->assertSame( 1, $logs['total'], 'The filter must re-enable social-event logging.' );
	}

	/**
	 * The AI usage counter option is autoload=false and increments
	 * atomically — two "concurrent" increments (simulated sequentially,
	 * since PHPUnit is single-threaded) both land, and get_usage_stats()
	 * still returns the same public shape.
	 *
	 * Would fail without the change: the old code stored everything under
	 * one autoloaded `mvs_ai_usage` option (no per-field row to assert
	 * autoload on), and this per-field option would not exist at all.
	 */
	public function test_ai_usage_increments_atomically_and_is_not_autoloaded(): void {
		global $wpdb;

		$service = new AIService();
		$track   = new \ReflectionMethod( AIService::class, 'track_usage' );
		$track->setAccessible( true );

		$track->invoke( $service, 'openai', 'analyze', true );
		$track->invoke( $service, 'openai', 'analyze', true );

		$stats = $service->get_usage_stats();
		$this->assertSame( 2, $stats['calls'] );
		$this->assertSame( 2, $stats['success'] );
		$this->assertSame( 0, $stats['failed'] );
		$this->assertGreaterThan( 0.0, $stats['cost'] );

		$option_name = 'mvs_ai_usage_' . gmdate( 'Y-m' ) . '_calls';
		$row         = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$option_name
			),
			ARRAY_A
		);
		$this->assertNotNull( $row, "Per-field usage option {$option_name} must exist as its own row." );
		$this->assertSame( '2', $row['option_value'], 'The per-field counter itself must hold the incremented value.' );
		$this->assertNotSame( 'yes', $row['autoload'], 'AI usage counters must not autoload.' );
	}

	public function test_upgrade_keeps_this_months_ai_spend(): void {
		$month = gmdate( 'Y-m' );
		update_option( 'mvs_ai_usage', array( $month => array( 'calls' => 9, 'success' => 8, 'failed' => 1, 'cost' => 4.5 ) ) );
		delete_option( 'mvs_ai_usage_' . $month . '_cost' );
		delete_option( 'mvs_ai_usage_' . $month . '_calls' );

		$migrate = new \ReflectionMethod( \WPMediaVerse\Core\Migrator::class, 'migrate_to_37' );
		if ( PHP_VERSION_ID < 80100 ) {
			$migrate->setAccessible( true );
		}
		$migrate->invoke( new \WPMediaVerse\Core\Migrator() );

		$this->assertSame( 4.5, (float) get_option( 'mvs_ai_usage_' . $month . '_cost' ), 'An upgrade reset this month\'s AI spend.' );
		$this->assertSame( 9.0, (float) get_option( 'mvs_ai_usage_' . $month . '_calls' ) );
		$this->assertFalse( get_option( 'mvs_ai_usage', false ), 'The old autoloaded option was left behind.' );
	}
}
