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
	 * Post meta marking a member's "Favorites" collection (2.6.0).
	 *
	 * Save replaced the lightbox Favorite button; everything a member ever
	 * favorited shows up in this collection. It is a VIEW over their
	 * mvs_favorites rows, not a copy: no rows move, so the favourite REST
	 * routes the app uses, counts and GDPR export all keep working unchanged.
	 * Always private, never deletable, never smart.
	 *
	 * @since 2.6.0
	 * @var string
	 */
	public const FAVORITES_META = '_mvs_favorites_collection';

	/**
	 * Per-request cache of member id => Favorites collection id.
	 *
	 * @var array<int,int>
	 */
	private static array $favorites_ids = array();

	/**
	 * A member's Favorites collection, created on first need.
	 *
	 * Matched by its marker, never by title: a translated or member-made
	 * "Favorites" collection is a different thing and stays untouched.
	 *
	 * @since 2.6.0
	 *
	 * @param int  $user_id Member.
	 * @param bool $create  Create it when missing.
	 * @return int Collection post ID, or 0.
	 */
	public function favorites_collection_id( int $user_id, bool $create = false ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		if ( ! empty( self::$favorites_ids[ $user_id ] ) ) {
			return self::$favorites_ids[ $user_id ];
		}

		$found = get_posts(
			array(
				'post_type'      => 'mvs_collection',
				'post_status'    => 'private',
				'author'         => $user_id,
				'meta_key'       => self::FAVORITES_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one row per member.
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$id = (int) ( $found[0] ?? 0 );

		// ponytail: two first requests at once can each create one; the lowest
		// ID wins the lookup above. Add a lock if duplicates are ever seen.
		if ( ! $id && $create ) {
			$id = (int) wp_insert_post(
				array(
					'post_type'   => 'mvs_collection',
					'post_status' => 'private',
					'post_author' => $user_id,
					'post_title'  => __( 'Favorites', 'wpmediaverse' ),
					'meta_input'  => array(
						self::FAVORITES_META => 1,
						\WPMediaVerse\Services\CollectionService::PRIVACY_META => 'private',
						'_mvs_collection_type' => 'manual',
					),
				)
			);
		}

		if ( $id ) {
			self::$favorites_ids[ $user_id ] = $id;
		}

		return $id;
	}

	/**
	 * The member whose Favorites collection this is, or 0 for any other.
	 *
	 * @since 2.6.0
	 *
	 * @param int $collection_id Collection post ID.
	 * @return int
	 */
	public static function favorites_owner( int $collection_id ): int {
		if ( $collection_id <= 0 || ! get_post_meta( $collection_id, self::FAVORITES_META, true ) ) {
			return 0;
		}

		return (int) get_post_field( 'post_author', $collection_id );
	}

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
		$table = $wpdb->prefix . 'mvs_favorites';
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

		$orderby     = isset( $columns[ $orderby ] ) ? $orderby : 'favorited';
		$order       = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
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
				"SELECT f.media_id, f.collection_id, f.created_at FROM {$table} f{$join} WHERE {$where} ORDER BY {$columns[ $orderby ]} {$order}, f.id {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * VIEWER-FILTERED. This returned raw favourite rows with no privacy check,
	 * so a manual collection could show a cover, and count, media the viewer
	 * cannot open - the same half-applied privacy the favourites list had
	 * (10297947358). The smart path has always resolved with a viewer id; this
	 * one now does too. Basecamp 10298492555.
	 *
	 * @since 1.3.0
	 * @since 2.4.2 $viewer_id added; rows the viewer cannot view are dropped.
	 *
	 * @param int      $collection_id Collection post ID.
	 * @param int      $limit         Max rows to return. 0 = no limit.
	 * @param int|null $viewer_id     Viewer to authorise against. Null = current
	 *                                user. Pass 0 for an explicit anonymous read.
	 * @return array<int> Media IDs ordered by created_at DESC.
	 */
	public function get_collection_media_ids( int $collection_id, int $limit = 100, ?int $viewer_id = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_favorites';

		// A member's Favorites collection holds everything they favorited; any
		// other manual collection holds the rows filed under it.
		$owner = self::favorites_owner( $collection_id );
		$col   = $owner ? 'user_id' : 'collection_id';
		$val   = $owner ? $owner : $collection_id;

		if ( $limit > 0 ) {
			$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$table} WHERE {$col} = %d ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $col is one of two literals.
					$val,
					$limit
				)
			);
		} else {
			$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$table} WHERE {$col} = %d ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $col is one of two literals.
					$val
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
		$ids = apply_filters( 'mvs_collection_media_ids', $ids, $collection_id, $limit );

		// Gate AFTER the filter, not before. Pro hooks this filter to union in
		// the multi-collection rows from mvs_pro_collection_items; gating first
		// would authorise Free's rows and let every Pro row straight through on
		// a combo install - the exact half-applied privacy this card is about.
		$viewer  = null === $viewer_id ? get_current_user_id() : (int) $viewer_id;
		$privacy = \WPMediaVerse\Core\Plugin::container()->has( 'privacy' )
			? \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )
			: null;

		if ( ! $privacy ) {
			return $ids;
		}

		// One batch load before the per-id can_view() loop below, instead of a
		// fresh MediaRepository query per id — real N+1 at $limit = 0 (unbounded
		// manual-collection reads), the shape collection.php and
		// CollectionController::manual_visible_ids() both call with.
		if ( $ids ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->prefetch( $ids );
		}

		return array_values(
			array_filter(
				$ids,
				static function ( $media_id ) use ( $privacy, $viewer ) {
					return $privacy->can_view( (int) $media_id, $viewer );
				}
			)
		);
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
