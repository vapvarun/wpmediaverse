<?php
/**
 * Deactivating Free cancels Free's own pending jobs, fast, and nobody else's (2.6.5).
 *
 * With the plugin off nothing listens for its jobs, and Action Scheduler loaded by another plugin would mark them
 * complete unrun. Deactivation used to clear only the WP-Cron housekeeping events, so every one-off job stayed
 * queued; Free's list also named Pro's mvs_story_cleanup, so switching Free off stopped Pro's sweep.
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Deactivator;

class DeactivationCancelsJobsTest extends WP_UnitTestCase {

	private const ROWS = 100000;

	private function status( int $id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id = %d", $id ) );
	}

	private function count_pending( string $hook ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND status = 'pending'", $hook ) );
	}

	public function test_deactivation_cancels_a_hundred_thousand_pending_free_jobs_in_under_ten_seconds_and_only_those(): void {
		global $wpdb;
		$this->assertGreaterThan( 0, did_action( 'action_scheduler_init' ), 'Action Scheduler must be loaded: the bundled copy boots with Free.' );
		$table = $wpdb->prefix . 'actionscheduler_actions';

		as_enqueue_async_action( 'mvs_ai_process_media', array( 1 ), 'mvs' );
		$cols = 'hook, status, scheduled_date_gmt, scheduled_date_local, args, schedule, group_id, attempts, last_attempt_gmt, last_attempt_local, claim_id, extended_args, priority';
		while ( ( $have = $this->count_pending( 'mvs_ai_process_media' ) ) < self::ROWS ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} ( {$cols} ) SELECT {$cols} FROM {$table} WHERE hook = %s AND status = 'pending' LIMIT %d", 'mvs_ai_process_media', self::ROWS - $have ) );
		}

		$running = as_enqueue_async_action( 'mvs_ai_process_media', array( 2 ), 'mvs' );
		\ActionScheduler::store()->log_execution( $running );
		$pro     = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'mvs_document_folder_purge', array( 9 ), 'mvs' );
		$story   = as_enqueue_async_action( 'mvs_story_cleanup', array( 40 ), 'mvs' );
		$other   = as_enqueue_async_action( 'woocommerce_cleanup_draft_orders', array(), 'woocommerce-db-updates' );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'mvs_story_cleanup' );

		$start = microtime( true );
		Deactivator::clear_scheduled();
		$took = microtime( true ) - $start;

		$this->assertLessThan( 10, $took, sprintf( 'Cancelling %d pending jobs took %.1f s.', self::ROWS, $took ) );
		$this->assertSame( 0, $this->count_pending( 'mvs_ai_process_media' ), 'A pending Free job survived deactivation.' );
		$this->assertSame( 'in-progress', $this->status( $running ), 'A running job was cancelled under its runner.' );
		$this->assertSame( 'pending', $this->status( $pro ), 'Free cancelled a Pro job: the group is shared, the hook list is not.' );
		$this->assertSame( 'pending', $this->status( $story ), 'Free cancelled Pro\'s story sweep.' );
		$this->assertSame( 'pending', $this->status( $other ), 'Free cancelled another plugin\'s job.' );
		$this->assertNotFalse( wp_next_scheduled( 'mvs_story_cleanup' ), 'Free cleared Pro\'s hourly story sweep.' );
	}

	public function test_the_uninstall_path_also_leaves_the_story_sweep(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'mvs_story_cleanup' );
		$this->assertNotContains( 'mvs_story_cleanup', Deactivator::CRON_HOOKS );

		Deactivator::clear_scheduled( true );

		$this->assertNotFalse( wp_next_scheduled( 'mvs_story_cleanup' ) );
		wp_unschedule_hook( 'mvs_story_cleanup' );
	}
}
