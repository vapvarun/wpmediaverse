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
		if ( ! did_action( 'action_scheduler_init' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
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

	public function test_daily_prune_deletes_only_our_finished_rows_past_the_filtered_age(): void {
		add_filter( 'mvs_finished_action_retention_days', static fn() => 3 );

		$old_done   = $this->finished( 5, 'mvs' );
		$young_done = $this->finished( 1, 'mvs' );
		$old_failed = $this->finished( 5, 'mvs', true );
		$not_ours   = $this->finished( 5, 'someone-else' );

		do_action( 'mvs_prune_logs' );

		$this->assertFalse( $this->exists( $old_done ), 'A finished job older than the filtered retention was kept.' );
		$this->assertTrue( $this->exists( $young_done ), 'A finished job inside the retention was deleted.' );
		$this->assertTrue( $this->exists( $old_failed ), 'A failed job was deleted; failures are kept for the owner to inspect.' );
		$this->assertTrue( $this->exists( $not_ours ), 'Another plugin\'s finished job was deleted.' );
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
