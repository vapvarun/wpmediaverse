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

		$media_count = (int) wp_count_posts( 'mvs_media' )->publish;
		$album_count = (int) wp_count_posts( 'mvs_album' )->publish;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total_views = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(views), 0) FROM {$wpdb->prefix}mvs_media_stats" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total_reactions = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_reactions" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total_favorites = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_favorites" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$items = array(
			array( 'Metric' => 'Published Media', 'Value' => $media_count ),
			array( 'Metric' => 'Albums', 'Value' => $album_count ),
			array( 'Metric' => 'Total Views', 'Value' => $total_views ),
			array( 'Metric' => 'Total Reactions', 'Value' => $total_reactions ),
			array( 'Metric' => 'Total Favorites', 'Value' => $total_favorites ),
			array( 'Metric' => 'DB Version', 'Value' => get_option( 'mvs_db_version', '0' ) ),
			array( 'Metric' => 'Plugin Version', 'Value' => MVS_VERSION ),
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
	 * Rebuild the mvs_media_index table from published media posts.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<size>]
	 * : Number of posts to process per batch. Default 100.
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

		$batch_size = (int) Utils\get_flag_value( $assoc_args, 'batch-size', 100 );
		$offset     = 0;
		$total      = 0;

		WP_CLI::log( 'Rebuilding mvs_media_index...' );

		do {
			$post_ids = get_posts(
				array(
					'post_type'      => 'mvs_media',
					'post_status'    => 'publish',
					'posts_per_page' => $batch_size,
					'offset'         => $offset,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			if ( empty( $post_ids ) ) {
				break;
			}

			foreach ( $post_ids as $mvs_post_id ) {
				$mvs_post     = get_post( $mvs_post_id );
				$privacy_meta = get_post_meta( $mvs_post_id, '_mvs_privacy', true );
				$privacy_val  = $privacy_meta ? $privacy_meta : 'public';
				$type_meta    = get_post_meta( $mvs_post_id, '_mvs_media_type', true );
				$media_type   = $type_meta ? $type_meta : '';
				$created      = $mvs_post->post_date_gmt ? $mvs_post->post_date_gmt : current_time( 'mysql', true );

				$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prefix . 'mvs_media_index',
					array(
						'media_id'          => $mvs_post_id,
						'post_author'       => $mvs_post->post_author,
						'media_type'        => $media_type,
						'privacy'           => $privacy_val,
						'moderation_status' => 'approved',
						'created_at'        => $created,
					),
					array( '%d', '%d', '%s', '%s', '%s', '%s' )
				);

				// Also ensure stats row.
				$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prefix . 'mvs_media_stats',
					array(
						'media_id'   => $mvs_post_id,
						'views'      => 0,
						'downloads'  => 0,
						'reactions'  => 0,
						'comments'   => 0,
						'shares'     => 0,
						'updated_at' => current_time( 'mysql', true ),
					),
					array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
				);

				++$total;
			}

			$offset += $batch_size;

			WP_CLI::log( "Processed {$total} media items..." );

		} while ( count( $post_ids ) === $batch_size );

		WP_CLI::success( "Reindex complete. {$total} media items indexed." );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT moderation_status, COUNT(*) as cnt FROM {$wpdb->prefix}mvs_media_index GROUP BY moderation_status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

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
