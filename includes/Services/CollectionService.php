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
	 * Rule keys accepted by save_rules() / resolve().
	 *
	 * @var string[]
	 */
	private const RULE_KEYS = array( 'media_type', 'tag', 'category', 'author', 'date_after', 'date_before', 'privacy' );

	/**
	 * Save smart collection rules.
	 *
	 * Rules are normalized before storage: keys are whitelisted, and
	 * tag/category/author values are coerced to IDs (a tag/category name or
	 * slug is resolved to its term ID) so resolve() always operates on IDs.
	 *
	 * @param int   $collection_id Collection post ID.
	 * @param array $rules         Array of rule definitions.
	 */
	public function save_rules( int $collection_id, array $rules ): void {
		$clean = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['key'] ) || ! isset( $rule['value'] ) ) {
				continue;
			}

			$key   = sanitize_key( $rule['key'] );
			$value = sanitize_text_field( (string) $rule['value'] );

			if ( ! in_array( $key, self::RULE_KEYS, true ) || '' === $value ) {
				continue;
			}

			if ( 'tag' === $key || 'category' === $key ) {
				$term_id = $this->resolve_term_id( $value, 'tag' === $key ? 'mvs_tag' : 'mvs_category' );
				if ( 0 === $term_id ) {
					continue;
				}
				$value = (string) $term_id;
			} elseif ( 'author' === $key ) {
				$value = (string) absint( $value );
				if ( '0' === $value ) {
					continue;
				}
			}

			$clean[] = array(
				'key'   => $key,
				'value' => $value,
			);
		}

		update_post_meta( $collection_id, '_mvs_collection_type', 'smart' );
		update_post_meta( $collection_id, '_mvs_collection_rules', $clean );
	}

	/**
	 * Resolve a tag/category rule value to a term ID.
	 *
	 * Accepts a numeric term ID, a slug, or a name. Legacy rules (seeded demo
	 * data and rows saved before 1.6.0 by the edit modal) stored names instead
	 * of IDs; resolving here heals them without a migration.
	 *
	 * @param string $value    Raw rule value.
	 * @param string $taxonomy Taxonomy name (mvs_tag or mvs_category).
	 * @return int Term ID, or 0 when unresolvable.
	 */
	private function resolve_term_id( string $value, string $taxonomy ): int {
		if ( is_numeric( $value ) ) {
			return absint( $value );
		}

		$term = get_term_by( 'slug', $value, $taxonomy );
		if ( ! $term ) {
			$term = get_term_by( 'name', $value, $taxonomy );
		}

		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
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

		// JOIN placeholders appear before WHERE placeholders in the final SQL,
		// so their params are collected separately and merged join-first.
		// A single rule-ordered array misaligned every value when a WHERE-type
		// rule (media_type/privacy/...) preceded a tag/category rule.
		$index_table  = $wpdb->prefix . 'mvs_media_index';
		$joins        = array();
		$wheres       = array( "idx.status = 'publish'" );
		$join_params  = array();
		$where_params = array();
		$join_idx     = 0;

		foreach ( $rules as $rule ) {
			if ( empty( $rule['key'] ) || ! isset( $rule['value'] ) ) {
				continue;
			}

			switch ( $rule['key'] ) {
				case 'media_type':
					$wheres[]       = 'idx.file_type LIKE %s';
					$where_params[] = '%' . $wpdb->esc_like( sanitize_text_field( $rule['value'] ) ) . '%';
					break;

				case 'tag':
				case 'category':
					$taxonomy = 'tag' === $rule['key'] ? 'mvs_tag' : 'mvs_category';
					$term_id  = $this->resolve_term_id( sanitize_text_field( (string) $rule['value'] ), $taxonomy );
					if ( 0 === $term_id ) {
						// Unresolvable term can never match anything.
						return array(
							'items' => array(),
							'total' => 0,
						);
					}
					$alias_tr      = 'tr' . $join_idx;
					$alias_tt      = 'tt' . $join_idx;
					$joins[]       = "INNER JOIN {$wpdb->term_relationships} AS {$alias_tr} ON {$alias_tr}.object_id = idx.media_id";
					$joins[]       = "INNER JOIN {$wpdb->term_taxonomy} AS {$alias_tt} ON {$alias_tt}.term_taxonomy_id = {$alias_tr}.term_taxonomy_id AND {$alias_tt}.taxonomy = %s AND {$alias_tt}.term_id = %d";
					$join_params[] = $taxonomy;
					$join_params[] = $term_id;
					++$join_idx;
					break;

				case 'author':
					$wheres[]       = 'idx.post_author = %d';
					$where_params[] = (int) $rule['value'];
					break;

				case 'date_after':
					$wheres[]       = 'idx.created_at >= %s';
					$where_params[] = sanitize_text_field( $rule['value'] ) . ' 00:00:00';
					break;

				case 'date_before':
					$wheres[]       = 'idx.created_at <= %s';
					$where_params[] = sanitize_text_field( $rule['value'] ) . ' 23:59:59';
					break;

				case 'privacy':
					$wheres[]       = 'idx.privacy = %s';
					$where_params[] = sanitize_text_field( $rule['value'] );
					break;
			}
		}

		$params    = array_merge( $join_params, $where_params );
		$join_sql  = implode( ' ', $joins );
		$where_sql = implode( ' AND ', $wheres );
		$offset    = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// Count total matches.
		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT idx.media_id) FROM {$index_table} AS idx {$join_sql} WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...$params
				)
			);
		} else {
			$total = (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT idx.media_id) FROM {$index_table} AS idx {$join_sql} WHERE {$where_sql}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			);
		}

		if ( 0 === $total ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		// Fetch page of results.
		$all_params = array_merge( $params, array( $per_page, $offset ) );
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT idx.media_id, idx.created_at FROM {$index_table} AS idx {$join_sql} WHERE {$where_sql} ORDER BY idx.created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$all_params
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

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
