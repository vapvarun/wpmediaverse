<?php
/**
 * The one place MediaVerse (Free and Pro) queues background work.
 *
 * Action Scheduler (bundled in libs/action-scheduler) when its store is ready, WP-Cron before that, for example
 * when an upgrade routine queues work early in a request. Every Free and Pro class that queues, checks or cancels a
 * job goes through here, so the group, the "is the store ready" rule and the clean-up of our own finished rows live
 * once. Rules and the job list: docs/standards/background-jobs.md.
 *
 * Lookups (is_queued, is_pending, cancel) match by hook and arguments in any group: every MediaVerse hook name
 * starts with mvs_ and is ours alone, and a pending row queued by a version before 2.6.5 sits in an older group.
 *
 * Arguments are passed to the listener positionally on both schedulers. Action Scheduler does that itself; for
 * WP-Cron they are stored as a list (array_values), because core calls the listener with the stored array as is and
 * PHP 8 reads string keys as named parameters.
 *
 * Deliberately outside this class, on WP-Cron only: the recurring housekeeping events armed on boot
 * (mvs_prune_logs, mvs_purge_old_views, mvs_process_account_deletions; Pro's boost, story, play-event and
 * replaced-document sweeps) and the preset licence activation retry, which reads DISABLE_WP_CRON to decide whether
 * to retry at all. The job list in docs/standards/background-jobs.md names each one.
 *
 * @package WPMediaVerse
 * @since   2.6.5
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Background-job entry point.
 */
final class Jobs {

	/**
	 * Action Scheduler group for every MediaVerse job, Free and Pro.
	 */
	public const GROUP = 'mvs';

	/**
	 * Groups MediaVerse used before 2.6.5, moved to GROUP on upgrade.
	 */
	public const LEGACY_GROUPS = array( 'wpmediaverse', 'wpmediaverse-pro', 'mvs-pro', 'mvs-connectors' );

	/**
	 * Days a completed job is kept by prune_history() (filter: mvs_finished_action_retention_days).
	 */
	public const FINISHED_RETENTION_DAYS = 7;

	/**
	 * Days a failed job is kept by prune_history(), long enough to read why it failed. The same rule holds for every
	 * plugin in the family.
	 */
	public const FAILED_RETENTION_DAYS = 90;

	/**
	 * Finished jobs deleted per batch.
	 */
	private const PRUNE_BATCH = 200;

	/**
	 * Batches per status per prune_history() call: at most 10,000 rows of each a day; the rest goes on the next run.
	 */
	private const PRUNE_MAX_BATCHES = 50;

	/**
	 * Pending actions pending_args() reads per hook.
	 */
	public const PENDING_READ_LIMIT = 100;

	/**
	 * Pending rows read per batch when moving them to GROUP.
	 */
	private const ADOPT_BATCH = 500;

	/**
	 * Whether Action Scheduler may be called: its functions exist from plugins_loaded, its store only from
	 * action_scheduler_init.
	 *
	 * @since 2.6.5
	 *
	 * @return bool
	 */
	public static function has_as(): bool {
		return function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' );
	}

	/**
	 * Run a callback now if Action Scheduler's store is ready, else on action_scheduler_init. Upgrade routines run
	 * before the store loads, and queuing before then fails.
	 *
	 * @since 2.6.5
	 *
	 * @param callable $callback Callback.
	 */
	public static function when_ready( callable $callback ): void {
		if ( did_action( 'action_scheduler_init' ) ) {
			$callback();
		} else {
			add_action( 'action_scheduler_init', $callback );
		}
	}

	/**
	 * Queue a job to run as soon as possible.
	 *
	 * @since 2.6.5
	 *
	 * @param string                   $hook Action hook.
	 * @param array<int|string, mixed> $args Hook arguments.
	 * @return bool Whether a scheduler accepted the job; false means the caller should do the work now.
	 */
	public static function async( string $hook, array $args = array() ): bool {
		if ( self::has_as() ) {
			return (bool) as_enqueue_async_action( $hook, $args, self::GROUP );
		}
		return true === wp_schedule_single_event( time(), $hook, array_values( $args ), true );
	}

	/**
	 * Queue a job unless a copy with the same arguments is already waiting to start. A copy that is running now does
	 * not count: it may have read its data before the change that queues this one.
	 *
	 * @since 2.6.5
	 *
	 * @param string                   $hook Action hook.
	 * @param array<int|string, mixed> $args Hook arguments.
	 * @return bool Whether a copy is waiting now (queued here or already); false means the caller should do the work now.
	 */
	public static function async_once( string $hook, array $args = array() ): bool {
		return self::is_pending( $hook, $args ) || self::async( $hook, $args );
	}

	/**
	 * Run a job once at a given time.
	 *
	 * @since 2.6.5
	 *
	 * @param int                      $timestamp Unix time.
	 * @param string                   $hook      Action hook.
	 * @param array<int|string, mixed> $args      Hook arguments.
	 * @return bool Whether a scheduler accepted the job.
	 */
	public static function at( int $timestamp, string $hook, array $args = array() ): bool {
		if ( self::has_as() ) {
			return (bool) as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
		}
		return true === wp_schedule_single_event( $timestamp, $hook, array_values( $args ), true );
	}

	/**
	 * Whether a pending or running copy exists, in either scheduler. Use it to avoid starting the same work twice;
	 * use is_pending() when the question is whether a later run is still coming.
	 *
	 * @since 2.6.5
	 *
	 * @param string                        $hook Action hook.
	 * @param array<int|string, mixed>|null $args Exact arguments, or null for any.
	 * @return bool
	 */
	public static function is_queued( string $hook, ?array $args = null ): bool {
		if ( self::has_as() && as_has_scheduled_action( $hook, $args ) ) {
			return true;
		}
		return null === $args ? self::cron_has( $hook ) : false !== wp_next_scheduled( $hook, array_values( $args ) );
	}

	/**
	 * Whether a copy is waiting to start, in either scheduler. A running copy does not count, so a job armed to run
	 * after the current one (a reaper, a follow-up pass) is armed even while one runs.
	 *
	 * @since 2.6.5
	 *
	 * @param string                        $hook Action hook.
	 * @param array<int|string, mixed>|null $args Exact arguments, or null for any.
	 * @return bool
	 */
	public static function is_pending( string $hook, ?array $args = null ): bool {
		if ( self::has_as() ) {
			$query = array(
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			);
			if ( null !== $args ) {
				$query['args'] = $args;
			}
			if ( as_get_scheduled_actions( $query, 'ids' ) ) {
				return true;
			}
		}
		return null === $args ? self::cron_has( $hook ) : false !== wp_next_scheduled( $hook, array_values( $args ) );
	}

	/**
	 * Next run time of a pending Action Scheduler copy with any arguments.
	 *
	 * @since 2.6.5
	 *
	 * @param string $hook Action hook.
	 * @return int Unix time; 0 when none is pending, or one is running now.
	 */
	public static function next( string $hook ): int {
		if ( ! self::has_as() ) {
			return (int) wp_next_scheduled( $hook );
		}
		$next = as_next_scheduled_action( $hook );
		return is_int( $next ) ? $next : 0;
	}

	/**
	 * Make sure exactly one pending recurring row exists for an argument-less hook.
	 *
	 * "Is it scheduled? If not, schedule it" is not atomic, so requests arriving together each scheduled one; Action
	 * Scheduler's $unique flag inserts only when no pending copy exists, and copies an older version left behind are
	 * cancelled here. Only the argument-less row counts: a continuation of the same hook carries a cursor. Runs only
	 * on a scheduling request; the cron runner re-arms a lost row on action_scheduler_ensure_recurring_actions.
	 *
	 * @since 2.6.5
	 *
	 * @param string     $hook     Action hook.
	 * @param int        $first    Unix time of the first run.
	 * @param int|string $schedule Interval in seconds, or a cron expression when $cron is true.
	 * @param bool       $cron     Whether $schedule is a cron expression.
	 */
	public static function ensure_recurring( string $hook, int $first, $schedule, bool $cron = false ): void {
		if ( ! self::is_scheduling_request() || ! self::has_as() ) {
			return;
		}

		$pending = count(
			(array) as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => array(),
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				),
				'ids'
			)
		);

		if ( $pending ) {
			for ( $i = 1; $i < $pending; $i++ ) {
				as_unschedule_action( $hook, array() );
			}
			return;
		}

		if ( $cron ) {
			as_schedule_cron_action( $first, (string) $schedule, $hook, array(), self::GROUP, true );
			return;
		}
		as_schedule_recurring_action( $first, (int) $schedule, $hook, array(), self::GROUP, true );
	}

	/**
	 * Whether this request may do scheduling and upkeep checks: cron, WP-CLI, an admin page load (not an admin-ajax
	 * call, which visitors' pages make too), or Action Scheduler's daily action_scheduler_ensure_recurring_actions
	 * run, which its async runner may execute inside an admin-ajax request. Each check costs a query, so a visitor's
	 * request never pays for it. The one rule for the family; DirectDelivery and the migrations use it too.
	 *
	 * @since 2.6.5
	 *
	 * @return bool
	 */
	public static function is_scheduling_request(): bool {
		return wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( is_admin() && ! wp_doing_ajax() )
			|| doing_action( 'action_scheduler_ensure_recurring_actions' );
	}

	/**
	 * Cancel pending copies of a job in both schedulers.
	 *
	 * @since 2.6.5
	 *
	 * @param string                   $hook Action hook.
	 * @param array<int|string, mixed> $args Exact arguments; empty cancels every copy whatever its arguments.
	 */
	public static function cancel( string $hook, array $args = array() ): void {
		if ( self::has_as() ) {
			as_unschedule_all_actions( $hook, $args );
		}
		if ( $args ) {
			wp_clear_scheduled_hook( $hook, array_values( $args ) );
		} else {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Arguments of the pending Action Scheduler copies of a job, oldest first, for progress read-outs.
	 *
	 * @since 2.6.5
	 *
	 * @param string $hook Action hook.
	 * @return array<int, array<int|string, mixed>> Up to PENDING_READ_LIMIT argument lists.
	 */
	public static function pending_args( string $hook ): array {
		if ( ! self::has_as() ) {
			return array();
		}
		$actions = (array) as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => self::PENDING_READ_LIMIT,
			)
		);
		return array_values( array_map( static fn( $action ) => (array) $action->get_args(), $actions ) );
	}

	/**
	 * Whether WP-Cron holds an event for a hook with any arguments.
	 *
	 * @param string $hook Action hook.
	 * @return bool
	 */
	private static function cron_has( string $hook ): bool {
		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ $hook ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delete MediaVerse's own job history in GROUP (Free and Pro), with the log rows: completed actions older than
	 * FINISHED_RETENTION_DAYS and failed actions older than FAILED_RETENTION_DAYS. Action Scheduler's own cleaner
	 * keeps every plugin's history for a month and that site-wide setting is not ours to change, so we trim only our
	 * own rows. Pending and running rows are never touched. Runs on the existing daily mvs_prune_logs event.
	 *
	 * @since 2.6.5
	 */
	public static function prune_history(): void {
		if ( ! self::has_as() ) {
			return;
		}

		/**
		 * Days a completed MediaVerse job is kept before MediaVerse deletes it with its log rows. Only the 'mvs'
		 * group is touched; pending and running jobs are never deleted, failed ones after 90 days.
		 *
		 * @since 2.6.5
		 *
		 * @param int $days Whole days, at least 1. Default 7.
		 */
		$days = max( 1, (int) apply_filters( 'mvs_finished_action_retention_days', self::FINISHED_RETENTION_DAYS ) );
		self::delete_finished( \ActionScheduler_Store::STATUS_COMPLETE, $days );
		self::delete_finished( \ActionScheduler_Store::STATUS_FAILED, self::FAILED_RETENTION_DAYS );
	}

	/**
	 * Delete GROUP's actions of one finished status scheduled more than $days ago, in batches.
	 *
	 * @param string $status Action Scheduler status.
	 * @param int    $days   Age in days.
	 */
	private static function delete_finished( string $status, int $days ): void {
		$before = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$store  = \ActionScheduler::store();

		for ( $batch = 0; $batch < self::PRUNE_MAX_BATCHES; $batch++ ) {
			$ids = (array) as_get_scheduled_actions(
				array(
					'group'        => self::GROUP,
					'status'       => $status,
					'date'         => $before,
					'date_compare' => '<',
					'per_page'     => self::PRUNE_BATCH,
					'orderby'      => 'none',
				),
				'ids'
			);
			foreach ( $ids as $id ) {
				$store->delete_action( (int) $id ); // The DB logger deletes the action's log rows on this hook.
			}
			if ( count( $ids ) < self::PRUNE_BATCH ) {
				break;
			}
		}
	}

	/**
	 * Move the pending actions of the given hooks out of the pre-2.6.5 groups into GROUP, keeping each one's
	 * schedule and arguments. Run once from the upgrade routine. Each plugin passes only its own hooks, so a newer
	 * Free never moves rows an older Pro still looks up by group.
	 *
	 * @since 2.6.5
	 *
	 * @param string[] $hooks Action hooks.
	 * @return int Actions moved.
	 */
	public static function adopt_pending( array $hooks ): int {
		if ( ! self::has_as() ) {
			return 0;
		}
		$store = \ActionScheduler::store();
		$moved = 0;
		foreach ( $hooks as $hook ) {
			foreach ( self::LEGACY_GROUPS as $group ) {
				do {
					$actions = (array) as_get_scheduled_actions(
						array(
							'hook'     => $hook,
							'group'    => $group,
							'status'   => \ActionScheduler_Store::STATUS_PENDING,
							'per_page' => self::ADOPT_BATCH,
						)
					);
					$found   = count( $actions );
					foreach ( $actions as $id => $action ) {
						$store->save_action( new \ActionScheduler_Action( $action->get_hook(), $action->get_args(), $action->get_schedule(), self::GROUP ) );
						$store->delete_action( (int) $id );
						++$moved;
					}
				} while ( self::ADOPT_BATCH === $found );
			}
		}
		return $moved;
	}
}
