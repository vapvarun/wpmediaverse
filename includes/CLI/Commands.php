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
		// Single source of truth — see AdminAggregatesService. The cache
		// layer there guarantees `wp mvs stats` from a cron poll doesn't
		// trigger fresh SUM scans on every invocation.
		$aggregates      = \WPMediaVerse\Core\Plugin::container()->get( 'admin_aggregates' );
		$media_count     = $aggregates->total_media();
		$album_count     = $aggregates->total_albums();
		$total_views     = $aggregates->total_views();
		$total_reactions = $aggregates->total_reactions();
		$total_favorites = $aggregates->total_favorites();

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

			if ( ! $media_id || ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
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
				if ( $check_key && ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( (int) $media_id, $check_key ) ) {
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
	 * Batch generate video thumbnails using ffmpeg.
	 *
	 * Queries all video media from mvs_media_index and generates a thumbnail
	 * frame at 1 second using ffmpeg. Saves the thumbnail to the uploads
	 * directory and stores the URL in mvs_media_meta.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Regenerate thumbnails even if they already exist.
	 *
	 * [--dry-run]
	 * : List what would be processed without generating anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs generate-video-thumbnails
	 *     wp mvs generate-video-thumbnails --force
	 *     wp mvs generate-video-thumbnails --dry-run
	 *
	 * @subcommand generate-video-thumbnails
	 */
	public function generate_video_thumbnails( $args, $assoc_args ) {
		global $wpdb;

		$force   = (bool) Utils\get_flag_value( $assoc_args, 'force', false );
		$dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );

		// Check if ffmpeg is available.
		$ffmpeg_check = shell_exec( 'which ffmpeg 2>/dev/null' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		if ( empty( $ffmpeg_check ) ) {
			WP_CLI::error( 'ffmpeg is not installed or not available in PATH. Install ffmpeg to use this command.' );
		}

		// Prepare thumbs directory.
		$upload_dir = wp_upload_dir();
		$thumb_dir  = $upload_dir['basedir'] . '/wpmediaverse/thumbs';
		$thumb_url  = $upload_dir['baseurl'] . '/wpmediaverse/thumbs';

		if ( ! $dry_run ) {
			wp_mkdir_p( $thumb_dir );
		}

		// Query all video media.
		$videos = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id, title FROM {$wpdb->prefix}mvs_media_index WHERE media_type = %s ORDER BY media_id ASC",
				'video'
			)
		);

		if ( empty( $videos ) ) {
			WP_CLI::success( 'No video media found. Nothing to do.' );
			return;
		}

		$generated = 0;
		$skipped   = 0;

		foreach ( $videos as $video ) {
			$media_id = (int) $video->media_id;
			$title    = $video->title ?: "(ID: {$media_id})";

			// Check if thumbnail already exists. Raw read — presence check,
			// not URL emission.
			$existing_thumb = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'thumb_large' );
			if ( $existing_thumb && ! $force ) {
				++$skipped;
				if ( $dry_run ) {
					WP_CLI::log( "Skip (has thumbnail): media {$media_id}: {$title}" );
				}
				continue;
			}

			// Resolve absolute filesystem path with traversal containment.
			$file_path = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_filesystem_path( $media_id );
			if ( null === $file_path ) {
				++$skipped;
				WP_CLI::warning( "No reachable file for media {$media_id}: {$title} — skipping." );
				continue;
			}

			if ( $dry_run ) {
				WP_CLI::log( "Would generate thumbnail for media {$media_id}: {$title}" );
				++$generated;
				continue;
			}

			// Generate thumbnail with ffmpeg.
			$thumb_name = 'video-thumb-' . $media_id . '.jpg';
			$thumb_path = $thumb_dir . '/' . $thumb_name;

			$escaped_input  = escapeshellarg( $file_path );
			$escaped_output = escapeshellarg( $thumb_path );
			$command        = "ffmpeg -y -i {$escaped_input} -ss 00:00:01 -vframes 1 -q:v 2 {$escaped_output} 2>&1";
			$output         = array();
			$return_code    = 0;

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- CLI-only, inputs sanitized with escapeshellarg.
			exec( $command, $output, $return_code );

			if ( 0 !== $return_code || ! file_exists( $thumb_path ) ) {
				++$skipped;
				WP_CLI::warning( "ffmpeg failed for media {$media_id}: {$title} (exit code {$return_code})." );
				continue;
			}

			// Store thumb URLs in meta.
			$full_thumb_url = $thumb_url . '/' . $thumb_name;
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'thumb_large', $full_thumb_url );
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'thumb_medium', $full_thumb_url );
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'thumb_thumb', $full_thumb_url );

			++$generated;
			WP_CLI::log( "Generated thumbnail for media {$media_id}: {$title}" );
		}

		$action = $dry_run ? 'Would generate' : 'Generated';
		WP_CLI::success( "Done. {$action} {$generated} thumbnails, skipped {$skipped}." );
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

	/**
	 * Sync BP activity hide_sitewide flag for all existing media.
	 *
	 * Walks every mvs_media_upload activity + every activity_update with
	 * `_mvs_media_ids` meta and recomputes hide_sitewide from the linked
	 * media's effective privacy (media + parent album, most-restrictive
	 * wins). Run this once after deploying 1.2.1's privacy-sync fix to
	 * bring legacy activity rows in line with the new behaviour.
	 *
	 * Originally drafted by Nitin Patil (Free remote 1.2.1 commit edfc643)
	 * as the migration counterpart to the `hide_sitewide` flip. Adapted
	 * here to use the canonical `ActivitySyncIntegration::should_hide_for_*`
	 * helpers introduced in 1.2.1 so single-source-of-truth on the privacy
	 * mapping (media + album, level scheme, future viewer-side filter)
	 * remains in one place. Idempotent — re-runs only update rows that
	 * have actually drifted.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would change without writing to the database.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs sync-activity-privacy --dry-run
	 *     wp mvs sync-activity-privacy
	 *
	 * @subcommand sync-activity-privacy
	 * @when after_wp_load
	 *
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI flags.
	 */
	public function sync_activity_privacy( $args, $assoc_args ) {
		unset( $args );

		if ( ! function_exists( 'buddypress' ) || ! bp_is_active( 'activity' ) ) {
			WP_CLI::error( 'BuddyPress activity component is not active.' );
		}

		global $wpdb;

		$dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );

		// Pass 1 — standalone mvs_media_upload activities (single-photo path).
		$upload_activities = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			"SELECT id, component, item_id, secondary_item_id, hide_sitewide
			 FROM {$wpdb->prefix}bp_activity
			 WHERE type = 'mvs_media_upload'
			 ORDER BY id ASC"
		);

		// Pass 2 — composer activities with attached media (`_mvs_media_ids` meta).
		$composer_activity_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			"SELECT DISTINCT activity_id
			 FROM {$wpdb->prefix}bp_activity_meta
			 WHERE meta_key = '_mvs_media_ids'"
		);

		$total = count( $upload_activities ) + count( $composer_activity_ids );
		if ( 0 === $total ) {
			WP_CLI::success( 'No MVS activities found. Nothing to sync.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d standalone upload activities and %d composer activities to inspect.', count( $upload_activities ), count( $composer_activity_ids ) ) );

		if ( ! $dry_run ) {
			WP_CLI::warning( 'This will update hide_sitewide + activity privacy meta in bp_activity / bp_activity_meta. Ensure you have a backup.' );
			WP_CLI::confirm( 'Proceed?' );
		}

		$hidden   = 0;
		$restored = 0;
		$skipped  = 0;
		$progress = Utils\make_progress_bar( 'Syncing activity privacy', $total );

		// Standalone mvs_media_upload activities.
		foreach ( $upload_activities as $act ) {
			$media_id = 0;
			if ( 'wpmediaverse' === $act->component && $act->item_id > 0 ) {
				$media_id = (int) $act->item_id;
			} elseif ( 'groups' === $act->component && $act->secondary_item_id > 0 ) {
				$media_id = (int) $act->secondary_item_id;
			}

			if ( $media_id <= 0 || ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			$desired_hide = \WPMediaVerse\Integrations\BuddyPress\ActivitySyncIntegration::should_hide_for_media( $media_id ) ? 1 : 0;
			$current_hide = (int) $act->hide_sitewide;

			if ( $desired_hide === $current_hide ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			if ( ! $dry_run ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prefix . 'bp_activity',
					array( 'hide_sitewide' => $desired_hide ),
					array( 'id' => (int) $act->id ),
					array( '%d' ),
					array( '%d' )
				);
				$effective = \WPMediaVerse\Integrations\BuddyPress\ActivitySyncIntegration::effective_privacy_for_media( $media_id );
				bp_activity_update_meta( (int) $act->id, '_mvs_activity_privacy', $effective );
				bp_activity_update_meta( (int) $act->id, '_mvs_activity_privacy_level', (string) \WPMediaVerse\Integrations\BuddyPress\ActivitySyncIntegration::privacy_to_level( $effective ) );
			}

			$desired_hide ? ++$hidden : ++$restored;
			$progress->tick();
		}

		// Composer (activity_update) activities with attached media.
		foreach ( $composer_activity_ids as $act_id ) {
			$act_id = (int) $act_id;

			$current_hide = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT hide_sitewide FROM {$wpdb->prefix}bp_activity WHERE id = %d", $act_id )
			);

			$raw_ids = bp_activity_get_meta( $act_id, '_mvs_media_ids', true );
			$ids     = array_filter( array_map( 'absint', explode( ',', (string) $raw_ids ) ) );

			if ( empty( $ids ) ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			$desired_hide = \WPMediaVerse\Integrations\BuddyPress\ActivitySyncIntegration::should_hide_for_batch( $ids ) ? 1 : 0;

			if ( $desired_hide === $current_hide ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			if ( ! $dry_run ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prefix . 'bp_activity',
					array( 'hide_sitewide' => $desired_hide ),
					array( 'id' => $act_id ),
					array( '%d' ),
					array( '%d' )
				);
				$effective = \WPMediaVerse\Integrations\BuddyPress\ActivitySyncIntegration::effective_privacy_for_batch( $ids );
				bp_activity_update_meta( $act_id, '_mvs_activity_privacy', $effective );
				bp_activity_update_meta( $act_id, '_mvs_activity_privacy_level', (string) \WPMediaVerse\Integrations\BuddyPress\ActivitySyncIntegration::privacy_to_level( $effective ) );
			}

			$desired_hide ? ++$hidden : ++$restored;
			$progress->tick();
		}

		$progress->finish();

		$prefix = $dry_run ? '[dry-run] Would' : '';
		WP_CLI::success(
			sprintf(
				'%s hide %d activities. %s restore %d activities. %d skipped (already correct or media missing).',
				$prefix ? $prefix : 'Hidden',
				$hidden,
				$prefix ? $prefix : 'Restored',
				$restored,
				$skipped
			)
		);
	}

	/**
	 * Migrate every stored media file from one storage driver to another.
	 *
	 * Reads the current storage driver, walks every media row in
	 * mvs_media_index, downloads each file from the source driver, uploads
	 * it to the destination driver, then verifies and removes the source
	 * copy. Idempotent — re-running skips media already present on the
	 * destination. The plugin's `mvs_storage_driver` option is NOT flipped
	 * automatically; the operator does that explicitly via
	 * `wp option update mvs_storage_driver <to>` once migrate verifies.
	 *
	 * Use cases:
	 *   - Activated S3 mid-life and need to move existing local files into
	 *     the bucket so signed URLs work for them.
	 *   - Switching FROM cloud back to local (S3 quota changes,
	 *     compliance, etc).
	 *   - Switching between cloud providers (S3 → BunnyCDN).
	 *
	 * Drivers must implement `download()` (added in interface 1.2.2).
	 * Both Free's LocalDriver and Pro's S3 + BunnyCDN drivers do.
	 *
	 * ## OPTIONS
	 *
	 * --from=<driver>
	 * : Source driver slug. local|s3|bunnycdn.
	 *
	 * --to=<driver>
	 * : Destination driver slug. Must differ from --from.
	 *
	 * [--dry-run]
	 * : Walk the media list and report which rows would migrate, but
	 *   do not download / upload / delete anything.
	 *
	 * [--keep-source]
	 * : Skip the post-verify source-side delete. Use when you want a
	 *   safety copy on the source until QA confirms the new driver
	 *   serves all media correctly. Default: source is deleted.
	 *
	 * [--media-id=<id>]
	 * : Migrate only one specific media row (testing / repair).
	 *
	 * [--limit=<n>]
	 * : Stop after this many rows (testing / batched runs).
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs migrate-storage --from=local --to=s3 --dry-run
	 *     wp mvs migrate-storage --from=local --to=s3 --keep-source
	 *     wp mvs migrate-storage --from=local --to=s3
	 *     wp mvs migrate-storage --from=s3 --to=bunnycdn --limit=100
	 *
	 * @subcommand migrate-storage
	 * @when after_wp_load
	 *
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI flags.
	 */
	public function migrate_storage( $args, $assoc_args ) {
		unset( $args );

		$from        = (string) Utils\get_flag_value( $assoc_args, 'from', '' );
		$to          = (string) Utils\get_flag_value( $assoc_args, 'to', '' );
		$dry_run     = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$keep_source = (bool) Utils\get_flag_value( $assoc_args, 'keep-source', false );
		$media_id    = (int) Utils\get_flag_value( $assoc_args, 'media-id', 0 );
		$limit       = (int) Utils\get_flag_value( $assoc_args, 'limit', 0 );

		if ( '' === $from || '' === $to ) {
			WP_CLI::error( 'Both --from and --to are required.' );
		}
		if ( $from === $to ) {
			WP_CLI::error( 'Source and destination drivers must differ.' );
		}

		// Resolve driver instances. The mvs_storage_driver filter is the
		// canonical entry point — it returns Pro's S3 / BunnyCDN drivers
		// when Pro is active, falls through to LocalDriver otherwise.
		$source_driver = apply_filters( 'mvs_storage_driver', null, $from );
		$dest_driver   = apply_filters( 'mvs_storage_driver', null, $to );

		if ( ! $source_driver instanceof \WPMediaVerse\Services\StorageDriverInterface ) {
			$source_driver = ( 'local' === $from ) ? new \WPMediaVerse\Services\LocalDriver() : null;
		}
		if ( ! $dest_driver instanceof \WPMediaVerse\Services\StorageDriverInterface ) {
			$dest_driver = ( 'local' === $to ) ? new \WPMediaVerse\Services\LocalDriver() : null;
		}

		if ( ! $source_driver ) {
			WP_CLI::error( "Source driver '{$from}' not available. Pro plugin required for s3/bunnycdn." );
		}
		if ( ! $dest_driver ) {
			WP_CLI::error( "Destination driver '{$to}' not available. Pro plugin required for s3/bunnycdn." );
		}

		global $wpdb;

		$where = "status IN ('publish','draft') AND file_path IS NOT NULL AND file_path != ''";
		if ( $media_id > 0 ) {
			$where .= $wpdb->prepare( ' AND media_id = %d', $media_id );
		}
		$limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT media_id, file_path, file_size FROM {$wpdb->prefix}mvs_media_index WHERE {$where} ORDER BY media_id ASC{$limit_sql}" );

		$total = count( $rows );
		if ( 0 === $total ) {
			WP_CLI::success( 'No media rows match. Nothing to migrate.' );
			return;
		}

		WP_CLI::log( sprintf( 'Migrating %d media file(s) from %s → %s%s%s', $total, $from, $to, $dry_run ? ' [DRY-RUN]' : '', $keep_source ? ' [KEEP-SOURCE]' : '' ) );

		if ( ! $dry_run ) {
			WP_CLI::warning( 'This will move files between storage drivers. Ensure both source AND destination are accessible. A backup of the source bucket / directory is strongly recommended.' );
			WP_CLI::confirm( 'Proceed?' );
		}

		$tmp_root = trailingslashit( get_temp_dir() ) . 'mvs-migrate-' . uniqid() . '/';
		$migrated = 0;
		$skipped  = 0;
		$failed   = 0;
		$progress = Utils\make_progress_bar( 'Migrating media', $total );

		foreach ( $rows as $row ) {
			$rel_path = (string) $row->file_path;

			// Skip if destination already has the file (idempotent re-run).
			if ( $dest_driver->exists( $rel_path ) ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			if ( $dry_run ) {
				++$migrated;
				$progress->tick();
				continue;
			}

			// Stage 1 — pull source to local temp.
			$tmp_path = $tmp_root . ltrim( $rel_path, '/' );
			if ( ! $source_driver->download( $rel_path, $tmp_path ) ) {
				WP_CLI::warning( sprintf( 'Download failed for media #%d (path: %s)', $row->media_id, $rel_path ) );
				++$failed;
				$progress->tick();
				continue;
			}

			// Stage 2 — push local temp to destination.
			if ( ! $dest_driver->store( $tmp_path, $rel_path ) ) {
				WP_CLI::warning( sprintf( 'Upload to destination failed for media #%d (path: %s)', $row->media_id, $rel_path ) );
				if ( file_exists( $tmp_path ) ) {
					wp_delete_file( $tmp_path );
				}
				++$failed;
				$progress->tick();
				continue;
			}

			// Stage 3 — verify destination presence before any deletes.
			if ( ! $dest_driver->exists( $rel_path ) ) {
				WP_CLI::warning( sprintf( 'Post-upload verify failed for media #%d (path: %s)', $row->media_id, $rel_path ) );
				++$failed;
				$progress->tick();
				continue;
			}

			// Stage 4 — delete the source unless keep-source flag set.
			if ( ! $keep_source ) {
				$source_driver->delete( $rel_path );
			}

			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
			++$migrated;
			$progress->tick();
		}

		$progress->finish();

		// Cleanup temp root if empty.
		if ( is_dir( $tmp_root ) ) {
			@rmdir( $tmp_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- empty-dir best-effort
		}

		$prefix = $dry_run ? '[dry-run] Would migrate' : 'Migrated';
		WP_CLI::success(
			sprintf(
				'%s %d file(s) from %s → %s. Skipped %d (already on destination). Failed %d.',
				$prefix,
				$migrated,
				$from,
				$to,
				$skipped,
				$failed
			)
		);

		if ( ! $dry_run && $failed > 0 ) {
			WP_CLI::warning( 'Some files failed to migrate. Review log lines above and re-run for retry. The mvs_storage_driver option has NOT been changed — flip it manually after a clean run: wp option update mvs_storage_driver ' . $to );
		} elseif ( ! $dry_run && 0 === $failed ) {
			WP_CLI::log( "Next step: flip the active driver to '{$to}':" );
			WP_CLI::log( "  wp option update mvs_storage_driver {$to}" );
		}
	}
}
