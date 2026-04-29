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

use WPMediaVerse\Repository\MediaRepository;

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

		$is_playlist = 'playlist' === MediaRepository::get( $album_id, 'album_type' );

		$max_pos = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(position) FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d",
				$album_id
			)
		);

		$added = 0;
		foreach ( $media_ids as $media_id ) {
			$media_id = (int) $media_id;

			if ( ! MediaRepository::exists( $media_id ) ) {
				continue;
			}

			// Playlist albums only accept audio media.
			if ( $is_playlist ) {
				$file_type = MediaRepository::get( $media_id, 'file_type' );
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
				MediaRepository::set( (int) $mid, 'album_id', $album_id );
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
	 * Post meta key storing the user-chosen cover media ID for an album.
	 * When unset, get_cover_url falls back to the first image item.
	 */
	const COVER_META_KEY = '_mvs_album_cover_media_id';

	/**
	 * Set the cover image for an album.
	 *
	 * @param int $album_id Album post ID.
	 * @param int $media_id Media post ID to use as cover. Pass 0 to clear.
	 * @return true|\WP_Error True on success, WP_Error with surfaced reason otherwise.
	 */
	public function set_cover( int $album_id, int $media_id ) {
		if ( $album_id <= 0 || 'mvs_album' !== get_post_type( $album_id ) ) {
			return new \WP_Error( 'mvs_album_not_found', __( 'Album not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// 0 clears the pinned cover so get_cover_url falls back to first-image.
		if ( 0 === $media_id ) {
			delete_post_meta( $album_id, self::COVER_META_KEY );
			return true;
		}

		// Reject cover requests for media that doesn't exist at all.
		if ( ! MediaRepository::exists( $media_id ) ) {
			return new \WP_Error(
				'mvs_media_not_found',
				__( 'Media item not found.', 'wpmediaverse' ),
				array( 'status' => 404 )
			);
		}

		// Only image-type media makes sense as a cover. Validate before any mutation.
		$file_type = MediaRepository::get( $media_id, 'file_type' );
		if ( ! is_string( $file_type ) || 0 !== strpos( $file_type, 'image/' ) ) {
			return new \WP_Error(
				'mvs_cover_not_image',
				__( 'Cover image must be an image (not video or audio).', 'wpmediaverse' ),
				array( 'status' => 400 )
			);
		}

		// If the media is not yet in this album, add it atomically. This removes
		// the client-side ordering dance (add_items before /cover) that caused
		// FAB and dashboard edit flows to 400 when choosing a new cover.
		if ( ! $this->is_item_in_album( $album_id, $media_id ) ) {
			$this->add_items( $album_id, array( $media_id ) );
			if ( ! $this->is_item_in_album( $album_id, $media_id ) ) {
				return new \WP_Error(
					'mvs_cover_add_failed',
					__( 'Could not add the selected media to this album (the album type may not accept it).', 'wpmediaverse' ),
					array( 'status' => 409 )
				);
			}
		}

		update_post_meta( $album_id, self::COVER_META_KEY, $media_id );

		/**
		 * Fires after an album's cover image is explicitly set.
		 *
		 * @param int $album_id Album post ID.
		 * @param int $media_id Media ID now pinned as cover.
		 */
		do_action( 'mvs_album_cover_set', $album_id, $media_id );

		return true;
	}

	/**
	 * Get the resolved cover media ID for an album (pinned, falling back to first image).
	 *
	 * Use this when the caller wants the cover URL regardless of whether it
	 * was explicitly pinned (e.g. signed-URL routing for any album cover). For
	 * the explicit pinned-only value, use get_cover_media_id() instead.
	 *
	 * @param int $album_id Album post ID.
	 * @return int|null Media ID or null.
	 */
	public function get_resolved_cover_media_id( int $album_id ): ?int {
		return $this->get_first_image_item( $album_id );
	}

	/**
	 * Get the cover image URL for an album.
	 *
	 * Resolution order: pinned cover → first image item → first item of any
	 * type → null. Stale pinned meta is cleaned on read.
	 *
	 * @param int    $album_id Album post ID.
	 * @param string $size     Image size key (e.g. 'large', 'thumbnail').
	 * @return string|null Cover URL, or null when the album has no renderable media.
	 */
	public function get_cover_url( int $album_id, string $size = 'large' ): ?string {
		$pinned_id = (int) get_post_meta( $album_id, self::COVER_META_KEY, true );
		if ( $pinned_id && $this->is_item_in_album( $album_id, $pinned_id ) ) {
			$url = $this->resolve_media_image_url( $pinned_id, $size );
			if ( $url ) {
				return $url;
			}
		}

		if ( $pinned_id ) {
			delete_post_meta( $album_id, self::COVER_META_KEY );
		}

		$first_media_id = $this->get_first_image_item( $album_id );
		if ( $first_media_id ) {
			return $this->resolve_media_image_url( $first_media_id, $size );
		}

		return null;
	}

	/**
	 * Get the pinned cover media ID, or 0 if none/invalid.
	 *
	 * Returns the explicit pin only — never falls back to the first item.
	 * Use this when callers need to distinguish between an explicitly pinned
	 * cover and an auto-selected one (e.g. the edit modal highlighting the
	 * current cover thumbnail, the REST `cover_media_id` field consumed by
	 * the dashboard block). For the resolved cover (pinned or first item),
	 * use get_resolved_cover_media_id() instead.
	 *
	 * @param int $album_id Album post ID.
	 * @return int Media ID of the pinned cover, or 0 when no valid pin exists.
	 */
	public function get_cover_media_id( int $album_id ): int {
		$pinned_id = (int) get_post_meta( $album_id, self::COVER_META_KEY, true );
		if ( $pinned_id && $this->is_item_in_album( $album_id, $pinned_id ) ) {
			return $pinned_id;
		}
		return 0;
	}

	/**
	 * Resolve a single media ID to its thumbnail URL, with an image-file fallback.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $size     Image size.
	 * @return string|null
	 */
	private function resolve_media_image_url( int $media_id, string $size ): ?string {
		$thumb = \WPMediaVerse\Core\TemplateHelpers::get_thumb_url( $media_id, $size );
		if ( $thumb ) {
			return $thumb;
		}

		$file_url  = MediaRepository::get( $media_id, 'file_url' );
		$file_type = MediaRepository::get( $media_id, 'file_type' );
		if ( $file_url && is_string( $file_type ) && 0 === strpos( $file_type, 'image/' ) ) {
			return set_url_scheme( $file_url );
		}

		return null;
	}

	/**
	 * Check whether a given media ID is part of an album's items table.
	 *
	 * @param int $album_id Album post ID.
	 * @param int $media_id Media ID.
	 */
	private function is_item_in_album( int $album_id, int $media_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d AND media_id = %d",
				$album_id,
				$media_id
			)
		) > 0;
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
