<?php
/**
 * Album service.
 *
 * Manages album items, ordering, and cover images.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Manages album item membership, ordering, and metadata.
 */
class AlbumService {

	/**
	 * Get items for an album, ordered by position.
	 *
	 * @param int $album_id Album post ID.
	 * @return array Array of items with media_id and position.
	 */
	public function get_items( int $album_id ): array {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id, position FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d ORDER BY position ASC",
				$album_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get the item count for an album.
	 *
	 * @param int $album_id Album post ID.
	 * @return int
	 */
	public function get_item_count( int $album_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d",
				$album_id
			)
		);
	}

	/**
	 * Add media items to an album.
	 *
	 * Validates post type and enforces audio-only for playlist albums.
	 *
	 * @param int   $album_id  Album post ID.
	 * @param int[] $media_ids Array of media post IDs.
	 * @return int Number of items successfully added.
	 */
	public function add_items( int $album_id, array $media_ids ): int {
		global $wpdb;

		$is_playlist = 'playlist' === MediaMeta::get( $album_id, 'album_type' );

		$max_pos = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(position) FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d",
				$album_id
			)
		);

		$added = 0;
		foreach ( $media_ids as $media_id ) {
			$media_id = (int) $media_id;

			if ( ! MediaMeta::exists( $media_id ) ) {
				continue;
			}

			// Playlist albums only accept audio media.
			if ( $is_playlist ) {
				$file_type = MediaMeta::get( $media_id, 'file_type' );
				if ( $file_type && 0 !== strpos( $file_type, 'audio/' ) ) {
					continue;
				}
			}

			++$max_pos;
			$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_album_items',
				array(
					'album_id' => $album_id,
					'media_id' => $media_id,
					'position' => $max_pos,
					'added_at' => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%d', '%s' )
			);

			if ( false !== $result ) {
				++$added;
			}
		}

		// Store album association on each media item.
		if ( $added > 0 ) {
			foreach ( $media_ids as $mid ) {
				MediaMeta::set( (int) $mid, 'album_id', $album_id );
			}

			/**
			 * Fires after media items are added to an album.
			 *
			 * @param int   $album_id  Album post ID.
			 * @param array $media_ids Media post IDs that were added.
			 * @param int   $added     Number of items successfully added.
			 */
			do_action( 'mvs_album_items_added', $album_id, $media_ids, $added );
		}

		// Auto-set cover if album has no cover yet.
		if ( $added > 0 && ! has_post_thumbnail( $album_id ) ) {
			$first_media = (int) $media_ids[0];
			$thumb_id    = (int) MediaMeta::get( $first_media, 'attachment_id' );
			if ( $thumb_id ) {
				set_post_thumbnail( $album_id, $thumb_id );
			}
		}

		return $added;
	}

	/**
	 * Remove a media item from an album.
	 *
	 * @param int $album_id Album post ID.
	 * @param int $media_id Media post ID.
	 * @return bool True if removed, false if not found.
	 */
	public function remove_item( int $album_id, int $media_id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_album_items',
			array(
				'album_id' => $album_id,
				'media_id' => $media_id,
			),
			array( '%d', '%d' )
		);

		return $deleted > 0;
	}

	/**
	 * Reorder album items.
	 *
	 * @param int   $album_id Album post ID.
	 * @param int[] $order    Ordered array of media IDs.
	 */
	public function reorder( int $album_id, array $order ): void {
		global $wpdb;

		foreach ( $order as $position => $media_id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_album_items',
				array( 'position' => (int) $position ),
				array(
					'album_id' => $album_id,
					'media_id' => (int) $media_id,
				),
				array( '%d' ),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Set the cover image for an album.
	 *
	 * @param int $album_id Album post ID.
	 * @param int $media_id Media post ID to use as cover.
	 * @return bool True on success.
	 */
	public function set_cover( int $album_id, int $media_id ): bool {
		$thumb_id = (int) MediaMeta::get( $media_id, 'attachment_id' );
		if ( ! $thumb_id ) {
			return false;
		}

		return (bool) set_post_thumbnail( $album_id, $thumb_id );
	}

	/**
	 * Get the cover image URL for an album.
	 *
	 * @param int    $album_id Album post ID.
	 * @param string $size     Image size.
	 * @return string|null Cover URL or null.
	 */
	public function get_cover_url( int $album_id, string $size = 'medium' ): ?string {
		// Use the first album item's thumbnail as cover.
		$first_media_id = $this->get_first_image_item( $album_id );
		if ( $first_media_id ) {
			$thumb = \WPMediaVerse\Core\TemplateHelpers::get_thumb_url( $first_media_id, $size );
			if ( $thumb ) {
				return $thumb;
			}
			// Fallback: if it's an image, use file_url directly.
			$file_url  = MediaMeta::get( $first_media_id, 'file_url' );
			$file_type = MediaMeta::get( $first_media_id, 'file_type' );
			if ( $file_url && is_string( $file_type ) && strpos( $file_type, 'image/' ) === 0 ) {
				return set_url_scheme( $file_url );
			}
		}

		return null;
	}

	/**
	 * Get the first image-type media item in an album.
	 *
	 * Falls back to the first item of any type if no images exist.
	 *
	 * @param int $album_id Album post ID.
	 * @return int|null Media post ID or null.
	 */
	private function get_first_image_item( int $album_id ): ?int {
		global $wpdb;

		$index_table = $wpdb->prefix . 'mvs_media_index';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$media_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ai.media_id
				FROM {$wpdb->prefix}mvs_album_items ai
				INNER JOIN {$index_table} idx ON idx.media_id = ai.media_id
				WHERE ai.album_id = %d AND idx.file_type LIKE %s
				ORDER BY ai.position ASC
				LIMIT 1",
				$album_id,
				'image/%'
			)
		);

		// If no image item, fall back to just the first item (for video thumbnails etc).
		if ( ! $media_id ) {
			$media_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d ORDER BY position ASC LIMIT 1",
					$album_id
				)
			);
		}
		// phpcs:enable

		return $media_id ? (int) $media_id : null;
	}

	/**
	 * Delete all items for an album (cascade).
	 *
	 * @param int $album_id Album post ID.
	 * @return int Number of rows deleted.
	 */
	public function delete_all_items( int $album_id ): int {
		global $wpdb;

		return (int) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_album_items',
			array( 'album_id' => $album_id ),
			array( '%d' )
		);
	}
}
