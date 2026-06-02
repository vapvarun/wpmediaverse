<?php
/**
 * Favorite service.
 *
 * Manages user favorites/bookmarks with collection support.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Manages user favorites/bookmarks with optional collection assignment.
 */
class FavoriteService {

	/**
	 * Toggle a favorite on a media item (idempotent).
	 *
	 * If already favorited, unfavorite. If not favorited, add favorite.
	 *
	 * @param int      $media_id      Media post ID.
	 * @param int      $user_id       User ID.
	 * @param int|null $collection_id Optional collection ID.
	 * @return array{action: string, favorited: bool}
	 */
	public function toggle( int $media_id, int $user_id, ?int $collection_id = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_favorites';

		$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE media_id = %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$media_id,
				$user_id
			)
		);

		if ( $existing ) {
			$wpdb->delete( $table, array( 'id' => $existing ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return array(
				'action'    => 'removed',
				'favorited' => false,
			);
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'media_id'      => $media_id,
				'user_id'       => $user_id,
				'collection_id' => $collection_id,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s' )
		);

		/**
		 * Fires after a media item is favorited.
		 *
		 * @since 1.1.0
		 *
		 * @param int $media_id Media ID that was favorited.
		 * @param int $user_id  User who favorited it.
		 */
		do_action( 'mvs_favorite_added', $media_id, $user_id );

		return array(
			'action'    => 'added',
			'favorited' => true,
		);
	}

	/**
	 * Check if a user has favorited a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID.
	 * @return bool
	 */
	public function is_favorited( int $media_id, int $user_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_favorites';

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE media_id = %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$media_id,
				$user_id
			)
		);
	}

	/**
	 * Get a user's favorited media IDs.
	 *
	 * @param int      $user_id       User ID.
	 * @param int|null $collection_id Optional collection filter.
	 * @param int      $per_page      Items per page.
	 * @param int      $page          Page number.
	 * @return array{items: array, total: int}
	 */
	public function get_user_favorites( int $user_id, ?int $collection_id = null, int $per_page = 20, int $page = 1 ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'mvs_favorites';
		$offset = ( $page - 1 ) * $per_page;

		$where  = 'user_id = %d';
		$params = array( $user_id );

		if ( null !== $collection_id ) {
			$where   .= ' AND collection_id = %d';
			$params[] = $collection_id;
		}

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);

		$params[] = $per_page;
		$params[] = $offset;

		$items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id, collection_id, created_at FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			),
			ARRAY_A
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Get media IDs in a collection across all users, newest first.
	 *
	 * Used by the manual-curation collection template. For smart collections
	 * use `CollectionService::resolve()` instead — this method returns the
	 * raw favorited media-id list without rule resolution.
	 *
	 * @since 1.3.0
	 *
	 * @param int $collection_id Collection post ID.
	 * @param int $limit         Max rows to return. 0 = no limit.
	 * @return array<int> Media IDs ordered by created_at DESC.
	 */
	public function get_collection_media_ids( int $collection_id, int $limit = 100 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_favorites';

		if ( $limit > 0 ) {
			$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$table} WHERE collection_id = %d ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$collection_id,
					$limit
				)
			);
		} else {
			$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$table} WHERE collection_id = %d ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$collection_id
				)
			);
		}

		$ids = array_map( 'absint', (array) $rows );

		/**
		 * Filters the media IDs that belong to a manual collection.
		 *
		 * Free returns the IDs stored in `mvs_favorites.collection_id` (the
		 * single-collection model). Pro hooks this to union in the multi-collection
		 * memberships stored in `mvs_pro_collection_items` so media added via the
		 * Pro "Save to" picker actually surfaces when the collection is viewed.
		 *
		 * @since 1.6.0
		 *
		 * @param int[] $ids           Media IDs (created_at DESC).
		 * @param int   $collection_id Collection post ID.
		 * @param int   $limit         Max rows requested (0 = no limit).
		 */
		return apply_filters( 'mvs_collection_media_ids', $ids, $collection_id, $limit );
	}

	/**
	 * Get the favorite count for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return int
	 */
	public function get_count( int $media_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_favorites';

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE media_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$media_id
			)
		);
	}
}
