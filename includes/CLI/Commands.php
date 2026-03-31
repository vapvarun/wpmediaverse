<?php
/**
 * WP-CLI commands for WPMediaVerse.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\CLI;

defined( 'ABSPATH' ) || exit;

use WP_CLI;
use WP_CLI\Utils;
use WPMediaVerse\Services\MediaMeta;

/**
 * Manage WPMediaVerse media, stats, and maintenance tasks.
 */
class Commands {

	/**
	 * Show plugin statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs stats
	 *
	 * @subcommand stats
	 */
	public function stats( $args, $assoc_args ) {
		global $wpdb;

		$media_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$album_count = (int) wp_count_posts( 'mvs_album' )->publish;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, no user input.
		$total_views     = (int) $wpdb->get_var( "SELECT COALESCE(SUM(views), 0) FROM {$wpdb->prefix}mvs_media_stats" );
		$total_reactions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_reactions" );
		$total_favorites = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_favorites" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = array(
			array(
				'Metric' => 'Published Media',
				'Value'  => $media_count,
			),
			array(
				'Metric' => 'Albums',
				'Value'  => $album_count,
			),
			array(
				'Metric' => 'Total Views',
				'Value'  => $total_views,
			),
			array(
				'Metric' => 'Total Reactions',
				'Value'  => $total_reactions,
			),
			array(
				'Metric' => 'Total Favorites',
				'Value'  => $total_favorites,
			),
			array(
				'Metric' => 'DB Version',
				'Value'  => get_option( 'mvs_db_version', '0' ),
			),
			array(
				'Metric' => 'Plugin Version',
				'Value'  => MVS_VERSION,
			),
		);

		Utils\format_items( 'table', $items, array( 'Metric', 'Value' ) );
	}

	/**
	 * Run or check database migrations.
	 *
	 * ## OPTIONS
	 *
	 * [--check]
	 * : Only check if migrations are needed, don't run them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs migrate
	 *     wp mvs migrate --check
	 *
	 * @subcommand migrate
	 */
	public function migrate( $args, $assoc_args ) {
		$current = (int) get_option( 'mvs_db_version', 0 );
		$target  = \WPMediaVerse\Core\Migrator::CURRENT_VERSION;

		if ( Utils\get_flag_value( $assoc_args, 'check', false ) ) {
			if ( $current >= $target ) {
				WP_CLI::success( "Database is up to date (version {$current})." );
			} else {
				WP_CLI::warning( "Database needs migration: v{$current} -> v{$target}." );
			}
			return;
		}

		if ( $current >= $target ) {
			WP_CLI::success( "Already at version {$target}. Nothing to do." );
			return;
		}

		WP_CLI::log( "Running migrations from v{$current} to v{$target}..." );
		$migrator = new \WPMediaVerse\Core\Migrator();
		$migrator->run();
		WP_CLI::success( "Database migrated to version {$target}." );
	}

	/**
	 * Prune old view records.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Number of days to retain. Default 90.
	 *
	 * [--dry-run]
	 * : Show how many rows would be deleted without deleting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs prune-views
	 *     wp mvs prune-views --days=30
	 *     wp mvs prune-views --dry-run
	 *
	 * @subcommand prune-views
	 */
	public function prune_views( $args, $assoc_args ) {
		$days    = (int) Utils\get_flag_value( $assoc_args, 'days', 90 );
		$dry_run = Utils\get_flag_value( $assoc_args, 'dry-run', false );

		if ( $dry_run ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_views WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
					$days
				)
			);
			WP_CLI::log( "Would delete {$count} view records older than {$days} days." );
			return;
		}

		$stats_service = \WPMediaVerse\Core\Plugin::container()->get( 'stats' );
		$deleted       = $stats_service->prune_views( $days );
		WP_CLI::success( "Pruned {$deleted} view records older than {$days} days." );
	}

	/**
	 * Cleanup expired access grants.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<size>]
	 * : Number of grants to process per batch. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs cleanup-expired
	 *     wp mvs cleanup-expired --batch-size=500
	 *
	 * @subcommand cleanup-expired
	 */
	public function cleanup_expired( $args, $assoc_args ) {
		$batch_size   = (int) Utils\get_flag_value( $assoc_args, 'batch-size', 100 );
		$access_rules = \WPMediaVerse\Core\Plugin::container()->get( 'access_rules' );
		$cleaned      = $access_rules->cleanup_expired( $batch_size );
		WP_CLI::success( "Cleaned up {$cleaned} expired access grants." );
	}

	/**
	 * Ensure every mvs_media_index row has a corresponding mvs_media_stats row.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<size>]
	 * : Number of rows to process per batch. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs reindex
	 *     wp mvs reindex --batch-size=50
	 *
	 * @subcommand reindex
	 */
	public function reindex( $args, $assoc_args ) {
		global $wpdb;

		$batch_size  = (int) Utils\get_flag_value( $assoc_args, 'batch-size', 100 );
		$last_id     = 0;
		$total       = 0;
		$stats_added = 0;

		WP_CLI::log( 'Verifying mvs_media_index and ensuring stats rows...' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE media_id > %d ORDER BY media_id ASC LIMIT %d",
					$last_id,
					$batch_size
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$media_id = (int) $row->media_id;
				$last_id  = $media_id;

				// Ensure stats row exists.
				$has_stats = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT media_id FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
						$media_id
					)
				);

				if ( ! $has_stats ) {
					$wpdb->insert(
						$wpdb->prefix . 'mvs_media_stats',
						array(
							'media_id'   => $media_id,
							'views'      => 0,
							'downloads'  => 0,
							'reactions'  => 0,
							'comments'   => 0,
							'shares'     => 0,
							'updated_at' => current_time( 'mysql', true ),
						),
						array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
					);
					++$stats_added;
				}

				++$total;
			}

			WP_CLI::log( "Processed {$total} media items..." );
			$row_count = count( $rows );
		} while ( $row_count === $batch_size );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		WP_CLI::success( "Reindex complete. {$total} media items checked, {$stats_added} stats rows created." );
	}

	/**
	 * Flush all WPMediaVerse caches.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs cache-flush
	 *
	 * @subcommand cache-flush
	 */
	public function cache_flush( $args, $assoc_args ) {
		$cache = \WPMediaVerse\Core\Plugin::container()->get( 'cache' );
		$cache->flush_all();
		WP_CLI::success( 'All WPMediaVerse caches flushed.' );
	}

	/**
	 * Backfill thumbnails into BuddyPress activity entries that have empty content.
	 *
	 * After migrating from rtMedia/MediaPress/BuddyBoss, imported media creates
	 * activity entries with empty content (no thumbnail). This command populates
	 * the activity content with the MVS media thumbnail so activities display
	 * images/videos inline.
	 *
	 * WARNING: This modifies the bp_activity table directly. Take a database
	 * backup before running without --dry-run.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be updated without writing to the database.
	 *
	 * [--source=<source>]
	 * : Only backfill activities from a specific migration source: rtmedia, mediapress, buddyboss, or all.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - rtmedia
	 *   - mediapress
	 *   - buddyboss
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs backfill-activity-thumbnails --dry-run
	 *     wp mvs backfill-activity-thumbnails
	 *     wp mvs backfill-activity-thumbnails --source=mediapress
	 *
	 * @subcommand backfill-activity-thumbnails
	 * @when after_wp_load
	 */
	public function backfill_activity_thumbnails( $args, $assoc_args ) {
		if ( ! function_exists( 'bp_activity_get' ) || ! bp_is_active( 'activity' ) ) {
			WP_CLI::error( 'BuddyPress activity component is not active.' );
		}

		global $wpdb;

		$dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$source  = Utils\get_flag_value( $assoc_args, 'source', 'all' );

		// Find mvs_media_upload activities with empty content.
		$activities = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT id, item_id, secondary_item_id, component
			 FROM {$wpdb->prefix}bp_activity
			 WHERE type = 'mvs_media_upload'
			   AND (content = '' OR content IS NULL)
			 ORDER BY id ASC"
		);

		$total   = count( $activities );
		$updated = 0;
		$skipped = 0;
		$errors  = 0;

		if ( 0 === $total ) {
			WP_CLI::success( 'No activities with empty content found. Nothing to backfill.' );
			return;
		}

		WP_CLI::log( "Found {$total} activity entries with empty content." );

		if ( ! $dry_run ) {
			WP_CLI::warning( 'This will modify the bp_activity table. Make sure you have a database backup.' );
			WP_CLI::confirm( 'Proceed with backfill?' );
		}

		$container = \WPMediaVerse\Core\Plugin::container();
		$bp_int    = $container->get( 'integration.buddypress' );

		$progress = Utils\make_progress_bar( 'Backfilling thumbnails', $total );

		foreach ( $activities as $act ) {
			$media_id = 0;
			if ( 'wpmediaverse' === $act->component && $act->item_id > 0 ) {
				$media_id = (int) $act->item_id;
			} elseif ( 'groups' === $act->component && $act->secondary_item_id > 0 ) {
				$media_id = (int) $act->secondary_item_id;
			}

			if ( ! $media_id || ! MediaMeta::exists( $media_id ) ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			// Filter by source if specified.
			if ( 'all' !== $source ) {
				$meta_keys = array(
					'rtmedia'    => 'rtmedia_id',
					'mediapress' => 'mpp_id',
					'buddyboss'  => 'bb_media_id',
				);
				$check_key = $meta_keys[ $source ] ?? '';
				if ( $check_key && ! MediaMeta::get( (int) $media_id, $check_key ) ) {
					++$skipped;
					$progress->tick();
					continue;
				}
			}

			$thumbnail = $bp_int->get_media_thumbnail_html( $media_id, 'large' );

			if ( ! $thumbnail ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			if ( $dry_run ) {
				++$updated;
				$progress->tick();
				continue;
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'bp_activity',
				array( 'content' => $thumbnail ),
				array( 'id' => (int) $act->id ),
				array( '%s' ),
				array( '%d' )
			);
			++$updated;
			$progress->tick();
		}

		$progress->finish();

		$action = $dry_run ? 'Would update' : 'Updated';
		WP_CLI::success(
			sprintf(
				'%s %d activity entries. Skipped %d. Errors: %d.',
				$action,
				$updated,
				$skipped,
				$errors
			)
		);
	}

	/**
	 * Show moderation queue statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs moderation-stats
	 *
	 * @subcommand moderation-stats
	 */
	public function moderation_stats( $args, $assoc_args ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no user input.
		$rows = $wpdb->get_results( "SELECT moderation_status, COUNT(*) as cnt FROM {$wpdb->prefix}mvs_media_index GROUP BY moderation_status", ARRAY_A );

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No media found in the index.' );
			return;
		}

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'Status' => $row['moderation_status'],
				'Count'  => (int) $row['cnt'],
			);
		}

		Utils\format_items( 'table', $items, array( 'Status', 'Count' ) );
	}
}
