<?php
/**
 * A state job a runner failed while MediaVerse was off is queued again after activation (2.6.5).
 *
 * Deactivation leaves state jobs pending. If another plugin keeps Action Scheduler running, the runner takes those
 * rows, finds no listener and marks them failed ("no callbacks are registered"): a file cleanup never deleted its
 * files and a member deletion never removed the member's data. Activation now gives them back; a job that really
 * ran and errored stays failed.
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Activator;
use WPMediaVerse\Core\Deactivator;
use WPMediaVerse\Core\Jobs;

class RequeueUnheardJobsTest extends WP_UnitTestCase {

	private const HOOK = 'mvs_cleanup_media_files';

	public function set_up(): void {
		parent::set_up();
		$this->assertGreaterThan( 0, did_action( 'action_scheduler_init' ), 'Action Scheduler must be loaded: the bundled copy boots with Free.' );
		$this->clear();
		set_current_screen( 'dashboard' );
	}

	public function tear_down(): void {
		$this->clear();
		delete_transient( Activator::REQUEUE_TRANSIENT );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s", self::HOOK ) );
	}

	private function status( int $id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id = %d", $id ) );
	}

	/** Queue a cleanup, deactivate, and let a runner take it with nothing listening. */
	private function failed_while_off( array $paths ): int {
		$id = as_enqueue_async_action( self::HOOK, array( $paths, 1 ), 'mvs' );
		Deactivator::clear_scheduled( false );
		$this->assertSame( 'pending', $this->status( $id ), 'Fixture: deactivation leaves a state job.' );
		remove_all_actions( self::HOOK );
		\ActionScheduler::runner()->process_action( $id );
		$this->assertSame( 'failed', $this->status( $id ), 'Fixture: the runner fails a row with no listener.' );
		return $id;
	}

	private function activate(): void {
		set_transient( Activator::REQUEUE_TRANSIENT, 1, DAY_IN_SECONDS );
		Jobs::requeue_after_activation( Activator::REQUEUE_TRANSIENT, Deactivator::STATE_JOB_HOOKS );
	}

	public function test_a_state_job_failed_while_off_is_queued_again_with_its_arguments(): void {
		$id = $this->failed_while_off( array( '2026/10/a.jpg', '2026/10/a.webp' ) );

		$this->activate();

		$this->assertSame( array( array( array( '2026/10/a.jpg', '2026/10/a.webp' ), 1 ) ), Jobs::pending_args( self::HOOK ), 'The cleanup was not queued again.' );
		$this->assertSame( '', $this->status( $id ), 'The failed row stayed beside its new copy.' );
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id = %d", $id ) ), 'The failed row\'s logs were left.' );
		$this->assertFalse( get_transient( Activator::REQUEUE_TRANSIENT ), 'Nothing is left, so the note must go.' );
	}

	public function test_a_job_that_really_ran_and_failed_stays_failed(): void {
		$id = as_enqueue_async_action( self::HOOK, array( array( 'b.jpg' ), 1 ), 'mvs' );
		remove_all_actions( self::HOOK );
		add_action(
			self::HOOK,
			static function (): void {
				throw new \RuntimeException( 'Storage driver refused the delete.' );
			}
		);
		\ActionScheduler::runner()->process_action( $id );
		$this->assertSame( 'failed', $this->status( $id ) );

		$this->activate();

		$this->assertSame( 'failed', $this->status( $id ), 'A job that ran and errored was queued again.' );
		$this->assertSame( array(), Jobs::pending_args( self::HOOK ) );
	}

	public function test_one_pending_copy_per_arguments(): void {
		$this->failed_while_off( array( 'c.jpg' ) );
		as_enqueue_async_action( self::HOOK, array( array( 'c.jpg' ), 1 ), 'mvs' );

		$this->activate();

		$this->assertCount( 1, Jobs::pending_args( self::HOOK ) );
	}

	public function test_more_than_one_batch_continues_on_the_next_request(): void {
		for ( $i = 0; $i <= Jobs::REQUEUE_BATCH; $i++ ) {
			$this->failed_while_off( array( "d{$i}.jpg" ) );
		}

		$this->activate();
		$this->assertNotFalse( get_transient( Activator::REQUEUE_TRANSIENT ), 'The note went with failed rows still left.' );
		Jobs::requeue_after_activation( Activator::REQUEUE_TRANSIENT, Deactivator::STATE_JOB_HOOKS );

		$this->assertCount( Jobs::REQUEUE_BATCH + 1, as_get_scheduled_actions( array( 'hook' => self::HOOK, 'status' => 'pending', 'per_page' => -1 ), 'ids' ) );
		$this->assertFalse( get_transient( Activator::REQUEUE_TRANSIENT ) );
	}

	public function test_activation_sets_the_note(): void {
		delete_transient( Activator::REQUEUE_TRANSIENT );
		Activator::activate();
		$this->assertNotFalse( get_transient( Activator::REQUEUE_TRANSIENT ) );
	}
}
