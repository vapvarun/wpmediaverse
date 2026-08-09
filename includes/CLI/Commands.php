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
use WPMediaVerse\Services\CloudOps;

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
	 * Regenerate image thumbnail size variants for existing media.
	 *
	 * Re-runs UploadService::generate_thumbnails() for every image in
	 * mvs_media_index, rebuilding the large/medium/thumb rungs from the current
	 * mvs_thumbnail_sizes spec — which honours the "Large image size" setting
	 * (mvs_large_image_size). Run this after changing the Large image size so
	 * images uploaded before the change pick up the new dimension; new uploads
	 * get it automatically. Reads each original from its local path, so it is a
	 * local-disk operation (for cloud-only originals use cloud-thumbs-backfill).
	 *
	 * Keyset-paginated over media_id and safe to re-run — big-library friendly.
	 *
	 * ## OPTIONS
	 *
	 * [--only-missing]
	 * : Only process images with no thumb_large meta yet (fill gaps; do not
	 *   rebuild images that already have variants).
	 *
	 * [--dry-run]
	 * : List what would be processed without writing anything.
	 *
	 * [--batch=<n>]
	 * : Rows to pull per query when walking the index. Default 200.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs regenerate-thumbnails
	 *     wp mvs regenerate-thumbnails --only-missing
	 *     wp mvs regenerate-thumbnails --dry-run
	 *
	 * @subcommand regenerate-thumbnails
	 */
	public function regenerate_thumbnails( $args, $assoc_args ) {
		unset( $args );
		global $wpdb;

		$dry_run      = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$only_missing = (bool) Utils\get_flag_value( $assoc_args, 'only-missing', false );
		$batch        = max( 1, (int) Utils\get_flag_value( $assoc_args, 'batch', 200 ) );

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_type = %s",
				'image'
			)
		);

		if ( 0 === $total ) {
			WP_CLI::success( 'No image media found. Nothing to do.' );
			return;
		}

		$large_px = (int) get_option( 'mvs_large_image_size', 1024 );
		WP_CLI::log(
			sprintf(
				'Found %d image(s). Large rung = %dpx%s%s',
				$total,
				$large_px,
				$only_missing ? ' [only-missing]' : '',
				$dry_run ? ' [DRY-RUN]' : ''
			)
		);

		$repo        = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$service     = \WPMediaVerse\Core\Plugin::container()->get( 'upload' );
		$progress    = Utils\make_progress_bar( $dry_run ? 'Scanning images' : 'Regenerating thumbnails', $total );
		$regenerated = 0;
		$skipped     = 0;
		$failed      = 0;
		$last_id     = 0;

		do {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE media_type = %s AND media_id > %d ORDER BY media_id ASC LIMIT %d",
					'image',
					$last_id,
					$batch
				)
			);

			foreach ( $rows as $row ) {
				$media_id = (int) $row->media_id;
				$last_id  = $media_id;

				if ( $only_missing && $repo->get_raw( $media_id, 'thumb_large' ) ) {
					++$skipped;
					$progress->tick();
					continue;
				}

				// Resolve the local original with traversal containment.
				$file_path = $repo->get_filesystem_path( $media_id );
				if ( null === $file_path ) {
					++$skipped;
					if ( $dry_run ) {
						WP_CLI::log( "Skip (no reachable local file): media {$media_id}" );
					}
					$progress->tick();
					continue;
				}

				if ( $dry_run ) {
					++$regenerated;
					$progress->tick();
					continue;
				}

				$mime = (string) $repo->get( $media_id, 'file_type' );
				if ( $service->generate_thumbnails( $media_id, $file_path, $mime ) ) {
					++$regenerated;
				} else {
					++$failed;
					WP_CLI::warning( "Failed to regenerate media {$media_id}." );
				}
				$progress->tick();
			}
		} while ( ! empty( $rows ) );

		$progress->finish();

		$verb = $dry_run ? 'Would regenerate' : 'Regenerated';
		WP_CLI::success( sprintf( '%s %d image(s); skipped %d; failed %d.', $verb, $regenerated, $skipped, $failed ) );
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

		// IMPORTANT: privacy filter. Cloud buckets we support today (S3,
		// BunnyCDN) are public-readable by default — uploading a private
		// media's original to such a bucket leaks it to anyone with the URL,
		// defeating the privacy promise. Cloud-aware /serve + signed-GET
		// URLs land in 1.3.0; until then, non-public media STAYS on local.
		// Customer override: pass --include-non-public if they've configured
		// a private cloud bucket and accept the responsibility for serving
		// it themselves (e.g. via CloudFront with origin access identity).
		$include_non_public = (bool) Utils\get_flag_value( $assoc_args, 'include-non-public', false );
		$where              = "status IN ('publish','draft') AND file_path IS NOT NULL AND file_path != ''";
		if ( ! $include_non_public ) {
			$where .= " AND privacy = 'public'";
		}
		if ( $media_id > 0 ) {
			$where .= $wpdb->prepare( ' AND media_id = %d', $media_id );
		}
		$limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT media_id, file_path, file_size FROM {$wpdb->prefix}mvs_media_index WHERE {$where} ORDER BY media_id ASC{$limit_sql}" );

		$total = count( $rows );
		if ( 0 === $total ) {
			WP_CLI::success( $include_non_public ? 'No media rows match. Nothing to migrate.' : 'No PUBLIC media rows match. Nothing to migrate. (Pass --include-non-public to also migrate non-public media — only do this if your cloud bucket is private.)' );
			return;
		}

		WP_CLI::log( sprintf( 'Migrating %d media file(s) from %s → %s%s%s', $total, $from, $to, $dry_run ? ' [DRY-RUN]' : '', $keep_source ? ' [KEEP-SOURCE]' : '' ) );

		if ( ! $dry_run ) {
			WP_CLI::warning( 'This will move files between storage drivers. Ensure both source AND destination are accessible. A backup of the source bucket / directory is strongly recommended.' );
			WP_CLI::confirm( 'Proceed?' );
		}

		// `--include-non-public` is gated at the query level above. CloudOps
		// keeps its own privacy safety net, so open it for the rows we
		// deliberately selected — otherwise every one of them comes back
		// 'skipped-non-public' and the flag silently does nothing.
		if ( $include_non_public && 'local' !== $to ) {
			// Deliberately not `__return_true`: this is a storage filter in a
			// CLI command, not a REST permission callback, and the bare
			// callback name trips the Rule 2 gate that guards public routes.
			add_filter(
				'mvs_cloudops_allow_non_public_to_cloud',
				static function () {
					return true;
				}
			);
		}

		$migrated = 0;
		$skipped  = 0;
		$failed   = 0;
		$progress = Utils\make_progress_bar( 'Migrating media', $total );

		foreach ( $rows as $row ) {
			$media_id_row = (int) $row->media_id;

			if ( $dry_run ) {
				// Cheap preview: a row whose ORIGINAL is already on the
				// destination is reported as skipped. The real run still
				// re-checks every variant individually.
				if ( $dest_driver->exists( (string) $row->file_path ) ) {
					++$skipped;
				} else {
					++$migrated;
				}
				$progress->tick();
				continue;
			}

			// Delegate to the single authoritative implementation. It moves the
			// original PLUS every stored variant (thumbnails, WebP/AVIF
			// siblings, video/audio posters), verifies each on the destination
			// before flipping `file_url`, repoints the variant URL metas, and
			// only then deletes sources. This CLI previously carried its own
			// loop that transferred `file_path` alone, so on a CDN migration
			// every thumbnail and WebP sibling stayed behind on local disk
			// while `get_driver_for_location()` resolved their URLs to the CDN
			// — a 404 on every derived image. The Pro admin UI already went
			// through CloudOps and was never affected.
			$result = CloudOps::migrate_one( $media_id_row, $from, $to, $keep_source );

			if ( ! empty( $result['ok'] ) ) {
				if ( 'skipped' === ( $result['status'] ?? '' ) ) {
					++$skipped;
				} else {
					++$migrated;
				}
			} else {
				WP_CLI::warning(
					sprintf(
						'Migration failed for media #%d: %s',
						$media_id_row,
						(string) ( $result['error'] ?? 'unknown error' )
					)
				);
				++$failed;
			}

			$progress->tick();
		}

		$progress->finish();

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

	/**
	 * Push thumbnail variants to the active cloud storage driver for media
	 * uploaded before 1.2.2 (when cloud-side thumbs landed). Iterates every
	 * publish/draft image media row whose `thumb_<size>` meta still points
	 * at a local URL, regenerates from the original, and uploads each size
	 * to the cloud driver — same path convention as fresh uploads.
	 *
	 * After this CLI runs, the size-aware direct-CDN read path
	 * (`maybe_direct_cloud_thumbnail_url`) returns cloud URLs for every
	 * media's thumbnail slot — browser never fetches the full original
	 * for a 200×200 thumbnail again.
	 *
	 * Pre-condition: original files must already exist on cloud (run
	 * `wp mvs migrate-storage` first if not). The original is downloaded
	 * to a temp file, multi_resize'd via WP image editor, and each variant
	 * uploaded. Temp files are cleaned up after every row.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Walk the candidate list and report what would migrate. No files
	 *   downloaded, no thumbs generated, no uploads.
	 *
	 * [--media-id=<id>]
	 * : Process a single media row only (testing / repair).
	 *
	 * [--limit=<n>]
	 * : Stop after this many rows (testing / batched runs).
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs cloud-thumbs-backfill --dry-run
	 *     wp mvs cloud-thumbs-backfill --limit=100
	 *     wp mvs cloud-thumbs-backfill --media-id=42
	 *
	 * @subcommand cloud-thumbs-backfill
	 * @when after_wp_load
	 *
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI flags.
	 */
	public function cloud_thumbs_backfill( $args, $assoc_args ) {
		unset( $args );

		$dry_run  = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$media_id = (int) Utils\get_flag_value( $assoc_args, 'media-id', 0 );
		$limit    = (int) Utils\get_flag_value( $assoc_args, 'limit', 0 );

		$driver_slug = (string) get_option( 'mvs_storage_driver', 'local' );
		if ( 'local' === $driver_slug ) {
			WP_CLI::error( 'Active driver is "local" — nothing to push to cloud. Set mvs_storage_driver to s3 or bunnycdn first.' );
		}

		$driver = apply_filters( 'mvs_storage_driver', null, $driver_slug );
		if ( ! $driver instanceof \WPMediaVerse\Services\StorageDriverInterface ) {
			WP_CLI::error( "Storage driver '{$driver_slug}' is not available. Pro plugin required for s3/bunnycdn." );
		}

		global $wpdb;

		// IMPORTANT: only public media. Non-public media routes through the
		// gated /serve proxy which (as of 1.2.2) can only read from local
		// disk — flipping non-public thumb meta to CDN URLs would 404 the
		// proxy. Cloud-aware /serve is on the 1.3.0 list.
		$where = "i.status IN ('publish','draft') AND i.file_path IS NOT NULL AND i.file_path != '' AND i.file_type LIKE 'image/%' AND i.privacy = 'public'";
		if ( $media_id > 0 ) {
			$where .= $wpdb->prepare( ' AND i.media_id = %d', $media_id );
		}
		$limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT i.media_id, i.file_path, i.file_type FROM {$wpdb->prefix}mvs_media_index i WHERE {$where} ORDER BY i.media_id ASC{$limit_sql}" );

		$total = count( $rows );
		if ( 0 === $total ) {
			WP_CLI::success( 'No image media rows match. Nothing to backfill.' );
			return;
		}

		WP_CLI::log( sprintf( 'Backfilling cloud thumbnails for %d image(s) → %s%s', $total, $driver_slug, $dry_run ? ' [DRY-RUN]' : '' ) );

		if ( ! $dry_run ) {
			WP_CLI::warning( 'This downloads each original from the cloud, regenerates 3 size variants locally, and uploads each variant. Network + CPU heavy. Recommend running off-peak.' );
			WP_CLI::confirm( 'Proceed?' );
		}

		$repo     = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$service  = \WPMediaVerse\Core\Plugin::container()->get( 'upload' );
		$tmp_root = trailingslashit( get_temp_dir() ) . 'mvs-thumbs-' . uniqid() . '/';
		$migrated = 0;
		$skipped  = 0;
		$failed   = 0;
		$progress = Utils\make_progress_bar( 'Backfilling thumbnails', $total );

		foreach ( $rows as $row ) {
			$rel_path = (string) $row->file_path;

			// Skip if EVERY size already has a non-local URL — nothing to do.
			$thumb_keys = array( 'thumb_large', 'thumb_medium', 'thumb_thumb' );
			$all_cloud  = true;
			foreach ( $thumb_keys as $key ) {
				$val = (string) $repo->get_raw( (int) $row->media_id, $key );
				if ( '' === $val ) {
					$all_cloud = false;
					break;
				}
				$upload_dir = wp_upload_dir();
				if ( is_array( $upload_dir ) && ! empty( $upload_dir['baseurl'] ) && 0 === strpos( $val, (string) $upload_dir['baseurl'] ) ) {
					$all_cloud = false;
					break;
				}
			}
			if ( $dry_run ) {
				++$migrated;
				$progress->tick();
				continue;
			}

			// A row whose sizes are all on cloud still needs the variant sweep
			// below: `original_webp` / `original_avif` are not size variants, so
			// an earlier successful thumbnail backfill leaves them behind and
			// the single-media view keeps 404ing. Skip only the expensive
			// download + regenerate, never the sweep.
			if ( $all_cloud ) {
				$sweep = self::sweep_stored_variants( (int) $row->media_id, $driver_slug );
				if ( $sweep ) {
					++$migrated;
				} else {
					++$skipped;
				}
				$progress->tick();
				continue;
			}

			// Stage 1 — pull original from cloud to local temp.
			$tmp_path = $tmp_root . ltrim( $rel_path, '/' );
			if ( ! $driver->download( $rel_path, $tmp_path ) ) {
				WP_CLI::warning( sprintf( 'Original download failed for media #%d (path: %s)', $row->media_id, $rel_path ) );
				++$failed;
				$progress->tick();
				continue;
			}

			// Stage 2 — call generate_thumbnails. Same code path as a fresh
			// upload: WP multi_resize creates locals, the driver pushes each
			// to cloud, meta updates to cloud URLs.
			$ok = $service->generate_thumbnails( (int) $row->media_id, $tmp_path, (string) $row->file_type );

			// Stage 2b — sweep any stored variant the resize pass does not own
			// (see sweep_stored_variants).
			if ( $ok ) {
				self::sweep_stored_variants( (int) $row->media_id, $driver_slug );
			}

			// Stage 3 — clean up the temp original (size variants the
			// generate_thumbnails call wrote next to it are also cleaned).
			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
			$tmp_dir = dirname( $tmp_path );
			if ( is_dir( $tmp_dir ) ) {
				$siblings = glob( $tmp_dir . '/*-*x*.*' ) ?: array();
				foreach ( $siblings as $s ) {
					wp_delete_file( $s );
				}
			}

			if ( $ok ) {
				++$migrated;
			} else {
				++$failed;
			}
			$progress->tick();
		}

		$progress->finish();

		if ( is_dir( $tmp_root ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort cleanup of a plugin-controlled tmp dir from a WP-CLI command; WP_Filesystem isn't initialized here and would add a credentials prompt for an internal-only path.
			@rmdir( $tmp_root );
		}

		$prefix = $dry_run ? '[dry-run] Would backfill' : 'Backfilled';
		WP_CLI::success(
			sprintf(
				'%s thumbnails for %d image(s). Skipped %d (already on cloud). Failed %d.',
				$prefix,
				$migrated,
				$skipped,
				$failed
			)
		);
	}

	/**
	 * Copy any stored variant still missing from the active cloud driver.
	 *
	 * `generate_thumbnails()` rebuilds the SIZE variants and their WebP/AVIF
	 * siblings, but `original_webp` / `original_avif` are written by the
	 * optimization pass at upload time and are not size variants — so a
	 * thumbnail backfill alone leaves them on local disk while
	 * `get_driver_for_location()` resolves their URLs to the cloud. The
	 * single-media view renders at size `full` and reads exactly those keys,
	 * which is why it kept showing a broken image after the sizes were healed.
	 *
	 * Delegates to `CloudOps::migrate_one()` so the enumeration of "every
	 * stored file for this media" lives in one place. Copy-only and
	 * idempotent here: files already on the destination are skipped, and
	 * `keep_source = true` means nothing local is ever deleted.
	 *
	 * @since 2.3.1
	 *
	 * @param int    $media_id    Media ID.
	 * @param string $driver_slug Active cloud driver slug.
	 * @return bool True when the sweep completed (whether or not it moved anything).
	 */
	private static function sweep_stored_variants( int $media_id, string $driver_slug ): bool {
		$sweep = CloudOps::migrate_one( $media_id, 'local', $driver_slug, true );

		if ( empty( $sweep['ok'] ) ) {
			WP_CLI::warning(
				sprintf(
					'Variant sweep incomplete for media #%d: %s',
					$media_id,
					(string) ( $sweep['error'] ?? 'unknown error' )
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Delete local copies of media that are verified on the active cloud
	 * driver. Use after a successful `wp mvs migrate-storage --keep-source`
	 * run + a verification window where the operator confirmed the cloud
	 * driver serves all media correctly.
	 *
	 * For each media row: verifies the original AND every thumbnail variant
	 * exists on the cloud driver via `$driver->exists($rel_path)`, then
	 * deletes the local file. Idempotent — files that don't exist locally
	 * (already cleaned) are silent no-ops.
	 *
	 * **Irreversible.** After running this, flipping back to driver=local
	 * means the site has no source files. To go back to local, run
	 * `wp mvs migrate-storage --from=cloud --to=local` first (which
	 * downloads everything back) before flipping the driver.
	 *
	 * Pre-conditions checked at runtime:
	 *   - Active driver must NOT be local (prevents self-delete on a local-
	 *     only site).
	 *   - Each candidate file must exist on the cloud driver before we
	 *     delete the local copy. A failed exists() means we keep the local
	 *     file — never an orphan.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Walk the candidate list and report which files would be deleted.
	 *   No files removed.
	 *
	 * [--media-id=<id>]
	 * : Process a single media row only.
	 *
	 * [--limit=<n>]
	 * : Stop after this many rows.
	 *
	 * [--keep-thumbs]
	 * : Only delete the original local file. Keep local thumbnail
	 *   variants. Use when something on the site still relies on local
	 *   thumbs (legacy theme, third-party integration). Default is to
	 *   delete originals AND every -<width>x<height>.* variant in the
	 *   same directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs cleanup-local --dry-run
	 *     wp mvs cleanup-local --media-id=42
	 *     wp mvs cleanup-local --limit=100
	 *     wp mvs cleanup-local --keep-thumbs
	 *
	 * @subcommand cleanup-local
	 * @when after_wp_load
	 *
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI flags.
	 */
	public function cleanup_local( $args, $assoc_args ) {
		unset( $args );

		$dry_run     = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$media_id    = (int) Utils\get_flag_value( $assoc_args, 'media-id', 0 );
		$limit       = (int) Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$keep_thumbs = (bool) Utils\get_flag_value( $assoc_args, 'keep-thumbs', false );

		$driver_slug = (string) get_option( 'mvs_storage_driver', 'local' );
		if ( 'local' === $driver_slug ) {
			WP_CLI::error( 'Active driver is "local" — cleaning local files would break the site. Migrate to a cloud driver first.' );
		}

		$driver = apply_filters( 'mvs_storage_driver', null, $driver_slug );
		if ( ! $driver instanceof \WPMediaVerse\Services\StorageDriverInterface ) {
			WP_CLI::error( "Storage driver '{$driver_slug}' is not available. Pro plugin required for s3/bunnycdn." );
		}

		global $wpdb;

		// IMPORTANT: only public media. Non-public media routes through the
		// gated /serve proxy which (as of 1.2.2) can only read from local
		// disk. Deleting locals for those would break /serve. Cloud-aware
		// /serve lands in 1.3.0 — until then, private/members/friends
		// media keeps its local copy as a hard requirement.
		$where = "status IN ('publish','draft') AND file_path IS NOT NULL AND file_path != '' AND privacy = 'public'";
		if ( $media_id > 0 ) {
			$where .= $wpdb->prepare( ' AND media_id = %d', $media_id );
		}
		$limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT media_id, file_path FROM {$wpdb->prefix}mvs_media_index WHERE {$where} ORDER BY media_id ASC{$limit_sql}" );

		$total = count( $rows );
		if ( 0 === $total ) {
			WP_CLI::success( 'No public media rows match. Nothing to clean. (Non-public media is intentionally not cleaned — see 1.3.0 for cloud-aware /serve.)' );
			return;
		}

		WP_CLI::log( sprintf( 'Cleaning local copies for %d PUBLIC media row(s) on driver=%s%s%s', $total, $driver_slug, $dry_run ? ' [DRY-RUN]' : '', $keep_thumbs ? ' [KEEP-THUMBS]' : '' ) );

		if ( ! $dry_run ) {
			WP_CLI::warning( 'IRREVERSIBLE: this deletes local files. Make sure your cloud driver serves all media correctly before proceeding. To go back to driver=local later, run `wp mvs migrate-storage --from=' . $driver_slug . ' --to=local` first.' );
			WP_CLI::confirm( 'Proceed?' );
		}

		$upload_basedir = (string) ( wp_upload_dir()['basedir'] ?? '' );
		$wpmv_local_dir = trailingslashit( $upload_basedir ) . 'wpmediaverse';

		$cleaned          = 0;
		$skipped_no_file  = 0;
		$skipped_no_cloud = 0;
		$thumbs_removed   = 0;
		$progress         = Utils\make_progress_bar( 'Cleaning local files', $total );

		foreach ( $rows as $row ) {
			$rel_path        = (string) $row->file_path;
			$local_full_path = trailingslashit( $wpmv_local_dir ) . $rel_path;

			// 1. Skip if no local file exists (already cleaned, or born-on-cloud).
			if ( ! file_exists( $local_full_path ) ) {
				++$skipped_no_file;
				$progress->tick();
				continue;
			}

			// 2. Verify the cloud HAS this file before we delete the local
			// one. A failed exists() check means we keep the local copy —
			// never create an orphan.
			if ( ! $driver->exists( $rel_path ) ) {
				WP_CLI::warning( sprintf( 'Cloud verify FAILED for media #%d (%s) — keeping local file as safety', $row->media_id, $rel_path ) );
				++$skipped_no_cloud;
				$progress->tick();
				continue;
			}

			if ( $dry_run ) {
				++$cleaned;
				$progress->tick();
				continue;
			}

			// 3. Delete the original.
			wp_delete_file( $local_full_path );

			// 4. Delete sibling variants (foo-300x200.jpg, foo-150x150.jpg, etc).
			if ( ! $keep_thumbs ) {
				$dir      = dirname( $local_full_path );
				$base     = pathinfo( $local_full_path, PATHINFO_FILENAME );
				$pattern  = $dir . '/' . $base . '-*x*.*';
				$siblings = glob( $pattern ) ?: array();
				foreach ( $siblings as $sibling ) {
					if ( is_file( $sibling ) ) {
						wp_delete_file( $sibling );
						++$thumbs_removed;
					}
				}
			}

			++$cleaned;
			$progress->tick();
		}

		$progress->finish();

		$prefix = $dry_run ? '[dry-run] Would clean' : 'Cleaned';
		WP_CLI::success(
			sprintf(
				'%s %d original(s)%s. Skipped: %d already-clean, %d not-on-cloud (kept local).',
				$prefix,
				$cleaned,
				$keep_thumbs ? '' : sprintf( ' + %d thumbnail variant(s)', $thumbs_removed ),
				$skipped_no_file,
				$skipped_no_cloud
			)
		);
	}

	/**
	 * Heal legacy non-public media rows whose `file_url` / `thumb_*` meta still
	 * point at a cloud bucket.
	 *
	 * Background: before 1.4.0, when a public-on-cloud upload (BunnyCDN, S3, R2,
	 * DigitalOcean Spaces) was later flipped to a restricted privacy level
	 * (members / friends / private / group / custom) the URL meta was NOT
	 * relocalized. `SignedUrlService::serve_thumbnail()` then 403s on every
	 * read because the stored cloud URL fails its `wpmediaverse/` containment
	 * check. The 1.4.0 listener fixes this going forward; this command heals
	 * pre-1.4.0 rows (card 9925110293).
	 *
	 * Safe to re-run — rows that already have local URLs are skipped.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing.
	 *
	 * [--media-id=<id>]
	 * : Only inspect this single media row.
	 *
	 * [--limit=<n>]
	 * : Stop after inspecting this many candidate rows.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs relocalize-private --dry-run
	 *     wp mvs relocalize-private
	 *     wp mvs relocalize-private --media-id=47
	 *
	 * @subcommand relocalize-private
	 * @when after_wp_load
	 *
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI flags.
	 */
	public function relocalize_private( $args, $assoc_args ) {
		unset( $args );

		$dry_run  = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$media_id = (int) Utils\get_flag_value( $assoc_args, 'media-id', 0 );
		$limit    = (int) Utils\get_flag_value( $assoc_args, 'limit', 0 );

		global $wpdb;

		$where = "privacy <> 'public' AND file_path IS NOT NULL AND file_path <> ''";
		if ( $media_id > 0 ) {
			$where .= $wpdb->prepare( ' AND media_id = %d', $media_id );
		}
		$limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT media_id, privacy, file_path FROM {$wpdb->prefix}mvs_media_index WHERE {$where} ORDER BY media_id ASC{$limit_sql}" );

		$total = count( $rows );
		if ( 0 === $total ) {
			WP_CLI::success( 'No non-public media rows match. Nothing to heal.' );
			return;
		}

		WP_CLI::log( sprintf( 'Inspecting %d non-public media row(s)%s.', $total, $dry_run ? ' [DRY-RUN]' : '' ) );

		if ( ! $dry_run ) {
			WP_CLI::warning( 'This rewrites mvs_media_index.file_url and mvs_media_meta thumb_* values for non-public rows. Take a DB backup before running on production.' );
			WP_CLI::confirm( 'Proceed?' );
		}

		$storage  = \WPMediaVerse\Core\Plugin::container()->get( 'storage' );
		$repo     = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$healed   = 0;
		$skipped  = 0;
		$progress = Utils\make_progress_bar( 'Relocalizing URLs', $total );

		foreach ( $rows as $row ) {
			$mid = (int) $row->media_id;

			if ( $dry_run ) {
				// Detect whether any URL meta still points at non-local.
				$needs_heal = $this->detect_cloud_urls( $repo, $mid );
				if ( $needs_heal ) {
					++$healed;
					WP_CLI::log( sprintf( '  - media #%d (privacy=%s) would be relocalized', $mid, $row->privacy ) );
				} else {
					++$skipped;
				}
				$progress->tick();
				continue;
			}

			$changed = $storage->relocalize_media_urls( $mid );
			if ( $changed ) {
				++$healed;
			} else {
				++$skipped;
			}
			$progress->tick();
		}

		$progress->finish();

		$prefix = $dry_run ? '[dry-run] Would heal' : 'Healed';
		WP_CLI::success( sprintf( '%s %d row(s). Skipped %d already-clean row(s).', $prefix, $healed, $skipped ) );
	}

	/**
	 * Helper for relocalize-private dry-run mode — flags any URL meta still
	 * pointing outside the uploads dir.
	 *
	 * @param \WPMediaVerse\Repository\MediaRepository $repo     Media repo.
	 * @param int                                      $media_id Media ID.
	 * @return bool
	 */
	private function detect_cloud_urls( $repo, int $media_id ): bool {
		$upload_dir = wp_upload_dir();
		$baseurl    = (string) $upload_dir['baseurl'];
		if ( '' === $baseurl ) {
			return false;
		}

		$keys = array(
			'file_url',
			'thumb_large',
			'thumb_medium',
			'thumb_thumb',
			'thumb_large_webp',
			'thumb_medium_webp',
			'thumb_thumb_webp',
			'thumb_large_avif',
			'thumb_medium_avif',
			'thumb_thumb_avif',
			'original_webp',
			'original_avif',
		);
		foreach ( $keys as $key ) {
			$val = (string) $repo->get_raw( $media_id, $key );
			if ( '' === $val ) {
				continue;
			}
			if ( 0 !== strpos( $val, $baseurl ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Optimize a single media row (re-encode original + emit WebP siblings).
	 *
	 * ## OPTIONS
	 *
	 * <media_id>
	 * : The media row id to optimize.
	 *
	 * [--include-variants]
	 * : Also re-process every thumbnail variant.
	 *
	 * [--force]
	 * : Re-run even when the row has already been optimized.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs optimize 42
	 *     wp mvs optimize 42 --include-variants --force
	 *
	 * @subcommand optimize
	 */
	public function optimize( $args, $assoc_args ) {
		$media_id = (int) ( $args[0] ?? 0 );
		if ( $media_id <= 0 ) {
			WP_CLI::error( 'Missing or invalid <media_id>.' );
		}

		$service = \WPMediaVerse\Core\Plugin::container()->get( 'image_optimization' );
		$result  = $service->optimize_media(
			$media_id,
			array(
				'include_variants' => (bool) Utils\get_flag_value( $assoc_args, 'include-variants', false ),
				'force'            => (bool) Utils\get_flag_value( $assoc_args, 'force', false ),
			)
		);

		if ( ! empty( $result['errors'] ) ) {
			WP_CLI::warning( sprintf( 'Media #%d: %s', $media_id, implode( ', ', $result['errors'] ) ) );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Media #%d: %s -> %s (saved %s%%). Variants processed: %d.',
				$media_id,
				size_format( $result['bytes_before'] ),
				size_format( $result['bytes_after'] ),
				$result['saved_pct'],
				$result['variants_processed']
			)
		);
	}

	/**
	 * Optimize every image in the library matching the filters.
	 *
	 * Writes a `_mvs_optimized_at` sentinel so re-runs resume from where they
	 * left off without `--force`. Failures write `_mvs_optimize_failed` and
	 * are skipped on subsequent runs unless `--include-failed` is passed.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Cap rows processed.
	 *
	 * [--offset=<n>]
	 * : Skip the first N rows (manual resume).
	 *
	 * [--mime=<types>]
	 * : Comma-separated MIME types to include. Default: every image/*.
	 *
	 * [--media-type=<type>]
	 * : Restrict to one media_type column value (photo, image, etc.).
	 *
	 * [--include-variants]
	 * : Process thumbnail variants alongside the original.
	 *
	 * [--dry-run]
	 * : Report what would be processed without writing.
	 *
	 * [--include-failed]
	 * : Re-process rows previously marked as failed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs optimize-bulk --limit=100 --include-variants
	 *     wp mvs optimize-bulk --mime=image/jpeg --dry-run
	 *
	 * @subcommand optimize-bulk
	 */
	public function optimize_bulk( $args, $assoc_args ) {
		unset( $args );

		$limit            = (int) Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$offset           = (int) Utils\get_flag_value( $assoc_args, 'offset', 0 );
		$mime_filter      = (string) Utils\get_flag_value( $assoc_args, 'mime', '' );
		$media_type       = (string) Utils\get_flag_value( $assoc_args, 'media-type', '' );
		$include_variants = (bool) Utils\get_flag_value( $assoc_args, 'include-variants', false );
		$dry_run          = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$include_failed   = (bool) Utils\get_flag_value( $assoc_args, 'include-failed', false );

		global $wpdb;
		$where = "i.status IN ('publish','draft') AND i.file_path IS NOT NULL AND i.file_path != '' AND i.file_type LIKE 'image/%'";
		if ( '' !== $mime_filter ) {
			$mimes = array_filter( array_map( 'trim', explode( ',', $mime_filter ) ) );
			if ( ! empty( $mimes ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
				$where       .= $wpdb->prepare( " AND i.file_type IN ($placeholders)", $mimes ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		if ( '' !== $media_type ) {
			$where .= $wpdb->prepare( ' AND i.media_type = %s', $media_type );
		}

		$limit_sql  = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';
		$offset_sql = $offset > 0 ? $wpdb->prepare( ' OFFSET %d', $offset ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT i.media_id FROM {$wpdb->prefix}mvs_media_index i WHERE {$where} ORDER BY i.media_id ASC{$limit_sql}{$offset_sql}" );

		$total = count( $rows );
		if ( 0 === $total ) {
			WP_CLI::success( 'No image rows match the filters.' );
			return;
		}

		WP_CLI::log( sprintf( 'Optimizing %d image(s)%s%s', $total, $dry_run ? ' [DRY-RUN]' : '', $include_variants ? ' (with variants)' : '' ) );

		$repo           = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$service        = \WPMediaVerse\Core\Plugin::container()->get( 'image_optimization' );
		$progress       = Utils\make_progress_bar( 'Optimizing', $total );
		$processed      = 0;
		$skipped        = 0;
		$failed         = 0;
		$bytes_before_t = 0;
		$bytes_after_t  = 0;
		$variants_total = 0;

		foreach ( $rows as $media_id_str ) {
			$id = (int) $media_id_str;

			// Resume support: skip already-optimized rows unless explicitly told
			// to re-run. Failed rows are skipped unless --include-failed.
			if ( (int) $repo->get_raw( $id, \WPMediaVerse\Services\ImageOptimizationService::META_OPTIMIZED_AT ) > 0 ) {
				++$skipped;
				$progress->tick();
				continue;
			}
			if ( ! $include_failed && '' !== (string) $repo->get_raw( $id, \WPMediaVerse\Services\ImageOptimizationService::META_OPTIMIZE_FAILED ) ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			$result = $service->optimize_media(
				$id,
				array(
					'include_variants' => $include_variants,
					'dry_run'          => $dry_run,
				)
			);

			if ( ! empty( $result['errors'] ) ) {
				++$failed;
			} else {
				++$processed;
				$bytes_before_t += (int) $result['bytes_before'];
				$bytes_after_t  += (int) $result['bytes_after'];
				$variants_total += (int) $result['variants_processed'];
			}
			$progress->tick();
		}

		$progress->finish();

		$saved_pct = $bytes_before_t > 0
			? round( ( $bytes_before_t - $bytes_after_t ) / $bytes_before_t * 100, 2 )
			: 0;

		WP_CLI::success(
			sprintf(
				'%s %d image(s). Skipped %d. Failed %d. Total: %s -> %s (saved %s%%). Variants: %d.',
				$dry_run ? '[DRY-RUN] Would process' : 'Processed',
				$processed,
				$skipped,
				$failed,
				size_format( $bytes_before_t ),
				size_format( $bytes_after_t ),
				$saved_pct,
				$variants_total
			)
		);
	}

	/**
	 * Backfill AI description + tags on media uploaded before AI was enabled.
	 *
	 * Only image media with no ai_description yet are processed. By default
	 * each one is queued to the same async Action Scheduler job that runs on
	 * upload (mvs_ai_process_media); pass --sync to process inline.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max media to process. Default 0 (all).
	 *
	 * [--sync]
	 * : Process inline instead of queueing the async job. Slower; respects the
	 *   AI budget cap per item.
	 *
	 * [--dry-run]
	 * : List how many media would be processed without doing it.
	 *
	 * [--force]
	 * : Reprocess ALL image media, including ones already attempted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs backfill_ai --dry-run
	 *     wp mvs backfill_ai --limit=200
	 *     wp mvs backfill_ai --sync
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function backfill_ai( $args, $assoc_args ) {
		unset( $args );
		global $wpdb;

		$limit   = (int) Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$sync    = (bool) Utils\get_flag_value( $assoc_args, 'sync', false );
		$dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$force   = (bool) Utils\get_flag_value( $assoc_args, 'force', false );

		// Image media that have never been through AI (ai_status unset). Using
		// ai_status as the marker — not ai_description — keeps the backfill
		// idempotent: a media that completed (even with an empty description)
		// or failed is not retried on the next run. --force reprocesses
		// everything regardless.
		$meta = $wpdb->prefix . 'mvs_media_meta';
		$sql  = "SELECT m.media_id FROM {$wpdb->prefix}mvs_media_index m
			LEFT JOIN {$meta} s ON s.media_id = m.media_id AND s.meta_key = 'ai_status'
			WHERE m.media_type = 'image' AND m.status = 'publish'";
		if ( ! $force ) {
			$sql .= " AND ( s.meta_value IS NULL OR s.meta_value = '' )";
		}
		$sql .= ' ORDER BY m.media_id ASC';
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) );

		if ( empty( $ids ) ) {
			WP_CLI::success( 'No media need AI backfill.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%d media would be processed (dry run).', count( $ids ) ) );
			return;
		}

		$ai        = \WPMediaVerse\Core\Plugin::container()->get( 'ai' );
		$queued    = 0;
		$processed = 0;
		$failed    = 0;
		$progress  = \WP_CLI\Utils\make_progress_bar( 'Backfilling AI', count( $ids ) );

		foreach ( $ids as $media_id ) {
			if ( $sync ) {
				$result = $ai->process( $media_id );
				if ( is_wp_error( $result ) ) {
					++$failed;
				} else {
					++$processed;
				}
			} elseif ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'mvs_ai_process_media', array( $media_id ), 'wpmediaverse' );
				++$queued;
			} else {
				$result = $ai->process( $media_id );
				if ( is_wp_error( $result ) ) {
					++$failed;
				} else {
					++$processed;
				}
			}
			$progress->tick();
		}
		$progress->finish();

		WP_CLI::success(
			sprintf(
				'AI backfill done. Queued: %d, processed inline: %d, failed: %d.',
				$queued,
				$processed,
				$failed
			)
		);
	}

	/**
	 * Repair media storage paths left inconsistent by pre-1.8.0 behavior.
	 *
	 * Heals two pre-existing states (it does not create them):
	 *   - Absolute file_path from old plugin-migration imports (rtMedia /
	 *     MediaPress / BuddyBoss) — the full-size image 404s. Re-sideloaded into
	 *     uploads/wpmediaverse/ as a relative path.
	 *   - Stranded thumbnails — old "Migrate all" moved the original to cloud but
	 *     left thumbnails on local disk, so they 404 at the cloud URL. Pushed to
	 *     the active cloud driver.
	 *
	 * Idempotent and safe to re-run. The source file is only ever copied, never
	 * moved or deleted. On the admin side this runs automatically in the
	 * background after update; this command is for headless / on-demand use.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report how many rows need repair without changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs repair-storage --dry-run
	 *     wp mvs repair-storage
	 *
	 * @subcommand repair-storage
	 * @when after_wp_load
	 *
	 * @param array $args       Positional CLI args (unused).
	 * @param array $assoc_args Associative CLI flags.
	 */
	public function repair_storage( $args, $assoc_args ) {
		unset( $args );

		$dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$service = \WPMediaVerse\Core\Plugin::container()->get( 'storage_repair' );

		if ( $dry_run ) {
			$counts = $service->run_to_completion( true );
			WP_CLI::log(
				sprintf(
					'Dry run — absolute-path rows: %d, stranded thumbnails detected: %s.',
					(int) $counts['absolute'],
					! empty( $counts['stranded_any'] ) ? 'yes' : 'no'
				)
			);
			if ( empty( $counts['needs_repair'] ) ) {
				WP_CLI::success( 'Nothing to repair.' );
			}
			return;
		}

		WP_CLI::log( 'Repairing storage paths (idempotent, copy-only)…' );
		$result = $service->run_to_completion( false );

		if ( ! empty( $result['dry_run'] ) || ( isset( $result['needs_repair'] ) && empty( $result['needs_repair'] ) ) ) {
			WP_CLI::success( 'Nothing to repair.' );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Repair done. Scanned: %d, absolute-path healed: %d, stranded thumbnails healed: %d, failed: %d.',
				(int) ( $result['scanned'] ?? 0 ),
				(int) ( $result['healed_absolute'] ?? 0 ),
				(int) ( $result['healed_stranded'] ?? 0 ),
				(int) ( $result['failed'] ?? 0 )
			)
		);
	}

	/**
	 * Report album/collection IDs that collide with media IDs.
	 *
	 * Albums store their attributes by calling MediaRepository::set() with their
	 * `wp_posts` ID. That column is AUTO_INCREMENT for real media, so the two ID
	 * sequences collide wherever the integers coincide — overwriting media data,
	 * inverting album access checks, and destroying a media row when the album
	 * is deleted.
	 *
	 * This command is READ-ONLY. It never writes. Run it before upgrading so you
	 * know what a site actually has.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format for the detail tables.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs diagnose_cpt_ids
	 *     wp mvs diagnose_cpt_ids --format=json
	 *
	 * @since 2.4.0
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function diagnose_cpt_ids( $args, $assoc_args ) {
		unset( $args );

		$format  = (string) Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$service = new \WPMediaVerse\Services\CptIdCollisionService();
		$report  = $service->analyze();

		$totals = $report['totals'];

		WP_CLI::log( '' );
		WP_CLI::log( '── Totals ────────────────────────────────────────────' );
		Utils\format_items(
			'table',
			array(
				array(
					'Metric' => 'Album + collection posts',
					'Count'  => $totals['cpt_posts'],
				),
				array(
					'Metric' => '…with an mvs_media_index row',
					'Count'  => $totals['cpt_indexed'],
				),
				array(
					'Metric' => '…COLLIDING with a real media row',
					'Count'  => $totals['collisions'],
				),
				array(
					'Metric' => '…clean (attribute-only rows)',
					'Count'  => $totals['privacy_only'],
				),
				array(
					'Metric' => 'Real media rows',
					'Count'  => $totals['real_media_rows'],
				),
			),
			array( 'Metric', 'Count' )
		);

		$forecast = $report['forecast'];

		WP_CLI::log( '' );
		WP_CLI::log( '── Forecast — will the NEXT album corrupt something? ──' );
		Utils\format_items(
			'table',
			array(
				array(
					'Metric' => 'Next wp_posts ID',
					'Value'  => $forecast['next_post_id'],
				),
				array(
					'Metric' => 'Next mvs_media_index ID',
					'Value'  => $forecast['next_media_id'],
				),
				array(
					'Metric' => 'Post IDs still behind media IDs',
					'Value'  => $forecast['window'],
				),
				array(
					'Metric' => '…of those, already used by media',
					'Value'  => $forecast['occupied'],
				),
				array(
					'Metric' => 'Chance the next album collides',
					'Value'  => $forecast['risk_percent'] . '%',
				),
			),
			array( 'Metric', 'Value' )
		);
		WP_CLI::log( '   ' . $forecast['verdict'] );

		$this->report_section(
			'Collisions — a CPT and a media item share one index row',
			$report['collisions'],
			array( 'cpt_id', 'post_type', 'cpt_title', 'cpt_author', 'media_title', 'media_author', 'privacy', 'index_slug' ),
			$format
		);

		$this->report_section(
			'DATA LOSS RISK — deleting these CPTs destroys the listed media',
			$report['purge_risk'],
			array( 'cpt_id', 'post_type', 'cpt_title', 'media_at_risk', 'media_author', 'file_path' ),
			$format
		);

		$this->report_section(
			'Slug overwritten — the media item now carries the CPT slug',
			$report['slug_overwrites'],
			array( 'cpt_id', 'cpt_title', 'cpt_slug', 'media_title', 'index_slug' ),
			$format
		);

		$this->report_section(
			'Attribute-only rows — safe to migrate to post meta',
			$report['privacy_only'],
			array( 'cpt_id', 'post_type', 'cpt_title', 'privacy', 'index_slug' ),
			$format
		);

		$this->report_section(
			'mvs_media_meta rows keyed by a CPT post ID',
			$report['meta_rows'],
			array( 'cpt_id', 'post_type', 'meta_key', 'meta_value', 'shares_with_media' ),
			$format
		);

		$this->report_section(
			'Taxonomy object_id spread',
			$report['taxonomy'],
			array( 'taxonomy', 'relationships', 'on_cpt_post', 'on_media', 'ambiguous' ),
			$format
		);

		WP_CLI::log( '' );

		if ( $totals['collisions'] > 0 ) {
			WP_CLI::warning(
				sprintf(
					'%d collision(s) found. Do NOT permanently delete the listed albums/collections until the fix ships — see plan/2026-08-08-cpt-id-collision-fix-plan.md.',
					$totals['collisions']
				)
			);
			return;
		}

		WP_CLI::success( 'No collisions on this site.' );
	}

	/**
	 * Print one titled section of the collision report.
	 *
	 * @since 2.4.0
	 *
	 * @param string $title  Section heading.
	 * @param array  $rows   Result rows.
	 * @param array  $fields Column order.
	 * @param string $format Output format.
	 * @return void
	 */
	private function report_section( string $title, array $rows, array $fields, string $format ): void {
		WP_CLI::log( '' );
		WP_CLI::log( '── ' . $title . ' ──' );

		if ( empty( $rows ) ) {
			WP_CLI::log( '   none' );
			return;
		}

		Utils\format_items( $format, $rows, $fields );
	}
}
