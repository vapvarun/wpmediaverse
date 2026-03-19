<?php
/**
 * BuddyBoss media import command.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\CLI;

defined( 'ABSPATH' ) || exit;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Import media from BuddyBoss Platform into WPMediaVerse.
 *
 * BuddyBoss stores media in three custom DB tables:
 *   {prefix}bp_media           – photos/videos uploaded to activity, profiles, groups
 *   {prefix}bp_document        – documents uploaded via BuddyBoss
 *   {prefix}bp_video           – videos (separate from bp_media in newer BB versions)
 *
 * Each row references a WordPress attachment ID (`attachment_id`) which
 * is the canonical file source. Albums are stored in `bp_media_albums`.
 */
class ImportBuddyBoss {

	/**
	 * Import media from BuddyBoss Platform.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<size>]
	 * : Number of items to process per batch. Default 50.
	 *
	 * [--dry-run]
	 * : Show what would be imported without actually importing.
	 *
	 * [--skip-albums]
	 * : Skip album import.
	 *
	 * [--offset=<offset>]
	 * : Start from a specific offset (for resuming). Default 0.
	 *
	 * [--source=<source>]
	 * : Which BuddyBoss table to import from: media, document, video, or all. Default all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs import-buddyboss
	 *     wp mvs import-buddyboss --dry-run
	 *     wp mvs import-buddyboss --source=media
	 *     wp mvs import-buddyboss --source=document --batch-size=100
	 *
	 * @subcommand import-buddyboss
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;

		$batch_size  = (int) Utils\get_flag_value( $assoc_args, 'batch-size', 50 );
		$dry_run     = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$skip_albums = (bool) Utils\get_flag_value( $assoc_args, 'skip-albums', false );
		$offset      = (int) Utils\get_flag_value( $assoc_args, 'offset', 0 );
		$source      = sanitize_key( Utils\get_flag_value( $assoc_args, 'source', 'all' ) );

		// Build the list of tables to import from.
		$sources = $this->resolve_sources( $source, $wpdb );
		if ( empty( $sources ) ) {
			WP_CLI::error( 'No BuddyBoss media tables found. Is BuddyBoss Platform installed and activated?' );
		}

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run — no changes will be made.' );
		}

		$total_imported = 0;
		$total_skipped  = 0;
		$total_errors   = 0;
		$album_map      = array();

		foreach ( $sources as $table_key => $table_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

			if ( 0 === $total ) {
				WP_CLI::log( "No items in {$table_name}, skipping." );
				continue;
			}

			WP_CLI::log( "Importing {$total} item(s) from {$table_name}..." );

			$remaining      = max( 0, $total - $offset );
			$progress       = Utils\make_progress_bar( "Importing from {$table_key}", $remaining );
			$current_offset = $offset;

			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$items = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$table_name} ORDER BY id ASC LIMIT %d OFFSET %d",
						$batch_size,
						$current_offset
					)
				);

				if ( empty( $items ) ) {
					break;
				}

				foreach ( $items as $item ) {
					$meta_key = '_mvs_bb_' . $table_key . '_id';

					$existing = get_posts(
						array(
							'post_type'      => 'mvs_media',
							'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
							'meta_value'     => $item->id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
							'fields'         => 'ids',
							'posts_per_page' => 1,
						)
					);

					if ( ! empty( $existing ) ) {
						++$total_skipped;
						$progress->tick();
						continue;
					}

					if ( $dry_run ) {
						++$total_imported;
						$progress->tick();
						continue;
					}

					$result = $this->import_item( $item, $table_key, $meta_key, $album_map, $skip_albums );

					if ( is_wp_error( $result ) ) {
						WP_CLI::warning(
							sprintf(
								'Failed to import BuddyBoss %s #%d: %s',
								$table_key,
								(int) $item->id,
								$result->get_error_message()
							)
						);
						++$total_errors;
					} else {
						++$total_imported;
					}

					$progress->tick();
				}

				$current_offset += $batch_size;

			} while ( count( $items ) === $batch_size );

			$progress->finish();
		}

		$action = $dry_run ? 'Would import' : 'Imported';
		WP_CLI::success(
			sprintf(
				'%s %d media item(s). Skipped %d (already imported). Errors: %d.',
				$action,
				$total_imported,
				$total_skipped,
				$total_errors
			)
		);
	}

	/**
	 * Resolve which BuddyBoss tables exist and should be imported.
	 *
	 * @since 1.0.0
	 *
	 * @param string  $source One of: all, media, document, video.
	 * @param \wpdb   $wpdb   WordPress database object.
	 * @return array Associative array of [ table_key => full_table_name ] for existing tables.
	 */
	private function resolve_sources( string $source, \wpdb $wpdb ): array {
		$candidates = array(
			'media'    => $wpdb->prefix . 'bp_media',
			'document' => $wpdb->prefix . 'bp_document',
			'video'    => $wpdb->prefix . 'bp_video',
		);

		if ( 'all' !== $source ) {
			if ( ! isset( $candidates[ $source ] ) ) {
				WP_CLI::error( "Unknown source '{$source}'. Use: media, document, video, or all." );
			}
			$candidates = array( $source => $candidates[ $source ] );
		}

		$found = array();
		foreach ( $candidates as $key => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			);
			if ( $exists ) {
				$found[ $key ] = $table;
			}
		}

		return $found;
	}

	/**
	 * Import a single BuddyBoss media row into WPMediaVerse.
	 *
	 * BuddyBoss table columns (common across bp_media, bp_document, bp_video):
	 *   id, blog_id, attachment_id, user_id, title, album_id, group_id,
	 *   privacy, menu_order, date_created, status, activity_id, type
	 *
	 * @since 1.0.0
	 *
	 * @param object $item        Row from a BuddyBoss media table.
	 * @param string $table_key   One of: media, document, video.
	 * @param string $meta_key    Postmeta key used to store origin ID (_mvs_bb_{key}_id).
	 * @param array  $album_map   Map of BB album IDs to MVS album IDs (by reference).
	 * @param bool   $skip_albums Whether to skip album creation/assignment.
	 * @return array|\WP_Error On success, array with 'media_id'. WP_Error on failure.
	 */
	private function import_item( object $item, string $table_key, string $meta_key, array &$album_map, bool $skip_albums ) {
		$attach_id = isset( $item->attachment_id ) ? (int) $item->attachment_id : 0;

		if ( ! $attach_id ) {
			return new \WP_Error(
				'no_attachment',
				/* translators: 1: table key, 2: row ID. */
				sprintf( __( 'BuddyBoss %1$s #%2$d has no attachment_id.', 'wpmediaverse' ), $table_key, (int) $item->id )
			);
		}

		$file_url = wp_get_attachment_url( $attach_id );
		if ( ! $file_url ) {
			return new \WP_Error(
				'missing_attachment',
				/* translators: %d: WordPress attachment ID. */
				sprintf( __( 'WP attachment #%d not found. The file may have been deleted.', 'wpmediaverse' ), $attach_id )
			);
		}

		// Determine media type from table context and MIME type.
		$mime       = get_post_mime_type( $attach_id );
		$media_type = $this->resolve_media_type( $table_key, $mime );

		// BuddyBoss privacy values: public, loggedin, onlyme, friends, groupmembers.
		$bb_privacy  = isset( $item->privacy ) ? $item->privacy : 'public';
		$privacy_map = array(
			'public'       => 'public',
			'loggedin'     => 'loggedin',
			'onlyme'       => 'private',
			'friends'      => 'friends',
			'groupmembers' => 'loggedin',
		);
		$privacy = isset( $privacy_map[ $bb_privacy ] ) ? $privacy_map[ $bb_privacy ] : 'public';

		$title = ! empty( $item->title )
			? sanitize_text_field( $item->title )
			: __( 'Imported Media', 'wpmediaverse' );

		// Normalise the created-at date — BB stores as UTC string.
		$post_date_gmt = ! empty( $item->date_created )
			? gmdate( 'Y-m-d H:i:s', strtotime( $item->date_created ) )
			: current_time( 'mysql', true );
		$post_date     = get_date_from_gmt( $post_date_gmt );

		$post_id = wp_insert_post(
			array(
				'post_type'     => 'mvs_media',
				'post_title'    => $title,
				'post_status'   => 'publish',
				'post_author'   => isset( $item->user_id ) ? (int) $item->user_id : 0,
				'post_date'     => $post_date,
				'post_date_gmt' => $post_date_gmt,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Core meta.
		update_post_meta( $post_id, '_mvs_file_url', esc_url_raw( $file_url ) );
		update_post_meta( $post_id, '_mvs_media_type', $media_type );
		update_post_meta( $post_id, '_mvs_privacy', $privacy );
		update_post_meta( $post_id, '_mvs_attachment_id', $attach_id );

		// Track origin.
		update_post_meta( $post_id, $meta_key, (int) $item->id );

		// Set featured image for thumbnails.
		set_post_thumbnail( $post_id, $attach_id );

		if ( $mime ) {
			update_post_meta( $post_id, '_mvs_file_type', sanitize_mime_type( $mime ) );
		}

		$file_path = get_attached_file( $attach_id );
		if ( $file_path && file_exists( $file_path ) ) {
			update_post_meta( $post_id, '_mvs_file_size', (int) filesize( $file_path ) );
		}

		// BuddyBoss group context.
		if ( ! empty( $item->group_id ) && (int) $item->group_id > 0 ) {
			update_post_meta( $post_id, '_mvs_bb_group_id', (int) $item->group_id );
		}

		// BuddyBoss activity context.
		if ( ! empty( $item->activity_id ) && (int) $item->activity_id > 0 ) {
			update_post_meta( $post_id, '_mvs_bb_activity_id', (int) $item->activity_id );
		}

		$result = array( 'media_id' => $post_id );

		// Album assignment.
		if ( ! $skip_albums && ! empty( $item->album_id ) && (int) $item->album_id > 0 ) {
			$bb_album_id = (int) $item->album_id;

			if ( ! isset( $album_map[ $bb_album_id ] ) ) {
				$mvs_album_id = $this->create_album_from_bb( $bb_album_id, isset( $item->user_id ) ? (int) $item->user_id : 0 );
				if ( $mvs_album_id ) {
					$album_map[ $bb_album_id ] = $mvs_album_id;
				}
			}

			if ( isset( $album_map[ $bb_album_id ] ) ) {
				$this->add_to_album( $album_map[ $bb_album_id ], $post_id );
				$result['album_id'] = $album_map[ $bb_album_id ];
			}
		}

		return $result;
	}

	/**
	 * Determine the MVS media type from BuddyBoss table context and MIME type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $table_key One of: media, document, video.
	 * @param string $mime      MIME type of the WP attachment.
	 * @return string One of: image, video, audio, document.
	 */
	private function resolve_media_type( string $table_key, string $mime ): string {
		if ( 'document' === $table_key ) {
			return 'document';
		}

		if ( 'video' === $table_key ) {
			return 'video';
		}

		// For bp_media rows, derive from MIME type.
		if ( $mime ) {
			if ( str_starts_with( $mime, 'image/' ) ) {
				return 'image';
			}
			if ( str_starts_with( $mime, 'video/' ) ) {
				return 'video';
			}
			if ( str_starts_with( $mime, 'audio/' ) ) {
				return 'audio';
			}
		}

		return 'document';
	}

	/**
	 * Create an MVS album from a BuddyBoss album record.
	 *
	 * BuddyBoss stores albums in the `{prefix}bp_media_albums` table with
	 * columns: id, user_id, group_id, title, privacy, date_created.
	 *
	 * @since 1.0.0
	 *
	 * @param int $bb_album_id     BuddyBoss album ID.
	 * @param int $fallback_author Author ID if the album row cannot be found.
	 * @return int|false MVS album post ID on success, false on failure.
	 */
	private function create_album_from_bb( int $bb_album_id, int $fallback_author ) {
		global $wpdb;

		$albums_table = $wpdb->prefix . 'bp_media_albums';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $albums_table )
		);

		$title  = __( 'Imported Album', 'wpmediaverse' );
		$author = $fallback_author;

		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$album = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT title, user_id FROM {$albums_table} WHERE id = %d",
					$bb_album_id
				)
			);

			if ( $album ) {
				if ( ! empty( $album->title ) ) {
					$title = sanitize_text_field( $album->title );
				}
				if ( ! empty( $album->user_id ) ) {
					$author = (int) $album->user_id;
				}
			}
		}

		$album_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_album',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_author' => $author,
			),
			true
		);

		if ( is_wp_error( $album_id ) ) {
			return false;
		}

		update_post_meta( $album_id, '_mvs_bb_album_id', $bb_album_id );

		return $album_id;
	}

	/**
	 * Add a media item to an MVS album.
	 *
	 * @since 1.0.0
	 *
	 * @param int $album_id MVS album post ID.
	 * @param int $media_id MVS media post ID.
	 */
	private function add_to_album( int $album_id, int $media_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mvs_album_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max_pos = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(position) FROM {$table} WHERE album_id = %d",
				$album_id
			)
		);

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'album_id' => $album_id,
				'media_id' => $media_id,
				'position' => $max_pos + 1,
				'added_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s' )
		);
	}
}
