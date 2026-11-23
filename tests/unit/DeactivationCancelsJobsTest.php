<?php
/**
 * Deactivating Free cancels Free's recomputable pending jobs, fast, and nobody else's (2.7.0).
 *
 * Jobs that finish an action already committed (Deactivator::STATE_JOB_HOOKS) stay pending, so the routine update
 * (deactivate, replace, activate) resumes them. Free's list used to name Pro's mvs_story_cleanup, so switching Free
 * off stopped Pro's sweep.
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

		$state = array(
			as_enqueue_async_action( 'mvs_deliver_webhook', array( 1 ), 'mvs' ),
			as_enqueue_async_action( 'mvs_cleanup_media_files', array( array( '2026/10/a.jpg' ), 1 ), 'mvs' ),
			as_enqueue_async_action( 'mvs_rotate_media_files', array( 'ids' => array( 1, 2 ) ), 'mvs' ),
			as_enqueue_async_action( 'mvs_user_deletion_cascade', array( 7 ), 'mvs' ),
		);
		$running = as_enqueue_async_action( 'mvs_ai_process_media', array( 2 ), 'mvs' );
		\ActionScheduler::store()->log_execution( $running );
		$pro     = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'mvs_document_folder_purge', array( 9 ), 'mvs' );
		$story   = as_enqueue_async_action( 'mvs_story_cleanup', array( 40 ), 'mvs' );
		$other   = as_enqueue_async_action( 'woocommerce_cleanup_draft_orders', array(), 'woocommerce-db-updates' );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'mvs_story_cleanup' );

		$start = microtime( true );
		$this->setExpectedDeprecated( Deactivator::class . '::clear_scheduled' );
		Deactivator::clear_scheduled( false ); // What deactivate() runs.
		$took = microtime( true ) - $start;

		$this->assertLessThan( 10, $took, sprintf( 'Cancelling %d pending jobs took %.1f s.', self::ROWS, $took ) );
		fwrite( STDERR, sprintf( "\nDeactivation over %d pending rows: %.2f s.\n", self::ROWS, $took ) ); // phpcs:ignore
		$this->assertSame( 0, $this->count_pending( 'mvs_ai_process_media' ), 'A pending Free job survived deactivation.' );
		foreach ( $state as $id ) {
			$this->assertSame( 'pending', $this->status( $id ), 'Deactivation cancelled a job that finishes a committed action.' );
		}
		$this->assertSame( 'in-progress', $this->status( $running ), 'A running job was cancelled under its runner.' );
		$this->assertSame( 'pending', $this->status( $pro ), 'Free cancelled a Pro job: the group is shared, the hook list is not.' );
		$this->assertSame( 'pending', $this->status( $story ), 'Free cancelled Pro\'s story sweep.' );
		$this->assertSame( 'pending', $this->status( $other ), 'Free cancelled another plugin\'s job.' );
		$this->assertNotFalse( wp_next_scheduled( 'mvs_story_cleanup' ), 'Free cleared Pro\'s hourly story sweep.' );
	}

	public function test_uninstall_also_clears_the_jobs_deactivation_leaves(): void {
		$id = as_enqueue_async_action( 'mvs_cleanup_media_files', array( array( '2026/10/a.jpg' ), 1 ), 'mvs' );

		Deactivator::clear_scheduled( true );

		$this->assertSame( 'canceled', $this->status( $id ) );
	}

	public function test_every_job_deactivation_leaves_is_a_job_free_owns(): void {
		$this->assertSame( array(), array_values( array_diff( Deactivator::STATE_JOB_HOOKS, Deactivator::JOB_HOOKS ) ) );
	}

	public function test_a_bulk_delete_of_two_thousand_media_leaves_few_cleanup_rows(): void {
		$cleanup = new \WPMediaVerse\Services\StorageCleanupService( \WPMediaVerse\Core\Plugin::container()->get( 'storage' ) );
		for ( $i = 0; $i < 2000; $i++ ) {
			$base = '2026/10/' . substr( md5( (string) $i ), 0, 16 );
			// The six files a real image leaves (original, webp, two sizes of each), as seen in a live cleanup row.
			$cleanup->enqueue( $i, array( "$base.jpg", "$base.webp", "$base-300x200.jpg", "$base-300x200.webp", "$base-150x150.jpg", "$base-150x150.webp" ) );
		}
		remove_all_actions( 'shutdown' );

		$cleanup->flush();

		$rows = $this->count_pending( 'mvs_cleanup_media_files' );
		$this->assertLessThan( 100, $rows, "2,000 deleted media left $rows cleanup rows." );
		fwrite( STDERR, "\n2,000 deleted media = $rows pending cleanup rows.\n" ); // phpcs:ignore
	}

	public function test_the_uninstall_path_also_leaves_the_story_sweep(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'mvs_story_cleanup' );
		$this->assertNotContains( 'mvs_story_cleanup', Deactivator::CRON_HOOKS );

		Deactivator::clear_scheduled( true );

		$this->assertNotFalse( wp_next_scheduled( 'mvs_story_cleanup' ) );
		wp_unschedule_hook( 'mvs_story_cleanup' );
	}
}
