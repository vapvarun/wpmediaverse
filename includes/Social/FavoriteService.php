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
	public function get_user_favorites( int $user_id, ?int $collection_id = null, int $per_page = 20, int $page = 1, string $search = '', string $orderby = 'favorited', string $order = 'DESC' ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'mvs_favorites';
		// Driving table is favourites; the index is only the joined side, so the
		// repository supplies the name rather than swallowing the query (Rule 7 —
		// see MediaRepository::index_table()).
		$index  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->index_table();
		$offset = ( $page - 1 ) * $per_page;

		$where  = 'f.user_id = %d';
		$params = array( $user_id );

		if ( null !== $collection_id ) {
			$where   .= ' AND f.collection_id = %d';
			$params[] = $collection_id;
		}

		// Sorting by anything the FAVOURITE does not itself know, or searching
		// at all, needs the media row. Everything is allowlisted to a column
		// name here — `orderby` reaches SQL and can never be interpolated.
		$columns = array(
			'favorited' => 'f.created_at',
			'title'     => 'm.title',
			'date'      => 'm.created_at',
		);

		$orderby = isset( $columns[ $orderby ] ) ? $orderby : 'favorited';
		$order   = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$needs_media = '' !== $search || 'favorited' !== $orderby;

		// The join stays OFF on the default path. Adding it unconditionally would
		// silently change `total` for every existing caller: a favourite whose
		// media was deleted is counted today and would stop being counted, which
		// is a behaviour change dressed up as a refactor (Production Rule 3).
		$join = $needs_media ? " INNER JOIN {$index} m ON m.media_id = f.media_id" : '';

		if ( '' !== $search ) {
			$where   .= ' AND m.title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} f{$join} WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);

		$params[] = $per_page;
		$params[] = $offset;

		$items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT f.media_id, f.collection_id, f.created_at FROM {$table} f{$join} WHERE {$where} ORDER BY {$columns[ $orderby ]} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

	/**
	 * Batch-resolve which of the given media a user has favorited.
	 *
	 * One query for a whole page of media instead of one per tile — the
	 * big-site path behind `is_favorited` in the media REST response.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $user_id   User ID (0 returns an empty set).
	 * @param int[] $media_ids Media IDs to test.
	 * @return array<int,bool> Map of favorited media_id => true. Absent key = not favorited.
	 */
	public function get_favorited_set( int $user_id, array $media_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $media_ids ) ) ) );
		if ( $user_id <= 0 || empty( $ids ) ) {
			return array();
		}

		$table        = $wpdb->prefix . 'mvs_favorites';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $user_id ), $ids );

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$table} WHERE user_id = %d AND media_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);

		$set = array();
		foreach ( (array) $rows as $mid ) {
			$set[ (int) $mid ] = true;
		}

		return $set;
	}
}
