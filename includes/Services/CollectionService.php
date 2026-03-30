<?php
/**
 * Collection service.
 *
 * Manages smart collection rules and resolves them to media queries.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Handles smart collection rule storage and resolution.
 */
class CollectionService {

	/**
	 * Save smart collection rules.
	 *
	 * @param int   $collection_id Collection post ID.
	 * @param array $rules         Array of rule definitions.
	 */
	public function save_rules( int $collection_id, array $rules ): void {
		update_post_meta( $collection_id, '_mvs_collection_type', 'smart' );
		update_post_meta( $collection_id, '_mvs_collection_rules', $rules );
	}

	/**
	 * Get the collection type.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return string 'manual' or 'smart'.
	 */
	public function get_type( int $collection_id ): string {
		$type = get_post_meta( $collection_id, '_mvs_collection_type', true );
		return $type ? $type : 'manual';
	}

	/**
	 * Get stored rules for a smart collection.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return array
	 */
	public function get_rules( int $collection_id ): array {
		$rules = get_post_meta( $collection_id, '_mvs_collection_rules', true );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Resolve smart collection rules to media IDs.
	 *
	 * Supported rule keys:
	 * - media_type: string (image, video, audio, document)
	 * - tag: int (term ID for mvs_tag)
	 * - category: int (term ID for mvs_category)
	 * - author: int (user ID)
	 * - date_after: string (Y-m-d)
	 * - date_before: string (Y-m-d)
	 * - privacy: string (public, members, private, etc.)
	 *
	 * @param int $collection_id Collection post ID.
	 * @param int $per_page      Items per page.
	 * @param int $page          Page number.
	 * @return array{items: array, total: int}
	 */
	public function resolve( int $collection_id, int $per_page = 20, int $page = 1 ): array {
		$rules = $this->get_rules( $collection_id );

		if ( empty( $rules ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		global $wpdb;

		$index_table = $wpdb->prefix . 'mvs_media_index';
		$joins       = array();
		$wheres      = array( "idx.status = 'publish'" );
		$params      = array();
		$join_idx    = 0;

		foreach ( $rules as $rule ) {
			if ( empty( $rule['key'] ) || ! isset( $rule['value'] ) ) {
				continue;
			}

			switch ( $rule['key'] ) {
				case 'media_type':
					$wheres[] = 'idx.file_type LIKE %s';
					$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $rule['value'] ) ) . '%';
					break;

				case 'tag':
				case 'category':
					$taxonomy = 'tag' === $rule['key'] ? 'mvs_tag' : 'mvs_category';
					$alias_tr = 'tr' . $join_idx;
					$alias_tt = 'tt' . $join_idx;
					$joins[]  = "INNER JOIN {$wpdb->term_relationships} AS {$alias_tr} ON {$alias_tr}.object_id = idx.media_id";
					$joins[]  = "INNER JOIN {$wpdb->term_taxonomy} AS {$alias_tt} ON {$alias_tt}.term_taxonomy_id = {$alias_tr}.term_taxonomy_id AND {$alias_tt}.taxonomy = %s AND {$alias_tt}.term_id = %d";
					$params[] = $taxonomy;
					$params[] = (int) $rule['value'];
					++$join_idx;
					break;

				case 'author':
					$wheres[] = 'idx.post_author = %d';
					$params[] = (int) $rule['value'];
					break;

				case 'date_after':
					$wheres[] = 'idx.created_at >= %s';
					$params[] = sanitize_text_field( $rule['value'] ) . ' 00:00:00';
					break;

				case 'date_before':
					$wheres[] = 'idx.created_at <= %s';
					$params[] = sanitize_text_field( $rule['value'] ) . ' 23:59:59';
					break;

				case 'privacy':
					$wheres[] = 'idx.privacy = %s';
					$params[] = sanitize_text_field( $rule['value'] );
					break;
			}
		}

		$join_sql  = implode( ' ', $joins );
		$where_sql = implode( ' AND ', $wheres );
		$offset    = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Count total matches.
		$count_sql = "SELECT COUNT(DISTINCT idx.media_id) FROM {$index_table} AS idx {$join_sql} WHERE {$where_sql}";
		$total     = ! empty( $params )
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			: (int) $wpdb->get_var( $count_sql );

		if ( 0 === $total ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		// Fetch page of results.
		$select_sql = "SELECT DISTINCT idx.media_id, idx.created_at FROM {$index_table} AS idx {$join_sql} WHERE {$where_sql} ORDER BY idx.created_at DESC LIMIT %d OFFSET %d";
		$all_params = array_merge( $params, array( $per_page, $offset ) );
		$rows       = $wpdb->get_results( $wpdb->prepare( $select_sql, ...$all_params ), ARRAY_A );

		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'media_id'   => (int) $row['media_id'],
				'created_at' => $row['created_at'],
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
