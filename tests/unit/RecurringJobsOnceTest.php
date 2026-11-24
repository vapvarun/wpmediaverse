<?php
/**
 * Recurring jobs exist once (QA, 2.6.0).
 *
 * Parallel requests each saw no pending tick and each scheduled one, so the
 * competition tick and autopilot ran two or three times. Jobs::ensure_recurring()
 * schedules with Action Scheduler's unique flag and cancels copies left by
 * older versions.
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Jobs;

class RecurringJobsOnceTest extends WP_UnitTestCase {

	private const HOOK = 'mvs_test_recurring_once';

	private function pending(): int {
		return count(
			as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				),
				'ids'
			)
		);
	}

	public function set_up(): void {
		parent::set_up();
		// Jobs are armed from admin, cron and WP-CLI only (JobsSchedulingGateTest).
		set_current_screen( 'dashboard' );
	}

	public function tear_down(): void {
		as_unschedule_all_actions( self::HOOK );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_duplicates_collapse_and_repeat_calls_add_nothing(): void {
		$this->assertGreaterThan( 0, did_action( 'action_scheduler_init' ), 'Action Scheduler must be loaded: the bundled copy boots with Free.' );
		// What the old check-then-schedule left behind after a burst of requests.
		for ( $i = 0; $i < 3; $i++ ) {
			as_schedule_recurring_action( time() + 300 + $i, 300, self::HOOK, array(), 'mvs-test' );
		}
		$this->assertSame( 3, $this->pending() );

		Jobs::ensure_recurring( self::HOOK, time() + 300, 300 );
		$this->assertSame( 1, $this->pending(), 'Duplicate recurring jobs were not collapsed.' );

		Jobs::ensure_recurring( self::HOOK, time() + 300, 300 );
		Jobs::ensure_recurring( self::HOOK, time() + 300, 300 );
		$this->assertSame( 1, $this->pending(), 'A repeat call scheduled another copy.' );
	}

	public function test_schedules_when_none_is_pending(): void {
		$this->assertGreaterThan( 0, did_action( 'action_scheduler_init' ), 'Action Scheduler must be loaded: the bundled copy boots with Free.' );
		Jobs::ensure_recurring( self::HOOK, time() + 300, 300 );
		$this->assertSame( 1, $this->pending() );
	}

	public function test_a_cursor_continuation_does_not_count_as_the_recurring_row(): void {
		$this->assertGreaterThan( 0, did_action( 'action_scheduler_init' ), 'Action Scheduler must be loaded: the bundled copy boots with Free.' );
		// A batch job that continues itself carries a cursor; it is not the daily row.
		as_enqueue_async_action( self::HOOK, array( 500 ), 'mvs' );

		Jobs::ensure_recurring( self::HOOK, time() + 300, 300 );

		$this->assertSame( 2, $this->pending(), 'The recurring row was not armed beside a continuation.' );
	}
}
