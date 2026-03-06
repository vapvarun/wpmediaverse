<?php
/**
 * rtMedia import command.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\CLI;

defined( 'ABSPATH' ) || exit;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Import media from rtMedia into WPMediaVerse.
 */
class ImportRtMedia {

	/**
	 * Import media from rtMedia.
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
	 * ## EXAMPLES
	 *
	 *     wp mvs import-rtmedia
	 *     wp mvs import-rtmedia --dry-run
	 *     wp mvs import-rtmedia --batch-size=100
	 *     wp mvs import-rtmedia --offset=500
	 *
	 * @subcommand import-rtmedia
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;

		// Check if rtMedia table exists.
		$rtm_table = $wpdb->prefix . 'rt_rtm_media';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $rtm_table )
		);

		if ( ! $table_exists ) {
			WP_CLI::error( 'rtMedia tables not found. Is rtMedia installed and activated?' );
		}

		$batch_size  = (int) Utils\get_flag_value( $assoc_args, 'batch-size', 50 );
		$dry_run     = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$skip_albums = (bool) Utils\get_flag_value( $assoc_args, 'skip-albums', false );
		$offset      = (int) Utils\get_flag_value( $assoc_args, 'offset', 0 );

		// Count total rtMedia items.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rtm_table}" );

		if ( 0 === $total ) {
			WP_CLI::warning( 'No rtMedia items found.' );
			return;
		}

		WP_CLI::log( "Found {$total} rtMedia items to import." );

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run — no changes will be made.' );
		}

		$remaining = max( 0, $total - $offset );
		$progress  = Utils\make_progress_bar( 'Importing media', $remaining );
		$imported  = 0;
		$skipped   = 0;
		$errors    = 0;
		$album_map = array();

		$current_offset = $offset;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$rtm_table} ORDER BY id ASC LIMIT %d OFFSET %d",
					$batch_size,
					$current_offset
				)
			);

			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $item ) {
				// Skip if this rtMedia record has already been imported.
				$existing = get_posts(
					array(
						'post_type'      => 'mvs_media',
						'meta_key'       => '_mvs_rtmedia_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value'     => $item->id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						'fields'         => 'ids',
						'posts_per_page' => 1,
					)
				);

				if ( ! empty( $existing ) ) {
					++$skipped;
					$progress->tick();
					continue;
				}

				if ( $dry_run ) {
					++$imported;
					$progress->tick();
					continue;
				}

				$result = $this->import_item( $item, $album_map, $skip_albums );

				if ( is_wp_error( $result ) ) {
					WP_CLI::warning(
						sprintf(
							'Failed to import rtMedia #%d: %s',
							(int) $item->id,
							$result->get_error_message()
						)
					);
					++$errors;
				} else {
					++$imported;

					// Update the album map if a new album was created.
					if ( ! empty( $item->album_id ) && ! $skip_albums && isset( $result['album_id'] ) ) {
						$album_map[ (int) $item->album_id ] = $result['album_id'];
					}
				}

				$progress->tick();
			}

			$current_offset += $batch_size;

		} while ( count( $items ) === $batch_size );

		$progress->finish();

		$action = $dry_run ? 'Would import' : 'Imported';
		WP_CLI::success(
			sprintf(
				'%s %d media item(s). Skipped %d (already imported). Errors: %d.',
				$action,
				$imported,
				$skipped,
				$errors
			)
		);
	}

	/**
	 * Import a single rtMedia item into WPMediaVerse.
	 *
	 * @since 1.0.0
	 *
	 * @param object $item        rtMedia row from rt_rtm_media.
	 * @param array  $album_map   Map of rtMedia album IDs to MVS album IDs (passed by reference).
	 * @param bool   $skip_albums Whether to skip album creation/assignment.
	 * @return array|\WP_Error On success, array with 'media_id' key (and optional 'album_id'). WP_Error on failure.
	 */
	private function import_item( object $item, array &$album_map, bool $skip_albums ) {
		// Verify the WP attachment exists and get its URL.
		$attachment_url = wp_get_attachment_url( (int) $item->media_id );
		if ( ! $attachment_url ) {
			return new \WP_Error(
				'no_attachment',
				sprintf(
					/* translators: %d: WordPress attachment ID. */
					__( 'WP attachment ID %d not found. The file may have been deleted.', 'wpmediaverse' ),
					(int) $item->media_id
				)
			);
		}

		// Map rtMedia media type to MVS media type.
		$type_map = array(
			'photo'    => 'image',
			'video'    => 'video',
			'music'    => 'audio',
			'document' => 'document',
			'other'    => 'document',
		);

		$rtm_type   = isset( $item->media_type ) ? $item->media_type : 'other';
		$media_type = isset( $type_map[ $rtm_type ] ) ? $type_map[ $rtm_type ] : 'document';

		// Map rtMedia privacy integers to MVS privacy strings.
		$privacy_map = array(
			0  => 'public',
			20 => 'loggedin',
			40 => 'friends',
			60 => 'private',
		);

		$rtm_privacy = isset( $item->privacy ) ? (int) $item->privacy : 0;
		$privacy     = isset( $privacy_map[ $rtm_privacy ] ) ? $privacy_map[ $rtm_privacy ] : 'public';

		// Derive a usable title.
		$post_title = ( ! empty( $item->media_title ) )
			? sanitize_text_field( $item->media_title )
			: __( 'Imported Media', 'wpmediaverse' );

		// Create the mvs_media post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => $post_title,
				'post_status' => 'publish',
				'post_author' => (int) $item->media_author,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Core meta.
		update_post_meta( $post_id, '_mvs_file_url', esc_url_raw( $attachment_url ) );
		update_post_meta( $post_id, '_mvs_media_type', $media_type );
		update_post_meta( $post_id, '_mvs_privacy', $privacy );

		// Store the originating rtMedia ID to prevent duplicate imports.
		update_post_meta( $post_id, '_mvs_rtmedia_id', (int) $item->id );

		// Store the original WP attachment ID for reference.
		update_post_meta( $post_id, '_mvs_attachment_id', (int) $item->media_id );

		// Set the WP attachment as the featured image for easy thumbnail access.
		set_post_thumbnail( $post_id, (int) $item->media_id );

		// MIME type from the WP attachment.
		$mime = get_post_mime_type( (int) $item->media_id );
		if ( $mime ) {
			update_post_meta( $post_id, '_mvs_file_type', sanitize_mime_type( $mime ) );
		}

		// File size directly from disk — avoids a meta lookup round-trip.
		$file_path = get_attached_file( (int) $item->media_id );
		if ( $file_path && file_exists( $file_path ) ) {
			update_post_meta( $post_id, '_mvs_file_size', (int) filesize( $file_path ) );
		}

		// Pull in any cover art stored in rtMedia (used for video thumbnails).
		if ( ! empty( $item->cover_art ) ) {
			update_post_meta( $post_id, '_mvs_cover_art', esc_url_raw( $item->cover_art ) );
		}

		// Store rtMedia context so the origin is traceable (e.g. "profile", "group").
		if ( ! empty( $item->context ) ) {
			update_post_meta( $post_id, '_mvs_rtmedia_context', sanitize_text_field( $item->context ) );
		}
		if ( ! empty( $item->context_id ) ) {
			update_post_meta( $post_id, '_mvs_rtmedia_context_id', (int) $item->context_id );
		}

		$result = array( 'media_id' => $post_id );

		// Handle album assignment.
		if ( ! $skip_albums && ! empty( $item->album_id ) && (int) $item->album_id > 0 ) {
			$rtm_album_id = (int) $item->album_id;

			if ( ! isset( $album_map[ $rtm_album_id ] ) ) {
				$mvs_album_id = $this->create_album_from_rtmedia( $rtm_album_id, (int) $item->media_author );
				if ( $mvs_album_id ) {
					$album_map[ $rtm_album_id ] = $mvs_album_id;
				}
			}

			if ( isset( $album_map[ $rtm_album_id ] ) ) {
				$this->add_to_album( $album_map[ $rtm_album_id ], $post_id );
				$result['album_id'] = $album_map[ $rtm_album_id ];
			}
		}

		return $result;
	}

	/**
	 * Create an MVS album from an rtMedia album record.
	 *
	 * @since 1.0.0
	 *
	 * @param int $rtmedia_album_id rtMedia album ID (the id column in rt_rtm_media).
	 * @param int $fallback_author  Author ID to use if the album row has no author.
	 * @return int|false MVS album post ID on success, false on failure.
	 */
	private function create_album_from_rtmedia( int $rtmedia_album_id, int $fallback_author ) {
		global $wpdb;

		$rtm_table = $wpdb->prefix . 'rt_rtm_media';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$album = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT media_title, media_author FROM {$rtm_table} WHERE id = %d",
				$rtmedia_album_id
			)
		);

		$title  = ( $album && ! empty( $album->media_title ) )
			? sanitize_text_field( $album->media_title )
			: __( 'Imported Album', 'wpmediaverse' );
		$author = ( $album && ! empty( $album->media_author ) ) ? (int) $album->media_author : $fallback_author;

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

		// Record originating rtMedia album ID to avoid duplicate album creation.
		update_post_meta( $album_id, '_mvs_rtmedia_album_id', $rtmedia_album_id );

		return $album_id;
	}

	/**
	 * Add a media item to an MVS album via the mvs_album_items table.
	 *
	 * @since 1.0.0
	 *
	 * @param int $album_id MVS album post ID.
	 * @param int $media_id MVS media post ID.
	 */
	private function add_to_album( int $album_id, int $media_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mvs_album_items';

		// Determine next position in the album.
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
