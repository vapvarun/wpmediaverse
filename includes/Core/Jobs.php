<?php
/**
 * The one place MediaVerse Free queues background work.
 *
 * Action Scheduler (bundled in libs/action-scheduler) when its store is ready, WP-Cron before that, for example
 * when an upgrade routine queues work early in a request. Every Free class that queues, checks or cancels a job goes
 * through here, so the group, the "is the store ready" rule and the clean-up of our own finished rows live once.
 * Rules and the job list: docs/standards/background-jobs.md.
 *
 * Lookups (is_queued, cancel) match by hook and arguments in any group: every MediaVerse hook name starts
 * with mvs_ and is ours alone, and a pending row queued by a version before 2.6.5 sits in an older group.
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
	 * Days a finished job is kept by prune_history() (filter: mvs_finished_action_retention_days).
	 */
	public const FINISHED_RETENTION_DAYS = 7;

	/**
	 * Finished jobs deleted per batch.
	 */
	private const PRUNE_BATCH = 200;

	/**
	 * Batches per prune_history() call: at most 10,000 rows a day; anything left goes on the next run.
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
	 * @return bool
	 */
	public static function has_as(): bool {
		return function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' );
	}

	/**
	 * Run a callback now if Action Scheduler's store is ready, else on action_scheduler_init. Upgrade routines run
	 * before the store loads, and queuing before then fails.
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
	 * @param string                   $hook Action hook.
	 * @param array<int|string, mixed> $args Hook arguments.
	 * @return bool Whether a scheduler accepted the job; false means the caller should do the work now.
	 */
	public static function async( string $hook, array $args = array() ): bool {
		if ( self::has_as() ) {
			return (bool) as_enqueue_async_action( $hook, $args, self::GROUP );
		}
		return true === wp_schedule_single_event( time(), $hook, $args, true );
	}

	/**
	 * Run a job once at a given time.
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
		return true === wp_schedule_single_event( $timestamp, $hook, $args, true );
	}

	/**
	 * Whether a pending or running copy exists, in either scheduler.
	 *
	 * @param string                        $hook Action hook.
	 * @param array<int|string, mixed>|null $args Exact arguments, or null for any.
	 * @return bool
	 */
	public static function is_queued( string $hook, ?array $args = null ): bool {
		if ( self::has_as() && as_has_scheduled_action( $hook, $args ) ) {
			return true;
		}
		return null === $args ? self::cron_has( $hook ) : false !== wp_next_scheduled( $hook, $args );
	}

	/**
	 * Cancel pending copies of a job in both schedulers.
	 *
	 * @param string                   $hook Action hook.
	 * @param array<int|string, mixed> $args Exact arguments; empty cancels every copy whatever its arguments.
	 */
	public static function cancel( string $hook, array $args = array() ): void {
		if ( self::has_as() ) {
			as_unschedule_all_actions( $hook, $args );
		}
		if ( $args ) {
			wp_clear_scheduled_hook( $hook, $args );
		} else {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Arguments of the pending Action Scheduler copies of a job, oldest first, for progress read-outs.
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
	 * Delete MediaVerse's finished job history: completed actions in GROUP (Free and Pro) older than
	 * FINISHED_RETENTION_DAYS, with their log rows. Action Scheduler's own cleaner keeps every plugin's history for
	 * a month and that site-wide setting is not ours to change, so we trim only our own rows. Failed, pending and
	 * running rows are never touched. Runs on the existing daily mvs_prune_logs event, not a job of its own.
	 */
	public static function prune_history(): void {
		if ( ! self::has_as() ) {
			return;
		}

		/**
		 * Days a finished (completed) MediaVerse job is kept before MediaVerse deletes it with its log rows. Only the
		 * 'mvs' group is touched; failed and pending jobs are never deleted.
		 *
		 * @since 2.6.5
		 *
		 * @param int $days Whole days, at least 1. Default 7.
		 */
		$days   = max( 1, (int) apply_filters( 'mvs_finished_action_retention_days', self::FINISHED_RETENTION_DAYS ) );
		$before = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$store  = \ActionScheduler::store();

		for ( $batch = 0; $batch < self::PRUNE_MAX_BATCHES; $batch++ ) {
			$ids = (array) as_get_scheduled_actions(
				array(
					'group'        => self::GROUP,
					'status'       => \ActionScheduler_Store::STATUS_COMPLETE,
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
