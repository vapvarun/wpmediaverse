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
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler not loaded.' );
		}
		$this->assertFalse( Jobs::is_scheduling_request() );

		global $wpdb;
		$before = $wpdb->num_queries;
		Jobs::ensure_recurring( self::HOOK, time() + 60, 3600 );
		$this->assertSame( $before, $wpdb->num_queries, 'A visitor request queried Action Scheduler.' );
		$this->assertFalse( as_next_scheduled_action( self::HOOK ) );
	}

	public function test_admin_page_load_arms_the_job(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler not loaded.' );
		}
		set_current_screen( 'dashboard' );
		$this->assertTrue( Jobs::is_scheduling_request() );

		Jobs::ensure_recurring( self::HOOK, time() + 60, 3600 );
		$this->assertNotFalse( as_next_scheduled_action( self::HOOK ) );
	}
}
