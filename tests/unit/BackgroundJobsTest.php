<?php
/**
 * Background jobs: one group, our own finished rows trimmed, old pending rows moved on upgrade (2.7.0).
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Jobs;
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
		set_current_screen( 'front' );
		remove_all_filters( 'mvs_finished_action_retention_days' );
		parent::tear_down();
	}

	/**
	 * Remove this test's rows, and the sweep the install-time upgrade queued before the test began.
	 */
	private function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook IN ( %s, %s, %s )", self::HOOK, ReportRepository::SWEEP_HOOK, Jobs::ADOPT_HOOK ) );
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
		// Prune ages a finished row by its last attempt, which marking it just set to now.
		$when = gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );
		$GLOBALS['wpdb']->update(
			$GLOBALS['wpdb']->prefix . 'actionscheduler_actions',
			array(
				'last_attempt_gmt' => $when,
				'last_attempt_local' => $when,
			),
			array( 'action_id' => $id )
		);
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
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook' => self::HOOK,
					'status' => 'pending',
				),
				'ids'
			),
			'async_once() queued a second waiting copy.'
		);
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
		set_current_screen( 'dashboard' ); // The move runs on scheduling requests only.
		as_schedule_single_action( time() + HOUR_IN_SECONDS, ReportRepository::SWEEP_HOOK, array( 42 ), 'wpmediaverse' );

		for ( $run = 0; $run < 2; $run++ ) {
			update_option( Migrator::VERSION_OPTION, Migrator::CURRENT_VERSION - 1 );
			( new Migrator() )->run();
		}

		$this->assertSame( array( 'mvs' ), $this->pending_groups( ReportRepository::SWEEP_HOOK ), 'A repeated upgrade duplicated or lost the job.' );
	}

	public function test_upgrade_moves_pending_jobs_from_the_old_groups_keeping_args_and_time(): void {
		set_current_screen( 'dashboard' ); // The move runs on scheduling requests only.
		$when = time() + HOUR_IN_SECONDS;
		as_schedule_single_action( $when, ReportRepository::SWEEP_HOOK, array( 42 ), 'wpmediaverse' );
		update_option( Migrator::VERSION_OPTION, Migrator::CURRENT_VERSION - 1 );

		( new Migrator() )->run();

		$this->assertSame( array( 'mvs' ), $this->pending_groups( ReportRepository::SWEEP_HOOK ) );
		$ids    = as_get_scheduled_actions(
			array(
				'hook' => ReportRepository::SWEEP_HOOK,
				'status' => 'pending',
			),
			'ids'
		);
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

	public function test_daily_prune_deletes_old_cancelled_rows_and_counts_what_it_deleted(): void {
		add_filter( 'mvs_finished_action_retention_days', static fn() => 3 );
		$old   = as_schedule_single_action( time() - 5 * DAY_IN_SECONDS, self::HOOK, array( 'old' ), 'mvs' );
		$young = as_schedule_single_action( time() - DAY_IN_SECONDS, self::HOOK, array( 'young' ), 'mvs' );
		\ActionScheduler::store()->cancel_action( $old );
		\ActionScheduler::store()->cancel_action( $young );
		$done = $this->finished( 5, 'mvs' );

		$this->assertNotFalse( has_action( 'mvs_prune_logs' ), 'Nothing runs the daily prune.' );
		$deleted = Jobs::prune_history();

		$this->assertFalse( $this->exists( $old ), 'A cancelled job older than the retention was kept.' );
		$this->assertTrue( $this->exists( $young ), 'A cancelled job inside the retention was deleted.' );
		$this->assertFalse( $this->exists( $done ) );
		$this->assertGreaterThanOrEqual( 2, $deleted, 'prune_history() did not count the rows it deleted.' );
	}

	public function test_async_falls_back_to_wp_cron_when_action_scheduler_refuses_and_counts_a_waiting_copy(): void {
		$refuse = static fn() => 0;
		add_filter( 'pre_as_enqueue_async_action', $refuse );
		try {
			$this->assertTrue( Jobs::async( self::HOOK, array( 3 ) ), 'A refused job was reported as not queued although WP-Cron could take it.' );
			$this->assertNotFalse( wp_next_scheduled( self::HOOK, array( 3 ) ) );
			$this->assertTrue( Jobs::async( self::HOOK, array( 3 ) ), 'A WP-Cron copy already waiting was reported as not queued.' );
			$this->assertFalse( Jobs::async( self::HOOK, array( 4 ), false ), 'Without the fallback a refused job must tell the caller to run it inline.' );
		} finally {
			remove_filter( 'pre_as_enqueue_async_action', $refuse );
		}
	}

	public function test_async_once_queues_in_action_scheduler_beside_a_wp_cron_copy(): void {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array( 9 ) );

		$this->assertTrue( Jobs::async_once( self::HOOK, array( 9 ) ) );

		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook' => self::HOOK,
					'status' => 'pending',
				),
				'ids'
			),
			'A WP-Cron copy left by an early request stopped the Action Scheduler job.'
		);
	}

	public function test_next_falls_back_to_wp_cron_and_pending_args_are_oldest_first(): void {
		$when = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $when, self::HOOK );
		$this->assertSame( $when, Jobs::next( self::HOOK ), 'next() ignored a WP-Cron copy.' );

		as_schedule_single_action( time() + 2 * HOUR_IN_SECONDS, self::HOOK, array( 'later' ), 'mvs' );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, self::HOOK, array( 'sooner' ), 'mvs' );
		$this->assertSame( array( array( 'sooner' ), array( 'later' ) ), Jobs::pending_args( self::HOOK ) );
	}

	public function test_a_recurring_interval_is_at_least_a_minute(): void {
		set_current_screen( 'dashboard' );
		Jobs::ensure_recurring( self::HOOK, time() + HOUR_IN_SECONDS, 10 );
		set_current_screen( 'front' );

		$ids = as_get_scheduled_actions(
			array(
				'hook' => self::HOOK,
				'status' => 'pending',
			),
			'ids'
		);
		$this->assertSame( MINUTE_IN_SECONDS, (int) \ActionScheduler::store()->fetch_action( (int) current( $ids ) )->get_schedule()->get_recurrence() );
	}

	public function test_adopt_keeps_priority_skips_claimed_rows_and_hands_the_rest_to_a_follow_up(): void {
		set_current_screen( 'dashboard' ); // The move runs on scheduling requests only.
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';

		$claimed = as_schedule_single_action( time() + HOUR_IN_SECONDS, self::HOOK, array( 'claimed' ), 'wpmediaverse', false, 5 );
		$wpdb->update( $table, array( 'claim_id' => 999 ), array( 'action_id' => $claimed ) );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, self::HOOK, array( 'bulk' ), 'wpmediaverse', false, 5 );
		// 2,048 unclaimed rows, more than one call moves.
		for ( $i = 0; $i < 11; $i++ ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} ( hook, status, scheduled_date_gmt, scheduled_date_local, args, schedule, group_id, attempts, last_attempt_gmt, last_attempt_local, claim_id, extended_args, priority ) SELECT hook, status, scheduled_date_gmt, scheduled_date_local, args, schedule, group_id, attempts, last_attempt_gmt, last_attempt_local, claim_id, extended_args, priority FROM {$table} WHERE hook = %s AND claim_id = 0", self::HOOK ) );
		}

		$this->assertNotFalse( has_action( Jobs::ADOPT_HOOK ), 'Nothing runs the follow-up job.' );
		$moved = Jobs::adopt_pending( array( self::HOOK ) );

		$this->assertSame( 2000, $moved, 'One call must stop at its row cap.' );
		$this->assertSame( array( array( array( self::HOOK ) ) ), Jobs::pending_args( Jobs::ADOPT_HOOK ), 'No follow-up job carries the rest.' );
		$this->assertTrue( $this->exists( $claimed ), 'A claimed row was moved while a runner holds it.' );
		$ids  = as_get_scheduled_actions(
			array(
				'hook' => self::HOOK,
				'group' => 'mvs',
				'status' => 'pending',
				'per_page' => 1,
			),
			'ids'
		);
		$this->assertSame( 5, \ActionScheduler::store()->fetch_action( (int) current( $ids ) )->get_priority(), 'The moved row lost its priority.' );

		Jobs::adopt_pending( array( self::HOOK ) );
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} a JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id WHERE a.hook = %s AND a.status = 'pending' AND g.slug = 'wpmediaverse'", self::HOOK ) );
		$this->assertSame( 1, $left, 'The follow-up did not move the rest; only the claimed row may stay.' );
	}

	public function test_a_visitor_request_hands_the_move_to_a_job_instead_of_doing_it(): void {
		as_schedule_single_action( time() + HOUR_IN_SECONDS, self::HOOK, array( 'later' ), 'wpmediaverse' );

		$this->assertSame( 0, Jobs::adopt_pending( array( self::HOOK ) ), 'A visitor request moved rows.' );
		$this->assertSame( array( 'wpmediaverse' ), $this->pending_groups( self::HOOK ) );
		$this->assertSame( array( array( array( self::HOOK ) ) ), Jobs::pending_args( Jobs::ADOPT_HOOK ), 'No job was queued to do the move.' );
	}

	public function test_a_job_finished_long_ago_is_pruned_by_when_it_finished_not_when_it_was_due(): void {
		add_filter( 'mvs_finished_action_retention_days', static fn() => 3 );
		// Due 30 days ago, finished an hour ago (a long stalled queue).
		$id = as_schedule_single_action( time() - 30 * DAY_IN_SECONDS, self::HOOK, array( 'late' ), 'mvs' );
		\ActionScheduler::store()->mark_complete( $id );

		Jobs::prune_history();

		$this->assertTrue( $this->exists( $id ), 'A job that finished an hour ago was pruned by its due date.' );
	}
}
