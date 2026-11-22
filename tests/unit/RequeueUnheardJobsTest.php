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
		$this->setExpectedDeprecated( Deactivator::class . '::clear_scheduled' );
		Deactivator::clear_scheduled( false );
		$this->assertSame( 'pending', $this->status( $id ), 'Fixture: deactivation leaves a state job.' );
		remove_all_actions( self::HOOK );
		\ActionScheduler::runner()->process_action( $id );
		$this->assertSame( 'failed', $this->status( $id ), 'Fixture: the runner fails a row with no listener.' );
		return $id;
	}

	/** Turn the plugin back on: its listener is registered again, and activation sets the note. */
	private function activate(): void {
		add_action( self::HOOK, '__return_null' );
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

		$this->assertCount(
			Jobs::REQUEUE_BATCH + 1,
			as_get_scheduled_actions(
				array(
					'hook' => self::HOOK,
					'status' => 'pending',
					'per_page' => -1,
				),
				'ids'
			)
		);
		$this->assertFalse( get_transient( Activator::REQUEUE_TRANSIENT ) );
	}

	/**
	 * Two scheduling requests (an admin page load and the cron spawn) read the same failed rows. The second finds
	 * rows the first already moved and deleted: it must neither throw nor queue a job with no hook.
	 */
	public function test_two_requests_over_the_same_rows_neither_throw_nor_queue_an_empty_job(): void {
		global $wpdb;
		for ( $i = 0; $i < Jobs::REQUEUE_BATCH; $i++ ) {
			$this->failed_while_off( array( "e{$i}.jpg" ) );
		}
		add_action( self::HOOK, '__return_null' );

		// The live store on a migrated site: unlike the test bootstrap's hybrid store, it throws on a missing row.
		$slot = new \ReflectionProperty( \ActionScheduler_Store::class, 'store' );
		$slot->setAccessible( true );
		$was = $slot->getValue();
		$slot->setValue( null, new \ActionScheduler_DBStore() );

		// Request B runs between request A queueing the copy of its first row and deleting that row, so A then
		// deletes a row B already deleted, and works through 199 more that B moved.
		$nested = false;
		$second = static function () use ( &$nested ): void {
			if ( ! $nested ) {
				$nested = true;
				Jobs::requeue_unheard( Deactivator::STATE_JOB_HOOKS );
			}
		};
		add_action( 'action_scheduler_stored_action', $second );
		try {
			Jobs::requeue_unheard( Deactivator::STATE_JOB_HOOKS );
		} finally {
			remove_action( 'action_scheduler_stored_action', $second );
			$slot->setValue( null, $was );
		}

		$this->assertTrue( $nested, 'Fixture: the second request ran.' );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = ''" ), 'A job with no hook was queued.' );
		$this->assertCount(
			Jobs::REQUEUE_BATCH,
			as_get_scheduled_actions(
				array(
					'hook' => self::HOOK,
					'status' => 'pending',
					'per_page' => -1,
				),
				'ids'
			),
			'One copy per row expected.'
		);
		$this->assertSame(
			array(),
			as_get_scheduled_actions(
				array(
					'hook' => self::HOOK,
					'status' => 'failed',
					'per_page' => -1,
				),
				'ids'
			),
			'Failed rows were left.'
		);
	}

	public function test_a_row_that_failed_more_than_a_week_ago_stays_failed(): void {
		global $wpdb;
		$id = $this->failed_while_off( array( 'f.jpg' ) );
		$wpdb->update( $wpdb->prefix . 'actionscheduler_actions', array( 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ), array( 'action_id' => $id ) );

		$this->activate();

		$this->assertSame( 'failed', $this->status( $id ), 'Week-old work was queued again.' );
	}

	public function test_a_hook_with_no_listener_now_stays_failed(): void {
		$id = $this->failed_while_off( array( 'g.jpg' ) );

		set_transient( Activator::REQUEUE_TRANSIENT, 1, DAY_IN_SECONDS );
		Jobs::requeue_after_activation( Activator::REQUEUE_TRANSIENT, Deactivator::STATE_JOB_HOOKS );

		$this->assertSame( 'failed', $this->status( $id ), 'A row was queued again for a hook nothing listens to.' );
	}

	public function test_activation_sets_the_note(): void {
		delete_transient( Activator::REQUEUE_TRANSIENT );
		Activator::activate();
		$this->assertNotFalse( get_transient( Activator::REQUEUE_TRANSIENT ) );
	}

	public function test_an_update_that_runs_the_migrations_sets_the_note(): void {
		delete_transient( Activator::REQUEUE_TRANSIENT );
		update_option( \WPMediaVerse\Core\Migrator::VERSION_OPTION, \WPMediaVerse\Core\Migrator::CURRENT_VERSION - 1 );

		( new \WPMediaVerse\Core\Migrator() )->run();

		$this->assertNotFalse( get_transient( Activator::REQUEUE_TRANSIENT ), 'A one-click update fires no activation hook; the migration runner must set the note.' );
	}
}
