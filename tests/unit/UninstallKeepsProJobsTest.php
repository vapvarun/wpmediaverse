<?php
/**
 * Deleting Free removes only Free's job rows: Pro's pending jobs share the mvs group and the mvs_ prefix (2.7.0).
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class UninstallKeepsProJobsTest extends WP_UnitTestCase {

	private function exists( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id = %d", $id ) );
	}

	public function test_a_pro_job_without_the_pro_prefix_survives_free_uninstall(): void {
		$this->assertTrue( (bool) did_action( 'action_scheduler_init' ), 'Action Scheduler did not initialise in the test bootstrap.' );
		update_option( 'mvs_delete_data_on_uninstall', '0' );

		$pro  = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'mvs_document_folder_purge', array( 9 ), 'mvs' );
		$free = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'mvs_close_orphan_reports', array( 9 ), 'mvs' );
		\ActionScheduler::store()->mark_complete( $free ); // A finished row: only uninstall's direct delete removes it.

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wpmediaverse/wpmediaverse.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertTrue( $this->exists( $pro ), 'Free uninstall deleted a pending Pro job.' );
		$this->assertFalse( $this->exists( $free ), 'Free uninstall left its own job row.' );

		as_unschedule_all_actions( 'mvs_document_folder_purge' );
	}
}
