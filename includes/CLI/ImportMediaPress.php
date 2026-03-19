<?php
/**
 * MediaPress import command.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\CLI;

defined( 'ABSPATH' ) || exit;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Import media from MediaPress into WPMediaVerse.
 *
 * MediaPress stores media as a custom post type `mpp-media` with postmeta
 * for file URL, type, and privacy. Albums are the `mpp-gallery` CPT.
 */
class ImportMediaPress {

	/**
	 * Import media from MediaPress.
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
	 * : Skip gallery/album import.
	 *
	 * [--offset=<offset>]
	 * : Start from a specific offset (for resuming). Default 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mvs import-mediapress
	 *     wp mvs import-mediapress --dry-run
	 *     wp mvs import-mediapress --batch-size=100
	 *     wp mvs import-mediapress --offset=200
	 *
	 * @subcommand import-mediapress
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		// MediaPress uses the mpp-media CPT.
		$total = (int) wp_count_posts( 'mpp-media' )->publish;

		// Also count pending/private — anything importable.
		$counts   = wp_count_posts( 'mpp-media' );
		$total    = 0;
		$statuses = array( 'publish', 'private', 'pending', 'draft' );
		foreach ( $statuses as $status ) {
			if ( isset( $counts->$status ) ) {
				$total += (int) $counts->$status;
			}
		}

		if ( ! post_type_exists( 'mpp-media' ) ) {
			WP_CLI::error( 'MediaPress post types not found. Is MediaPress installed and activated?' );
		}

		if ( 0 === $total ) {
			WP_CLI::warning( 'No MediaPress media items found.' );
			return;
		}

		$batch_size  = (int) Utils\get_flag_value( $assoc_args, 'batch-size', 50 );
		$dry_run     = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$skip_albums = (bool) Utils\get_flag_value( $assoc_args, 'skip-albums', false );
		$offset      = (int) Utils\get_flag_value( $assoc_args, 'offset', 0 );

		WP_CLI::log( "Found {$total} MediaPress media items to import." );

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
			$items = get_posts(
				array(
					'post_type'      => 'mpp-media',
					'post_status'    => $statuses,
					'posts_per_page' => $batch_size,
					'offset'         => $current_offset,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'all',
				)
			);

			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $item ) {
				// Skip if already imported.
				$existing = get_posts(
					array(
						'post_type'      => 'mvs_media',
						'meta_key'       => '_mvs_mpp_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value'     => $item->ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
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
							'Failed to import MediaPress #%d (%s): %s',
							$item->ID,
							$item->post_title,
							$result->get_error_message()
						)
					);
					++$errors;
				} else {
					++$imported;

					if ( ! $skip_albums && isset( $result['album_id'] ) ) {
						$mpp_gallery_id = (int) get_post_meta( $item->ID, '_mpp_gallery_id', true );
						if ( $mpp_gallery_id ) {
							$album_map[ $mpp_gallery_id ] = $result['album_id'];
						}
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
	 * Import a single MediaPress media item into WPMediaVerse.
	 *
	 * MediaPress postmeta keys of interest:
	 *   _mpp_media_type   – photo, video, music, doc
	 *   _mpp_src          – full file URL (fallback: wp_get_attachment_url on the post itself)
	 *   _mpp_gallery_id   – parent gallery/album ID (mpp-gallery CPT post ID)
	 *   _mpp_privacy      – public, loggedin, friends, onlyme
	 *   _mpp_component    – buddypress context: members, groups
	 *   _mpp_component_id – BuddyPress user or group ID
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $item        MediaPress media post.
	 * @param array    $album_map   Map of MPP gallery IDs to MVS album IDs (by reference).
	 * @param bool     $skip_albums Whether to skip album creation/assignment.
	 * @return array|\WP_Error On success, array with 'media_id'. WP_Error on failure.
	 */
	private function import_item( \WP_Post $item, array &$album_map, bool $skip_albums ) {
		// MediaPress media items are themselves WP attachments (or have a linked attachment).
		// Try _mpp_src first, then fall back to wp_get_attachment_url( $item->ID ).
		$file_url = get_post_meta( $item->ID, '_mpp_src', true );
		if ( ! $file_url ) {
			$file_url = wp_get_attachment_url( $item->ID );
		}

		if ( ! $file_url ) {
			return new \WP_Error(
				'no_file_url',
				/* translators: %d: MediaPress media post ID. */
				sprintf( __( 'No file URL found for MediaPress media #%d.', 'wpmediaverse' ), $item->ID )
			);
		}

		// Map MediaPress media types to MVS media types.
		$mpp_type = get_post_meta( $item->ID, '_mpp_media_type', true );
		$type_map = array(
			'photo' => 'image',
			'video' => 'video',
			'music' => 'audio',
			'doc'   => 'document',
			'audio' => 'audio',
		);
		$media_type = isset( $type_map[ $mpp_type ] ) ? $type_map[ $mpp_type ] : 'document';

		// Map MediaPress privacy to MVS privacy.
		$mpp_privacy = get_post_meta( $item->ID, '_mpp_privacy', true );
		$privacy_map = array(
			'public'    => 'public',
			'loggedin'  => 'loggedin',
			'friends'   => 'friends',
			'onlyme'    => 'private',
		);
		$privacy = isset( $privacy_map[ $mpp_privacy ] ) ? $privacy_map[ $mpp_privacy ] : 'public';

		// If the MPP post status was private, force private privacy.
		if ( 'private' === $item->post_status ) {
			$privacy = 'private';
		}

		// Build the mvs_media post.
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'mvs_media',
				'post_title'   => $item->post_title ?: __( 'Imported Media', 'wpmediaverse' ),
				'post_content' => $item->post_content,
				'post_status'  => 'publish',
				'post_author'  => $item->post_author,
				'post_date'    => $item->post_date,
				'post_date_gmt' => $item->post_date_gmt,
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

		// Track origin to prevent duplicate imports.
		update_post_meta( $post_id, '_mvs_mpp_id', $item->ID );

		// Reuse the attachment ID for thumbnails.
		$attach_id = (int) get_post_meta( $item->ID, '_mpp_attachment_id', true );
		if ( ! $attach_id && wp_attachment_is_image( $item->ID ) ) {
			$attach_id = $item->ID;
		}
		if ( $attach_id ) {
			update_post_meta( $post_id, '_mvs_attachment_id', $attach_id );
			set_post_thumbnail( $post_id, $attach_id );
		}

		// MIME type.
		$mime = get_post_mime_type( $item->ID );
		if ( ! $mime ) {
			$mime = get_post_meta( $item->ID, '_mpp_mime_type', true );
		}
		if ( $mime ) {
			update_post_meta( $post_id, '_mvs_file_type', sanitize_mime_type( $mime ) );
		}

		// File size.
		$file_path = get_attached_file( $item->ID );
		if ( $file_path && file_exists( $file_path ) ) {
			update_post_meta( $post_id, '_mvs_file_size', (int) filesize( $file_path ) );
		}

		// BuddyPress component context (members / groups).
		$mpp_component    = get_post_meta( $item->ID, '_mpp_component', true );
		$mpp_component_id = get_post_meta( $item->ID, '_mpp_component_id', true );
		if ( $mpp_component ) {
			update_post_meta( $post_id, '_mvs_mpp_component', sanitize_text_field( $mpp_component ) );
		}
		if ( $mpp_component_id ) {
			update_post_meta( $post_id, '_mvs_mpp_component_id', (int) $mpp_component_id );
		}

		$result = array( 'media_id' => $post_id );

		// Album assignment.
		if ( ! $skip_albums ) {
			$mpp_gallery_id = (int) get_post_meta( $item->ID, '_mpp_gallery_id', true );

			if ( $mpp_gallery_id > 0 ) {
				if ( ! isset( $album_map[ $mpp_gallery_id ] ) ) {
					$mvs_album_id = $this->create_album_from_mpp_gallery( $mpp_gallery_id, (int) $item->post_author );
					if ( $mvs_album_id ) {
						$album_map[ $mpp_gallery_id ] = $mvs_album_id;
					}
				}

				if ( isset( $album_map[ $mpp_gallery_id ] ) ) {
					$this->add_to_album( $album_map[ $mpp_gallery_id ], $post_id );
					$result['album_id'] = $album_map[ $mpp_gallery_id ];
				}
			}
		}

		return $result;
	}

	/**
	 * Create an MVS album from a MediaPress gallery post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $mpp_gallery_id MediaPress gallery (mpp-gallery) post ID.
	 * @param int $fallback_author Author ID to use if gallery has none.
	 * @return int|false MVS album post ID on success, false on failure.
	 */
	private function create_album_from_mpp_gallery( int $mpp_gallery_id, int $fallback_author ) {
		$gallery = get_post( $mpp_gallery_id );

		$title  = ( $gallery && $gallery->post_title ) ? sanitize_text_field( $gallery->post_title ) : __( 'Imported Album', 'wpmediaverse' );
		$author = ( $gallery && $gallery->post_author ) ? (int) $gallery->post_author : $fallback_author;

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

		update_post_meta( $album_id, '_mvs_mpp_gallery_id', $mpp_gallery_id );

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

		$table   = $wpdb->prefix . 'mvs_album_items';
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
