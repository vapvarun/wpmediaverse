<?php
/**
 * Background jobs: one group, our own finished rows trimmed, old pending rows moved on upgrade (2.6.5).
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Migrator;
use WPMediaVerse\Repository\ReportRepository;

class BackgroundJobsTest extends WP_UnitTestCase {

	private const HOOK = 'mvs_test_finished_job';

	public function set_up(): void {
		parent::set_up();
		// Free bundles Action Scheduler; a bootstrap without it would pass this file with no assertion run.
		$this->assertTrue( (bool) did_action( 'action_scheduler_init' ), 'Action Scheduler did not initialise in the test bootstrap.' );
		$this->clear();
	}

	public function tear_down(): void {
		$this->clear();
		remove_all_filters( 'mvs_finished_action_retention_days' );
		parent::tear_down();
	}

	/**
	 * Remove this test's rows, and the sweep the install-time upgrade queued before the test began.
	 */
	private function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook IN ( %s, %s )", self::HOOK, ReportRepository::SWEEP_HOOK ) );
		wp_unschedule_hook( self::HOOK );
	}

	/**
	 * Group slug of each pending action for a hook.
	 *
	 * @param string $hook Hook.
	 * @return string[]
	 */
	private function pending_groups( string $hook ): array {
		$store = \ActionScheduler::store();
		$ids   = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		return array_values( array_map( static fn( $id ) => $store->fetch_action( (int) $id )->get_group(), $ids ) );
	}

	/**
	 * A completed action whose run was $days_ago days back.
	 *
	 * @param int    $days_ago Days.
	 * @param string $group    Group.
	 * @param bool   $failed   Mark failed instead of complete.
	 * @return int Action id.
	 */
	private function finished( int $days_ago, string $group, bool $failed = false ): int {
		$id    = as_schedule_single_action( time() - $days_ago * DAY_IN_SECONDS, self::HOOK, array( $days_ago, $group, $failed ), $group );
		$store = \ActionScheduler::store();
		$failed ? $store->mark_failure( $id ) : $store->mark_complete( $id );
		return $id;
	}

	private function exists( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id = %d", $id ) );
	}

	public function test_a_queued_job_lands_in_the_mvs_group(): void {
		ReportRepository::queue_sweep();

		$this->assertSame( array( 'mvs' ), $this->pending_groups( ReportRepository::SWEEP_HOOK ) );
	}

	public function test_daily_prune_deletes_only_our_finished_rows_past_their_age(): void {
		add_filter( 'mvs_finished_action_retention_days', static fn() => 3 );

		$old_done     = $this->finished( 5, 'mvs' );
		$young_done   = $this->finished( 1, 'mvs' );
		$young_failed = $this->finished( 5, 'mvs', true );
		$old_failed   = $this->finished( 95, 'mvs', true );
		$not_ours     = $this->finished( 95, 'someone-else', true );

		do_action( 'mvs_prune_logs' );

		$this->assertFalse( $this->exists( $old_done ), 'A completed job older than the filtered retention was kept.' );
		$this->assertTrue( $this->exists( $young_done ), 'A completed job inside the retention was deleted.' );
		$this->assertTrue( $this->exists( $young_failed ), 'A failed job younger than 90 days was deleted; the owner still needs to read why.' );
		$this->assertFalse( $this->exists( $old_failed ), 'A failed job older than 90 days was kept.' );
		$this->assertTrue( $this->exists( $not_ours ), 'Another plugin\'s job was deleted.' );
	}

	public function test_a_running_copy_is_queued_but_not_pending(): void {
		$id = as_enqueue_async_action( self::HOOK, array( 7 ), 'mvs' );
		\ActionScheduler::store()->log_execution( $id );

		$this->assertTrue( \WPMediaVerse\Core\Jobs::is_queued( self::HOOK, array( 7 ) ), 'A running copy should stop the same work starting twice.' );
		$this->assertFalse( \WPMediaVerse\Core\Jobs::is_pending( self::HOOK, array( 7 ) ), 'A running copy counted as a run still to come.' );
		$this->assertTrue( \WPMediaVerse\Core\Jobs::async_once( self::HOOK, array( 7 ) ) );
		$this->assertTrue( \WPMediaVerse\Core\Jobs::is_pending( self::HOOK, array( 7 ) ), 'async_once() did not queue beside a running copy.' );
		\WPMediaVerse\Core\Jobs::async_once( self::HOOK, array( 7 ) );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => self::HOOK, 'status' => 'pending' ), 'ids' ), 'async_once() queued a second waiting copy.' );
	}

	public function test_the_wp_cron_fallback_passes_keyed_arguments_positionally(): void {
		global $wp_actions;
		$ready = $wp_actions['action_scheduler_init'];
		unset( $wp_actions['action_scheduler_init'] ); // Before Action Scheduler's store loads, Jobs uses WP-Cron.
		try {
			$this->assertTrue( \WPMediaVerse\Core\Jobs::async( self::HOOK, array( 'media_id' => 7 ) ) );
			$this->assertTrue( \WPMediaVerse\Core\Jobs::is_queued( self::HOOK, array( 'media_id' => 7 ) ) );
		} finally {
			$wp_actions['action_scheduler_init'] = $ready;
		}

		$event = wp_get_scheduled_event( self::HOOK, array( 7 ) );
		$this->assertNotFalse( $event, 'The WP-Cron event was not stored with positional arguments.' );

		$got = null;
		add_action(
			self::HOOK,
			static function ( $value ) use ( &$got ) {
				$got = $value;
			}
		);
		do_action_ref_array( self::HOOK, $event->args ); // What wp-cron.php does with a stored event.
		$this->assertSame( 7, $got );
	}

	public function test_a_second_upgrade_run_changes_nothing(): void {
		as_schedule_single_action( time() + HOUR_IN_SECONDS, ReportRepository::SWEEP_HOOK, array( 42 ), 'wpmediaverse' );

		for ( $run = 0; $run < 2; $run++ ) {
			update_option( Migrator::VERSION_OPTION, Migrator::CURRENT_VERSION - 1 );
			( new Migrator() )->run();
		}

		$this->assertSame( array( 'mvs' ), $this->pending_groups( ReportRepository::SWEEP_HOOK ), 'A repeated upgrade duplicated or lost the job.' );
	}

	public function test_upgrade_moves_pending_jobs_from_the_old_groups_keeping_args_and_time(): void {
		$when = time() + HOUR_IN_SECONDS;
		as_schedule_single_action( $when, ReportRepository::SWEEP_HOOK, array( 42 ), 'wpmediaverse' );
		update_option( Migrator::VERSION_OPTION, Migrator::CURRENT_VERSION - 1 );

		( new Migrator() )->run();

		$this->assertSame( array( 'mvs' ), $this->pending_groups( ReportRepository::SWEEP_HOOK ) );
		$ids    = as_get_scheduled_actions( array( 'hook' => ReportRepository::SWEEP_HOOK, 'status' => 'pending' ), 'ids' );
		$action = \ActionScheduler::store()->fetch_action( (int) current( $ids ) );
		$this->assertSame( array( 42 ), $action->get_args() );
		$this->assertSame( $when, $action->get_schedule()->get_date()->getTimestamp() );
	}

	public function test_progress_read_out_sees_pending_jobs_in_old_and_new_groups(): void {
		as_schedule_single_action( time() + HOUR_IN_SECONDS, ReportRepository::SWEEP_HOOK, array( 1 ), 'wpmediaverse' );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, ReportRepository::SWEEP_HOOK, array( 2 ), 'mvs' );

		$args = \WPMediaVerse\Core\Jobs::pending_args( ReportRepository::SWEEP_HOOK );
		sort( $args );

		$this->assertSame( array( array( 1 ), array( 2 ) ), $args );
	}
}
