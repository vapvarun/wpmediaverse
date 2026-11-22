<?php
/**
 * Recurring jobs are armed from admin, cron and WP-CLI only.
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Jobs;

/**
 * Four services called Jobs::ensure_recurring() on every init, one Action
 * Scheduler query each on every visitor's page view.
 */
class JobsSchedulingGateTest extends WP_UnitTestCase {

	const HOOK = 'mvs_test_gate_job';

	public function tear_down(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK );
		}
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_visitor_request_does_not_touch_the_scheduler(): void {
		$this->assertGreaterThan( 0, did_action( 'action_scheduler_init' ), 'Action Scheduler must be loaded: the bundled copy boots with Free.' );
		$this->assertFalse( Jobs::is_scheduling_request() );

		global $wpdb;
		$before = $wpdb->num_queries;
		Jobs::ensure_recurring( self::HOOK, time() + 60, 3600 );
		$this->assertSame( $before, $wpdb->num_queries, 'A visitor request queried Action Scheduler.' );
		$this->assertFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_admin_page_load_arms_the_job(): void {
		$this->assertGreaterThan( 0, did_action( 'action_scheduler_init' ), 'Action Scheduler must be loaded: the bundled copy boots with Free.' );
		set_current_screen( 'dashboard' );
		$this->assertTrue( Jobs::is_scheduling_request() );

		Jobs::ensure_recurring( self::HOOK, time() + 60, 3600 );
		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_the_daily_housekeeping_run_arms_the_job_inside_admin_ajax(): void {
		$this->assertGreaterThan( 0, did_action( 'action_scheduler_init' ), 'Action Scheduler must be loaded: the bundled copy boots with Free.' );
		// Action Scheduler's async runner executes queued actions inside an admin-ajax request.
		set_current_screen( 'dashboard' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( Jobs::is_scheduling_request(), 'Fixture: a bare admin-ajax request is not a scheduling request.' );

		$arm = static function (): void {
			Jobs::ensure_recurring( self::HOOK, time() + 60, 3600 );
		};
		add_action( 'action_scheduler_ensure_recurring_actions', $arm );
		do_action( 'action_scheduler_ensure_recurring_actions' );
		remove_action( 'action_scheduler_ensure_recurring_actions', $arm );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ), 'The housekeeping run inside admin-ajax did not arm the job.' );
	}
}
