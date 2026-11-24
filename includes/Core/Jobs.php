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
 * starts with mvs_ and is ours alone, and a pending row queued by a version before 2.7.0 sits in an older group.
 *
 * Arguments are passed to the listener positionally on both schedulers. Action Scheduler does that itself; for
 * WP-Cron they are stored as a list (array_values), because core calls the listener with the stored array as is and
 * PHP 8 reads string keys as named parameters.
 *
 * Differences from \Jetonomy\Queue (the sister plugin's entry point; the shared method names behave the same):
 * - ensure_recurring() also takes a cron expression ($cron = true), which the streak check uses; Jetonomy takes only
 *   an interval. Both clamp an interval to at least 60 seconds.
 * - No recurring() registry: each MediaVerse recurring job arms itself on action_scheduler_ensure_recurring_actions.
 * - No delete_rows(): uninstall deletes rows by hook list directly, since Jobs is not loaded there.
 * - adopt_pending() moves pre-2.7.0 rows into GROUP; Jetonomy never used another group.
 *
 * Same job, Jetonomy's name: requeue_after_activation() = flag_recovery(), requeue_unheard() = recover_kept_jobs(),
 * REQUEUE_BATCH = RECOVER_BATCH, STATE_JOB_HOOKS (Free Deactivator, Pro Lifecycle) = KEEP_ON_DEACTIVATE,
 * cancel_pending() = cancel_on_deactivate().
 *
 * Deliberately outside this class, on WP-Cron only: the recurring housekeeping events armed on boot
 * (mvs_prune_logs, mvs_purge_old_views, mvs_process_account_deletions; Pro's boost, story, play-event and
 * replaced-document sweeps) and the preset licence activation retry, which reads DISABLE_WP_CRON to decide whether
 * to retry at all. The job list in docs/standards/background-jobs.md names each one.
 *
 * @package WPMediaVerse
 * @since   2.7.0
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
	 * Groups MediaVerse used before 2.7.0, moved to GROUP on upgrade.
	 */
	public const LEGACY_GROUPS = array( 'wpmediaverse', 'wpmediaverse-pro', 'mvs-pro', 'mvs-connectors' );

	/**
	 * Days a completed or cancelled job is kept by prune_history() (filter: mvs_finished_action_retention_days).
	 *
	 * @since 2.7.0
	 */
	public const FINISHED_RETENTION_DAYS = 7;

	/**
	 * Days a failed job is kept by prune_history(), long enough to read why it failed. The same rule holds for every
	 * plugin in the family.
	 *
	 * @since 2.7.0
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
	 * Seconds one prune_history() or adopt_pending() call may spend; the rest waits for the next run.
	 */
	private const TIME_BUDGET = 10;

	/**
	 * Pending actions pending_args() reads per hook.
	 *
	 * @since 2.7.0
	 */
	public const PENDING_READ_LIMIT = 100;

	/**
	 * Pending rows read per batch when moving them to GROUP.
	 */
	private const ADOPT_BATCH = 500;

	/**
	 * Pending rows one adopt_pending() call moves; the rest goes to a follow-up ADOPT_HOOK job.
	 */
	private const ADOPT_MAX_ROWS = 2000;

	/**
	 * Follow-up job that carries on moving pending rows into GROUP (argument: the hook list).
	 *
	 * @since 2.7.0
	 */
	public const ADOPT_HOOK = 'mvs_adopt_pending_jobs';

	/**
	 * Failed rows requeue_unheard() moves per call.
	 *
	 * @since 2.7.0
	 */
	public const REQUEUE_BATCH = 200;

	/**
	 * Failed rows last attempted longer ago than this are not re-queued: the plugin was off for a long time and the
	 * work may no longer be wanted.
	 *
	 * @since 2.7.0
	 */
	public const REQUEUE_DAYS = 7;

	/**
	 * Whether Action Scheduler may be called: its functions exist from plugins_loaded, its store only from
	 * action_scheduler_init.
	 *
	 * @since 2.7.0
	 *
	 * @return bool
	 */
	public static function has_as(): bool {
		return function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' );
	}

	/**
	 * Run a callback now if Action Scheduler's store is ready or Action Scheduler is not loaded at all (the callback
	 * then queues through WP-Cron), else on action_scheduler_init. Upgrade routines run before the store loads, and
	 * queuing before then fails.
	 *
	 * @since 2.7.0
	 *
	 * @param callable $callback Callback (no arguments).
	 */
	public static function when_ready( callable $callback ): void {
		if ( did_action( 'action_scheduler_init' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			$callback();
		} else {
			add_action( 'action_scheduler_init', static fn() => $callback() );
		}
	}

	/**
	 * Queue a job to run as soon as possible.
	 *
	 * @since 2.7.0
	 *
	 * @param string                   $hook          Action hook.
	 * @param array<int|string, mixed> $args          Hook arguments.
	 * @param bool                     $cron_fallback Use WP-Cron when Action Scheduler is not ready or refuses the job.
	 *                                                False for work the caller would rather finish inline.
	 * @param int                      $cron_delay    Seconds from now for the WP-Cron fallback.
	 * @return bool Whether a scheduler holds the job (accepted here, or a WP-Cron copy with these arguments already
	 *              waits); false means the caller should do the work now.
	 */
	public static function async( string $hook, array $args = array(), bool $cron_fallback = true, int $cron_delay = 0 ): bool {
		// as_enqueue_async_action() answers 0 when the store refuses the job; that is not a hand-off.
		if ( self::has_as() && as_enqueue_async_action( $hook, $args, self::GROUP ) ) {
			return true;
		}
		if ( ! $cron_fallback ) {
			return false;
		}
		$args = array_values( $args );
		if ( wp_next_scheduled( $hook, $args ) ) {
			return true;
		}
		return true === wp_schedule_single_event( time() + $cron_delay, $hook, $args, true );
	}

	/**
	 * Queue a job unless a copy with the same arguments is already waiting to start. A copy that is running now does
	 * not count: it may have read its data before the change that queues this one, so a chain may queue its next pass.
	 * Once Action Scheduler is ready only its own pending copies count: a WP-Cron copy left by an early request is not
	 * a reason to skip the Action Scheduler job.
	 *
	 * @since 2.7.0
	 *
	 * @param string                   $hook Action hook.
	 * @param array<int|string, mixed> $args Hook arguments.
	 * @return bool Whether a copy is waiting now (queued here or already); false means the caller should do the work now.
	 */
	public static function async_once( string $hook, array $args = array() ): bool {
		if ( ! self::has_as() ) {
			return self::is_pending( $hook, $args ) || self::async( $hook, $args );
		}
		// Unique insert first, so two requests cannot both add a copy. Action Scheduler also refuses when a copy is
		// running; that copy may have read stale data, so then a plain copy is added unless one is already pending.
		if ( as_enqueue_async_action( $hook, $args, self::GROUP, true ) ) {
			return true;
		}
		return self::as_pending( $hook, $args ) || (bool) as_enqueue_async_action( $hook, $args, self::GROUP );
	}

	/**
	 * Run a job once at a given time.
	 *
	 * @since 2.7.0
	 *
	 * @param int                      $timestamp Unix time.
	 * @param string                   $hook      Action hook.
	 * @param array<int|string, mixed> $args      Hook arguments.
	 * @param bool                     $unique    Action Scheduler only: refuse when a pending or running copy with
	 *                                            the same arguments exists.
	 * @return bool Whether a scheduler accepted the job.
	 */
	public static function at( int $timestamp, string $hook, array $args = array(), bool $unique = false ): bool {
		if ( self::has_as() ) {
			return (bool) as_schedule_single_action( $timestamp, $hook, $args, self::GROUP, $unique );
		}
		return true === wp_schedule_single_event( $timestamp, $hook, array_values( $args ), true );
	}

	/**
	 * Whether a pending or running copy exists, in either scheduler. Use it to avoid starting the same work twice;
	 * use is_pending() when the question is whether a later run is still coming.
	 *
	 * @since 2.7.0
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
	 * @since 2.7.0
	 *
	 * @param string                        $hook Action hook.
	 * @param array<int|string, mixed>|null $args Exact arguments, or null for any.
	 * @return bool
	 */
	public static function is_pending( string $hook, ?array $args = null ): bool {
		if ( self::has_as() && self::as_pending( $hook, $args ) ) {
			return true;
		}
		return null === $args ? self::cron_has( $hook ) : false !== wp_next_scheduled( $hook, array_values( $args ) );
	}

	/**
	 * Whether Action Scheduler holds a pending copy. Call only when has_as().
	 *
	 * @param string                        $hook Action hook.
	 * @param array<int|string, mixed>|null $args Exact arguments, or null for any.
	 * @return bool
	 */
	private static function as_pending( string $hook, ?array $args ): bool {
		$query = array(
			'hook'     => $hook,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
		);
		if ( null !== $args ) {
			$query['args'] = $args;
		}
		return (bool) as_get_scheduled_actions( $query, 'ids' );
	}

	/**
	 * Next run time of a pending copy with any arguments.
	 *
	 * @since 2.7.0
	 *
	 * @param string $hook Action hook.
	 * @return int Unix time; 0 when none is pending, one is running now, or the soonest is an async copy (use
	 *             is_pending() to ask whether any copy waits).
	 */
	public static function next( string $hook ): int {
		if ( self::has_as() ) {
			$next = as_next_scheduled_action( $hook );
			if ( is_int( $next ) ) {
				return $next;
			}
		}
		return (int) wp_next_scheduled( $hook );
	}

	/**
	 * Make sure exactly one pending recurring row exists for an argument-less hook.
	 *
	 * "Is it scheduled? If not, schedule it" is not atomic, so requests arriving together each scheduled one; Action
	 * Scheduler's $unique flag inserts only when no pending copy exists, and copies an older version left behind are
	 * cancelled here. Only the argument-less row counts: a continuation of the same hook carries a cursor. Runs only
	 * on a scheduling request; the cron runner re-arms a lost row on action_scheduler_ensure_recurring_actions.
	 *
	 * @since 2.7.0
	 *
	 * @param string     $hook     Action hook.
	 * @param int        $first    Unix time of the first run.
	 * @param int|string $schedule Interval in seconds (at least 60), or a cron expression when $cron is true.
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
		as_schedule_recurring_action( $first, max( MINUTE_IN_SECONDS, (int) $schedule ), $hook, array(), self::GROUP, true );
	}

	/**
	 * Whether this request may do scheduling and upkeep checks: cron, WP-CLI, an admin page load (not an admin-ajax
	 * call, which visitors' pages make too), or Action Scheduler's daily action_scheduler_ensure_recurring_actions
	 * run, which its async runner may execute inside an admin-ajax request. Each check costs a query, so a visitor's
	 * request never pays for it. The one rule for the family; DirectDelivery and the migrations use it too.
	 *
	 * @since 2.7.0
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
	 * @since 2.7.0
	 *
	 * Note the asymmetry with is_queued() and is_pending(): there "any arguments" is null, here it is an empty array,
	 * because cancelling only the argument-less copy is never what a caller wants. Jetonomy's Queue reads the same.
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
	 * Cancel every pending Action Scheduler row of exactly these hooks, in any group, set-based (10,000 per UPDATE).
	 * For deactivation: once a plugin is off its listeners are gone, and Action Scheduler (often loaded by another
	 * plugin) would run its rows with no listener and mark them failed ("no callbacks are registered"); cancelling
	 * says so for the jobs whose work comes back by itself. Callers pass their own hook list, never a group: 'mvs'
	 * is shared by Free and Pro. Unlike cancel() it needs no Action Scheduler store and costs a few queries for any
	 * number of rows; it does not touch WP-Cron.
	 *
	 * @since 2.7.0
	 *
	 * @param string[] $hooks Exact hook names.
	 * @return int Rows cancelled.
	 */
	public static function cancel_pending( array $hooks ): int {
		return \WPMediaVerse\Repository\JobRepository::cancel_pending( $hooks );
	}

	/**
	 * After an activation or an update, give back the jobs a runner failed while the plugin was off. On scheduling
	 * requests while the transient is set; each request moves up to REQUEUE_BATCH rows (a few queries each, one
	 * SELECT for the batch), and the transient goes once fewer remain.
	 *
	 * @since 2.7.0
	 *
	 * @param string   $transient Set on activation by the caller.
	 * @param string[] $hooks     The caller's state hooks (jobs that finish an action already committed).
	 */
	public static function requeue_after_activation( string $transient, array $hooks ): void {
		if ( ! self::is_scheduling_request() || ! get_transient( $transient ) ) {
			return;
		}
		self::when_ready(
			static function () use ( $transient, $hooks ): void {
				if ( self::requeue_unheard( $hooks ) < self::REQUEUE_BATCH ) {
					delete_transient( $transient );
				}
			}
		);
	}

	/**
	 * Queue again, with the same arguments, the failed rows of these hooks that failed only because no listener was
	 * registered (the plugin was off), and delete each failed row with its logs. A row that ran and errored is left
	 * failed. One pending copy per hook and arguments (async_once). Only hooks that have a listener now, and only rows
	 * last attempted within REQUEUE_DAYS: older ones stay failed, and prune_history() deletes them in time.
	 *
	 * Safe when several requests read the same rows: each row is claimed first (failed to cancelled, one UPDATE), so
	 * only one request queues its copy; the others count it as handled and move on. A row whose copy no scheduler
	 * takes goes back to failed.
	 *
	 * @since 2.7.0
	 *
	 * @param string[] $hooks Exact hook names.
	 * @return int Rows handled, by this request or a concurrent one; at most REQUEUE_BATCH.
	 */
	public static function requeue_unheard( array $hooks ): int {
		if ( ! self::has_as() ) {
			return 0;
		}
		// Action Scheduler logs the reason in the site's language at failure time: match the English text and the
		// longest fixed part of the current translation. ponytail: a locale changed while the plugin was off misses
		// its rows; they stay failed, as before.
		$needles = array( 'no callbacks are registered' );
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Action Scheduler's own string, to match its log.
		foreach ( explode( '%1$s', __( 'Scheduled action for %1$s will not be executed as no callbacks are registered.', 'action-scheduler' ) ) as $part ) {
			if ( strlen( trim( $part ) ) > strlen( $needles[1] ?? '' ) ) {
				$needles[1] = trim( $part );
			}
		}

		$hooks = array_values( array_filter( $hooks, 'has_action' ) );
		$store = \ActionScheduler::store();
		$moved = 0;
		foreach ( \WPMediaVerse\Repository\JobRepository::unheard_failed( $hooks, $needles, self::REQUEUE_BATCH, self::REQUEUE_DAYS ) as $row ) {
			// ponytail: a request that dies between the claim and the copy leaves the row cancelled (pruned in 7 days).
			if ( ! \WPMediaVerse\Repository\JobRepository::claim_failed( $row['id'] ) ) {
				++$moved; // Another request has it.
				continue;
			}
			if ( ! self::async_once( $row['hook'], $row['args'] ) ) {
				\WPMediaVerse\Repository\JobRepository::release_failed( $row['id'] );
				continue;
			}
			try {
				$store->delete_action( (string) $row['id'] ); // The DB logger deletes the row's logs on this hook.
			} catch ( \InvalidArgumentException $e ) {
				unset( $e ); // Already gone; the copy is queued either way.
			}
			++$moved;
		}
		return $moved;
	}

	/**
	 * Arguments of the pending Action Scheduler copies of a job, oldest first, for progress read-outs.
	 *
	 * @since 2.7.0
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
				'orderby'  => 'date',
				'order'    => 'ASC',
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
	 * Delete MediaVerse's own job history in GROUP (Free and Pro), with the log rows: completed and cancelled actions
	 * older than FINISHED_RETENTION_DAYS and failed actions older than FAILED_RETENTION_DAYS. Action Scheduler's own
	 * cleaner keeps every plugin's history for a month and that site-wide setting is not ours to change, so we trim
	 * only our own rows. Pending and running rows are never touched. Runs on the existing daily mvs_prune_logs event,
	 * for at most TIME_BUDGET seconds and PRUNE_MAX_BATCHES batches per status; the rest goes on the next run.
	 *
	 * @since 2.7.0
	 *
	 * @return int Rows deleted.
	 */
	public static function prune_history(): int {
		if ( ! self::has_as() ) {
			return 0;
		}

		/**
		 * Days a completed or cancelled MediaVerse job is kept before MediaVerse deletes it with its log rows. Only
		 * the 'mvs' group is touched; pending and running jobs are never deleted, failed ones after 90 days.
		 *
		 * @since 2.7.0
		 *
		 * @param int $days Whole days, at least 1. Default 7.
		 */
		$days = max( 1, (int) apply_filters( 'mvs_finished_action_retention_days', self::FINISHED_RETENTION_DAYS ) );
		/**
		 * Days a failed MediaVerse job is kept, from its last attempt, so the owner can read why it failed.
		 *
		 * @since 2.7.0
		 *
		 * @param int $days Whole days, at least 1. Default 90.
		 */
		$failed_days = max( 1, (int) apply_filters( 'mvs_failed_action_retention_days', self::FAILED_RETENTION_DAYS ) );
		$deadline    = microtime( true ) + self::TIME_BUDGET;
		// A finished row is aged by its last attempt (when it finished); a cancelled one never ran, so by its date.
		return self::delete_finished( \ActionScheduler_Store::STATUS_COMPLETE, 'modified', $days, $deadline )
			+ self::delete_finished( \ActionScheduler_Store::STATUS_CANCELED, 'date', $days, $deadline )
			+ self::delete_finished( \ActionScheduler_Store::STATUS_FAILED, 'modified', $failed_days, $deadline );
	}

	/**
	 * Delete GROUP's actions of one finished status older than $days, in batches, until $deadline.
	 *
	 * @param string $status   Action Scheduler status.
	 * @param string $by       'modified' (last attempt) or 'date' (scheduled date).
	 * @param int    $days     Age in days.
	 * @param float  $deadline microtime() after which no further row is deleted.
	 * @return int Rows deleted.
	 */
	private static function delete_finished( string $status, string $by, int $days, float $deadline ): int {
		$before  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$store   = \ActionScheduler::store();
		$deleted = 0;

		for ( $batch = 0; $batch < self::PRUNE_MAX_BATCHES && microtime( true ) < $deadline; $batch++ ) {
			$ids = (array) as_get_scheduled_actions(
				array(
					'group'        => self::GROUP,
					'status'       => $status,
					$by            => $before,
					$by . '_compare' => '<',
					'per_page'     => self::PRUNE_BATCH,
					'orderby'      => 'none',
				),
				'ids'
			);
			foreach ( $ids as $id ) {
				if ( microtime( true ) >= $deadline ) {
					return $deleted;
				}
				try {
					$store->delete_action( (string) $id ); // The DB logger deletes the action's log rows on this hook.
					++$deleted;
				} catch ( \InvalidArgumentException $e ) {
					unset( $e ); // Another request deleted it first.
				}
			}
			if ( count( $ids ) < self::PRUNE_BATCH ) {
				break;
			}
		}
		return $deleted;
	}

	/**
	 * Move the pending actions of the given hooks out of the pre-2.7.0 groups into GROUP, keeping each one's
	 * schedule, arguments and priority. Run from the upgrade routine. Each plugin passes only its own hooks, so a
	 * newer Free never moves rows an older Pro still looks up by group.
	 *
	 * Moves at most ADOPT_MAX_ROWS rows for at most TIME_BUDGET seconds; when rows remain it queues an ADOPT_HOOK job
	 * with the same hook list to carry on. Only rows no runner has claimed are moved, and the old row is cancelled
	 * before its copy is saved, so a runner that claims it in between finds it cancelled and skips it.
	 *
	 * @since 2.7.0
	 *
	 * @param string[] $hooks Action hooks.
	 * @return int Actions moved.
	 */
	public static function adopt_pending( array $hooks ): int {
		if ( ! self::has_as() ) {
			return 0;
		}
		// The upgrade can run on a visitor's request; the move then runs as its own job instead.
		if ( ! self::is_scheduling_request() && ! doing_action( self::ADOPT_HOOK ) ) {
			self::async_once( self::ADOPT_HOOK, array( array_values( $hooks ) ) );
			return 0;
		}
		$store    = \ActionScheduler::store();
		$moved    = 0;
		$deadline = microtime( true ) + self::TIME_BUDGET;
		foreach ( $hooks as $hook ) {
			foreach ( self::LEGACY_GROUPS as $group ) {
				do {
					if ( $moved >= self::ADOPT_MAX_ROWS || microtime( true ) >= $deadline ) {
						self::async( self::ADOPT_HOOK, array( array_values( $hooks ) ) );
						return $moved;
					}
					$actions = (array) as_get_scheduled_actions(
						array(
							'hook'     => (string) $hook,
							'group'    => $group,
							'status'   => \ActionScheduler_Store::STATUS_PENDING,
							'claimed'  => false,
							'per_page' => min( self::ADOPT_BATCH, self::ADOPT_MAX_ROWS - $moved ),
						)
					);
					$fetched = count( $actions );
					foreach ( $actions as $id => $action ) {
						// ponytail: a runner that claims and starts the row between the query and this cancel still
						// runs it once more as the copy; the window is one query wide.
						$store->cancel_action( (int) $id );
						$copy = new \ActionScheduler_Action( $action->get_hook(), $action->get_args(), $action->get_schedule(), self::GROUP );
						$copy->set_priority( $action->get_priority() );
						$store->save_action( $copy );
						$store->delete_action( (int) $id );
						++$moved;
					}
				} while ( self::ADOPT_BATCH === $fetched );
			}
		}
		return $moved;
	}
}
