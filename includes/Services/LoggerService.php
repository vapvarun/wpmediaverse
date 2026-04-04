<?php
/**
 * Centralized logging service.
 *
 * Provides structured logging with levels (error, warning, info),
 * stored in a custom DB table with auto-pruning.
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

class LoggerService {

	const LEVEL_ERROR   = 'error';
	const LEVEL_WARNING = 'warning';
	const LEVEL_INFO    = 'info';

	const PRUNE_DAYS = 30;

	/**
	 * Register action hooks for automatic logging of key operations.
	 */
	public static function register_hooks(): void {
		// Media upload success.
		add_action(
			'mvs_media_uploaded',
			function ( int $media_id, array $file_data ): void {
				self::info(
					'upload',
					sprintf( 'Media uploaded: %s (%s)', $file_data['media_type'] ?? '', size_format( $file_data['file_size'] ?? 0 ) ),
					array(
						'media_id'   => $media_id,
						'file_type'  => $file_data['file_type'] ?? '',
						'file_size'  => $file_data['file_size'] ?? 0,
						'media_type' => $file_data['media_type'] ?? '',
					)
				);
			},
			10,
			2
		);

		// AI moderation flag.
		add_action(
			'mvs_media_flagged',
			function ( int $media_id, array $result ): void {
				self::warning(
					'moderation',
					'AI flagged media content',
					array(
						'media_id' => $media_id,
						'flags'    => $result['flags'] ?? array(),
						'provider' => $result['provider'] ?? '',
					)
				);
			},
			10,
			2
		);

		// Moderation status change (approve/reject).
		add_action(
			'mvs_moderation_changed',
			function ( int $media_id, string $status, string $old_status, int $user_id ): void {
				self::info(
					'moderation',
					sprintf( 'Media moderation: %s → %s', $old_status, $status ),
					array(
						'media_id'     => $media_id,
						'new_status'   => $status,
						'old_status'   => $old_status,
						'moderator_id' => $user_id,
					)
				);
			},
			10,
			4
		);

		// User report submitted.
		add_action(
			'mvs_report_submitted',
			function ( int $report_id, int $reporter_id, string $target_type, int $target_id, string $reason ): void {
				self::info(
					'report',
					sprintf( 'Report submitted: %s #%d — %s', $target_type, $target_id, $reason ),
					array(
						'report_id'   => $report_id,
						'reporter_id' => $reporter_id,
						'target_type' => $target_type,
						'target_id'   => $target_id,
						'reason'      => $reason,
					)
				);
			},
			10,
			5
		);

		// User blocked.
		add_action(
			'mvs_user_blocked',
			function ( int $blocker_id, int $blocked_id ): void {
				self::info(
					'user',
					sprintf( 'User #%d blocked user #%d', $blocker_id, $blocked_id ),
					array(
						'blocker_id' => $blocker_id,
						'blocked_id' => $blocked_id,
					)
				);
			},
			10,
			2
		);
	}

	/**
	 * Log an error.
	 *
	 * @param string $context  Context (e.g. 'storage', 'ai', 'webhook').
	 * @param string $message  Log message.
	 * @param array  $metadata Optional metadata.
	 */
	public static function error( string $context, string $message, array $metadata = array() ): void {
		self::log( self::LEVEL_ERROR, $context, $message, $metadata );
	}

	/**
	 * Log a warning.
	 *
	 * @param string $context  Context.
	 * @param string $message  Log message.
	 * @param array  $metadata Optional metadata.
	 */
	public static function warning( string $context, string $message, array $metadata = array() ): void {
		self::log( self::LEVEL_WARNING, $context, $message, $metadata );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $context  Context.
	 * @param string $message  Log message.
	 * @param array  $metadata Optional metadata.
	 */
	public static function info( string $context, string $message, array $metadata = array() ): void {
		self::log( self::LEVEL_INFO, $context, $message, $metadata );
	}

	/**
	 * Write a log entry to the database.
	 *
	 * @param string $level    Log level.
	 * @param string $context  Context category.
	 * @param string $message  Log message.
	 * @param array  $metadata Additional data.
	 */
	private static function log( string $level, string $context, string $message, array $metadata ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mvs_error_log';

		// Check table exists (avoids errors during activation).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'level'      => $level,
				'context'    => substr( $context, 0, 50 ),
				'message'    => substr( $message, 0, 1000 ),
				'metadata'   => ! empty( $metadata ) ? wp_json_encode( $metadata ) : null,
				'user_id'    => get_current_user_id(),
				'ip_address' => self::get_ip(),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Get log entries with filtering and pagination.
	 *
	 * @param array $args Query arguments.
	 * @return array{items: array, total: int}
	 */
	public static function get_logs( array $args = array() ): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'mvs_error_log';
		$defaults = array(
			'level'    => '',
			'context'  => '',
			'per_page' => 50,
			'page'     => 1,
		);

		$args   = wp_parse_args( $args, $defaults );
		$where  = array( '1=1' );
		$params = array();

		if ( $args['level'] ) {
			$where[]  = 'level = %s';
			$params[] = $args['level'];
		}

		if ( $args['context'] ) {
			$where[]  = 'context = %s';
			$params[] = $args['context'];
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $args['page'] - 1 ) * $args['per_page'];

		if ( $params ) {
			$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...$params
				)
			);

			$params[] = $args['per_page'];
			$params[] = $offset;

			$items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...$params
				)
			);
		} else {
			$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT COUNT(*) FROM {$table}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			$items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$args['per_page'],
					$offset
				)
			);
		}

		return array(
			'items' => $items ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Prune old log entries (>30 days).
	 */
	public static function prune(): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'mvs_error_log';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::PRUNE_DAYS * DAY_IN_SECONDS ) );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);
	}

	/**
	 * Get distinct context values for filter dropdowns.
	 *
	 * @return string[]
	 */
	public static function get_contexts(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'mvs_error_log';

		return $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT DISTINCT context FROM {$table} ORDER BY context ASC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private static function get_ip(): string {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '';
	}
}
