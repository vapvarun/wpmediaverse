<?php
/**
 * View-retention cron — bounded growth on mvs_media_views.
 *
 * Every media view inserts a row into `mvs_media_views`. Aggregates
 * (counts per media + per user) live in `mvs_media_stats`, which is the
 * source of truth for displayed view counts. The raw rows in
 * `mvs_media_views` only matter for short-window analytics ("who viewed
 * this in the last X days"), so we cap retention to keep the table
 * bounded as traffic grows.
 *
 * @since 1.2.1
 *
 * @package WPMediaVerse\Services
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Daily cron that drops view rows older than the retention window.
 */
class ViewRetentionService {

	/**
	 * Setting key. Days; 0 = unlimited; default 90.
	 */
	public const SETTING = 'mvs_view_retention_days';

	/**
	 * Default retention in days when the option is unset.
	 */
	public const DEFAULT_DAYS = 90;

	/**
	 * Hard upper bound to prevent a misconfigured site setting an absurd value.
	 */
	public const MAX_DAYS = 730;

	/**
	 * Daily cron callback. Reads `mvs_view_retention_days`; deletes rows
	 * older than `created_at < UTC_TIMESTAMP() - INTERVAL N DAY` from
	 * `mvs_media_views`. Aggregates in `mvs_media_stats` are unaffected —
	 * only the raw event log is trimmed.
	 *
	 * Idempotent: deleting zero rows is a no-op. Errors swallowed (the
	 * next daily run will retry).
	 */
	public static function purge(): void {
		self::purge_table( 'mvs_media_views', self::resolve_retention_days(), 'view_retention' );
	}

	/**
	 * Same daily job: trim `mvs_activity`, the feed the app reads
	 * (`/mvs/v1/feed`). Nothing ever removed its rows, so it grew with every
	 * upload, reaction, comment and follow on the site (2.6.0). A feed older
	 * than the window is not read; 0 keeps it forever.
	 *
	 * @since 2.6.0
	 */
	public static function purge_activity(): void {
		/**
		 * Days of activity feed kept. 0 = keep forever.
		 *
		 * @since 2.6.0
		 *
		 * @param int $days Default 90, like view retention.
		 */
		$days = min( self::MAX_DAYS, max( 0, (int) apply_filters( 'mvs_activity_retention_days', self::DEFAULT_DAYS ) ) );
		self::purge_table( 'mvs_activity', $days, 'activity_retention' );
	}

	/**
	 * Delete rows older than $days from one of our tables (created_at is
	 * indexed on both), in bounded batches.
	 *
	 * @param string $table  Table name without prefix.
	 * @param int    $days   Retention in days; 0 or less = keep forever.
	 * @param string $source Log source.
	 */
	private static function purge_table( string $table, int $days, string $source ): void {
		if ( $days <= 0 ) {
			return; // 0 = retain forever.
		}

		global $wpdb;

		$prev = $wpdb->show_errors( false );

		// LIMIT prevents the cron from holding the table hostage if retention
		// was just dropped from 365 → 30 (large backfill window).
		$sql = $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}{$table}
			 WHERE created_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )
			 LIMIT 50000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is code-controlled.
			$days
		);

		$total_deleted = 0;
		// Loop in 50k batches up to a safety cap so a 10M-row backlog
		// drains over multiple days without blocking other cron jobs.
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- $sql is built by $wpdb->prepare() above; re-preparing inside the loop wastes cycles.
			$rows = (int) $wpdb->query( $sql );
			if ( $rows <= 0 ) {
				break;
			}
			$total_deleted += $rows;
		}

		$wpdb->show_errors( $prev );

		if ( $total_deleted > 0 ) {
			LoggerService::info(
				$source,
				'Purged old rows.',
				array(
					'table'          => $table,
					'deleted_rows'   => $total_deleted,
					'retention_days' => $days,
				)
			);
		}
	}

	/**
	 * Resolve the retention window in days, clamped to [0, MAX_DAYS].
	 *
	 * Returns 0 to mean "retain forever". Negative or non-numeric values
	 * fall back to the default.
	 */
	public static function resolve_retention_days(): int {
		$raw = get_option( self::SETTING, self::DEFAULT_DAYS );

		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
		}
		if ( ! is_numeric( $raw ) ) {
			return self::DEFAULT_DAYS;
		}

		$days = (int) $raw;
		if ( $days < 0 ) {
			return self::DEFAULT_DAYS;
		}
		if ( $days > self::MAX_DAYS ) {
			return self::MAX_DAYS;
		}

		return $days;
	}

	/**
	 * Sanitize callback for the Settings API. Same clamping as
	 * `resolve_retention_days` plus a string-to-int coercion.
	 */
	public static function sanitize_setting( $value ): int {
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_DAYS;
		}
		$days = (int) $value;
		if ( $days < 0 ) {
			return 0;
		}
		if ( $days > self::MAX_DAYS ) {
			return self::MAX_DAYS;
		}
		return $days;
	}
}
