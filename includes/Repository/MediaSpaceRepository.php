<?php
/**
 * Document <-> Space link repository.
 *
 * A document has ONE home drive (mvs_media_index.drive_type/drive_id). This
 * join (mvs_media_spaces) lets the same document also appear in additional
 * Spaces without a second copy. The space Files tab unions its native rows with
 * the rows linked here; the link itself grants that space's members view access
 * (see Pro's PermissionService). Mirrors Eventonomy's event<->space model.
 *
 * Row cleanup lives elsewhere: the table is in
 * MediaRepository::MEDIA_CHILD_TABLES, so delete_cascade() (on media delete) and
 * the orphan sweep already purge it. This repository only reads and writes links.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes document<->space links.
 */
class MediaSpaceRepository {

	/**
	 * Link a document to a space. Idempotent - re-linking is a no-op.
	 *
	 * @param int $media_id Document media id.
	 * @param int $space_id Space id.
	 * @param int $added_by Member who created the link.
	 * @return bool True when a row was inserted (false when it already existed).
	 */
	public function link( int $media_id, int $space_id, int $added_by = 0 ): bool {
		global $wpdb;

		if ( $media_id <= 0 || $space_id <= 0 ) {
			return false;
		}

		$table = $wpdb->prefix . 'mvs_media_spaces';

		// INSERT IGNORE against the (media_id, space_id) primary key: a duplicate
		// link is silently dropped rather than erroring, so callers need not
		// pre-check. Affected-rows is 1 on a real insert, 0 on the ignored dup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} ( media_id, space_id, added_by, added_at ) VALUES ( %d, %d, %d, %s )",
				$media_id,
				$space_id,
				max( 0, $added_by ),
				current_time( 'mysql', true )
			)
		);

		return (int) $inserted > 0;
	}

	/**
	 * Remove a document<->space link. A no-op if it was not linked.
	 *
	 * @param int $media_id Document media id.
	 * @param int $space_id Space id.
	 * @return bool True when a row was removed.
	 */
	public function unlink( int $media_id, int $space_id ): bool {
		global $wpdb;

		if ( $media_id <= 0 || $space_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'mvs_media_spaces',
			array(
				'media_id' => $media_id,
				'space_id' => $space_id,
			),
			array( '%d', '%d' )
		);

		return (int) $deleted > 0;
	}

	/**
	 * Media ids linked INTO a space (the listing/view union set).
	 *
	 * @param int $space_id Space id.
	 * @return int[] Media ids linked to the space.
	 */
	public function media_ids_for_space( int $space_id ): array {
		return $this->linked_ids( 'media_id', 'space_id', $space_id );
	}

	/**
	 * Spaces a document is linked into (NOT counting its home drive).
	 *
	 * @param int $media_id Document media id.
	 * @return int[] Space ids.
	 */
	public function spaces_for_media( int $media_id ): array {
		return $this->linked_ids( 'space_id', 'media_id', $media_id );
	}

	/**
	 * Spaces for many documents at once, keyed by media id.
	 *
	 * One query for a whole page. Resolving links per row is the N+1 shape the
	 * document listing's prefetch budget exists to prevent.
	 *
	 * @param int[] $media_ids Document media ids.
	 * @return array<int,int[]> media_id => space ids (only ids that have links).
	 */
	public function spaces_for_media_many( array $media_ids ): array {
		global $wpdb;

		$media_ids = array_values( array_unique( array_filter( array_map( 'intval', $media_ids ) ) ) );

		if ( ! $media_ids ) {
			return array();
		}

		$table        = $wpdb->prefix . 'mvs_media_spaces';
		$placeholders = implode( ', ', array_fill( 0, count( $media_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT media_id, space_id FROM {$table} WHERE media_id IN ({$placeholders})", ...$media_ids )
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->media_id ][] = (int) $row->space_id;
		}

		return $out;
	}

	/**
	 * One side of the link table, looked up by the other.
	 *
	 * Both columns are literals supplied by this class and re-checked against
	 * the allow-list below, because they cannot be bound as parameters.
	 *
	 * @param string $select Column to return: media_id|space_id.
	 * @param string $where  Column to filter on: media_id|space_id.
	 * @param int    $id     Value for the filter column.
	 * @return int[] Ids, empty when the id is not positive.
	 */
	private function linked_ids( string $select, string $where, int $id ): array {
		global $wpdb;

		$allowed = array( 'media_id', 'space_id' );

		if ( $id <= 0 || ! in_array( $select, $allowed, true ) || ! in_array( $where, $allowed, true ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'mvs_media_spaces';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT {$select} FROM {$table} WHERE {$where} = %d", $id ) );

		return array_map( 'intval', (array) $ids );
	}
}
