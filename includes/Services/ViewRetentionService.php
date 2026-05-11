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
	 * older than `created_at < NOW() - INTERVAL N DAY` from
	 * `mvs_media_views`. Aggregates in `mvs_media_stats` are unaffected —
	 * only the raw event log is trimmed.
	 *
	 * Idempotent: deleting zero rows is a no-op. Errors swallowed (the
	 * next daily run will retry).
	 */
	public static function purge(): void {
		$days = self::resolve_retention_days();
		if ( $days <= 0 ) {
			return; // 0 = retain forever.
		}

		global $wpdb;

		$prev = $wpdb->show_errors( false );

		// Bounded delete. DELETE on an indexed column (created_at index
		// exists on mvs_media_views) is fast even at millions of rows;
		// LIMIT prevents the cron from holding the table hostage if
		// retention was just dropped from 365 → 30 (large backfill window).
		$sql = $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}mvs_media_views
			 WHERE created_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )
			 LIMIT 50000",
			$days
		);

		$total_deleted = 0;
		// Loop in 50k batches up to a safety cap so a 10M-row backlog
		// drains over multiple days without blocking other cron jobs.
		for ( $i = 0; $i < 20; $i++ ) {
			$rows = (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $rows <= 0 ) {
				break;
			}
			$total_deleted += $rows;
		}

		$wpdb->show_errors( $prev );

		if ( $total_deleted > 0 && function_exists( '\\WPMediaVerse\\Services\\LoggerService::info' ) ) {
			LoggerService::info(
				'view_retention',
				'Purged old view rows.',
				array(
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
